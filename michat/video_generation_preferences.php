<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/includes/Chat/ChatIdentity.php';
require_once __DIR__ . '/includes/ai_agent_runtime.php';
require_once __DIR__ . '/includes/AI/AIAgentConfigRepository.php';
require_once __DIR__ . '/includes/AI/AIAgentConfigService.php';
require_once __DIR__ . '/includes/Videos/VideoGenerationPolicy.php';

function videoGenerationPreferencesExit(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    videoGenerationPreferencesExit(['ok' => false, 'error' => 'DB no disponible'], 500);
}

$userId = ChatIdentity::resolveUserId($db_connection);
if ($userId <= 0) {
    videoGenerationPreferencesExit(['ok' => false, 'error' => 'No autenticado'], 401);
}

function ensureVideoGenerationGlobal(AIAgentConfigRepository $repository): void
{
    if ($repository->findGlobalByKey('video_main')) return;
    try {
        $repository->insertGlobal(VideoGenerationPolicy::defaultGlobalConfig());
    } catch (DomainException $e) {
        if (!$repository->findGlobalByKey('video_main')) throw $e;
    }
}

$action = (string)($_REQUEST['action'] ?? 'get');
$repository = new AIAgentConfigRepository($db_connection);

if ($action === 'get') {
    aiRuntimeLoad($db_connection, $userId);
    $cfg = aiAgentConfig('video_main');
    $region = class_exists('Config') && method_exists('Config', 'getRegion') ? Config::getRegion() : 'us-east-1';
    $model = $cfg ? (string)($cfg['model_id'] ?? VideoGenerationPolicy::DEFAULT_MODEL) : VideoGenerationPolicy::fallbackForRegion($region);
    videoGenerationPreferencesExit([
        'ok' => true,
        'agent_key' => 'video_main',
        'model_id' => $model,
        'is_active' => $cfg ? (int)($cfg['is_active'] ?? 1) : 1,
        'source' => $cfg ? (string)($cfg['scope'] ?? 'effective') : 'builtin_fallback',
        'region' => $region,
        'allowed_models' => VideoGenerationPolicy::allowedModels(),
    ]);
}

if ($action !== 'save' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    videoGenerationPreferencesExit(['ok' => false, 'error' => 'Acción no permitida'], 405);
}

$csrf = (string)($_POST['csrf_token'] ?? '');
if ($csrf === '' || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
    videoGenerationPreferencesExit(['ok' => false, 'error' => 'Token CSRF inválido. Recarga la página.'], 403);
}

$modelId = trim((string)($_POST['model_id'] ?? ''));
if (!VideoGenerationPolicy::isAllowed($modelId)) {
    videoGenerationPreferencesExit(['ok' => false, 'error' => 'Modelo de video no permitido'], 400);
}
$region = class_exists('Config') && method_exists('Config', 'getRegion') ? Config::getRegion() : 'us-east-1';
if (!VideoGenerationPolicy::supportsRegion($modelId, $region)) {
    videoGenerationPreferencesExit(['ok' => false, 'error' => "El modelo seleccionado no está disponible en la región {$region}."], 400);
}
$isActive = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

try {
    ensureVideoGenerationGlobal($repository);
    $service = new AIAgentConfigService($repository);
    $saved = $service->upsertUserOverride($userId, 'video_main', $modelId, $isActive);
    videoGenerationPreferencesExit([
        'ok' => true,
        'agent_key' => 'video_main',
        'model_id' => (string)($saved['model_id'] ?? $modelId),
        'is_active' => (int)($saved['is_active'] ?? $isActive),
        'source' => 'user',
        'region' => $region,
    ]);
} catch (Throwable $e) {
    error_log('VIDEO_GENERATION_PREFERENCES: ' . $e->getMessage());
    videoGenerationPreferencesExit(['ok' => false, 'error' => 'No se pudo guardar la configuración de videos.'], 500);
}
