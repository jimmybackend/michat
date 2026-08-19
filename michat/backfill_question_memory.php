<?php
/**
 * backfill_question_memory.php
 *
 * Utilidad OPCIONAL para reconstruir level_0 históricos que fueron eliminados
 * por versiones antiguas de compress_session_context.php.
 *
 * No llama a ninguna IA. Solo:
 *  1) empareja cada respuesta assistant/text/phase=respond con el user/text previo;
 *  2) crea SessionContextBlocks level_0 históricos con is_locked=1;
 *  3) crea EmbeddingJobs con el embedding_main efectivo del propietario.
 *
 * Uso:
 *   php backfill_question_memory.php --secret='TU_SECRET' --batch=200
 *
 * Repite el comando hasta que created=0.
 */

define('QUESTION_MEMORY_BACKFILL_SECRET', 'Z1!xC6@vB3#nM8$kL4*jH9^gF2&dS7');

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Solo CLI\n");
}

$opts = getopt('', ['secret:', 'batch:']);
$secret = trim((string)($opts['secret'] ?? ''));
$batch = max(1, min(1000, (int)($opts['batch'] ?? 200)));

if (!hash_equals(QUESTION_MEMORY_BACKFILL_SECRET, $secret)) {
    fwrite(STDERR, "Clave inválida\n");
    exit(1);
}

try {
    $bootstrap = __DIR__ . '/app_bootstrap.php';
    if (!is_file($bootstrap)) {
        throw new RuntimeException('app_bootstrap.php no encontrado');
    }
    require_once $bootstrap;

    if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
        throw new RuntimeException('DB no disponible');
    }

    $runtime = __DIR__ . '/includes/ai_agent_runtime.php';
    if (!is_file($runtime)) {
        throw new RuntimeException('includes/ai_agent_runtime.php no encontrado');
    }
    require_once $runtime;
    $db_connection->set_charset('utf8mb4');
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$sql = "
    SELECT
        a.id_ AS answer_id,
        a.session_id_,
        cs.user_id_,
        a.content AS answer_text,
        a.created_at AS answer_created_at
    FROM ChatMessages a
    JOIN ChatSessions cs ON cs.id_ = a.session_id_
    WHERE a.role = 'assistant'
      AND a.content_type = 'text'
      AND a.phase = 'respond'
      AND a.content IS NOT NULL
      AND a.content <> ''
      AND EXISTS (
          SELECT 1
          FROM ChatMessages u
          WHERE u.session_id_ = a.session_id_
            AND u.role = 'user'
            AND u.content_type = 'text'
            AND u.id_ < a.id_
            AND u.content IS NOT NULL
            AND u.content <> ''
      )
      AND NOT EXISTS (
          SELECT 1
          FROM SessionContextBlocks scb
          WHERE scb.block_type = 'level_0'
            AND scb.answer_msg_id = a.id_
      )
    ORDER BY a.id_ ASC
    LIMIT ?
";

$stmt = $db_connection->prepare($sql);
if (!$stmt) {
    fwrite(STDERR, "No se pudo preparar búsqueda: {$db_connection->error}\n");
    exit(1);
}
$stmt->bind_param('i', $batch);
$stmt->execute();
$res = $stmt->get_result();
$answers = [];
while ($row = $res->fetch_assoc()) {
    $answers[] = $row;
}
$stmt->close();

$created = 0;
$queued = 0;
$skipped = 0;
$errors = [];

$stmtQuestion = $db_connection->prepare("
    SELECT id_, content
    FROM ChatMessages
    WHERE session_id_ = ?
      AND role = 'user'
      AND content_type = 'text'
      AND id_ < ?
      AND content IS NOT NULL
      AND content <> ''
    ORDER BY id_ DESC
    LIMIT 1
");

foreach ($answers as $row) {
    $answerId = (int)$row['answer_id'];
    $sessionId = (int)$row['session_id_'];
    $userId = max(1, (int)$row['user_id_']);
    $answer = trim((string)$row['answer_text']);
    $createdAt = (string)($row['answer_created_at'] ?? date('Y-m-d H:i:s'));

    if (!$stmtQuestion) {
        $errors[] = "No se pudo preparar búsqueda de pregunta";
        break;
    }

    $stmtQuestion->bind_param('ii', $sessionId, $answerId);
    $stmtQuestion->execute();
    $qRow = $stmtQuestion->get_result()->fetch_assoc();

    if (!$qRow) {
        $skipped++;
        continue;
    }

    $questionId = (int)$qRow['id_'];
    $question = trim((string)$qRow['content']);
    if ($question === '' || $answer === '') {
        $skipped++;
        continue;
    }

    try {
        $db_connection->begin_transaction();

        $previewText = "Pregunta: {$question}\nRespuesta: {$answer}";
        $preview = mb_substr($previewText, 0, 8000);
        $tokenCount = (int)ceil(mb_strlen($previewText) / 4);

        $ins = $db_connection->prepare("
            INSERT INTO SessionContextBlocks (
                session_id_, block_type, question_msg_id, answer_msg_id,
                content_preview, is_locked, token_count, is_memory_summary, created_at
            ) VALUES (?, 'level_0', ?, ?, ?, 1, ?, 0, ?)
        ");
        if (!$ins) {
            throw new RuntimeException($db_connection->error);
        }
        $ins->bind_param('iiisis', $sessionId, $questionId, $answerId, $preview, $tokenCount, $createdAt);
        if (!$ins->execute()) {
            $err = $ins->error;
            $ins->close();
            throw new RuntimeException($err);
        }
        $blockId = (int)$ins->insert_id;
        $ins->close();

        aiRuntimeLoad($db_connection, $userId);
        if (aiAgentActive('embedding_main', false)) {
            $modelId = aiAgentModel('embedding_main', '');
            if ($modelId !== '') {
                $job = $db_connection->prepare("
                    INSERT IGNORE INTO EmbeddingJobs
                        (target_type, target_id, model_id, status, attempts)
                    VALUES ('session_block', ?, ?, 'pending', 0)
                ");
                if ($job) {
                    $job->bind_param('is', $blockId, $modelId);
                    $job->execute();
                    if ($job->affected_rows > 0) $queued++;
                    $job->close();
                }
            }
        }

        $db_connection->commit();
        $created++;
    } catch (Throwable $e) {
        $db_connection->rollback();
        $errors[] = "assistant {$answerId}: " . $e->getMessage();
    }
}

if ($stmtQuestion) $stmtQuestion->close();

echo json_encode([
    'ok' => empty($errors),
    'scanned' => count($answers),
    'created' => $created,
    'queued' => $queued,
    'skipped' => $skipped,
    'errors' => $errors,
    'repeat' => ($created > 0),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
