<?php
// chat2_session_restore.php
// Quita el estado 'archived' y vuelve la sesión a 'open'.
// POST: session_id (int)
// RESP: { ok, id, status, title, model_id, provider, updated_at }

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
    $bootstrap = __DIR__ . '/app_bootstrap.php';
    if (!is_file($bootstrap)) $bootstrap = __DIR__ . '/../app_bootstrap.php';

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
    // Ajusta según tus roles con privilegios
    return in_array($r, ['administración','soporte','admin','administrator','support'], true);
}

/* ============================
   Parámetros
   ============================ */
$session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
if ($session_id <= 0) jexit(['ok' => false, 'error' => 'session_id inválido'], 400);

$user_id = 0;
if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
    $user_id = (int)$_SESSION['user_id'];
}
if (!$user_id && isset($_POST['user_id']) && is_numeric($_POST['user_id'])) {
    // (opcional) permitir override explícito
    $user_id = (int)$_POST['user_id'];
}
if (!$user_id) $user_id = 1; // fallback seguro

$role = isset($_SESSION['role']) ? (string)$_SESSION['role'] : '';

/* ============================
   Verificar existencia + permisos
   ============================ */
$sqlGet = "SELECT id_, user_id_, title, status, model_id, provider, created_at, updated_at
           FROM ChatSessions
           WHERE id_ = ?";
$stmtG = $db_connection->prepare($sqlGet);
if (!$stmtG) jexit(['ok' => false, 'error' => 'Error preparando consulta: ' . $db_connection->error], 500);

$stmtG->bind_param('i', $session_id);
if (!$stmtG->execute()) {
    $e = $stmtG->error; $stmtG->close();
    jexit(['ok' => false, 'error' => 'Error ejecutando consulta: ' . $e], 500);
}
$res = $stmtG->get_result();
if (!$res || !$res->num_rows) {
    $stmtG->close();
    jexit(['ok' => false, 'error' => 'Sesión no encontrada'], 404);
}
$row = $res->fetch_assoc();
$stmtG->close();

$owner_id = (int)$row['user_id_'];
$can_edit = ($owner_id === $user_id) || is_admin_like($role);
if (!$can_edit) {
    jexit(['ok' => false, 'error' => 'No tienes permisos para restaurar esta sesión'], 403);
}

/* ============================
   Actualizar estado => 'open'
   (destinado a desarchivar)
   ============================ */
if ($owner_id === $user_id || !is_admin_like($role)) {
    // caso normal: asegurar dueño en el WHERE
    $sqlUp = "UPDATE ChatSessions SET status = 'open' WHERE id_ = ? AND user_id_ = ? LIMIT 1";
    $stmtU = $db_connection->prepare($sqlUp);
    if (!$stmtU) jexit(['ok' => false, 'error' => 'Error preparando UPDATE: ' . $db_connection->error], 500);
    $stmtU->bind_param('ii', $session_id, $owner_id);
} else {
    // admin/soporte puede actualizar sin condicionar por user_id_
    $sqlUp = "UPDATE ChatSessions SET status = 'open' WHERE id_ = ? LIMIT 1";
    $stmtU = $db_connection->prepare($sqlUp);
    if (!$stmtU) jexit(['ok' => false, 'error' => 'Error preparando UPDATE: ' . $db_connection->error], 500);
    $stmtU->bind_param('i', $session_id);
}

if (!$stmtU->execute()) {
    $e = $stmtU->error; $stmtU->close();
    jexit(['ok' => false, 'error' => 'Error ejecutando UPDATE: ' . $e], 500);
}
$stmtU->close();

/* ============================
   Releer fila para devolver info
   ============================ */
$stmtG2 = $db_connection->prepare($sqlGet);
if ($stmtG2) {
    $stmtG2->bind_param('i', $session_id);
    if ($stmtG2->execute()) {
        $res2 = $stmtG2->get_result();
        $row2 = $res2 ? $res2->fetch_assoc() : null;
        $stmtG2->close();
        if ($row2) {
            jexit([
                'ok'        => true,
                'id'        => (int)$row2['id_'],
                'status'    => (string)$row2['status'],
                'title'     => (string)$row2['title'],
                'model_id'  => (string)$row2['model_id'],
                'provider'  => $row2['provider'] !== null ? (string)$row2['provider'] : null,
                'updated_at'=> (string)$row2['updated_at'],
            ]);
        }
    } else {
        $stmtG2->close();
    }
}

// Fallback si no pudimos releer (raro)
jexit([
    'ok'     => true,
    'id'     => (int)$session_id,
    'status' => 'open'
]);
