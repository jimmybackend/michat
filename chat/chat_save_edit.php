<?php
/**
 * chat_save_edit.php
 *
 * Guarda la pregunta del usuario y la respuesta del asistente
 * cuando la edición de archivo se hace por el atajo directo
 * de chat.js (sin pasar por bedrock_chat2.php).
 *
 * Esto evita que los mensajes de edición de código se pierdan
 * del historial de la sesión.
 */

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/app_bootstrap.php';

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB no disponible'], JSON_UNESCAPED_UNICODE);
    exit;
}

function jexit_save($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function next_id_save(mysqli $db, string $table, string $col): int {
    $table = preg_replace('/[^A-Za-z0-9_]+/', '', $table);
    $col   = preg_replace('/[^A-Za-z0-9_]+/', '', $col);
    $rs = $db->query("SELECT IFNULL(MAX($col), 0) + 1 AS nxt FROM $table");
    if (!$rs) return 1;
    $row = $rs->fetch_assoc();
    return (int)($row['nxt'] ?? 1);
}

// ===== 1. Validar sesión =====
if (empty($_SESSION['user_id'])) {
    jexit_save(['ok' => false, 'error' => 'No autenticado.'], 401);
}

$userId    = (int)$_SESSION['user_id'];
$sessionId = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
$userText  = isset($_POST['user_text']) ? trim((string)$_POST['user_text']) : '';
$replyText = isset($_POST['reply_text']) ? trim((string)$_POST['reply_text']) : '';
$modelUsed = isset($_POST['model_used']) ? trim((string)$_POST['model_used']) : 'code_edit_direct';

if ($sessionId <= 0 || $userText === '') {
    jexit_save(['ok' => false, 'error' => 'Faltan parámetros: session_id, user_text'], 400);
}

// ===== 2. Verificar que la sesión existe =====
$chkSession = $db_connection->prepare("SELECT id_ FROM ChatSessions WHERE id_ = ? LIMIT 1");
$chkSession->bind_param('i', $sessionId);
$chkSession->execute();
$resSession = $chkSession->get_result();
if ($resSession->num_rows === 0) {
    $chkSession->close();
    jexit_save(['ok' => false, 'error' => 'Sesión no encontrada.'], 404);
}
$chkSession->close();

// ===== 3. Guardar mensaje de USUARIO =====
$userMsgId = next_id_save($db_connection, 'ChatMessages', 'id_');

$role_user      = 'user';
$ctype          = 'text';
$content_user   = $userText;
$s3_key         = null;
$mime           = null;
$size_bytes     = null;
$thumb_key      = null;
$duration_ms    = null;
$model_msg      = null;
$stop_reason    = null;
$prompt_tok     = null;
$compl_tok      = null;
$latency_ms     = null;
$meta           = null;
$is_primordial  = 0;
$phase          = 'respond';
$parent_msg_id  = null;

$sqlUser = "INSERT INTO ChatMessages (
    id_, session_id_, user_id_, role, content_type, content,
    s3_key, mime_type, size_bytes, thumb_s3_key, duration_ms,
    model_id, stop_reason, prompt_tokens, completion_tokens, latency_ms, meta,
    is_primordial, phase, parent_msg_id
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

$stmtUser = $db_connection->prepare($sqlUser);
if (!$stmtUser) {
    jexit_save(['ok' => false, 'error' => 'Error preparando INSERT user: ' . $db_connection->error], 500);
}

$stmtUser->bind_param(
    "iiisssssisissiiisisi",
    $userMsgId, $sessionId, $userId, $role_user, $ctype, $content_user,
    $s3_key, $mime, $size_bytes, $thumb_key, $duration_ms,
    $model_msg, $stop_reason, $prompt_tok, $compl_tok, $latency_ms, $meta,
    $is_primordial, $phase, $parent_msg_id
);

if (!$stmtUser->execute()) {
    $err = $stmtUser->error;
    $stmtUser->close();
    jexit_save(['ok' => false, 'error' => 'Error insertando mensaje de usuario: ' . $err], 500);
}
$stmtUser->close();

// ===== 4. Guardar mensaje de ASISTENTE =====
$assistantMsgId = next_id_save($db_connection, 'ChatMessages', 'id_');

$role_assistant   = 'assistant';
$ctype_a          = 'text';
$content_reply    = $replyText;
$s3_key_a         = null;
$mime_a           = null;
$size_bytes_a     = null;
$thumb_key_a      = null;
$duration_ms_a    = null;
$model_msg_a      = $modelUsed;
$stop_reason_a    = 'end_turn';
$prompt_tok_a     = 0;
$compl_tok_a      = 0;
$latency_ms_a     = null;
$meta_a           = json_encode(['source' => 'code_edit_direct'], JSON_UNESCAPED_UNICODE);
$is_primordial_a  = 0;
$phase_a          = 'respond';
$parent_msg_id_a  = null;

$sqlAssistant = "INSERT INTO ChatMessages (
    id_, session_id_, user_id_, role, content_type, content,
    s3_key, mime_type, size_bytes, thumb_s3_key, duration_ms,
    model_id, stop_reason, prompt_tokens, completion_tokens, latency_ms, meta,
    is_primordial, phase, parent_msg_id
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

$stmtAssistant = $db_connection->prepare($sqlAssistant);
if (!$stmtAssistant) {
    jexit_save(['ok' => false, 'error' => 'Error preparando INSERT assistant: ' . $db_connection->error], 500);
}

$stmtAssistant->bind_param(
    "iiisssssisissiiisisi",
    $assistantMsgId, $sessionId, $userId, $role_assistant, $ctype_a, $content_reply,
    $s3_key_a, $mime_a, $size_bytes_a, $thumb_key_a, $duration_ms_a,
    $model_msg_a, $stop_reason_a, $prompt_tok_a, $compl_tok_a, $latency_ms_a, $meta_a,
    $is_primordial_a, $phase_a, $parent_msg_id_a
);

if (!$stmtAssistant->execute()) {
    $err = $stmtAssistant->error;
    $stmtAssistant->close();
    jexit_save(['ok' => false, 'error' => 'Error insertando mensaje de asistente: ' . $err], 500);
}
$stmtAssistant->close();

// ===== 5. Crear bloque de contexto para la memoria de sesión =====
try {
    $blockId = next_id_save($db_connection, 'SessionContextBlocks', 'id_');
    $preview = mb_substr($userText, 0, 100) . " → " . mb_substr($replyText, 0, 300);
    $preview = mb_substr($preview, 0, 600);
    $tokenCount = (int)ceil(mb_strlen($preview) / 4);

    $sqlBlock = "INSERT INTO SessionContextBlocks (
        id_, session_id_, block_type, question_msg_id, answer_msg_id,
        content_preview, is_locked, token_count
    ) VALUES (?, ?, 'level_0', ?, ?, ?, 0, ?)";

    $stmtBlock = $db_connection->prepare($sqlBlock);
    if ($stmtBlock) {
        $stmtBlock->bind_param(
            "iiissi",
            $blockId, $sessionId, $userMsgId, $assistantMsgId, $preview, $tokenCount
        );
        $stmtBlock->execute();
        $stmtBlock->close();
    }
} catch (Throwable $e) {
    error_log('chat_save_edit SessionContextBlocks: ' . $e->getMessage());
    // No bloquear por esto
}

// ===== 6. Respuesta =====
jexit_save([
    'ok' => true,
    'saved' => [
        'user_msg_id'      => $userMsgId,
        'assistant_msg_id' => $assistantMsgId
    ]
]);