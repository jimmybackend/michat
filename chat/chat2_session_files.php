<?php
// chat2_session_files.php
// Devuelve los archivos adjuntos de una sesión.
// GET/POST: session_id (int)
// RESP: { ok, session_id, prefix, files: [...] }

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
   Resolver rutas (bootstrap) - igual que chat2_session_archive.php
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
    return in_array($r, ['administración','soporte','admin','administrator','support'], true);
}

/* ============================
   Parámetros (aceptamos GET o POST)
   ============================ */
$session_id = 0;
if (isset($_GET['session_id']) && is_numeric($_GET['session_id'])) {
    $session_id = (int)$_GET['session_id'];
} elseif (isset($_POST['session_id']) && is_numeric($_POST['session_id'])) {
    $session_id = (int)$_POST['session_id'];
}

if ($session_id <= 0) {
    jexit(['ok' => false, 'error' => 'session_id inválido'], 400);
}

$user_id = 0;
if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
    $user_id = (int)$_SESSION['user_id'];
}
if (!$user_id && isset($_POST['user_id']) && is_numeric($_POST['user_id'])) {
    $user_id = (int)$_POST['user_id'];
}
if (!$user_id) $user_id = 1; // fallback

$role = isset($_SESSION['role']) ? (string)$_SESSION['role'] : '';

/* ============================
   Verificar existencia + permisos (igual que archive)
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
$can_read = ($owner_id === $user_id) || is_admin_like($role);
if (!$can_read) {
    jexit(['ok' => false, 'error' => 'No tienes permisos para ver archivos de esta sesión'], 403);
}

/* ============================
   Construir prefijo de ruta
   Data/Chat/Uploads/{user_id}/{YYYY}/{MM}/{DD}/{session_id}/
   ============================ */
$prefix = '';
if (!empty($row['created_at'])) {
    try {
        $sessionDate = new DateTime($row['created_at']);
        $year  = $sessionDate->format('Y');
        $month = $sessionDate->format('m');
        $day   = $sessionDate->format('d');

        $prefix = "Data/Chat/Uploads/"
                . $owner_id . "/"
                . $year . "/"
                . $month . "/"
                . $day . "/"
                . $session_id . "/";
    } catch (Throwable $e) {
        // Si la fecha no se puede parsear, devolvemos lista vacía
        jexit([
            'ok'         => true,
            'session_id' => $session_id,
            'prefix'     => '',
            'files'      => []
        ]);
    }
}

/* ============================
   Consultar archivos en FileS3
   ============================ */
$sqlFiles = "
    SELECT
        f.id_,
        f.Nombre,
        f.Encriptado,
        f.Tamano,
        f.Metadatos,
        f.Ruta,
        f.Found,
        f.AccessType,
        f.Fecha,
        f.user_id_
    FROM FileS3 f
    WHERE f.user_id_ = ?
      AND f.Ruta LIKE CONCAT(?, '%')
      AND f.Found = 1
    ORDER BY f.Fecha DESC
";

$stmtF = $db_connection->prepare($sqlFiles);
if (!$stmtF) jexit(['ok' => false, 'error' => 'Error preparando FileS3: ' . $db_connection->error], 500);

$stmtF->bind_param('is', $owner_id, $prefix);
if (!$stmtF->execute()) {
    $e = $stmtF->error; $stmtF->close();
    jexit(['ok' => false, 'error' => 'Error ejecutando FileS3: ' . $e], 500);
}
$resF = $stmtF->get_result();

$files = [];
while ($file = $resF->fetch_assoc()) {
    $meta = $file['Metadatos'];
    $decoded = json_decode((string)$meta, true);
    if (json_last_error() === JSON_ERROR_NONE) $meta = $decoded;

    $files[] = [
        'id'          => (int)$file['id_'],
        'filename'    => (string)$file['Nombre'],
        's3_key'      => (string)$file['Encriptado'],
        'size_bytes'  => (int)$file['Tamano'],
        'metadata'    => $meta,
        'ruta'        => (string)$file['Ruta'],
        'found'       => (int)$file['Found'],
        'access_type' => (string)$file['AccessType'],
        'created_at'  => (string)$file['Fecha'],
        'user_id'     => (int)$file['user_id_']
    ];
}
$stmtF->close();

/* ============================
   Guardar sesión actual en PHP (opcional, para próximos requests)
   ============================ */
$_SESSION['chat_session_id'] = $session_id;

/* ============================
   Respuesta
   ============================ */
jexit([
    'ok'         => true,
    'session_id' => $session_id,
    'prefix'     => $prefix,
    'count'      => count($files),
    'files'      => $files
]);