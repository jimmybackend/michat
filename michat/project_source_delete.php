<?php
// project_source_delete.php
// Elimina una fuente de un proyecto (archivo S3 + ProjectSources)
// POST: source_id (int)
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

function jexit($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
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

$user_id = 0;
if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
    $user_id = (int)$_SESSION['user_id'];
}
if (!$user_id) $user_id = 1;

$source_id = isset($_POST['source_id']) ? (int)$_POST['source_id'] : 0;
if ($source_id <= 0) {
    jexit(['ok'=>false,'error'=>'source_id inválido'], 400);
}

// ===== Obtener fuente y verificar permisos =====
$sql = "SELECT ps.id_, ps.files3_id_, ps.s3_key, ps.filename, p.user_id_
        FROM ProjectSources ps
        JOIN Projects p ON p.id_ = ps.project_id_
        WHERE ps.id_ = ?";
$stmt = $db_connection->prepare($sql);
if (!$stmt) jexit(['ok'=>false,'error'=>'Error preparando: '.$db_connection->error], 500);
$stmt->bind_param('i', $source_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    $stmt->close();
    jexit(['ok'=>false,'error'=>'Fuente no encontrada'], 404);
}
$source = $res->fetch_assoc();
$stmt->close();

if ((int)$source['user_id_'] !== $user_id) {
    jexit(['ok'=>false,'error'=>'No tienes permisos'], 403);
}

$manager = new S3Manager();

try {
    // 1. Eliminar archivo de S3 y FileS3 (si existe files3_id_)
    if (!empty($source['files3_id_'])) {
        $manager->deleteFile((int)$source['files3_id_']);
    } else {
        // Si no hay files3_id_, eliminar solo de S3 directamente
        $s3 = Config::getS3();
        $bucket = $manager->getBucket();
        $s3->deleteObject([
            'Bucket' => $bucket,
            'Key' => $source['s3_key']
        ]);
    }
    
    // 2. EmbeddingJobs no tiene FK a SourceChunks: limpiarlo antes de borrar la fuente.
    $stmtJobs = $db_connection->prepare("
        DELETE ej FROM EmbeddingJobs ej
        JOIN SourceChunks sc ON ej.target_type='source_chunk' AND ej.target_id=sc.id_
        WHERE sc.source_id_=?
    ");
    if ($stmtJobs) {
        $stmtJobs->bind_param('i', $source_id);
        $stmtJobs->execute();
        $stmtJobs->close();
    }

    // 3. Eliminar registro de ProjectSources (cascada eliminará chunks y embeddings)
    $sqlDel = "DELETE FROM ProjectSources WHERE id_ = ?";
    $stmtDel = $db_connection->prepare($sqlDel);
    if (!$stmtDel) jexit(['ok'=>false,'error'=>'Error preparando DELETE: '.$db_connection->error], 500);
    $stmtDel->bind_param('i', $source_id);
    if (!$stmtDel->execute()) {
        $e = $stmtDel->error; $stmtDel->close();
        jexit(['ok'=>false,'error'=>'Error eliminando: '.$e], 500);
    }
    $stmtDel->close();
    
    jexit([
        'ok' => true,
        'message' => 'Fuente eliminada correctamente',
        'filename' => $source['filename']
    ]);
    
} catch (Throwable $e) {
    jexit(['ok'=>false,'error'=>'Error eliminando: '.$e->getMessage()], 500);
}