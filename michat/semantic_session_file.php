<?php

declare(strict_types=1);

@ini_set('max_execution_time', '600');
@set_time_limit(600);

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/includes/Chat/ChatIdentity.php';
require_once __DIR__ . '/includes/SessionAttachmentKnowledgeService.php';

function semanticSessionFileExit(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semanticSessionFileExit(['ok'=>false,'error'=>'Método no permitido'], 405);
}
if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    semanticSessionFileExit(['ok'=>false,'error'=>'DB no disponible'], 500);
}

$userId = ChatIdentity::resolveUserId($db_connection);
if ($userId <= 0) semanticSessionFileExit(['ok'=>false,'error'=>'Sesión inválida'], 401);

$fileId = (int)($_POST['file_id'] ?? 0);
$sessionId = (int)($_POST['session_id'] ?? 0);
if ($fileId <= 0 || $sessionId <= 0) {
    semanticSessionFileExit(['ok'=>false,'error'=>'file_id y session_id son obligatorios'], 400);
}

try {
    $result = (new SessionAttachmentKnowledgeService($db_connection))->semantic($userId, $sessionId, $fileId);
    semanticSessionFileExit($result + [
        'mensaje' => !empty($result['embedding_ready'])
            ? 'Semántica creada y embedding listo para RAG.'
            : 'Semántica creada. El embedding pendiente puede reintentarse desde Mantenimiento.',
    ]);
} catch (InvalidArgumentException $e) {
    semanticSessionFileExit(['ok'=>false,'error'=>$e->getMessage()], 400);
} catch (Throwable $e) {
    $message = $e->getMessage();
    $status = str_contains($message, 'desactivad') ? 409
        : ((str_contains($message, 'no es tuya') || str_contains($message, 'no pertenece')) ? 403 : 500);
    semanticSessionFileExit(['ok'=>false,'error'=>$message], $status);
}
