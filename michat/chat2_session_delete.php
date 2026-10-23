<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (session_status() === PHP_SESSION_NONE) session_start();

function trashExit(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    trashExit(['ok'=>false,'error'=>'Método no permitido'],405);
}
try {
    require_once __DIR__.'/app_bootstrap.php';
    require_once __DIR__.'/includes/Chat/ChatIdentity.php';
    require_once __DIR__.'/includes/Chat/SessionLifecycleService.php';
    require_once __DIR__.'/includes/Security/CsrfGuard.php';
    if (!isset($db_connection) || !($db_connection instanceof mysqli)) throw new RuntimeException('DB no disponible');
    CsrfGuard::assertSessionToken((string)($_POST['csrf_token'] ?? ''));
    $userId = ChatIdentity::resolveUserId($db_connection);
    if ($userId <= 0) trashExit(['ok'=>false,'error'=>'Sesión de usuario no válida'],401);
    $sessionId = (int)($_POST['session_id'] ?? 0);
    if ($sessionId <= 0) trashExit(['ok'=>false,'error'=>'session_id inválido'],400);
    // Baja reversible: status=closed. No borrar mensajes, tareas, memoria ni adjuntos.
    $row = (new SessionLifecycleService($db_connection))->trash($userId,$sessionId);
    trashExit(['ok'=>true]+$row);
} catch (OutOfBoundsException $e) {
    trashExit(['ok'=>false,'error'=>'Sesión no encontrada'],404);
} catch (InvalidArgumentException $e) {
    trashExit(['ok'=>false,'error'=>$e->getMessage()],400);
} catch (Throwable $e) {
    error_log('chat2_session_delete.php: '.$e->getMessage());
    trashExit(['ok'=>false,'error'=>'No se pudo mover la conversación a Eliminadas'],500);
}
