<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/includes/Chat/ChatIdentity.php';
require_once __DIR__ . '/includes/Chat/AuthenticatedMediaScope.php';
require_once __DIR__ . '/includes/Videos/VideoGenerationPolicy.php';
require_once __DIR__ . '/S3Manager.php';

function videoStatusExit(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function videoStatusUpdateMeta(mysqli $db, int $messageId, array $meta, ?string $content = null): void
{
    $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if ($content === null) {
        $stmt = $db->prepare('UPDATE ChatMessages SET meta=? WHERE id_=?');
        if (!$stmt) throw new RuntimeException('No se pudo preparar actualización de video.');
        $stmt->bind_param('si', $json, $messageId);
    } else {
        $stmt = $db->prepare('UPDATE ChatMessages SET content=?, meta=? WHERE id_=?');
        if (!$stmt) throw new RuntimeException('No se pudo preparar actualización de video.');
        $stmt->bind_param('ssi', $content, $json, $messageId);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('No se pudo actualizar el video: ' . $error);
    }
    $stmt->close();
}

/** @return array{key:string,size:int}|null */
function videoStatusFindOutput($s3, string $bucket, string $prefix): ?array
{
    $continuation = null;
    $best = null;
    do {
        $args = ['Bucket' => $bucket, 'Prefix' => $prefix];
        if ($continuation) $args['ContinuationToken'] = $continuation;
        $result = $s3->listObjectsV2($args);
        foreach (($result['Contents'] ?? []) as $object) {
            $key = (string)($object['Key'] ?? '');
            if ($key === '' || !preg_match('/\.mp4$/i', $key)) continue;
            $candidate = ['key' => $key, 'size' => (int)($object['Size'] ?? 0)];
            if (str_ends_with(strtolower($key), '/output.mp4')) return $candidate;
            if ($best === null || $candidate['size'] > $best['size']) $best = $candidate;
        }
        $continuation = $result['NextContinuationToken'] ?? null;
    } while ($continuation);
    return $best;
}

function videoStatusRecordUsage(mysqli $db, int $sessionId, int $messageId, string $modelId, int $durationMs): void
{
    $stmt = $db->prepare(
        "INSERT INTO TokenUsage (session_id_,message_id_,phase,model_id,input_tokens,output_tokens,estimated_cost_usd,duration_ms)
         SELECT ?,?,'respond',?,0,0,0,?
         WHERE NOT EXISTS (
             SELECT 1 FROM TokenUsage WHERE message_id_=? AND phase='respond' AND model_id=? LIMIT 1
         )"
    );
    if (!$stmt) return;
    $stmt->bind_param('iisiis', $sessionId, $messageId, $modelId, $durationMs, $messageId, $modelId);
    try { $stmt->execute(); } catch (Throwable $e) { error_log('VIDEO_GENERATION_USAGE: ' . $e->getMessage()); }
    $stmt->close();
}

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    videoStatusExit(['ok' => false, 'error' => 'DB no disponible'], 500);
}

$messageId = (int)($_GET['message_id'] ?? 0);
$waitSecs = min(120, max(0, (int)($_GET['wait_secs'] ?? 0)));
if ($messageId <= 0) videoStatusExit(['ok' => false, 'error' => 'message_id inválido'], 400);

// Ownership se resuelve antes de S3, Bedrock y cualquier UPDATE.
$mediaScope = new AuthenticatedMediaScope($db_connection);
try {
    $userId = $mediaScope->authenticatedUserId($_GET['user_id'] ?? null);
    $row = $mediaScope->resolveOwnedMessage($userId, $messageId);
} catch (MediaAuthenticationException $e) {
    videoStatusExit(['ok' => false, 'error' => $e->getMessage()], 401);
} catch (MediaIdentityMismatchException $e) {
    videoStatusExit(['ok' => false, 'error' => $e->getMessage()], 403);
} catch (MediaScopeNotFoundException $e) {
    videoStatusExit(['ok' => false, 'error' => 'Mensaje no encontrado'], 404);
}

if ((string)($row['content_type'] ?? '') !== 'video') {
    videoStatusExit(['ok' => false, 'error' => 'El mensaje no corresponde a un trabajo de video.'], 400);
}

$sessionId = (int)$row['session_id_'];
$modelId = (string)($row['model_id'] ?? '');
$durationMs = (int)($row['duration_ms'] ?? 6000);
$s3Key = trim((string)($row['s3_key'] ?? ''));
$meta = json_decode((string)($row['meta'] ?? '{}'), true);
if (!is_array($meta)) $meta = [];
$status = VideoGenerationPolicy::normalizeStatus((string)($meta['status'] ?? 'in_progress'));
$invocationArn = trim((string)($meta['invocationArn'] ?? ''));
$prefix = trim((string)($meta['output_prefix'] ?? ''));
$prompt = trim((string)($meta['prompt'] ?? ''));

if ($status === 'failed') {
    videoStatusExit(['ok' => true, 'status' => 'failed', 'message_id' => $messageId]);
}

if (!class_exists('Config') || !method_exists('Config', 'getBedrockRuntime')) {
    videoStatusExit(['ok' => false, 'error' => 'Bedrock Runtime no está configurado.'], 500);
}

$rootPrefix = defined('Config::RUTA_RAIZ') && Config::RUTA_RAIZ ? rtrim((string)Config::RUTA_RAIZ, '/') . '/' : '';
if ($prefix === '') {
    $prefix = $rootPrefix . "Chat/GenerationsVideos/{$userId}/{$sessionId}/msg_{$messageId}/";
    $meta['output_prefix'] = $prefix;
}

try {
    $s3 = Config::getS3();
    $bucket = (string)(new S3Manager())->getBucket();
    if ($bucket === '') throw new RuntimeException('Bucket S3 no configurado.');
    $bedrock = Config::getBedrockRuntime(['http' => ['connect_timeout' => 10, 'timeout' => 60]]);
} catch (Throwable $e) {
    error_log('VIDEO_GENERATION_STATUS_INIT: ' . $e->getMessage());
    videoStatusExit(['ok' => false, 'error' => 'No se pudo inicializar S3/Bedrock para consultar el video.'], 500);
}

// Si ya quedó persistido el objeto final, el endpoint es idempotente.
if ($s3Key !== '') {
    try {
        $head = $s3->headObject(['Bucket' => $bucket, 'Key' => $s3Key]);
        $size = (int)($head['ContentLength'] ?? 0);
        $mime = (string)($head['ContentType'] ?? 'video/mp4');
        $meta['status'] = 'completed';
        $meta['completed_at'] = $meta['completed_at'] ?? date('c');
        videoStatusUpdateMeta($db_connection, $messageId, $meta);
        videoStatusExit([
            'ok' => true,
            'status' => 'completed',
            'message_id' => $messageId,
            's3_key' => $s3Key,
            'mime_type' => $mime,
            'size_bytes' => $size,
            'duration_ms' => $durationMs,
            'model_id' => $modelId,
        ]);
    } catch (Throwable $e) {
        // El registro puede haberse escrito antes de que S3 sea visible; continuar.
    }
}

$deadline = microtime(true) + $waitSecs;
$notes = [];
do {
    if ($invocationArn !== '') {
        try {
            $response = $bedrock->getAsyncInvoke(['invocationArn' => $invocationArn]);
            $remoteStatus = VideoGenerationPolicy::normalizeStatus((string)($response['status'] ?? ''));
            if ($remoteStatus !== '') $status = $remoteStatus;
            if ($status === 'failed') {
                $meta['status'] = 'failed';
                $meta['failed_at'] = date('c');
                $failure = trim((string)($response['failureMessage'] ?? ''));
                if ($failure !== '') $meta['error'] = mb_substr($failure, 0, 500);
                videoStatusUpdateMeta($db_connection, $messageId, $meta, '⚠️ La generación del video falló.');
                videoStatusExit([
                    'ok' => true,
                    'status' => 'failed',
                    'message_id' => $messageId,
                    'error' => $failure !== '' ? $failure : null,
                ]);
            }
        } catch (Throwable $e) {
            $notes[] = 'GetAsyncInvoke: ' . $e->getMessage();
        }
    }

    try {
        $output = videoStatusFindOutput($s3, $bucket, $prefix);
        if ($output !== null) {
            $head = $s3->headObject(['Bucket' => $bucket, 'Key' => $output['key']]);
            $finalKey = (string)$output['key'];
            $mime = (string)($head['ContentType'] ?? 'video/mp4');
            $size = (int)($head['ContentLength'] ?? $output['size']);
            $meta['status'] = 'completed';
            $meta['completed_at'] = date('c');
            $meta['source_key'] = $finalKey;
            $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $content = $prompt !== '' ? 'Video generado: ' . $prompt : 'Video generado con Amazon Nova Reel.';

            $stmt = $db_connection->prepare(
                "UPDATE ChatMessages
                 SET content=?, content_type='video', s3_key=?, mime_type=?, size_bytes=?, meta=?
                 WHERE id_=? AND user_id_=? AND session_id_=?"
            );
            if (!$stmt) throw new RuntimeException('No se pudo preparar la persistencia del video.');
            $stmt->bind_param('sssisiii', $content, $finalKey, $mime, $size, $metaJson, $messageId, $userId, $sessionId);
            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                throw new RuntimeException('No se pudo persistir el video: ' . $error);
            }
            $stmt->close();

            $startedAt = isset($meta['started_at']) ? strtotime((string)$meta['started_at']) : false;
            $generationMs = $startedAt ? max(0, (int)round((microtime(true) - (float)$startedAt) * 1000)) : 0;
            videoStatusRecordUsage($db_connection, $sessionId, $messageId, $modelId, $generationMs);

            $out = [
                'ok' => true,
                'status' => 'completed',
                'message_id' => $messageId,
                's3_key' => $finalKey,
                'mime_type' => $mime,
                'size_bytes' => $size,
                'duration_ms' => $durationMs,
                'model_id' => $modelId,
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'billing_unit' => 'video_second', 'units' => $durationMs / 1000],
            ];
            if ($notes !== []) $out['notes'] = array_slice($notes, -3);
            videoStatusExit($out);
        }
    } catch (Throwable $e) {
        $notes[] = 'S3: ' . $e->getMessage();
    }

    if (microtime(true) >= $deadline) {
        $meta['status'] = 'in_progress';
        $meta['last_check_at'] = date('c');
        try { videoStatusUpdateMeta($db_connection, $messageId, $meta); } catch (Throwable $e) { $notes[] = $e->getMessage(); }
        $out = ['ok' => true, 'status' => 'in_progress', 'message_id' => $messageId, 'model_id' => $modelId];
        if ($notes !== []) $out['notes'] = array_slice($notes, -3);
        videoStatusExit($out);
    }

    sleep(3);
} while (true);
