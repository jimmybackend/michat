<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/includes/Chat/ChatIdentity.php';
require_once __DIR__ . '/includes/ai_agent_runtime.php';
require_once __DIR__ . '/includes/AI/AIAgentConfigRepository.php';
require_once __DIR__ . '/includes/AI/AIAgentConfigService.php';
require_once __DIR__ . '/includes/Images/ImageGenerationPolicy.php';

function imageGenerationPreferencesExit(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    imageGenerationPreferencesExit(['ok' => false, 'error' => 'DB no disponible'], 500);
}

$userId = ChatIdentity::resolveUserId($db_connection);
if ($userId <= 0) {
    imageGenerationPreferencesExit(['ok' => false, 'error' => 'No autenticado'], 401);
}

function ensureImageGenerationGlobal(AIAgentConfigRepository $repository): void
{
    if ($repository->findGlobalByKey('image_main')) return;
    try {
        $repository->insertGlobal(ImageGenerationPolicy::defaultGlobalConfig());
    } catch (DomainException $e) {
        // Carrera benigna: otra petición pudo crear la misma fila global.
        if (!$repository->findGlobalByKey('image_main')) throw $e;
    }
}

$action = (string)($_REQUEST['action'] ?? 'get');
$repository = new AIAgentConfigRepository($db_connection);

if ($action === 'get') {
    aiRuntimeLoad($db_connection, $userId);
    $cfg = aiAgentConfig('image_main');
    imageGenerationPreferencesExit([
        'ok' => true,
        'agent_key' => 'image_main',
        'model_id' => $cfg ? (string)($cfg['model_id'] ?? ImageGenerationPolicy::DEFAULT_MODEL) : ImageGenerationPolicy::DEFAULT_MODEL,
        'is_active' => $cfg ? (int)($cfg['is_active'] ?? 1) : 1,
        'source' => $cfg ? (string)($cfg['scope'] ?? 'effective') : 'builtin_fallback',
        'allowed_models' => ImageGenerationPolicy::allowedModels(),
    ]);
}

if ($action !== 'save' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    imageGenerationPreferencesExit(['ok' => false, 'error' => 'Acción no permitida'], 405);
}

$csrf = (string)($_POST['csrf_token'] ?? '');
if ($csrf === '' || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
    imageGenerationPreferencesExit(['ok' => false, 'error' => 'Token CSRF inválido. Recarga la página.'], 403);
}

$modelId = trim((string)($_POST['model_id'] ?? ''));
if (!ImageGenerationPolicy::isAllowed($modelId)) {
    imageGenerationPreferencesExit(['ok' => false, 'error' => 'Modelo de generación no permitido'], 400);
}
$isActive = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

try {
    ensureImageGenerationGlobal($repository);
    $service = new AIAgentConfigService($repository);
    $saved = $service->upsertUserOverride($userId, 'image_main', $modelId, $isActive);
    imageGenerationPreferencesExit([
        'ok' => true,
        'agent_key' => 'image_main',
        'model_id' => (string)($saved['model_id'] ?? $modelId),
        'is_active' => (int)($saved['is_active'] ?? $isActive),
        'source' => 'user',
    ]);
} catch (Throwable $e) {
    error_log('IMAGE_GENERATION_PREFERENCES: ' . $e->getMessage());
    imageGenerationPreferencesExit(['ok' => false, 'error' => 'No se pudo guardar la configuración de imágenes.'], 500);
}
