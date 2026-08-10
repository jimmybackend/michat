<?php
// semantic_session_file.php
// Resume un adjunto con IA y lo guarda en SessionContextBlocks como
// bloque 'file' (is_locked=1) con su embedding Titan listo.
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/S3Manager.php';

function jexit($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function next_id(mysqli $db, $table, $col) {
    $rs = $db->query("SELECT COALESCE(MAX($col),0)+1 AS nxt FROM $table");
    if (!$rs) return 1;
    $row = $rs->fetch_assoc();
    return (int)($row['nxt'] ?? 1);
}

function floatsToBinaryBlob(array $floats): string {
    $binary = '';
    foreach ($floats as $f) $binary .= pack('g', (float)$f);
    return $binary;
}

function looks_like_code(string $t): bool {
    return (bool)preg_match('/\b(function|class|const|let|var|import|export|return|if\s*\(|echo|print|<\?php|=>|<div|<script|error|bug|c[oó]digo|archivo|file|script|variable|array|json|php|js|html|css|sql|query|database|bd|api|endpoint|config|route|controller|model)\b/i', $t);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jexit(['ok'=>false,'error'=>'Método no permitido'], 405);

$userId = 0;
foreach (['user_id_','user_id','id_usuario','id_user','id'] as $k) {
    if (isset($_SESSION[$k]) && ctype_digit((string)$_SESSION[$k])) { $userId = (int)$_SESSION[$k]; break; }
}
if ($userId <= 0) jexit(['ok'=>false,'error'=>'Sesión inválida'], 401);

$file_id    = isset($_POST['file_id'])    ? (int)$_POST['file_id']    : 0;
$session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
if ($file_id <= 0)    jexit(['ok'=>false,'error'=>'file_id inválido'], 400);
if ($session_id <= 0) jexit(['ok'=>false,'error'=>'session_id inválido'], 400);

$stmt = $db_connection->prepare("SELECT id_ FROM ChatSessions WHERE id_ = ? AND user_id_ = ? LIMIT 1");
$stmt->bind_param('ii', $session_id, $userId);
$stmt->execute();
$okSess = $stmt->get_result()->num_rows > 0;
$stmt->close();
if (!$okSess) jexit(['ok'=>false,'error'=>'La sesión no existe o no es tuya'], 403);

$stmt = $db_connection->prepare("SELECT id_, Nombre, Encriptado, Ruta FROM FileS3 WHERE id_ = ? AND user_id_ = ? LIMIT 1");
$stmt->bind_param('ii', $file_id, $userId);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$file) jexit(['ok'=>false,'error'=>'El archivo no existe o no es tuyo'], 403);

$enc  = str_replace('\\', '/', trim((string)$file['Encriptado']));
$ruta = rtrim(str_replace('\\', '/', (string)$file['Ruta']), '/');
$s3Key = (strpos($enc, $ruta) === 0) ? $enc : ($ruta . '/' . ltrim($enc, '/'));
$s3Key = preg_replace('~^(Data\d*)/\1/~i', '$1/', $s3Key);

try {
    $manager = new S3Manager($db_connection);
    $s3      = Config::getS3();
    $bucket  = $manager->getBucket();
    $result  = $s3->getObject(['Bucket'=>$bucket, 'Key'=>$s3Key]);
    $content = (string)$result['Body'];
} catch (Throwable $e) {
    jexit(['ok'=>false,'error'=>'No se pudo leer el archivo de S3: '.$e->getMessage()], 500);
}
if (trim($content) === '') jexit(['ok'=>false,'error'=>'El archivo está vacío'], 422);

$content = mb_substr($content, 0, 24000);

// ===== Bedrock =====
try {
    $region = (class_exists('Config') && defined('Config::REGION') && Config::REGION) ? Config::REGION : 'us-east-1';
    $ak = getenv('AWS_ACCESS_KEY_ID') ?: (defined('Config::ACCESS_KEY') ? Config::ACCESS_KEY : '');
    $sk = getenv('AWS_SECRET_ACCESS_KEY') ?: (defined('Config::SECRET_KEY') ? Config::SECRET_KEY : '');
    $bedrock = new Aws\BedrockRuntime\BedrockRuntimeClient([
        'region' => $region, 'version' => 'latest',
        'credentials' => ['key'=>$ak, 'secret'=>$sk],
        'http' => ['connect_timeout'=>10, 'timeout'=>120],
    ]);
} catch (Throwable $e) {
    jexit(['ok'=>false,'error'=>'Bedrock: '.$e->getMessage()], 500);
}

// 1) Resumen semántico con Nova (lite si es código, micro si es texto)
$modelId = looks_like_code($content) ? 'amazon.nova-lite-v1:0' : 'amazon.nova-micro-v1:0';

$system = "Eres un motor de memoria permanente para un asistente de programación. Resume el contenido de este archivo adjunto para que la conversación recuerde su esencia.
REGLAS:
1. Preserva nombres de funciones, clases, variables, rutas, puertos y valores exactos.
2. Describe el propósito del archivo y su lógica clave.
3. Máximo 400 palabras, texto plano, sin markdown.
4. Responde en el mismo idioma del contenido.";

try {
    $res = $bedrock->converse([
        'modelId' => $modelId,
        'messages' => [['role'=>'user','content'=>[['text'=>"ARCHIVO: {$file['Nombre']}\n\nCONTENIDO:\n" . $content]]]],
        'system' => [['text'=>$system]],
        'inferenceConfig' => ['maxTokens'=>800, 'temperature'=>0.2, 'topP'=>0.9],
    ]);
    $summary = '';
    foreach (($res['output']['message']['content'] ?? []) as $b) {
        if (isset($b['text'])) $summary .= $b['text'];
    }
    $summary = trim($summary);
    $inTokens  = (int)($res['usage']['inputTokens'] ?? 0);
    $outTokens = (int)($res['usage']['outputTokens'] ?? 0);
} catch (Throwable $e) {
    jexit(['ok'=>false,'error'=>'Error generando resumen: '.$e->getMessage()], 500);
}
if ($summary === '') jexit(['ok'=>false,'error'=>'La IA no devolvió resumen'], 500);

// 2) Embedding Titan del resumen
try {
    $embRes = $bedrock->invokeModel([
        'modelId' => 'amazon.titan-embed-text-v2:0',
        'contentType' => 'application/json',
        'accept' => 'application/json',
        'body' => json_encode(['inputText'=>mb_substr($summary,0,8000), 'dimensions'=>1024, 'normalize'=>true]),
    ]);
    $embData = json_decode((string)$embRes['body'], true);
    $embedding = $embData['embedding'] ?? [];
    $embTokens = (int)($embData['inputTextTokenCount'] ?? 0);
} catch (Throwable $e) {
    jexit(['ok'=>false,'error'=>'Error generando embedding: '.$e->getMessage()], 500);
}
if (empty($embedding)) jexit(['ok'=>false,'error'=>'Embedding vacío'], 500);

$binary = floatsToBinaryBlob($embedding);
$json   = json_encode($embedding);

// 3) Reemplazar semántica previa de este archivo
$stmt = $db_connection->prepare("DELETE FROM SessionContextBlocks WHERE session_id_ = ? AND s3_path = ? AND block_type = 'file'");
$stmt->bind_param('is', $session_id, $s3Key);
$stmt->execute();
$stmt->close();

// 4) Insertar bloque 'file' con embedding listo
$blockId = next_id($db_connection, 'SessionContextBlocks', 'id_');
$meta    = json_encode(['filename'=>$file['Nombre'],'files3_id'=>$file_id,'type'=>'semantic_summary'], JSON_UNESCAPED_UNICODE);
$tokens  = (int)ceil(mb_strlen($summary) / 4);
$titan   = 'amazon.titan-embed-text-v2:0';

$stmt = $db_connection->prepare("INSERT INTO SessionContextBlocks (id_, session_id_, block_type, content_preview, s3_path, is_locked, source_ids, token_count, embedding, embedding_json, embedding_model) VALUES (?, ?, 'file', ?, ?, 1, ?, ?, ?, ?, ?)");
$null = $binary;
$stmt->bind_param('iisssibss', $blockId, $session_id, $summary, $s3Key, $meta, $tokens, $null, $json, $titan);
$stmt->send_long_data(6, $binary);
$stmt->execute();
$stmt->close();

// 5) Registro de costos (fase summarize + embedding)
try {
    $tcMsgId = null;
    $stmtMsg = $db_connection->prepare("SELECT id_ FROM ChatMessages WHERE session_id_ = ? ORDER BY id_ DESC LIMIT 1");
    $stmtMsg->bind_param('i', $session_id);
    $stmtMsg->execute();
    $rMsg = $stmtMsg->get_result()->fetch_assoc();
    $stmtMsg->close();
    if ($rMsg) $tcMsgId = (int)$rMsg['id_'];

    $costNova  = ($inTokens / 1000000 * (strpos($modelId,'nova-lite')!==false ? 0.06 : 0.035)) + ($outTokens / 1000000 * (strpos($modelId,'nova-lite')!==false ? 0.24 : 0.14));
    $costTitan = ($embTokens / 1000000 * 0.10);

    foreach ([['summarize',$modelId,$inTokens,$outTokens,$costNova],['embedding',$titan,$embTokens,0,$costTitan]] as $log) {
        $tcId = next_id($db_connection, 'TokenUsage', 'id_');
        $stmtTC = $db_connection->prepare("INSERT INTO TokenUsage (id_, session_id_, message_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)");
        $stmtTC->bind_param('iiissiidi', $tcId, $session_id, $tcMsgId, $log[0], $log[1], $log[2], $log[3], $log[4]);
        $stmtTC->execute();
        $stmtTC->close();
    }
} catch (Throwable $e) { /* el registro de costo nunca debe romper el flujo */ }

jexit([
    'ok' => true,
    'mensaje' => 'Semántica creada. La conversación ya tiene memoria permanente de este archivo.',
    'resumen' => mb_substr($summary, 0, 300)
]);