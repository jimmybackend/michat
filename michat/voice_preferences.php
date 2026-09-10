<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/includes/Chat/ChatIdentity.php';
require_once __DIR__ . '/includes/ai_agent_runtime.php';
require_once __DIR__ . '/includes/AI/AIAgentConfigRepository.php';
require_once __DIR__ . '/includes/AI/AIAgentConfigService.php';

function voicePreferencesExit(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    voicePreferencesExit(['ok' => false, 'error' => 'DB no disponible'], 500);
}

$userId = ChatIdentity::resolveUserId($db_connection);
if ($userId <= 0) voicePreferencesExit(['ok' => false, 'error' => 'No autenticado'], 401);

const VOICE_MAIN_MODEL = 'amazon.nova-2-sonic-v1:0';
const VOICE_MAIN_ALLOWED_VOICES = ['lupe', 'carlos'];

function voiceMainDefaultGlobal(): array
{
    return [
        'agent_key' => 'voice_main',
        'agent_group' => 'voice',
        'display_name' => 'Voz / Audio',
        'description' => 'Recibe voz y devuelve voz más transcripción mediante Amazon Nova 2 Sonic.',
        'model_id' => VOICE_MAIN_MODEL,
        'fallback_model_id' => '',
        'model_ladder_json' => json_encode([VOICE_MAIN_MODEL], JSON_UNESCAPED_SLASHES),
        'system_instruction' => 'Eres el asistente de voz de MiChat. Responde en español de forma clara, natural y concisa. Usa el historial proporcionado como contexto cuando sea pertinente.',
        'user_prompt_template' => '',
        'temperature' => 0.5,
        'max_tokens_prompt' => 0,
        'max_tokens_output' => 1024,
        'top_p' => 0.9,
        'seed' => 0,
        'max_attempts' => 1,
        'extra_config' => json_encode([
            'voice_id' => 'lupe',
            'locale' => 'es-US',
            'input_sample_rate' => 16000,
            'output_sample_rate' => 24000,
            'max_record_seconds' => 30,
        ], JSON_UNESCAPED_SLASHES),
        'token_usage_phase' => 'respond',
        'is_active' => 0,
        'sort_order' => 350,
    ];
}

function ensureVoiceMainGlobal(AIAgentConfigRepository $repository): void
{
    if ($repository->findGlobalByKey('voice_main')) return;
    try {
        $repository->insertGlobal(voiceMainDefaultGlobal());
    } catch (DomainException $e) {
        if (!$repository->findGlobalByKey('voice_main')) throw $e;
    }
}

$action = (string)($_REQUEST['action'] ?? 'get');
$repository = new AIAgentConfigRepository($db_connection);

if ($action === 'get') {
    aiRuntimeLoad($db_connection, $userId);
    $cfg = aiAgentConfig('voice_main');
    $extra = is_array($cfg['_extra'] ?? null) ? $cfg['_extra'] : [];
    voicePreferencesExit([
        'ok' => true,
        'agent_key' => 'voice_main',
        'model_id' => $cfg ? (string)($cfg['model_id'] ?? VOICE_MAIN_MODEL) : VOICE_MAIN_MODEL,
        'voice_id' => in_array((string)($extra['voice_id'] ?? ''), VOICE_MAIN_ALLOWED_VOICES, true) ? (string)$extra['voice_id'] : 'lupe',
        'locale' => 'es-US',
        'is_active' => $cfg ? (int)($cfg['is_active'] ?? 0) : 0,
        'source' => $cfg ? (string)($cfg['scope'] ?? 'effective') : 'builtin_fallback',
    ]);
}

if ($action !== 'save' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    voicePreferencesExit(['ok' => false, 'error' => 'Acción no permitida'], 405);
}

$csrf = (string)($_POST['csrf_token'] ?? '');
if ($csrf === '' || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
    voicePreferencesExit(['ok' => false, 'error' => 'Token CSRF inválido. Recarga la página.'], 403);
}
$modelId = trim((string)($_POST['model_id'] ?? ''));
$voiceId = strtolower(trim((string)($_POST['voice_id'] ?? '')));
$isActive = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
if ($modelId !== VOICE_MAIN_MODEL) voicePreferencesExit(['ok' => false, 'error' => 'Modelo de voz no permitido'], 400);
if (!in_array($voiceId, VOICE_MAIN_ALLOWED_VOICES, true)) voicePreferencesExit(['ok' => false, 'error' => 'Voz no permitida'], 400);

try {
    ensureVoiceMainGlobal($repository);
    $service = new AIAgentConfigService($repository);
    $saved = $service->upsertUserOverride($userId, 'voice_main', $modelId, $isActive, [
        'voice_id' => $voiceId,
        'locale' => 'es-US',
        'input_sample_rate' => 16000,
        'output_sample_rate' => 24000,
        'max_record_seconds' => 30,
    ]);
    voicePreferencesExit([
        'ok' => true,
        'agent_key' => 'voice_main',
        'model_id' => (string)($saved['model_id'] ?? $modelId),
        'voice_id' => $voiceId,
        'locale' => 'es-US',
        'is_active' => (int)($saved['is_active'] ?? $isActive),
        'source' => 'user',
    ]);
} catch (Throwable $e) {
    error_log('VOICE_PREFERENCES: ' . $e->getMessage());
    voicePreferencesExit(['ok' => false, 'error' => 'No se pudo guardar la configuración de voz.'], 500);
}
