<?php

declare(strict_types=1);

@ini_set('max_execution_time', '600');
@set_time_limit(600);

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

function sessionUploadExit(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    require_once __DIR__ . '/app_bootstrap.php';
    require_once __DIR__ . '/S3Manager.php';
    require_once __DIR__ . '/includes/Chat/ChatIdentity.php';
    require_once __DIR__ . '/includes/SessionAttachmentKnowledgeService.php';
    require_once __DIR__ . '/includes/SessionImageKnowledgeService.php';
} catch (Throwable $e) {
    sessionUploadExit(['ok'=>false,'error'=>'bootstrap: '.$e->getMessage()], 500);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    sessionUploadExit(['ok'=>false,'error'=>'Método no permitido'], 405);
}
if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    sessionUploadExit(['ok'=>false,'error'=>'DB no disponible'], 500);
}

$userId = ChatIdentity::resolveUserId($db_connection);
if ($userId <= 0) sessionUploadExit(['ok'=>false,'error'=>'No autenticado'], 401);

$sessionId = (int)($_POST['session_id'] ?? 0);
if ($sessionId <= 0) sessionUploadExit(['ok'=>false,'error'=>'session_id es requerido'], 400);

$stmt = $db_connection->prepare('SELECT id_ FROM ChatSessions WHERE id_=? AND user_id_=? LIMIT 1');
if (!$stmt) sessionUploadExit(['ok'=>false,'error'=>'No se pudo validar la sesión'], 500);
$stmt->bind_param('ii', $sessionId, $userId);
$stmt->execute();
$owned = $stmt->get_result()->num_rows > 0;
$stmt->close();
if (!$owned) sessionUploadExit(['ok'=>false,'error'=>'Sesión no encontrada o acceso denegado'], 403);

if (empty($_FILES['files']) || !isset($_FILES['files']['name']) || !is_array($_FILES['files']['name'])) {
    sessionUploadExit(['ok'=>false,'error'=>'No se recibieron archivos'], 400);
}

$now = new DateTimeImmutable();
$year = $now->format('Y');
$month = $now->format('m');
$day = $now->format('d');
$rutaDestino = "Data/Chat/Uploads/{$userId}/{$year}/{$month}/{$day}/{$sessionId}/";

$manager = new S3Manager();
$knowledgeService = new SessionAttachmentKnowledgeService($db_connection);
$imageKnowledgeService = new SessionImageKnowledgeService($db_connection);
$uploaded = [];
$errors = [];

try {
    foreach ([
        'Data/Chat/Uploads/',
        "Data/Chat/Uploads/{$userId}/",
        "Data/Chat/Uploads/{$userId}/{$year}/",
        "Data/Chat/Uploads/{$userId}/{$year}/{$month}/",
        "Data/Chat/Uploads/{$userId}/{$year}/{$month}/{$day}/",
        $rutaDestino,
    ] as $prefix) {
        if (!$manager->folderExistsDb($userId, $prefix)) {
            $manager->upsertFolderDbPublic($userId, $prefix);
        }
    }
} catch (Throwable $e) {
    error_log('session_upload folders: ' . $e->getMessage());
}

$count = count($_FILES['files']['name']);
for ($i = 0; $i < $count; $i++) {
    if (!isset($_FILES['files']['error'][$i]) || $_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
        $errors[] = "Archivo {$i} no recibido o con error";
        continue;
    }

    $tmpPath = (string)($_FILES['files']['tmp_name'][$i] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        $errors[] = "El archivo {$i} no corresponde a un upload válido";
        continue;
    }

    $originalName = basename((string)($_FILES['files']['name'][$i] ?? ''));
    if ($originalName === '') {
        $errors[] = "El archivo {$i} no tiene nombre válido";
        continue;
    }

    $fileSize = filesize($tmpPath);
    if ($fileSize === false) {
        $errors[] = "No se pudo determinar el tamaño de {$originalName}";
        continue;
    }
    $mimeType = mime_content_type($tmpPath) ?: 'application/octet-stream';

    try {
        $result = $manager->uploadFile($tmpPath, $originalName, $rutaDestino, $userId, $mimeType, (int)$fileSize);
        if (!is_array($result) || !isset($result['id'])) {
            throw new RuntimeException('S3Manager::uploadFile() no devolvió un ID de FileS3 válido');
        }
        $fileId = (int)$result['id'];

        try {
            $knowledge = SessionImageKnowledgeService::supportsFilename($originalName)
                ? $imageKnowledgeService->process($userId, $sessionId, $fileId)
                : $knowledgeService->process($userId, $sessionId, $fileId);
        } catch (Throwable $knowledgeError) {
            $knowledge = [
                'ok' => false,
                'error' => $knowledgeError->getMessage(),
                'ready_for_attachment_rag' => false,
            ];
            error_log('session_upload knowledge: ' . $knowledgeError->getMessage());
        }

        $uploaded[] = [
            'id' => $fileId,
            'files3_id' => $fileId,
            'filename' => $result['nombre_original'] ?? $originalName,
            's3_key' => $result['key_s3'] ?? null,
            'size' => (int)$fileSize,
            'size_bytes' => (int)$fileSize,
            'mime_type' => $mimeType,
            'ruta' => $result['ruta'] ?? $rutaDestino,
            'created_at' => date('Y-m-d H:i:s'),
            'knowledge' => $knowledge,
        ];
    } catch (Throwable $e) {
        $errors[] = "Error subiendo {$originalName}: {$e->getMessage()}";
        error_log('session_upload.php: ' . $e->getMessage());
    }
}

$ready = 0;
foreach ($uploaded as $file) {
    if (!empty($file['knowledge']['ready_for_attachment_rag'])) $ready++;
}

sessionUploadExit([
    'ok' => true,
    'success' => count($uploaded) > 0,
    'uploaded' => $uploaded,
    'errors' => $errors,
    'ruta_destino' => $rutaDestino,
    'knowledge_ready' => $ready,
    'message' => count($uploaded) . ' archivo(s) subido(s); ' . $ready . ' listo(s) para búsqueda semántica/RAG.',
]);
