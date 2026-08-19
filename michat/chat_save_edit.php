<?php
/**
 * chat_save_edit.php
 * Persiste Q&A de la ruta directa code_edit.php y la integra con:
 * - ChatMessages
 * - Memoria selectiva level_0 cruda
 * - EmbeddingJobs usando embedding_main efectivo
 * - ChatActivityEvents mediante trace_id
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (session_status() === PHP_SESSION_NONE) session_start();

function jexit_save(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (empty($_SESSION['user_id'])) {
    jexit_save(['ok' => false, 'error' => 'No autenticado.'], 401);
}
$userId = (int)$_SESSION['user_id'];
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

require_once __DIR__ . '/app_bootstrap.php';
if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    jexit_save(['ok' => false, 'error' => 'DB no disponible'], 500);
}

function saveEditActivityEmit(mysqli $db, string $traceId, int $sessionId, int $userId, string $eventKey, string $status, string $title, ?string $summary = null, $details = null): void {
    if ($traceId === '' || !preg_match('/^[A-Za-z0-9_-]{16,36}$/', $traceId)) return;
    if (!in_array($status, ['started','completed','info','waiting','error'], true)) $status = 'info';
    try {
        $detailsJson = $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare("INSERT INTO ChatActivityEvents (trace_id, session_id_, user_id_, phase, event_key, status, title, summary, details_json, model_id, duration_ms) VALUES (?, ?, ?, 'respond', ?, ?, ?, ?, ?, NULL, NULL)");
        if (!$stmt) return;
        $stmt->bind_param('siisssss', $traceId, $sessionId, $userId, $eventKey, $status, $title, $summary, $detailsJson);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('SAVE_EDIT_ACTIVITY: ' . $e->getMessage());
    }
}

$sessionId = (int)($_POST['session_id'] ?? 0);
$userText  = trim((string)($_POST['user_text'] ?? ''));
$replyText = trim((string)($_POST['reply_text'] ?? ''));
$modelUsed = trim((string)($_POST['model_used'] ?? 'code_edit_direct'));
$traceId   = trim((string)($_POST['trace_id'] ?? ''));
if ($traceId !== '' && !preg_match('/^[A-Za-z0-9_-]{16,36}$/', $traceId)) $traceId = '';

if ($sessionId <= 0 || $userText === '' || $replyText === '') {
    jexit_save(['ok' => false, 'error' => 'Faltan parámetros: session_id, user_text, reply_text'], 400);
}

$chk = $db_connection->prepare("SELECT id_, project_id_ FROM ChatSessions WHERE id_=? AND user_id_=? LIMIT 1");
if (!$chk) jexit_save(['ok'=>false,'error'=>'No se pudo validar la sesión.'], 500);
$chk->bind_param('ii', $sessionId, $userId);
$chk->execute();
$sessionRow = $chk->get_result()->fetch_assoc();
$chk->close();
if (!$sessionRow) {
    saveEditActivityEmit($db_connection, $traceId, $sessionId, $userId, 'trace_error', 'error', 'No se pudo guardar la respuesta', 'La sesión no pertenece al usuario.');
    jexit_save(['ok' => false, 'error' => 'Sesión no encontrada o sin permisos.'], 403);
}

saveEditActivityEmit($db_connection, $traceId, $sessionId, $userId, 'persistence_started', 'started', 'Guardando conversación', 'Persistiendo la edición directa y preparando memoria selectiva.');

$db_connection->begin_transaction();
try {
    $userMeta = $traceId !== '' ? json_encode(['source'=>'code_edit_direct','trace_id'=>$traceId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : json_encode(['source'=>'code_edit_direct'], JSON_UNESCAPED_UNICODE);
    $stmtUser = $db_connection->prepare("INSERT INTO ChatMessages (session_id_, user_id_, role, content_type, content, model_id, stop_reason, prompt_tokens, completion_tokens, meta, is_primordial, phase, parent_msg_id) VALUES (?, ?, 'user', 'text', ?, NULL, NULL, NULL, NULL, ?, 0, 'respond', NULL)");
    if (!$stmtUser) throw new RuntimeException('INSERT user: '.$db_connection->error);
    $stmtUser->bind_param('iiss', $sessionId, $userId, $userText, $userMeta);
    if (!$stmtUser->execute()) throw new RuntimeException('INSERT user: '.$stmtUser->error);
    $userMsgId = (int)$db_connection->insert_id;
    $stmtUser->close();

    $assistantMetaData = ['source'=>'code_edit_direct'];
    if ($traceId !== '') $assistantMetaData['trace_id'] = $traceId;
    $assistantMeta = json_encode($assistantMetaData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $zero = 0;
    $stmtAssistant = $db_connection->prepare("INSERT INTO ChatMessages (session_id_, user_id_, role, content_type, content, model_id, stop_reason, prompt_tokens, completion_tokens, meta, is_primordial, phase, parent_msg_id) VALUES (?, ?, 'assistant', 'text', ?, ?, 'end_turn', ?, ?, ?, 0, 'respond', NULL)");
    if (!$stmtAssistant) throw new RuntimeException('INSERT assistant: '.$db_connection->error);
    $stmtAssistant->bind_param('iissiis', $sessionId, $userId, $replyText, $modelUsed, $zero, $zero, $assistantMeta);
    if (!$stmtAssistant->execute()) throw new RuntimeException('INSERT assistant: '.$stmtAssistant->error);
    $assistantMsgId = (int)$db_connection->insert_id;
    $stmtAssistant->close();

    $rawQa = "Pregunta: {$userText}\nRespuesta: {$replyText}";
    $preview = mb_substr($rawQa, 0, 8000);
    $tokenCount = (int)ceil(mb_strlen($rawQa) / 4);
    $stmtBlock = $db_connection->prepare("INSERT INTO SessionContextBlocks (session_id_, block_type, question_msg_id, answer_msg_id, content_preview, is_locked, token_count, is_memory_summary) VALUES (?, 'level_0', ?, ?, ?, 0, ?, 0)");
    if (!$stmtBlock) throw new RuntimeException('INSERT SessionContextBlocks: '.$db_connection->error);
    $stmtBlock->bind_param('iiisi', $sessionId, $userMsgId, $assistantMsgId, $preview, $tokenCount);
    if (!$stmtBlock->execute()) throw new RuntimeException('INSERT SessionContextBlocks: '.$stmtBlock->error);
    $blockId = (int)$db_connection->insert_id;
    $stmtBlock->close();

    $embeddingModel = null;
    $embeddingJobId = null;
    $cfg = $db_connection->prepare("SELECT model_id, is_active FROM UserAIAgentConfigs WHERE agent_key='embedding_main' AND user_id_ IN (1, ?) ORDER BY (user_id_ = ?) DESC, user_id_ DESC LIMIT 1");
    if ($cfg) {
        $cfg->bind_param('ii', $userId, $userId);
        $cfg->execute();
        $cfgRow = $cfg->get_result()->fetch_assoc();
        $cfg->close();
        if ($cfgRow && (int)$cfgRow['is_active'] === 1 && trim((string)$cfgRow['model_id']) !== '') {
            $embeddingModel = trim((string)$cfgRow['model_id']);
            $job = $db_connection->prepare("INSERT INTO EmbeddingJobs (target_type, target_id, model_id, status, attempts) VALUES ('session_block', ?, ?, 'pending', 0)");
            if ($job) {
                $job->bind_param('is', $blockId, $embeddingModel);
                if ($job->execute()) $embeddingJobId = (int)$db_connection->insert_id;
                $job->close();
            }
        }
    }

    $db_connection->commit();

    saveEditActivityEmit($db_connection, $traceId, $sessionId, $userId, 'messages_saved', 'completed', 'Respuesta guardada', "Mensajes #{$userMsgId} y #{$assistantMsgId} persistidos.", [
        'user_msg_id'=>$userMsgId,
        'assistant_msg_id'=>$assistantMsgId,
        'model_used'=>$modelUsed,
    ]);
    saveEditActivityEmit($db_connection, $traceId, $sessionId, $userId, 'memory_queued', 'completed', 'Memoria Q&A preparada', $embeddingJobId ? 'Q&A crudo guardado y embedding encolado.' : 'Q&A crudo guardado; embedding_main no generó un job.', [
        'session_block_id'=>$blockId,
        'embedding_job_id'=>$embeddingJobId,
        'embedding_model'=>$embeddingModel,
    ]);
    saveEditActivityEmit($db_connection, $traceId, $sessionId, $userId, 'trace_completed', 'completed', 'Proceso terminado', 'Edición directa, persistencia e integración de memoria completadas.', [
        'assistant_msg_id'=>$assistantMsgId,
        'trace_id'=>$traceId ?: null,
    ]);

    jexit_save([
        'ok'=>true,
        'trace_id'=>$traceId !== '' ? $traceId : null,
        'saved'=>[
            'user_msg_id'=>$userMsgId,
            'assistant_msg_id'=>$assistantMsgId,
            'memory_block_id'=>$blockId,
            'embedding_job_id'=>$embeddingJobId,
            'embedding_model'=>$embeddingModel,
        ]
    ]);
} catch (Throwable $e) {
    try { $db_connection->rollback(); } catch (Throwable $ignored) {}
    saveEditActivityEmit($db_connection, $traceId, $sessionId, $userId, 'trace_error', 'error', 'Error guardando la edición', $e->getMessage());
    error_log('chat_save_edit: '.$e->getMessage());
    jexit_save(['ok'=>false,'error'=>'No se pudo guardar la edición: '.$e->getMessage()], 500);
}
