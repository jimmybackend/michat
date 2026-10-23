<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app_bootstrap.php';
require_once dirname(__DIR__) . '/Chat/ChatIdentity.php';
require_once __DIR__ . '/FileDeliveryService.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
try {
    $userId = ChatIdentity::resolveUserId($db_connection);
    $key = $_GET['archivo'] ?? '';
    if (!is_string($key)) throw new FileDeliveryException('Parámetro archivo inválido.', 400);
    $delivery = new FileDeliveryService($db_connection, Config::getS3(), Config::getBucket());
    $file = $delivery->resolve($userId, $key, $_SESSION);
    session_write_close();
    $delivery->send($file, $inlinePdf);
} catch (Throwable $e) {
    if (headers_sent()) { error_log('File delivery: interrupted stream'); exit; }
    $code = $e instanceof FileDeliveryException ? $e->getCode() : 502;
    $message = $e instanceof FileDeliveryException ? $e->getMessage() : 'No se pudo leer el archivo de S3. Verifica la configuración y el acceso del servidor.';
    if (!$e instanceof FileDeliveryException) {
        // Do not expose AWS URLs, bucket names, request details or credentials.
        error_log('File delivery failed: ' . get_class($e));
    }
    http_response_code($code);
    if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok'=>false, 'error'=>$message], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $message;
    }
}
