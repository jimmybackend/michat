<?php
// session_upload.php
// Sube archivos para adjuntos de sesión de chat (no vinculados a proyecto)
// POST: session_id (int, opcional), files[] (archivos)
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

function jexit($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function next_id(mysqli $db, $table, $col) {
    $table = preg_replace('/[^A-Za-z0-9_]+/','',$table);
    $col   = preg_replace('/[^A-Za-z0-9_]+/','',$col);
    $rs = $db->query("SELECT COALESCE(MAX($col), 0) + 1 AS nxt FROM $table");
    if (!$rs) return 1;
    $row = $rs->fetch_assoc();
    return (int)($row['nxt'] ?? 1);
}

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

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    jexit(['ok'=>false,'error'=>'DB no disponible'], 500);
}

require_once __DIR__ . '/S3Manager.php';

// ===== Validar método =====
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jexit(['ok'=>false,'error'=>'Método no permitido'], 405);
}

// ===== User ID =====
$user_id = 0;
if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
    $user_id = (int)$_SESSION['user_id'];
}
if (!$user_id && isset($_POST['user_id']) && is_numeric($_POST['user_id'])) {
    $user_id = (int)$_POST['user_id'];
}
if (!$user_id) $user_id = 1;

// ===== Session ID (opcional) =====
$session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;

// ===== Validar archivos =====
if (empty($_FILES['files']) || !is_array($_FILES['files']['name'])) {
    jexit(['ok'=>false,'error'=>'No se recibieron archivos'], 400);
}

// ===== Construir ruta destino con fecha y session_id =====
// Formato: Data/Chat/Uploads/{user_id}/{YYYY}/{MM}/{DD}/{session_id}/
$now = new DateTime();
$year = $now->format('Y');
$month = $now->format('m');
$day = $now->format('d');

// Validar session_id si se proporcionó
if ($session_id <= 0) {
    // Si no hay session_id, usar un valor por defecto temporal
    // En producción esto debería ser un error
    $session_id = 0;
}

$rutaDestino = "Data/Chat/Uploads/{$user_id}/{$year}/{$month}/{$day}/{$session_id}/";

// ===== Subir archivos =====
$manager = new S3Manager();
$uploaded = [];
$errors = [];

// Crear carpetas S3Folders para la jerarquía completa
try {
    $userIdInt = (int)$user_id;
    $prefixes = [
        "Data/Chat/Uploads/",
        "Data/Chat/Uploads/{$userIdInt}/",
        "Data/Chat/Uploads/{$userIdInt}/{$year}/",
        "Data/Chat/Uploads/{$userIdInt}/{$year}/{$month}/",
        "Data/Chat/Uploads/{$userIdInt}/{$year}/{$month}/{$day}/",
        "Data/Chat/Uploads/{$userIdInt}/{$year}/{$month}/{$day}/{$session_id}/"
    ];
    
    foreach ($prefixes as $prefix) {
        if (!$manager->folderExistsDb($userIdInt, $prefix)) {
            $manager->upsertFolderDbPublic($userIdInt, $prefix);
        }
    }
} catch (Throwable $e) {
    // Si falla la creación de carpetas, continuar igual con el upload
    // Las carpetas se crearán bajo demanda en uploadFile
    error_log('Warning: Error creando S3Folders: ' . $e->getMessage());
}

$count = count($_FILES['files']['name']);
for ($i = 0; $i < $count; $i++) {
    if (!isset($_FILES['files']['error'][$i]) || $_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
        $errors[] = 'Archivo '.$i.' no recibido o con error';
        continue;
    }
    
    $tmpPath = $_FILES['files']['tmp_name'][$i];
    $originalName = basename($_FILES['files']['name'][$i]);
    $fileSize = filesize($tmpPath);
    $mimeType = mime_content_type($tmpPath);
    
    try {
        // 1. Subir a S3 y registrar en FileS3
        $result = $manager->uploadFile($tmpPath, $originalName, $rutaDestino, $user_id, $mimeType, $fileSize);
        
        $uploaded[] = [
            'id' => $result['id'],
            'files3_id' => $result['id'],
            'filename' => $result['nombre_original'],
            's3_key' => $result['key_s3'],
            'size' => $fileSize,
            'mime_type' => $mimeType,
            'ruta' => $result['ruta'],
            'created_at' => date('Y-m-d H:i:s')
        ];
        
    } catch (Throwable $e) {
        $errors[] = 'Error subiendo '.$originalName.': '.$e->getMessage();
    }
}

jexit([
    'ok' => true,
    'uploaded' => $uploaded,
    'errors' => $errors,
    'ruta_destino' => $rutaDestino,
    'message' => count($uploaded).' archivos subidos correctamente'
]);
