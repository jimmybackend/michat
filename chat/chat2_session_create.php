<?php
// chat2_session_create.php
// Crea una nueva sesión de chat (sin autoincrement).
// Entradas (POST): title?, user_id?, model (OBLIGATORIO)
// Salida JSON: { ok, id, title, status, model_id, provider, created_at, updated_at }

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
   Helpers
   ============================ */
function infer_provider($model_id) {
    $m = (string)$model_id;
    if ($m === '') return null;

    // prefijos típicos del select
    if (strpos($m, 'amazon.') === 0) return 'amazon';
    if (strpos($m, 'us.anthropic.') === 0 || strpos($m, 'anthropic.') === 0 || strpos($m, 'anthropic') !== false) return 'anthropic';
    if (strpos($m, 'openai:') === 0 || strpos($m, 'openai') !== false) return 'openai';

    return null;
}

function next_id(mysqli $db, $table, $col) {
    $table = preg_replace('/[^A-Za-z0-9_]+/','',$table);
    $col   = preg_replace('/[^A-Za-z0-9_]+/','',$col);

    $sql = "SELECT COALESCE(MAX($col), 0) + 1 AS nxt FROM $table";
    $rs  = $db->query($sql);
    if (!$rs) return 1;
    $row = $rs->fetch_assoc();
    return (int)($row['nxt'] ?? 1);
}

/* ============================
   Parámetros
   ============================ */
$title = isset($_POST['title']) ? trim((string)$_POST['title']) : '';
if ($title === '') $title = 'Nueva conversación (Auto)';

$user_id = 0;
if (isset($_POST['user_id']) && is_numeric($_POST['user_id'])) {
    $user_id = (int)$_POST['user_id'];
}
if (!$user_id && isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
    $user_id = (int)$_SESSION['user_id'];
}
if (!$user_id) $user_id = 1; // Fallback seguro; ajusta si tu app requiere otro.

// ✅ Modelo OBLIGATORIO (ya lo envías desde el <select>)
$model_id = isset($_POST['model']) ? trim((string)$_POST['model']) : '';
if ($model_id === '') {
    jexit(['ok'=>false,'error'=>'Falta parámetro model'], 400);
}
$provider = infer_provider($model_id);

// ✅ Proyecto opcional
$project_id = isset($_POST['project_id']) && $_POST['project_id'] !== '' ? (int)$_POST['project_id'] : null;

// Si se proporciona project_id, verificar que exista y pertenezca al usuario
if ($project_id !== null) {
    $sqlCheckProject = "SELECT id_ FROM Projects WHERE id_ = ? AND user_id_ = ? AND status = 'active'";
    $stmtCheckProject = $db_connection->prepare($sqlCheckProject);
    if ($stmtCheckProject) {
        $stmtCheckProject->bind_param('ii', $project_id, $user_id);
        $stmtCheckProject->execute();
        $resCheckProject = $stmtCheckProject->get_result();
        if ($resCheckProject->num_rows === 0) {
            $stmtCheckProject->close();
            $project_id = null; // Si no es válido, lo ignoramos
        } else {
            $stmtCheckProject->close();
        }
    }
}
/* ============================
   Crear id_ y hacer INSERT
   ============================ */
$attempts = 0;
$maxAttempts = 3;
$created_id = null;
$error = null;

while ($attempts < $maxAttempts) {
    $attempts++;

    $id_ = next_id($db_connection, 'ChatSessions', 'id_');

$sql = "INSERT INTO ChatSessions (id_, user_id_, project_id_, title, model_id, provider, status, meta)
        VALUES (?, ?, ?, ?, ?, ?, 'open', NULL)";
$stmt = $db_connection->prepare($sql);
if (!$stmt) {
    $error = 'Error preparando SQL: ' . $db_connection->error;
    break;
}
$stmt->bind_param('iiisss', $id_, $user_id, $project_id, $title, $model_id, $provider);
    $ok = $stmt->execute();

    if ($ok) {
        $created_id = $id_;
        $stmt->close();
        break;
    } else {
        // Si es colisión (id_ duplicado), reintenta; de lo contrario sal
        $err = (string)$stmt->error;
        $stmt->close();
        if (stripos($err, 'duplicate') !== false || stripos($err, 'duplic') !== false) {
            usleep(50000);
            continue;
        }
        $error = 'Error insertando: ' . $err;
        break;
    }
}

if (!$created_id) {
    jexit(['ok' => false, 'error' => $error ?: 'No se pudo crear la sesión'], 500);
}

// Leer la fila para devolver timestamps (opcional)
$sqlGet = "SELECT title, status, model_id, provider, project_id_, created_at, updated_at
           FROM ChatSessions WHERE id_ = ?";
$stmtG = $db_connection->prepare($sqlGet);
if ($stmtG) {
    $stmtG->bind_param('i', $created_id);
    if ($stmtG->execute()) {
        $res = $stmtG->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmtG->close();

if ($row) { 
    jexit([
        'ok' => true,
        'id' => (int)$created_id,
        'project_id' => $row['project_id_'] !== null ? (int)$row['project_id_'] : null,
        'title' => (string)($row['title'] ?? $title),
        'status' => (string)($row['status'] ?? 'open'),
        'model_id' => (string)($row['model_id'] ?? $model_id),
        'provider' => $row['provider'] !== null ? (string)$row['provider'] : $provider,
        'created_at' => (string)($row['created_at'] ?? ''),
        'updated_at' => (string)($row['updated_at'] ?? ''),
    ]);
}
    } else {
        $stmtG->close();
    }
}

// Fallback si no pudimos releer (raro)
jexit([
    'ok' => true,
    'id' => (int)$created_id,
    'project_id' => $project_id,
    'title' => $title,
    'status' => 'open',
    'model_id' => $model_id,
    'provider' => $provider
]);
