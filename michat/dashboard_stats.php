<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ini_set('display_errors','0');
try {
    require_once __DIR__ . '/app_bootstrap.php';
    require_once __DIR__ . '/includes/Chat/ChatIdentity.php';
    require_once __DIR__ . '/includes/Dashboard/DashboardStatisticsService.php';
    $user = ChatIdentity::resolveUserId($db_connection);
    if ($user < 1) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'No autorizado']); exit; }
    $month = trim((string)($_GET['month'] ?? date('Y-m')));
    echo json_encode((new DashboardStatisticsService($db_connection))->month($user,$month), JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (InvalidArgumentException $e) {
    http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('dashboard_stats: '.$e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'No se pudo consultar el informe. Revisa el registro del servidor.']);
}
