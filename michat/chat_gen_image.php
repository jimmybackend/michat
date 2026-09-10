<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/includes/Chat/ChatIdentity.php';
require_once __DIR__ . '/includes/Chat/AuthenticatedMediaScope.php';
require_once __DIR__ . '/includes/ai_agent_runtime.php';
require_once __DIR__ . '/includes/Images/ImageGenerationPolicy.php';
require_once __DIR__ . '/S3Manager.php';

function imageGenerationExit(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function imageGenerationNextId(mysqli $db, string $table): int
{
    if ($table !== 'ChatMessages') throw new LogicException('Tabla no permitida');
    $result = $db->query('SELECT COALESCE(MAX(id_),0)+1 AS next_id FROM ChatMessages');
    if (!$result) throw new RuntimeException('No se pudo reservar ID de mensaje');
    return max(1, (int)($result->fetch_assoc()['next_id'] ?? 1));
}

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    imageGenerationExit(['ok' => false, 'error' => 'DB no disponible'], 500);
}

$sessionId = (int)($_POST['session_id'] ?? 0);
$prompt = trim((string)($_POST['prompt'] ?? ''));
if ($sessionId <= 0) imageGenerationExit(['ok' => false, 'error' => 'session_id inválido'], 400);
if ($prompt === '') imageGenerationExit(['ok' => false, 'error' => 'Escribe qué imagen quieres crear.'], 400);

// La sesión persistida determina la identidad y el alcance. El cliente nunca
// puede generar contenido dentro de una conversación ajena.
$mediaScope = new AuthenticatedMediaScope($db_connection);
try {
    $userId = $mediaScope->authenticatedUserId($_POST['user_id'] ?? null);
    $mediaScope->resolveOwnedSession($userId, $sessionId);
} catch (MediaAuthenticationException $e) {
    imageGenerationExit(['ok' => false, 'error' => $e->getMessage()], 401);
} catch (MediaIdentityMismatchException $e) {
    imageGenerationExit(['ok' => false, 'error' => $e->getMessage()], 403);
} catch (MediaScopeNotFoundException $e) {
    imageGenerationExit(['ok' => false, 'error' => 'Sesión no encontrada'], 404);
}

try {
    aiRuntimeLoad($db_connection, $userId);
} catch (Throwable $e) {
    error_log('IMAGE_GENERATION_RUNTIME: ' . $e->getMessage());
    imageGenerationExit(['ok' => false, 'error' => 'No se pudo cargar la configuración de IA.'], 500);
}

$imageConfig = aiAgentConfig('image_main');
if ($imageConfig && !aiAgentActive('image_main', true)) {
    imageGenerationExit(['ok' => false, 'error' => 'La generación de imágenes está desactivada en Preferencias.'], 409);
}

$modelId = aiAgentModel('image_main', ImageGenerationPolicy::DEFAULT_MODEL);
if (!ImageGenerationPolicy::isAllowed($modelId)) {
    error_log('IMAGE_GENERATION_CONFIG: modelo no permitido: ' . $modelId);
    imageGenerationExit(['ok' => false, 'error' => 'El modelo configurado para imágenes no es compatible.'], 500);
}

$maxPromptChars = ImageGenerationPolicy::maxPromptChars($modelId);
if (mb_strlen($prompt) > $maxPromptChars) {
    imageGenerationExit([
        'ok' => false,
        'error' => "El prompt supera el límite de {$maxPromptChars} caracteres del modelo seleccionado.",
        'max_prompt_chars' => $maxPromptChars,
    ], 400);
}

$defaults = ImageGenerationPolicy::defaults();
$width = (int)aiAgentExtra('image_main', 'width', $defaults['width']);
$height = (int)aiAgentExtra('image_main', 'height', $defaults['height']);
$quality = strtolower(trim((string)aiAgentExtra('image_main', 'quality', $defaults['quality'])));
$cfgScale = (float)aiAgentExtra('image_main', 'cfg_scale', $defaults['cfg_scale']);

// Primera versión deliberadamente cerrada: evita que parámetros manipulados
// desde el cliente provoquen resoluciones costosas o incompatibles.
$width = $width === 1024 ? 1024 : 1024;
$height = $height === 1024 ? 1024 : 1024;
$quality = in_array($quality, ['standard', 'premium'], true) ? $quality : 'standard';
$cfgScale = max(1.1, min(10.0, $cfgScale));
$seedMax = $modelId === ImageGenerationPolicy::NOVA_CANVAS_V1 ? 858993459 : 2147483647;
$seed = random_int(0, $seedMax);

$body = [
    'taskType' => 'TEXT_IMAGE',
    'textToImageParams' => ['text' => $prompt],
    'imageGenerationConfig' => [
        'numberOfImages' => 1,
        'quality' => $quality,
        'height' => $height,
        'width' => $width,
        'cfgScale' => $cfgScale,
        'seed' => $seed,
    ],
];

$started = hrtime(true);
$s3Key = null;
try {
    if (!class_exists('Config') || !method_exists('Config', 'getBedrockRuntime')) {
        throw new RuntimeException('Bedrock Runtime no está configurado.');
    }

    // Config::getBedrockRuntime() conserva la cadena de credenciales y región
    // oficial del despliegue; no crea una segunda configuración AWS paralela.
    $bedrock = Config::getBedrockRuntime();
    $response = $bedrock->invokeModel([
        'modelId' => $modelId,
        'accept' => 'application/json',
        'contentType' => 'application/json',
        'body' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);

    $raw = (string)$response->get('body');
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    $imageBase64 = isset($data['images'][0]) && is_string($data['images'][0])
        ? $data['images'][0]
        : (string)($data['images'][0]['image'] ?? '');
    if ($imageBase64 === '') throw new RuntimeException('Bedrock no devolvió una imagen.');

    $binary = base64_decode($imageBase64, true);
    if ($binary === false || $binary === '') throw new RuntimeException('Bedrock devolvió una imagen inválida.');

    $mimeType = 'image/png';
    $sizeBytes = strlen($binary);
    $manager = new S3Manager();
    $bucket = (string)$manager->getBucket();
    if ($bucket === '') throw new RuntimeException('Bucket S3 no configurado.');

    $prefix = 'Chat/GenerationsImages/' . $userId . '/' . $sessionId . '/';
    if (defined('Config::RUTA_RAIZ') && Config::RUTA_RAIZ) {
        $prefix = rtrim((string)Config::RUTA_RAIZ, '/') . '/' . $prefix;
    }
    $s3Key = $prefix . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.png';

    Config::getS3()->putObject([
        'Bucket' => $bucket,
        'Key' => $s3Key,
        'Body' => $binary,
        'ContentType' => $mimeType,
        'ACL' => 'private',
    ]);

    $latencyMs = max(0, (int)round((hrtime(true) - $started) / 1_000_000));
    $messageId = imageGenerationNextId($db_connection, 'ChatMessages');
    $content = 'Imagen generada: ' . $prompt;
    $meta = json_encode([
        'source' => 'image_main',
        'generation' => 'text_to_image',
        'model_id' => $modelId,
        'seed' => $seed,
        'width' => $width,
        'height' => $height,
        'quality' => $quality,
        'cfg_scale' => $cfgScale,
        'billing_unit' => 'image',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $stmt = $db_connection->prepare(
        "INSERT INTO ChatMessages
         (id_,session_id_,user_id_,role,content_type,content,s3_key,mime_type,size_bytes,model_id,stop_reason,prompt_tokens,completion_tokens,latency_ms,meta,is_primordial,phase)
         VALUES (?,?,?,'assistant','image',?,?,?, ?,?,'end_turn',NULL,NULL,?,?,0,'respond')"
    );
    if (!$stmt) throw new RuntimeException('No se pudo preparar el mensaje de imagen: ' . $db_connection->error);
    $stmt->bind_param('iiisssisis', $messageId, $sessionId, $userId, $content, $s3Key, $mimeType, $sizeBytes, $modelId, $latencyMs, $meta);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('No se pudo guardar la imagen en el chat: ' . $error);
    }
    $stmt->close();

    // Los modelos de imagen se facturan por imagen/resolución, no por tokens
    // de texto equivalentes. Registramos la llamada con 0/0 tokens para que
    // la telemetría muestre modelo y duración sin inventar un conteo.
    $usage = $db_connection->prepare(
        "INSERT INTO TokenUsage (session_id_,message_id_,phase,model_id,input_tokens,output_tokens,estimated_cost_usd,duration_ms)
         VALUES (?,?,'respond',?,0,0,0,?)"
    );
    if ($usage) {
        $usage->bind_param('iisi', $sessionId, $messageId, $modelId, $latencyMs);
        try { $usage->execute(); } catch (Throwable $ignored) { error_log('IMAGE_GENERATION_USAGE: ' . $ignored->getMessage()); }
        $usage->close();
    }

    imageGenerationExit([
        'ok' => true,
        'message_id' => $messageId,
        's3_key' => $s3Key,
        'mime_type' => $mimeType,
        'size_bytes' => $sizeBytes,
        'model_id' => $modelId,
        'latency_ms' => $latencyMs,
        'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'billing_unit' => 'image'],
    ]);
} catch (Throwable $e) {
    // Si S3 alcanzó a recibir el objeto pero la persistencia falló, evitamos
    // dejar basura huérfana cuando sea posible.
    if ($s3Key !== null && class_exists('Config') && method_exists('Config', 'getS3')) {
        try {
            $bucket = (new S3Manager())->getBucket();
            if ($bucket) Config::getS3()->deleteObject(['Bucket' => $bucket, 'Key' => $s3Key]);
        } catch (Throwable $ignored) {}
    }
    error_log('IMAGE_GENERATION: ' . $e->getMessage());
    imageGenerationExit(['ok' => false, 'error' => 'No se pudo generar la imagen: ' . $e->getMessage()], 500);
}
