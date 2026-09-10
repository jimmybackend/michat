<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/includes/Chat/ChatIdentity.php';
require_once __DIR__ . '/includes/ai_agent_runtime.php';

function voiceTurnExit(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    voiceTurnExit(['ok' => false, 'error' => 'DB no disponible'], 500);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    voiceTurnExit(['ok' => false, 'error' => 'Método no permitido'], 405);
}

$userId = ChatIdentity::resolveUserId($db_connection);
if ($userId <= 0) voiceTurnExit(['ok' => false, 'error' => 'No autenticado'], 401);
$csrf = (string)($_POST['csrf_token'] ?? '');
if ($csrf === '' || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
    voiceTurnExit(['ok' => false, 'error' => 'Token CSRF inválido. Recarga la página.'], 403);
}

$sessionId = (int)($_POST['session_id'] ?? 0);
if ($sessionId <= 0) voiceTurnExit(['ok' => false, 'error' => 'Selecciona una conversación antes de usar voz.'], 400);

$stmt = $db_connection->prepare('SELECT id_, project_id_ FROM ChatSessions WHERE id_=? AND user_id_=? LIMIT 1');
if (!$stmt) voiceTurnExit(['ok' => false, 'error' => 'No se pudo validar la conversación.'], 500);
$stmt->bind_param('ii', $sessionId, $userId);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$session) voiceTurnExit(['ok' => false, 'error' => 'La conversación no existe o no es tuya.'], 404);

if (!isset($_FILES['audio']) || !is_array($_FILES['audio'])) {
    voiceTurnExit(['ok' => false, 'error' => 'No se recibió audio.'], 400);
}
$upload = $_FILES['audio'];
if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    voiceTurnExit(['ok' => false, 'error' => 'No se pudo recibir el audio.'], 400);
}
$audioSize = (int)($upload['size'] ?? 0);
$maxAudioBytes = 16000 * 2 * 30;
if ($audioSize < 3200 || $audioSize > $maxAudioBytes) {
    voiceTurnExit(['ok' => false, 'error' => 'El audio debe durar aproximadamente entre 0.1 y 30 segundos.'], 400);
}
$tmpAudio = (string)($upload['tmp_name'] ?? '');
if ($tmpAudio === '' || !is_uploaded_file($tmpAudio)) {
    voiceTurnExit(['ok' => false, 'error' => 'Carga de audio inválida.'], 400);
}

try {
    aiRuntimeLoad($db_connection, $userId);
} catch (Throwable $e) {
    error_log('VOICE_RUNTIME_LOAD: ' . $e->getMessage());
    voiceTurnExit(['ok' => false, 'error' => 'No se pudo cargar la configuración de voz.'], 500);
}

$cfg = aiAgentConfig('voice_main');
if (!$cfg || (int)($cfg['is_active'] ?? 0) !== 1) {
    voiceTurnExit(['ok' => false, 'error' => 'La voz está desactivada. Activa Voz / Audio en Preferencias → Modelos IA.'], 409);
}
$modelId = trim((string)($cfg['model_id'] ?? ''));
if ($modelId !== 'amazon.nova-2-sonic-v1:0') {
    voiceTurnExit(['ok' => false, 'error' => 'El modelo de voz configurado no es compatible.'], 409);
}
$extra = is_array($cfg['_extra'] ?? null) ? $cfg['_extra'] : [];
$voiceId = strtolower(trim((string)($extra['voice_id'] ?? 'lupe')));
if (!in_array($voiceId, ['lupe', 'carlos'], true)) $voiceId = 'lupe';
$systemPrompt = trim((string)($cfg['system_instruction'] ?? ''));
if ($systemPrompt === '') $systemPrompt = 'Eres el asistente de voz de MiChat. Responde en español de forma clara, natural y concisa.';
$maxTokens = max(128, min(2048, (int)($cfg['max_tokens_output'] ?? 1024)));
$temperature = max(0.0, min(1.0, (float)($cfg['temperature'] ?? 0.5)));
$topP = max(0.01, min(1.0, (float)($cfg['top_p'] ?? 0.9)));

$history = [];
$stmt = $db_connection->prepare("SELECT role, content FROM ChatMessages WHERE session_id_=? AND user_id_=? AND content_type='text' AND role IN ('user','assistant') ORDER BY id_ DESC LIMIT 12");
if ($stmt) {
    $stmt->bind_param('ii', $sessionId, $userId);
    if ($stmt->execute()) {
        $rows = array_reverse($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        foreach ($rows as $row) {
            $content = trim((string)($row['content'] ?? ''));
            $role = strtolower((string)($row['role'] ?? ''));
            if ($content !== '' && in_array($role, ['user', 'assistant'], true)) {
                $history[] = ['role' => $role, 'content' => mb_substr($content, 0, 6000)];
            }
        }
    }
    $stmt->close();
}

$nodeCandidates = array_values(array_filter([
    trim((string)(getenv('MICHAT_NODE_BINARY') ?: '')),
    '/usr/bin/node',
    '/usr/local/bin/node',
]));
$node = '';
foreach ($nodeCandidates as $candidate) {
    if (is_file($candidate) && is_executable($candidate)) { $node = $candidate; break; }
}
if ($node === '') voiceTurnExit(['ok' => false, 'error' => 'Node.js no está instalado en el servidor para Nova 2 Sonic.'], 503);
$script = __DIR__ . '/voice/sonic_turn.mjs';
if (!is_file($script) || !is_readable($script)) voiceTurnExit(['ok' => false, 'error' => 'Bridge de Nova 2 Sonic no disponible.'], 503);

$configFile = tempnam(sys_get_temp_dir(), 'michat_voice_');
if ($configFile === false) voiceTurnExit(['ok' => false, 'error' => 'No se pudo preparar el turno de voz.'], 500);
$config = [
    'model_id' => $modelId,
    'voice_id' => $voiceId,
    'region' => Config::getRegion(),
    'system_prompt' => $systemPrompt,
    'history' => $history,
    'max_tokens' => $maxTokens,
    'temperature' => $temperature,
    'top_p' => $topP,
];
file_put_contents($configFile, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
@chmod($configFile, 0600);

$env = $_ENV;
foreach (['PATH','HOME','AWS_REGION','AWS_DEFAULT_REGION','AWS_ACCESS_KEY_ID','AWS_SECRET_ACCESS_KEY','AWS_SESSION_TOKEN'] as $name) {
    $value = getenv($name);
    if ($value !== false && $value !== '') $env[$name] = $value;
}
$env['AWS_REGION'] = Config::getRegion();
try {
    $credentials = Config::getAwsCredentials();
    if (is_array($credentials)) {
        $env['AWS_ACCESS_KEY_ID'] = (string)$credentials['key'];
        $env['AWS_SECRET_ACCESS_KEY'] = (string)$credentials['secret'];
        if (!empty($credentials['token'])) $env['AWS_SESSION_TOKEN'] = (string)$credentials['token'];
    }
} catch (Throwable $e) {
    @unlink($configFile);
    voiceTurnExit(['ok' => false, 'error' => 'Configuración AWS de voz inválida.'], 500);
}

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$startedAt = hrtime(true);
$process = @proc_open([$node, $script, '--config', $configFile, '--audio', $tmpAudio], $descriptors, $pipes, __DIR__ . '/voice', $env);
if (!is_resource($process)) {
    @unlink($configFile);
    voiceTurnExit(['ok' => false, 'error' => 'No se pudo iniciar Nova 2 Sonic.'], 500);
}
fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
$stdout = '';
$stderr = '';
$deadline = microtime(true) + 100.0;
$exitCode = null;
while (true) {
    $stdout .= (string)stream_get_contents($pipes[1]);
    $stderr .= (string)stream_get_contents($pipes[2]);
    $status = proc_get_status($process);
    if (!$status['running']) { $exitCode = (int)$status['exitcode']; break; }
    if (microtime(true) >= $deadline) {
        proc_terminate($process, 15);
        usleep(250000);
        $status = proc_get_status($process);
        if ($status['running']) proc_terminate($process, 9);
        $exitCode = 124;
        break;
    }
    usleep(50000);
}
$stdout .= (string)stream_get_contents($pipes[1]);
$stderr .= (string)stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$closeCode = proc_close($process);
if (($exitCode === null || $exitCode < 0) && $closeCode >= 0) $exitCode = $closeCode;
@unlink($configFile);
$durationMs = max(0, (int)round((hrtime(true) - $startedAt) / 1_000_000));

if ($exitCode !== 0) {
    error_log('VOICE_SONIC_ERROR: ' . mb_substr(trim($stderr), 0, 1000));
    voiceTurnExit(['ok' => false, 'error' => $exitCode === 124 ? 'El turno de voz excedió el tiempo máximo.' : 'Nova 2 Sonic no pudo completar el turno.'], 502);
}

$result = json_decode(trim($stdout), true);
if (!is_array($result) || empty($result['ok'])) {
    error_log('VOICE_SONIC_BAD_RESPONSE: ' . mb_substr(trim($stdout), 0, 500));
    voiceTurnExit(['ok' => false, 'error' => 'Respuesta de voz inválida.'], 502);
}
$userText = trim((string)($result['user_transcript'] ?? ''));
$assistantText = trim((string)($result['assistant_transcript'] ?? ''));
$audioBase64 = (string)($result['audio_pcm_base64'] ?? '');
if ($userText === '' || $assistantText === '') voiceTurnExit(['ok' => false, 'error' => 'La transcripción de voz quedó incompleta.'], 502);
if (strlen($userText) > 20000 || strlen($assistantText) > 40000 || strlen($audioBase64) > 8_000_000) {
    voiceTurnExit(['ok' => false, 'error' => 'La respuesta de voz excedió los límites de seguridad.'], 502);
}
$usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];
$inputTokens = max(0, (int)($usage['input_tokens'] ?? 0));
$outputTokens = max(0, (int)($usage['output_tokens'] ?? 0));

$db_connection->begin_transaction();
try {
    $userMeta = json_encode(['source' => 'nova2_sonic_voice', 'voice_id' => $voiceId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmtUser = $db_connection->prepare("INSERT INTO ChatMessages (session_id_,user_id_,role,content_type,content,model_id,stop_reason,prompt_tokens,completion_tokens,meta,is_primordial,phase,parent_msg_id) VALUES (?,?,'user','text',?,NULL,NULL,NULL,NULL,?,0,'respond',NULL)");
    if (!$stmtUser) throw new RuntimeException('No se pudo guardar la transcripción del usuario.');
    $stmtUser->bind_param('iiss', $sessionId, $userId, $userText, $userMeta);
    $stmtUser->execute();
    $userMessageId = (int)$db_connection->insert_id;
    $stmtUser->close();

    $assistantMeta = json_encode(['source' => 'nova2_sonic_voice', 'voice_id' => $voiceId, 'output_sample_rate' => 24000], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmtAssistant = $db_connection->prepare("INSERT INTO ChatMessages (session_id_,user_id_,role,content_type,content,model_id,stop_reason,prompt_tokens,completion_tokens,meta,is_primordial,phase,parent_msg_id) VALUES (?,?,'assistant','text',?,?,'end_turn',?,?,?,0,'respond',?)");
    if (!$stmtAssistant) throw new RuntimeException('No se pudo guardar la respuesta de voz.');
    $stmtAssistant->bind_param('iissiisi', $sessionId, $userId, $assistantText, $modelId, $inputTokens, $outputTokens, $assistantMeta, $userMessageId);
    $stmtAssistant->execute();
    $assistantMessageId = (int)$db_connection->insert_id;
    $stmtAssistant->close();

    $cost = 0.0;
    $phase = 'respond';
    $stmtUsage = $db_connection->prepare('INSERT INTO TokenUsage (session_id_,message_id_,phase,model_id,input_tokens,output_tokens,estimated_cost_usd,duration_ms) VALUES (?,?,?,?,?,?,?,?)');
    if (!$stmtUsage) throw new RuntimeException('No se pudo guardar TokenUsage de voz.');
    $stmtUsage->bind_param('iissiidi', $sessionId, $assistantMessageId, $phase, $modelId, $inputTokens, $outputTokens, $cost, $durationMs);
    $stmtUsage->execute();
    $stmtUsage->close();

    $stmtTouch = $db_connection->prepare('UPDATE ChatSessions SET updated_at=CURRENT_TIMESTAMP WHERE id_=? AND user_id_=?');
    if ($stmtTouch) { $stmtTouch->bind_param('ii', $sessionId, $userId); $stmtTouch->execute(); $stmtTouch->close(); }
    $db_connection->commit();
} catch (Throwable $e) {
    $db_connection->rollback();
    error_log('VOICE_PERSIST: ' . $e->getMessage());
    voiceTurnExit(['ok' => false, 'error' => 'El turno se procesó pero no pudo persistirse de forma segura.'], 500);
}

voiceTurnExit([
    'ok' => true,
    'session_id' => $sessionId,
    'user_message_id' => $userMessageId,
    'assistant_message_id' => $assistantMessageId,
    'model_id' => $modelId,
    'voice_id' => $voiceId,
    'user_transcript' => $userText,
    'assistant_transcript' => $assistantText,
    'audio_pcm_base64' => $audioBase64,
    'output_sample_rate' => 24000,
    'usage' => [
        'input_tokens' => $inputTokens,
        'output_tokens' => $outputTokens,
        'total_tokens' => $inputTokens + $outputTokens,
        'input_speech_tokens' => (int)($usage['input_speech_tokens'] ?? 0),
        'output_speech_tokens' => (int)($usage['output_speech_tokens'] ?? 0),
        'input_text_tokens' => (int)($usage['input_text_tokens'] ?? 0),
        'output_text_tokens' => (int)($usage['output_text_tokens'] ?? 0),
    ],
]);
