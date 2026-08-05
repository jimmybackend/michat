<?php
// project_upload.php
// Sube archivos a un proyecto específico y los registra en ProjectSources
// POST: project_id (int), files[] (archivos)
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

// ===== Validar proyecto =====
$project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
if ($project_id <= 0) {
    jexit(['ok'=>false,'error'=>'project_id inválido'], 400);
}

$sqlCheck = "SELECT id_, name, slug, root_prefix FROM Projects WHERE id_ = ? AND user_id_ = ? AND status = 'active'";
$stmtCheck = $db_connection->prepare($sqlCheck);
if (!$stmtCheck) jexit(['ok'=>false,'error'=>'Error preparando: '.$db_connection->error], 500);
$stmtCheck->bind_param('ii', $project_id, $user_id);
$stmtCheck->execute();
$resCheck = $stmtCheck->get_result();
if ($resCheck->num_rows === 0) {
    $stmtCheck->close();
    jexit(['ok'=>false,'error'=>'Proyecto no encontrado o no tienes permisos'], 404);
}
$project = $resCheck->fetch_assoc();
$stmtCheck->close();

// ===== Validar archivos =====
if (empty($_FILES['files']) || !is_array($_FILES['files']['name'])) {
    jexit(['ok'=>false,'error'=>'No se recibieron archivos'], 400);
}

// ===== Construir ruta destino con fecha =====
// Formato: Data/Chat/Uploads/{YYYY}/{MM}/{DD}/{slug_proyecto}/
$now = new DateTime();
$year = $now->format('Y');
$month = $now->format('m');
$day = $now->format('d');
$slug = $project['slug'];

$rutaDestino = "Data/Chat/Uploads/{$year}/{$month}/{$day}/{$slug}/";

// ===== Subir archivos =====
$manager = new S3Manager();
$uploaded = [];
$errors = [];

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
        // 1. Subir a S3 y registrar en FileS3 (reutilizamos S3Manager)
        $result = $manager->uploadFile($tmpPath, $originalName, $rutaDestino, $user_id, $mimeType, $fileSize);
        
        // 2. Registrar en ProjectSources
        $sourceId = next_id($db_connection, 'ProjectSources', 'id_');
        $s3Key = $result['key_s3'];
        $filename = $originalName;
        
        // Detectar lenguaje por extensión
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $language_map = [
            'php' => 'php', 'js' => 'javascript', 'ts' => 'typescript',
            'py' => 'python', 'java' => 'java', 'cpp' => 'cpp', 'c' => 'c',
            'cs' => 'csharp', 'go' => 'go', 'rs' => 'rust', 'rb' => 'ruby',
            'html' => 'html', 'css' => 'css', 'scss' => 'scss',
            'json' => 'json', 'xml' => 'xml', 'yaml' => 'yaml', 'yml' => 'yaml',
            'md' => 'markdown', 'txt' => 'text', 'sql' => 'sql',
            'sh' => 'shell', 'bash' => 'shell',
        ];
        $language = $language_map[$ext] ?? 'unknown';
        
        $sqlInsert = "INSERT INTO ProjectSources (id_, project_id_, files3_id_, s3_key, filename, mime_type, size_bytes, language, sha256, status)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, 'pending')";
        $stmtInsert = $db_connection->prepare($sqlInsert);
        if (!$stmtInsert) {
            $errors[] = 'Error preparando INSERT ProjectSources: '.$db_connection->error;
            continue;
        }
        
        $files3_id = $result['id'];
        $stmtInsert->bind_param('iiisssis', 
            $sourceId, 
            $project_id, 
            $files3_id, 
            $s3Key, 
            $filename, 
            $mimeType, 
            $fileSize, 
            $language
        );
        
        if (!$stmtInsert->execute()) {
            $errors[] = 'Error insertando ProjectSources: '.$stmtInsert->error;
            $stmtInsert->close();
            continue;
        }
        $stmtInsert->close();
        
        $uploaded[] = [
            'id' => $sourceId,
            'filename' => $filename,
            's3_key' => $s3Key,
            'size' => $fileSize,
            'language' => $language,
            'status' => 'pending'
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