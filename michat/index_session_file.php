<?php

declare(strict_types=1);

@ini_set('max_execution_time', '600');
@set_time_limit(600);

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/includes/Chat/ChatIdentity.php';
require_once __DIR__ . '/includes/SessionAttachmentKnowledgeService.php';
require_once __DIR__ . '/includes/SessionImageKnowledgeService.php';

function indexSessionFileExit(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    indexSessionFileExit(['ok'=>false,'error'=>'Método no permitido'], 405);
}
if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    indexSessionFileExit(['ok'=>false,'error'=>'DB no disponible'], 500);
}

$userId = ChatIdentity::resolveUserId($db_connection);
if ($userId <= 0) indexSessionFileExit(['ok'=>false,'error'=>'Sesión inválida'], 401);

$fileId = (int)($_POST['file_id'] ?? 0);
$sessionId = (int)($_POST['session_id'] ?? 0);
if ($fileId <= 0 || $sessionId <= 0) {
    indexSessionFileExit(['ok'=>false,'error'=>'file_id y session_id son obligatorios'], 400);
}

try {
    $imageService = new SessionImageKnowledgeService($db_connection);
    $isImage = $imageService->supports($userId, $sessionId, $fileId);
    $result = $isImage
        ? $imageService->process($userId, $sessionId, $fileId)
        : (new SessionAttachmentKnowledgeService($db_connection))->index($userId, $sessionId, $fileId);
    indexSessionFileExit($result + [
        'mensaje' => $isImage
            ? (!empty($result['embedding_ready'])
                ? 'Imagen analizada y embedding visual listo para RAG.'
                : 'Imagen analizada. El embedding pendiente puede reintentarse desde Mantenimiento.')
            : (!empty($result['embedding_ready'])
                ? 'Archivo indexado y embeddings listos para RAG.'
                : 'Archivo indexado. Los embeddings pendientes pueden reintentarse desde Mantenimiento.'),
    ]);
} catch (InvalidArgumentException $e) {
    indexSessionFileExit(['ok'=>false,'error'=>$e->getMessage()], 400);
} catch (Throwable $e) {
    $status = str_contains($e->getMessage(), 'no es tuya') || str_contains($e->getMessage(), 'no pertenece') ? 403 : 500;
    indexSessionFileExit(['ok'=>false,'error'=>$e->getMessage()], $status);
}
