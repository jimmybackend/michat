<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/includes/Chat/ChatIdentity.php';
require_once __DIR__ . '/includes/Chat/AuthenticatedMediaScope.php';
require_once __DIR__ . '/includes/ai_agent_runtime.php';
require_once __DIR__ . '/includes/Videos/VideoGenerationPolicy.php';
require_once __DIR__ . '/S3Manager.php';

function videoGenerationExit(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function videoGenerationUpdateMeta(mysqli $db, int $messageId, array $meta, ?string $content = null): void
{
    $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if ($content === null) {
        $stmt = $db->prepare('UPDATE ChatMessages SET meta=? WHERE id_=?');
        if (!$stmt) throw new RuntimeException('No se pudo preparar la actualización del trabajo de video.');
        $stmt->bind_param('si', $json, $messageId);
    } else {
        $stmt = $db->prepare('UPDATE ChatMessages SET content=?, meta=? WHERE id_=?');
        if (!$stmt) throw new RuntimeException('No se pudo preparar la actualización del trabajo de video.');
        $stmt->bind_param('ssi', $content, $json, $messageId);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('No se pudo actualizar el trabajo de video: ' . $error);
    }
    $stmt->close();
}

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    videoGenerationExit(['ok' => false, 'error' => 'DB no disponible'], 500);
}

$sessionId = (int)($_POST['session_id'] ?? 0);
$prompt = trim((string)($_POST['prompt'] ?? ''));
if ($sessionId <= 0) videoGenerationExit(['ok' => false, 'error' => 'session_id inválido'], 400);
if ($prompt === '') videoGenerationExit(['ok' => false, 'error' => 'Escribe qué video quieres crear.'], 400);

// La sesión persistida determina identidad y alcance antes de cualquier efecto
// en DB, S3 o Bedrock. user_id del request sólo funciona como assertion.
$mediaScope = new AuthenticatedMediaScope($db_connection);
try {
    $userId = $mediaScope->authenticatedUserId($_POST['user_id'] ?? null);
    $mediaScope->resolveOwnedSession($userId, $sessionId);
} catch (MediaAuthenticationException $e) {
    videoGenerationExit(['ok' => false, 'error' => $e->getMessage()], 401);
} catch (MediaIdentityMismatchException $e) {
    videoGenerationExit(['ok' => false, 'error' => $e->getMessage()], 403);
} catch (MediaScopeNotFoundException $e) {
    videoGenerationExit(['ok' => false, 'error' => 'Sesión no encontrada'], 404);
}

try {
    aiRuntimeLoad($db_connection, $userId);
} catch (Throwable $e) {
    error_log('VIDEO_GENERATION_RUNTIME: ' . $e->getMessage());
    videoGenerationExit(['ok' => false, 'error' => 'No se pudo cargar la configuración de IA.'], 500);
}

$region = class_exists('Config') && method_exists('Config', 'getRegion') ? Config::getRegion() : 'us-east-1';
$videoConfig = aiAgentConfig('video_main');
if ($videoConfig && !aiAgentActive('video_main', true)) {
    videoGenerationExit(['ok' => false, 'error' => 'La generación de videos está desactivada en Preferencias.'], 409);
}

$defaultModel = VideoGenerationPolicy::fallbackForRegion($region);
$modelId = aiAgentModel('video_main', $defaultModel);
if (!VideoGenerationPolicy::isAllowed($modelId)) {
    error_log('VIDEO_GENERATION_CONFIG: modelo no permitido: ' . $modelId);
    videoGenerationExit(['ok' => false, 'error' => 'El modelo configurado para videos no es compatible.'], 500);
}
if (!VideoGenerationPolicy::supportsRegion($modelId, $region)) {
    videoGenerationExit(['ok' => false, 'error' => "{$modelId} no está disponible en la región {$region}."], 409);
}

$maxPromptChars = VideoGenerationPolicy::maxPromptChars($modelId);
if (mb_strlen($prompt) > $maxPromptChars) {
    videoGenerationExit([
        'ok' => false,
        'error' => "El prompt supera el límite de {$maxPromptChars} caracteres para el clip simple de Nova Reel.",
        'max_prompt_chars' => $maxPromptChars,
    ], 400);
}

$defaults = VideoGenerationPolicy::defaults();
$durationSeconds = (int)$defaults['duration_seconds'];
$fps = (int)$defaults['fps'];
$dimension = (string)$defaults['dimension'];
$durationMs = $durationSeconds * 1000;
$seed = random_int(0, 2147483646);
$role = 'assistant';
$contentType = 'video';
$content = "🎬 Generando video…\n\n**Prompt:** " . $prompt;
$meta = [
    'kind' => 'video_job',
    'source' => 'video_main',
    'provider' => 'amazon-bedrock',
    'generation' => 'text_to_video',
    'status' => 'queued',
    'created_at' => date('c'),
    'prompt' => $prompt,
    'output_prefix' => null,
    'invocationArn' => null,
    'model_id' => $modelId,
    'region' => $region,
    'duration_s' => $durationSeconds,
    'fps' => $fps,
    'dimension' => $dimension,
    'seed' => $seed,
    'billing_unit' => 'video_second',
];
$metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

try {
    // ChatMessages usa AUTO_INCREMENT: evita la carrera de MAX(id_)+1.
    $stmt = $db_connection->prepare(
        "INSERT INTO ChatMessages
         (session_id_,user_id_,role,content_type,content,s3_key,mime_type,size_bytes,thumb_s3_key,duration_ms,model_id,stop_reason,prompt_tokens,completion_tokens,latency_ms,meta,is_primordial,phase,parent_msg_id)
         VALUES (?,?,?,?,?,NULL,NULL,NULL,NULL,?,?,NULL,NULL,NULL,NULL,?,0,'respond',NULL)"
    );
    if (!$stmt) throw new RuntimeException('No se pudo preparar el placeholder de video: ' . $db_connection->error);
    $stmt->bind_param('iisssiss', $sessionId, $userId, $role, $contentType, $content, $durationMs, $modelId, $metaJson);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('No se pudo guardar el trabajo de video: ' . $error);
    }
    $messageId = (int)$db_connection->insert_id;
    $stmt->close();
    if ($messageId <= 0) throw new RuntimeException('No se obtuvo el ID del mensaje de video.');

    $rootPrefix = defined('Config::RUTA_RAIZ') && Config::RUTA_RAIZ ? rtrim((string)Config::RUTA_RAIZ, '/') . '/' : '';
    $outputPrefix = $rootPrefix . "Chat/GenerationsVideos/{$userId}/{$sessionId}/msg_{$messageId}/";
    $meta['output_prefix'] = $outputPrefix;
    videoGenerationUpdateMeta($db_connection, $messageId, $meta);

    if (!class_exists('Config') || !method_exists('Config', 'getBedrockRuntime')) {
        throw new RuntimeException('Bedrock Runtime no está configurado.');
    }

    // Mantiene la misma cadena de credenciales/región que el resto de MiChat.
    // S3 se inicializa después del guard de ownership para conservar la frontera multiusuario.
    $s3 = Config::getS3();
    $bucket = (string)(new S3Manager())->getBucket();
    if ($bucket === '') throw new RuntimeException('Bucket S3 no configurado.');
    unset($s3);

    $modelInput = [
        'taskType' => 'TEXT_VIDEO',
        'textToVideoParams' => ['text' => $prompt],
        'videoGenerationConfig' => [
            'durationSeconds' => $durationSeconds,
            'fps' => $fps,
            'dimension' => $dimension,
            'seed' => $seed,
        ],
    ];

    $bedrock = Config::getBedrockRuntime();
    $response = $bedrock->startAsyncInvoke([
        'modelId' => $modelId,
        'modelInput' => $modelInput,
        'outputDataConfig' => [
            's3OutputDataConfig' => ['s3Uri' => "s3://{$bucket}/{$outputPrefix}"],
        ],
        'clientRequestToken' => 'michat-video-' . $messageId . '-' . bin2hex(random_bytes(8)),
    ]);

    $invocationArn = trim((string)($response['invocationArn'] ?? ''));
    if ($invocationArn === '') throw new RuntimeException('Bedrock no devolvió invocationArn.');

    $meta['status'] = 'in_progress';
    $meta['invocationArn'] = $invocationArn;
    $meta['started_at'] = date('c');
    videoGenerationUpdateMeta($db_connection, $messageId, $meta);

    videoGenerationExit([
        'ok' => true,
        'message_id' => $messageId,
        'invocationArn' => $invocationArn,
        'status' => 'in_progress',
        'model_id' => $modelId,
        'region' => $region,
        'duration_s' => $durationSeconds,
        'output_prefix' => $outputPrefix,
    ]);
} catch (Throwable $e) {
    if (isset($messageId) && (int)$messageId > 0) {
        try {
            $meta['status'] = 'failed';
            $meta['failed_at'] = date('c');
            $meta['error'] = mb_substr($e->getMessage(), 0, 500);
            videoGenerationUpdateMeta($db_connection, (int)$messageId, $meta, '⚠️ No se pudo generar el video.');
        } catch (Throwable $ignored) {}
    }
    error_log('VIDEO_GENERATION_START: ' . $e->getMessage());
    videoGenerationExit(['ok' => false, 'error' => 'No se pudo iniciar el video: ' . $e->getMessage()], 500);
}
