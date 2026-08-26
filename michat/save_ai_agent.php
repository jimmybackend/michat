<?php
declare(strict_types=1);

/**
 * save_ai_agent.php
 * Adapter HTTP para CREATE / UPDATE de configuraciones GLOBAL.
 */

session_start();
header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/includes/Chat/ChatIdentity.php';
require_once __DIR__ . '/includes/AI/AIAgentConfigRepository.php';
require_once __DIR__ . '/includes/AI/AIAgentConfigService.php';

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function nullableString(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function nullableInt(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
        throw new InvalidArgumentException('Se esperaba un número entero válido.');
    }
    return (int)$value;
}

function nullableFloat(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        throw new InvalidArgumentException('Se esperaba un número decimal válido.');
    }
    return (float)$value;
}

if (empty($_SESSION['usuario'])) {
    respond(['success' => false, 'message' => 'No autorizado. Debes iniciar sesión.'], 401);
}

$userId = ChatIdentity::resolveUserId($db_connection);
if ($userId <= 0) respond(['success'=>false,'message'=>'Sesión inválida.'],401);
if (!ChatIdentity::canManageGlobalAiConfiguration()) respond(['success'=>false,'message'=>'Acceso denegado.'],403);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['success' => false, 'message' => 'Método no permitido. Use POST.'], 405);
}

$csrfToken = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$sessionToken = (string)($_SESSION['csrf_token'] ?? '');
if ($csrfToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $csrfToken)) {
    respond(['success' => false, 'message' => 'Token CSRF inválido o ausente.'], 403);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    respond(['success' => false, 'message' => 'Datos inválidos. Se espera JSON.'], 400);
}

try {
    $id = nullableInt($data['id_'] ?? null);
    if ($id !== null && $id <= 0) {
        throw new InvalidArgumentException('El ID debe ser mayor que cero.');
    }

    $agentKey = trim((string)($data['agent_key'] ?? ''));
    $agentGroup = trim((string)($data['agent_group'] ?? ''));
    $displayName = trim((string)($data['display_name'] ?? ''));
    $description = nullableString($data['description'] ?? null);
    $modelId = trim((string)($data['model_id'] ?? ''));
    $fallbackModelId = nullableString($data['fallback_model_id'] ?? null);
    $modelLadderJson = nullableString($data['model_ladder_json'] ?? null);
    $systemInstruction = nullableString($data['system_instruction'] ?? null);
    $userPromptTemplate = nullableString($data['user_prompt_template'] ?? null);
    $temperature = nullableFloat($data['temperature'] ?? null);
    $maxTokensPrompt = nullableInt($data['max_tokens_prompt'] ?? null);
    $maxTokensOutput = nullableInt($data['max_tokens_output'] ?? null);
    $topP = nullableFloat($data['top_p'] ?? null);
    $seed = nullableInt($data['seed'] ?? 0) ?? 0;
    $maxAttempts = nullableInt($data['max_attempts'] ?? 1) ?? 1;
    $extraConfig = nullableString($data['extra_config'] ?? null);
    $tokenUsagePhase = nullableString($data['token_usage_phase'] ?? null);
    $isActive = !empty($data['is_active']) ? 1 : 0;
    $sortOrder = nullableInt($data['sort_order'] ?? 0) ?? 0;

    if ($agentKey === '') {
        throw new InvalidArgumentException("El campo 'agent_key' es requerido.");
    }
    if ($agentGroup === '') {
        throw new InvalidArgumentException("El campo 'agent_group' es requerido.");
    }
    if ($displayName === '') {
        throw new InvalidArgumentException("El campo 'display_name' es requerido.");
    }
    if ($modelId === '') {
        throw new InvalidArgumentException("El campo 'model_id' es requerido porque la columna es NOT NULL.");
    }

    if (mb_strlen($agentKey) > 100) throw new InvalidArgumentException('agent_key excede 100 caracteres.');
    if (mb_strlen($agentGroup) > 50) throw new InvalidArgumentException('agent_group excede 50 caracteres.');
    if (mb_strlen($displayName) > 180) throw new InvalidArgumentException('display_name excede 180 caracteres.');
    if (mb_strlen($modelId) > 180) throw new InvalidArgumentException('model_id excede 180 caracteres.');
    if ($fallbackModelId !== null && mb_strlen($fallbackModelId) > 180) throw new InvalidArgumentException('fallback_model_id excede 180 caracteres.');
    if ($tokenUsagePhase !== null && mb_strlen($tokenUsagePhase) > 30) throw new InvalidArgumentException('token_usage_phase excede 30 caracteres.');

    if ($temperature !== null && ($temperature < 0 || $temperature > 2)) {
        throw new InvalidArgumentException('temperature debe estar entre 0 y 2, o quedar vacío (NULL).');
    }
    if ($topP !== null && ($topP < 0 || $topP > 1)) {
        throw new InvalidArgumentException('top_p debe estar entre 0 y 1, o quedar vacío (NULL).');
    }
    if ($maxTokensPrompt !== null && $maxTokensPrompt < 0) throw new InvalidArgumentException('max_tokens_prompt no puede ser negativo.');
    if ($maxTokensOutput !== null && $maxTokensOutput < 0) throw new InvalidArgumentException('max_tokens_output no puede ser negativo.');
    if ($seed < 0) throw new InvalidArgumentException('seed no puede ser negativo.');
    if ($maxAttempts < 1 || $maxAttempts > 255) throw new InvalidArgumentException('max_attempts debe estar entre 1 y 255.');

    foreach ([
        'model_ladder_json' => $modelLadderJson,
        'extra_config' => $extraConfig
    ] as $jsonField => $jsonValue) {
        if ($jsonValue !== null) {
            json_decode($jsonValue, true, 512, JSON_THROW_ON_ERROR);
        }
    }

    $config = [
        'agent_key'=>$agentKey,'agent_group'=>$agentGroup,'display_name'=>$displayName,
        'description'=>$description,'model_id'=>$modelId,'fallback_model_id'=>$fallbackModelId,
        'model_ladder_json'=>$modelLadderJson,'system_instruction'=>$systemInstruction,
        'user_prompt_template'=>$userPromptTemplate,'temperature'=>$temperature,
        'max_tokens_prompt'=>$maxTokensPrompt,'max_tokens_output'=>$maxTokensOutput,'top_p'=>$topP,
        'seed'=>$seed,'max_attempts'=>$maxAttempts,'extra_config'=>$extraConfig,
        'token_usage_phase'=>$tokenUsagePhase,'is_active'=>$isActive,'sort_order'=>$sortOrder,
    ];
    $saved=(new AIAgentConfigService(new AIAgentConfigRepository($db_connection)))->saveGlobal($id,$config);
    respond([
        'success'=>true,
        'message'=>$saved['action']==='created'?'Agente creado correctamente.':'Agente actualizado correctamente.',
        'action'=>$saved['action'],'id_'=>$saved['id_'],'agent_key'=>$agentKey,
    ]);

} catch (JsonException $e) {
    respond([
        'success' => false,
        'message' => 'Uno de los campos JSON no contiene JSON válido: ' . $e->getMessage()
    ], 422);
} catch (DomainException $e) {
    respond(['success'=>false,'message'=>'Ya existe un registro GLOBAL con ese agent_key.'],409);
} catch (OutOfBoundsException $e) {
    respond(['success'=>false,'message'=>'El registro GLOBAL no existe.'],404);
} catch (InvalidArgumentException $e) {
    respond(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('SAVE_AI_AGENT: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Error interno al guardar la configuración.'], 500);
}
