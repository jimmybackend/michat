<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/includes/Chat/ChatIdentity.php';
require_once __DIR__ . '/includes/ai_agent_runtime.php';
require_once __DIR__ . '/includes/AI/AIAgentConfigRepository.php';
require_once __DIR__ . '/includes/AI/AIAgentConfigService.php';

function attachmentVisionPreferencesExit(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    attachmentVisionPreferencesExit(['ok' => false, 'error' => 'DB no disponible'], 500);
}

$userId = ChatIdentity::resolveUserId($db_connection);
if ($userId <= 0) {
    attachmentVisionPreferencesExit(['ok' => false, 'error' => 'No autenticado'], 401);
}

const ATTACHMENT_VISION_DEFAULT_MODEL = 'amazon.nova-lite-v1:0';
const ATTACHMENT_VISION_ALLOWED_MODELS = [
    'amazon.nova-lite-v1:0',
    'amazon.nova-pro-v1:0',
    'amazon.nova-premier-v1:0',
];

function attachmentVisionDefaultGlobal(): array
{
    return [
        'agent_key' => 'attachment_vision',
        'agent_group' => 'vision',
        'display_name' => 'Visión de imágenes adjuntas',
        'description' => 'Analiza imágenes adjuntas una vez y convierte lo observado en conocimiento reutilizable por RAG.',
        'model_id' => ATTACHMENT_VISION_DEFAULT_MODEL,
        'fallback_model_id' => 'amazon.nova-pro-v1:0',
        'model_ladder_json' => json_encode(ATTACHMENT_VISION_ALLOWED_MODELS, JSON_UNESCAPED_SLASHES),
        'system_instruction' => 'Analiza imágenes de forma objetiva para convertirlas en conocimiento recuperable. Describe solo lo observable. Extrae texto visible con fidelidad cuando sea legible, identifica estructuras, tablas, diagramas, código, interfaces, errores, objetos y relaciones relevantes. No inventes contenido oculto ni datos que no puedan verse.',
        'user_prompt_template' => "Analiza el archivo de imagen '{{filename}}' y devuelve una descripción útil para futuras búsquedas semánticas. Organiza la respuesta con: TIPO DE IMAGEN, DESCRIPCIÓN VISUAL, TEXTO VISIBLE, DATOS/OBJETOS IMPORTANTES, RELACIONES O ESTRUCTURA, POSIBLES ERRORES/ALERTAS y TÉRMINOS CLAVE. Si una sección no aplica, indícalo brevemente. Si contiene código, SQL, tablas, mensajes de error o nombres técnicos legibles, consérvalos con precisión.",
        'temperature' => 0.1,
        'max_tokens_prompt' => 0,
        'max_tokens_output' => 1000,
        'top_p' => 0.9,
        'seed' => 0,
        'max_attempts' => 1,
        'extra_config' => json_encode([
            'max_tokens' => 1000,
            'max_image_bytes' => 3932160,
        ], JSON_UNESCAPED_SLASHES),
        'token_usage_phase' => 'vision',
        'is_active' => 1,
        'sort_order' => 345,
    ];
}

function ensureAttachmentVisionGlobal(AIAgentConfigRepository $repository): void
{
    if ($repository->findGlobalByKey('attachment_vision')) return;

    try {
        $repository->insertGlobal(attachmentVisionDefaultGlobal());
    } catch (DomainException $e) {
        // Carrera benigna: otra petición pudo insertar la misma configuración fija.
        if (!$repository->findGlobalByKey('attachment_vision')) throw $e;
    }
}

$action = (string)($_REQUEST['action'] ?? 'get');
$repository = new AIAgentConfigRepository($db_connection);

if ($action === 'get') {
    aiRuntimeLoad($db_connection, $userId);
    $cfg = aiAgentConfig('attachment_vision');

    attachmentVisionPreferencesExit([
        'ok' => true,
        'agent_key' => 'attachment_vision',
        'model_id' => $cfg ? (string)($cfg['model_id'] ?? ATTACHMENT_VISION_DEFAULT_MODEL) : ATTACHMENT_VISION_DEFAULT_MODEL,
        'is_active' => $cfg ? (int)($cfg['is_active'] ?? 1) : 1,
        'source' => $cfg ? ((string)($cfg['scope'] ?? 'effective')) : 'builtin_fallback',
        'allowed_models' => ATTACHMENT_VISION_ALLOWED_MODELS,
    ]);
}

if ($action !== 'save' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    attachmentVisionPreferencesExit(['ok' => false, 'error' => 'Acción no permitida'], 405);
}

$csrf = (string)($_POST['csrf_token'] ?? '');
if ($csrf === '' || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
    attachmentVisionPreferencesExit(['ok' => false, 'error' => 'Token CSRF inválido. Recarga la página.'], 403);
}

$modelId = trim((string)($_POST['model_id'] ?? ''));
if (!in_array($modelId, ATTACHMENT_VISION_ALLOWED_MODELS, true)) {
    attachmentVisionPreferencesExit(['ok' => false, 'error' => 'Modelo visual no permitido'], 400);
}
$isActive = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

try {
    ensureAttachmentVisionGlobal($repository);
    $service = new AIAgentConfigService($repository);
    $saved = $service->upsertUserOverride($userId, 'attachment_vision', $modelId, $isActive);

    attachmentVisionPreferencesExit([
        'ok' => true,
        'agent_key' => 'attachment_vision',
        'model_id' => (string)($saved['model_id'] ?? $modelId),
        'is_active' => (int)($saved['is_active'] ?? $isActive),
        'source' => 'user',
    ]);
} catch (Throwable $e) {
    error_log('ATTACHMENT_VISION_PREFERENCES: ' . $e->getMessage());
    attachmentVisionPreferencesExit(['ok' => false, 'error' => 'No se pudo guardar la configuración visual.'], 500);
}
