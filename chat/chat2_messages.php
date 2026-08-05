<?php
// chat2_messages.php
// Lista los mensajes de una sesión.
// GET: session_id (int) [obligatorio], limit? (int, default 300, máx 2000)
// RESP: { ok, session: {...}, messages: [ { id, role, content_type, content, s3_key, mime_type, size_bytes, thumb_s3_key, duration_ms, model_id, stop_reason, prompt_tokens, completion_tokens, latency_ms, meta, created_at, user_id } ] }

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

/* ============================
   Helpers (salida)
   ============================ */
function jexit($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================
   Resolver rutas (bootstrap)
   ============================ */
function resolve_root_candidates(): array {
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? (string)$_SERVER['DOCUMENT_ROOT'] : '';
    $rootFromDoc = $docRoot !== '' ? realpath($docRoot . '/..') : false;

    $candidates = [];
    foreach ([
        $rootFromDoc,
        realpath(__DIR__ . '/../../'),
        realpath(__DIR__ . '/../..'),
        realpath(__DIR__ . '/../../../'),
        realpath(__DIR__ . '/../'),
        realpath(__DIR__),
    ] as $p) {
        if ($p && is_dir($p)) $candidates[$p] = true;
    }
    return array_keys($candidates);
}

function find_file_in_candidates(string $filename, array $bases, array $subfolders): ?string {
    $filename = ltrim($filename, '/');
    foreach ($bases as $base) {
        foreach ($subfolders as $sub) {
            $sub = ($sub === '' ? '' : '/' . trim($sub,'/'));
            $try = rtrim($base,'/') . $sub . '/' . $filename;
            if (is_file($try)) return $try;
        }
    }
    return null;
}

/* ============================
   Cargar bootstrap (vendor + Config + db)
   ============================ */
try {
    // 1) mismo folder
    $bootstrap = __DIR__ . '/app_bootstrap.php';

    // 2) un nivel arriba
    if (!is_file($bootstrap)) {
        $bootstrap = __DIR__ . '/../app_bootstrap.php';
    }

    // 3) búsqueda por candidatos (por si moviste carpetas)
    if (!is_file($bootstrap)) {
        $bases = resolve_root_candidates();
        $bootstrap = find_file_in_candidates('app_bootstrap.php', $bases, ['', 'public_html', 'api', 'app', 'www']);
    }

    if (!$bootstrap || !is_file($bootstrap)) {
        throw new RuntimeException('app_bootstrap.php no encontrado.');
    }

    require_once $bootstrap;

} catch (Throwable $e) {
    jexit(['ok' => false, 'error' => 'bootstrap: ' . $e->getMessage()], 500);
}

// ===== Validar DB =====
if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    jexit(['ok'=>false,'error'=>'DB no disponible (bootstrap)'], 500);
}

/* ============================
   Helpers (permisos)
   ============================ */
function is_admin_like($role) {
    $r = strtolower((string)$role);
    return in_array($r, ['administración','soporte','admin','administrator','support'], true);
}

/* ============================
   Parámetros
   ============================ */
$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
if ($session_id <= 0) jexit(['ok' => false, 'error' => 'session_id inválido'], 400);

$user_id = 0;
if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
    $user_id = (int)$_SESSION['user_id'];
}
if (!$user_id && isset($_GET['user_id']) && is_numeric($_GET['user_id'])) {
    // (opcional) permitir override explícito por querystring
    $user_id = (int)$_GET['user_id'];
}
if (!$user_id) $user_id = 1; // fallback seguro

$role = isset($_SESSION['role']) ? (string)$_SESSION['role'] : '';

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 300;
if ($limit <= 0) $limit = 300;
if ($limit > 2000) $limit = 2000;

/* ============================
   Verificar existencia + permisos
   ============================ */
$sqlGet = "SELECT id_, user_id_, project_id_, title, status, model_id, provider, context_summary, created_at, updated_at
           FROM ChatSessions
           WHERE id_ = ?";
$stmtG = $db_connection->prepare($sqlGet);
if (!$stmtG) jexit(['ok' => false, 'error' => 'Error preparando consulta: ' . $db_connection->error], 500);

$stmtG->bind_param('i', $session_id);
if (!$stmtG->execute()) {
    $e = $stmtG->error; $stmtG->close();
    jexit(['ok' => false, 'error' => 'Error ejecutando consulta: ' . $e], 500);
}
$resS = $stmtG->get_result();
if (!$resS || !$resS->num_rows) {
    $stmtG->close();
    jexit(['ok' => false, 'error' => 'Sesión no encontrada'], 404);
}
$sessionRow = $resS->fetch_assoc();
$stmtG->close();

$owner_id = (int)$sessionRow['user_id_'];
$can_view = ($owner_id === $user_id) || is_admin_like($role);
if (!$can_view) {
    jexit(['ok' => false, 'error' => 'No tienes permisos para ver esta sesión'], 403);
}

/* ============================
   Listar mensajes de la sesión
   ============================ */
// Nota: sanitizamos $limit y lo interpolamos; MySQL permite literales numéricos seguros.
$limit_sql = (string)$limit;
$sqlM = "
SELECT
    id_, session_id_, user_id_, role, content_type, content,
    s3_key, mime_type, size_bytes, thumb_s3_key, duration_ms,
    model_id, stop_reason, prompt_tokens, completion_tokens, latency_ms, meta,
    phase, parent_msg_id, created_at
FROM ChatMessages cm
WHERE session_id_ = ?
  AND NOT EXISTS (
      -- Filtro mágico: Excluye mensajes de system con phase='respond' 
      -- si ya existe uno con phase='compile' para el mismo mensaje de usuario
      SELECT 1 FROM ChatMessages cm2 
      WHERE cm2.session_id_ = cm.session_id_ 
        AND cm2.role = 'system' 
        AND cm2.phase = 'compile' 
        AND cm2.parent_msg_id = cm.parent_msg_id
        AND cm.phase = 'respond'
        AND cm.role = 'system'
  )
ORDER BY id_ ASC
LIMIT $limit_sql
";

$stmtM = $db_connection->prepare($sqlM);
if (!$stmtM) jexit(['ok' => false, 'error' => 'Error preparando mensajes: ' . $db_connection->error], 500);

$stmtM->bind_param('i', $session_id);
if (!$stmtM->execute()) {
    $e = $stmtM->error; $stmtM->close();
    jexit(['ok' => false, 'error' => 'Error ejecutando mensajes: ' . $e], 500);
}
$resM = $stmtM->get_result();

$messages = [];
while ($m = $resM->fetch_assoc()) {
    $messages[] = [
        'id'                => (int)$m['id_'],
        'session_id'        => (int)$m['session_id_'],
        'user_id'           => (int)$m['user_id_'],
        'role'              => (string)$m['role'],
        'content_type'      => (string)$m['content_type'],
        'content'           => (string)$m['content'],
        's3_key'            => $m['s3_key'] !== null ? (string)$m['s3_key'] : null,
        'mime_type'         => $m['mime_type'] !== null ? (string)$m['mime_type'] : null,
        'size_bytes'        => $m['size_bytes'] !== null ? (int)$m['size_bytes'] : null,
        'thumb_s3_key'      => $m['thumb_s3_key'] !== null ? (string)$m['thumb_s3_key'] : null,
        'duration_ms'       => $m['duration_ms'] !== null ? (int)$m['duration_ms'] : null,
        'model_id'          => $m['model_id'] !== null ? (string)$m['model_id'] : null,
        'stop_reason'       => $m['stop_reason'] !== null ? (string)$m['stop_reason'] : null,
        'prompt_tokens'     => $m['prompt_tokens'] !== null ? (int)$m['prompt_tokens'] : null,
        'completion_tokens' => $m['completion_tokens'] !== null ? (int)$m['completion_tokens'] : null,
        'latency_ms'        => $m['latency_ms'] !== null ? (int)$m['latency_ms'] : null,
        'meta'              => $m['meta'] !== null ? (string)$m['meta'] : null,
        'phase'             => $m['phase'] !== null ? (string)$m['phase'] : null,       // <-- NUEVO
        'parent_msg_id'     => $m['parent_msg_id'] !== null ? (int)$m['parent_msg_id'] : null, // <-- NUEVO
        'created_at'        => (string)$m['created_at'],
    ];
}
$stmtM->close();

/* ============================
   Respuesta
   ============================ */
// Si la sesión tiene proyecto, cargar contexto del proyecto
$projectContext = null;
if (!empty($sessionRow['project_id_'])) {
    $projectId = (int)$sessionRow['project_id_'];
    
    // Cargar reglas del proyecto
    $sqlCtx = "SELECT type, title, content FROM ProjectContext WHERE project_id_ = ? ORDER BY created_at ASC";
    $stmtCtx = $db_connection->prepare($sqlCtx);
    if ($stmtCtx) {
        $stmtCtx->bind_param('i', $projectId);
        $stmtCtx->execute();
        $resCtx = $stmtCtx->get_result();
        $contextItems = [];
        while ($ctx = $resCtx->fetch_assoc()) {
            $contextItems[] = [
                'type' => $ctx['type'],
                'title' => $ctx['title'],
                'content' => $ctx['content']
            ];
        }
        $stmtCtx->close();
        
        if (!empty($contextItems)) {
            $projectContext = $contextItems;
        }
    }
}

jexit([
    'ok' => true,
    'session' => [
        'id'              => (int)$sessionRow['id_'],
        'user_id'         => (int)$sessionRow['user_id_'],
        'project_id'      => !empty($sessionRow['project_id_']) ? (int)$sessionRow['project_id_'] : null,
        'title'           => (string)$sessionRow['title'],
        'status'          => (string)$sessionRow['status'],
        'model_id'        => (string)$sessionRow['model_id'],
        'provider'        => $sessionRow['provider'] !== null ? (string)$sessionRow['provider'] : null,
        'context_summary' => !empty($sessionRow['context_summary']) ? (string)$sessionRow['context_summary'] : null,
        'project_context' => $projectContext,
        'created_at'      => (string)$sessionRow['created_at'],
        'updated_at'      => (string)$sessionRow['updated_at'],
    ],
    'messages' => $messages
]);
