<?php

declare(strict_types=1);

/**
 * trace_metrics_api.php · Fase 7.7
 * API READ-ONLY de métricas agregadas para el explorador de trazabilidad.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (session_status() === PHP_SESSION_NONE) session_start();

function traceMetricsExit(array $payload, int $status = 200): void
{
    http_response_code($status);
    $pretty = isset($_GET['pretty']) && (string)$_GET['pretty'] === '1';
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ($pretty ? JSON_PRETTY_PRINT : 0);
    echo json_encode($payload, $flags);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    traceMetricsExit(['ok'=>false,'api_version'=>'7.7','error'=>'La API de métricas es read-only. Usa GET.'], 405);
}

try {
    $endpointDir = __DIR__;
    $bootstrapHelper = $endpointDir . '/includes/Chat/ChatEndpointBootstrap.php';
    $identityHelper = $endpointDir . '/includes/Chat/ChatIdentity.php';
    $repoPath = $endpointDir . '/includes/Trace/TraceMetricsRepository.php';
    foreach ([$bootstrapHelper, $identityHelper, $repoPath] as $required) {
        if (!is_file($required)) throw new RuntimeException('Falta ' . str_replace($endpointDir . '/', '', $required));
    }
    require_once $bootstrapHelper;
    require_once $identityHelper;
    require_once $repoPath;

    $db = ChatEndpointBootstrap::mysqli($endpointDir);
    $db->set_charset('utf8mb4');
    $viewerUserId = ChatIdentity::resolveUserId($db);
    if ($viewerUserId <= 0) traceMetricsExit(['ok'=>false,'api_version'=>'7.7','error'=>'Sesión de usuario no válida'], 401);

    $adminLike = ChatIdentity::isAdminLike();
    $targetUserId = isset($_GET['user_id']) && is_numeric($_GET['user_id']) ? (int)$_GET['user_id'] : $viewerUserId;
    if ($targetUserId <= 0) traceMetricsExit(['ok'=>false,'api_version'=>'7.7','error'=>'user_id inválido'], 400);
    if ($targetUserId !== $viewerUserId && !$adminLike) {
        traceMetricsExit(['ok'=>false,'api_version'=>'7.7','error'=>'No tienes permisos para consultar otro usuario'], 403);
    }

    $sessionId = (int)($_GET['session_id'] ?? 0);
    $projectId = isset($_GET['project_id']) && is_numeric($_GET['project_id']) ? (int)$_GET['project_id'] : null;
    $month = trim((string)($_GET['month'] ?? ''));
    if ($sessionId <= 0) traceMetricsExit(['ok'=>false,'api_version'=>'7.7','error'=>'session_id es obligatorio'], 400);

    $repo = new TraceMetricsRepository($db, $viewerUserId, $adminLike, $targetUserId);
    traceMetricsExit(['ok'=>true,'data'=>$repo->summary($sessionId, $projectId, $month !== '' ? $month : null)]);
} catch (InvalidArgumentException $e) {
    traceMetricsExit(['ok'=>false,'api_version'=>'7.7','error'=>$e->getMessage()], 400);
} catch (RuntimeException $e) {
    $msg = $e->getMessage();
    if (str_contains($msg, 'permis') || str_contains($msg, 'pertenece')) {
        traceMetricsExit(['ok'=>false,'api_version'=>'7.7','error'=>$msg], 403);
    }
    error_log('TRACE_METRICS_7_7: ' . $msg);
    traceMetricsExit(['ok'=>false,'api_version'=>'7.7','error'=>'Error interno construyendo métricas.'], 500);
} catch (Throwable $e) {
    error_log('TRACE_METRICS_7_7: ' . $e->getMessage());
    traceMetricsExit(['ok'=>false,'api_version'=>'7.7','error'=>'Error interno construyendo métricas.'], 500);
}
