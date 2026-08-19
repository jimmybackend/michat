<?php

declare(strict_types=1);

/**
 * trace_node_edit_api.php · Fase 7.5
 *
 * Escritura segura de los REGISTROS VIVOS enlazados desde el grafo de trazabilidad.
 * El snapshot histórico de ChatActivityEvents jamás se modifica.
 *
 * POST JSON:
 * {
 *   "csrf_token": "...",
 *   "user_id": 1,
 *   "source": "ProjectContext|UserProceduralMemory|SessionContextBlocks|SourceChunks|ChatSessions",
 *   "id": 17,
 *   "fields": {...}
 * }
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function traceEditExit(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function traceEditJsonObject(string $raw, string $label): ?string
{
    $raw = trim($raw);
    if ($raw === '') return null;
    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new InvalidArgumentException($label . ' no es JSON válido: ' . json_last_error_msg());
    }
    return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function traceEditClampInt($value, int $min, int $max, int $default): int
{
    if (!is_numeric($value)) return $default;
    return max($min, min($max, (int)$value));
}

function traceEditEmbeddingState(mysqli $db, int $targetUserId, string $endpointDir): array
{
    $helper = $endpointDir . '/includes/ai_agent_runtime.php';
    if (!is_file($helper)) {
        return ['available' => false, 'model_id' => '', 'reason' => 'ai_agent_runtime_missing'];
    }
    try {
        require_once $helper;
        aiRuntimeLoad($db, $targetUserId);
        $active = aiAgentActive('embedding_main', false);
        $model = trim(aiAgentModel('embedding_main', ''));
        return [
            'available' => $active && $model !== '',
            'model_id' => $model,
            'reason' => ($active && $model !== '') ? null : 'embedding_main_inactive',
        ];
    } catch (Throwable $e) {
        error_log('TRACE_EDIT_EMBEDDING_CONFIG: ' . $e->getMessage());
        return ['available' => false, 'model_id' => '', 'reason' => 'embedding_config_error'];
    }
}

function traceEditQueueEmbedding(mysqli $db, string $targetType, int $targetId, array $embedding): array
{
    if (empty($embedding['available']) || trim((string)($embedding['model_id'] ?? '')) === '') {
        return ['queued' => false, 'model_id' => null, 'reason' => $embedding['reason'] ?? 'embedding_unavailable'];
    }
    $model = (string)$embedding['model_id'];
    $stmt = $db->prepare(
        "INSERT INTO EmbeddingJobs (target_type, target_id, model_id, status, attempts, error_message)
         VALUES (?, ?, ?, 'pending', 0, NULL)
         ON DUPLICATE KEY UPDATE status='pending', attempts=0, error_message=NULL, updated_at=NOW()"
    );
    if (!$stmt) {
        return ['queued' => false, 'model_id' => $model, 'reason' => 'embedding_job_prepare_failed'];
    }
    $stmt->bind_param('sis', $targetType, $targetId, $model);
    $ok = $stmt->execute();
    $error = $ok ? null : $stmt->error;
    $stmt->close();
    return ['queued' => $ok, 'model_id' => $model, 'reason' => $ok ? null : ($error ?: 'embedding_job_failed')];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    traceEditExit(['ok' => false, 'api_version' => '7.5', 'error' => 'Usa POST JSON.'], 405);
}

try {
    $endpointDir = __DIR__;
    $bootstrapHelper = $endpointDir . '/includes/Chat/ChatEndpointBootstrap.php';
    $identityHelper = $endpointDir . '/includes/Chat/ChatIdentity.php';
    if (!is_file($bootstrapHelper)) throw new RuntimeException('Falta includes/Chat/ChatEndpointBootstrap.php');
    if (!is_file($identityHelper)) throw new RuntimeException('Falta includes/Chat/ChatIdentity.php');

    require_once $bootstrapHelper;
    require_once $identityHelper;

    $db = ChatEndpointBootstrap::mysqli($endpointDir);
    $db->set_charset('utf8mb4');

    $viewerUserId = ChatIdentity::resolveUserId($db);
    if ($viewerUserId <= 0) {
        traceEditExit(['ok' => false, 'api_version' => '7.5', 'error' => 'Sesión de usuario no válida'], 401);
    }

    $raw = (string)file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        traceEditExit(['ok' => false, 'api_version' => '7.5', 'error' => 'El cuerpo debe ser JSON válido'], 400);
    }

    $csrf = (string)($input['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token']) || $csrf === '' || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
        traceEditExit(['ok' => false, 'api_version' => '7.5', 'error' => 'Token CSRF inválido'], 403);
    }

    $adminLike = ChatIdentity::isAdminLike();
    $targetUserId = isset($input['user_id']) && is_numeric($input['user_id'])
        ? (int)$input['user_id']
        : $viewerUserId;
    if ($targetUserId <= 0) throw new InvalidArgumentException('user_id inválido');
    if ($targetUserId !== $viewerUserId && !$adminLike) {
        traceEditExit(['ok' => false, 'api_version' => '7.5', 'error' => 'No tienes permisos para editar otro usuario'], 403);
    }

    $source = trim((string)($input['source'] ?? ''));
    $id = isset($input['id']) && is_numeric($input['id']) ? (int)$input['id'] : 0;
    $fields = isset($input['fields']) && is_array($input['fields']) ? $input['fields'] : [];
    if ($id <= 0) throw new InvalidArgumentException('id inválido');

    $editable = ['ProjectContext', 'UserProceduralMemory', 'SessionContextBlocks', 'SourceChunks', 'ChatSessions'];
    if (!in_array($source, $editable, true)) {
        traceEditExit([
            'ok' => false,
            'api_version' => '7.5',
            'error' => 'Este tipo de nodo es de solo lectura para preservar el historial.',
            'editable_sources' => $editable,
        ], 400);
    }

    $embedding = traceEditEmbeddingState($db, $targetUserId, $endpointDir);
    $embeddingResult = ['queued' => false, 'model_id' => null, 'reason' => 'not_applicable'];
    $warnings = [];
    $record = null;

    $db->begin_transaction();
    try {
        if ($source === 'ProjectContext') {
            $stmt = $db->prepare(
                "SELECT pc.id_, pc.project_id_, pc.type, pc.title, pc.content, pc.source_chunk_id
                 FROM ProjectContext pc JOIN Projects p ON p.id_=pc.project_id_
                 WHERE pc.id_=? AND p.user_id_=? LIMIT 1 FOR UPDATE"
            );
            if (!$stmt) throw new RuntimeException('No se pudo preparar ProjectContext: ' . $db->error);
            $stmt->bind_param('ii', $id, $targetUserId);
            $stmt->execute();
            $current = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$current) throw new RuntimeException('ProjectContext no encontrado o sin permisos');

            $allowedTypes = ['rule','decision','fact','style','todo','note'];
            $type = trim((string)($fields['type'] ?? $current['type']));
            if (!in_array($type, $allowedTypes, true)) throw new InvalidArgumentException('Tipo de ProjectContext inválido');
            $title = trim((string)($fields['title'] ?? ($current['title'] ?? '')));
            $title = $title === '' ? null : mb_substr($title, 0, 255);
            $content = trim((string)($fields['content'] ?? $current['content']));
            if ($content === '') throw new InvalidArgumentException('El contenido de ProjectContext no puede quedar vacío');
            $sourceChunkId = isset($fields['source_chunk_id']) && is_numeric($fields['source_chunk_id']) && (int)$fields['source_chunk_id'] > 0
                ? (int)$fields['source_chunk_id']
                : ($current['source_chunk_id'] !== null ? (int)$current['source_chunk_id'] : null);
            if (array_key_exists('source_chunk_id', $fields) && ((string)$fields['source_chunk_id'] === '' || (int)$fields['source_chunk_id'] <= 0)) {
                $sourceChunkId = null;
            }
            if ($sourceChunkId !== null) {
                $chk = $db->prepare("SELECT 1 FROM SourceChunks WHERE id_=? AND project_id_=? LIMIT 1");
                if (!$chk) throw new RuntimeException('No se pudo validar source_chunk_id');
                $projectId = (int)$current['project_id_'];
                $chk->bind_param('ii', $sourceChunkId, $projectId);
                $chk->execute();
                $exists = $chk->get_result()->num_rows > 0;
                $chk->close();
                if (!$exists) throw new InvalidArgumentException('source_chunk_id no pertenece a este proyecto');
            }

            $upd = $db->prepare("UPDATE ProjectContext SET type=?, title=?, content=?, source_chunk_id=?, embedding=NULL WHERE id_=?");
            if (!$upd) throw new RuntimeException('No se pudo preparar UPDATE ProjectContext: ' . $db->error);
            $upd->bind_param('sssii', $type, $title, $content, $sourceChunkId, $id);
            if (!$upd->execute()) throw new RuntimeException('No se pudo actualizar ProjectContext: ' . $upd->error);
            $upd->close();
            $embeddingResult = traceEditQueueEmbedding($db, 'project_context', $id, $embedding);
            $record = ['id'=>$id,'source'=>$source,'project_id'=>(int)$current['project_id_'],'type'=>$type,'title'=>$title,'content'=>$content,'source_chunk_id'=>$sourceChunkId];
        }

        if ($source === 'UserProceduralMemory') {
            $stmt = $db->prepare("SELECT id_, memory_type, content, source_session_id, confidence, is_active FROM UserProceduralMemory WHERE id_=? AND user_id_=? LIMIT 1 FOR UPDATE");
            if (!$stmt) throw new RuntimeException('No se pudo preparar UserProceduralMemory: ' . $db->error);
            $stmt->bind_param('ii', $id, $targetUserId);
            $stmt->execute();
            $current = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$current) throw new RuntimeException('Memoria procedural no encontrada o sin permisos');

            $allowedTypes = ['preference','rule','pattern','correction','workflow'];
            $memoryType = trim((string)($fields['memory_type'] ?? $current['memory_type']));
            if (!in_array($memoryType, $allowedTypes, true)) throw new InvalidArgumentException('memory_type inválido');
            $content = trim((string)($fields['content'] ?? $current['content']));
            if ($content === '') throw new InvalidArgumentException('La memoria procedural no puede quedar vacía');
            $confidence = traceEditClampInt($fields['confidence'] ?? $current['confidence'], 1, 255, (int)$current['confidence']);
            $isActive = array_key_exists('is_active', $fields) ? (!empty($fields['is_active']) ? 1 : 0) : ((int)$current['is_active'] === 1 ? 1 : 0);

            $upd = $db->prepare("UPDATE UserProceduralMemory SET memory_type=?, content=?, confidence=?, is_active=? WHERE id_=? AND user_id_=?");
            if (!$upd) throw new RuntimeException('No se pudo preparar UPDATE procedural: ' . $db->error);
            $upd->bind_param('ssiiii', $memoryType, $content, $confidence, $isActive, $id, $targetUserId);
            if (!$upd->execute()) throw new RuntimeException('No se pudo actualizar memoria procedural: ' . $upd->error);
            $upd->close();
            $record = ['id'=>$id,'source'=>$source,'user_id'=>$targetUserId,'memory_type'=>$memoryType,'content'=>$content,'confidence'=>$confidence,'is_active'=>(bool)$isActive,'source_session_id'=>$current['source_session_id'] !== null ? (int)$current['source_session_id'] : null];
        }

        if ($source === 'SessionContextBlocks') {
            $stmt = $db->prepare(
                "SELECT scb.id_, scb.session_id_, scb.block_type, scb.content_preview, scb.token_count, scb.is_locked,
                        scb.question_msg_id, scb.answer_msg_id, scb.s3_path
                 FROM SessionContextBlocks scb JOIN ChatSessions cs ON cs.id_=scb.session_id_
                 WHERE scb.id_=? AND cs.user_id_=? LIMIT 1 FOR UPDATE"
            );
            if (!$stmt) throw new RuntimeException('No se pudo preparar SessionContextBlocks: ' . $db->error);
            $stmt->bind_param('ii', $id, $targetUserId);
            $stmt->execute();
            $current = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$current) throw new RuntimeException('Bloque de sesión no encontrado o sin permisos');

            $allowedTypes = ['primordial','level_0','level_1','level_2','level_3','file','file_chunk'];
            $blockType = trim((string)($fields['block_type'] ?? $current['block_type']));
            if (!in_array($blockType, $allowedTypes, true)) throw new InvalidArgumentException('block_type inválido');
            $content = trim((string)($fields['content_preview'] ?? $current['content_preview']));
            if ($content === '') throw new InvalidArgumentException('El bloque no puede quedar vacío');
            $tokenCount = traceEditClampInt($fields['token_count'] ?? $current['token_count'], 0, 2000000, (int)$current['token_count']);
            if (!array_key_exists('token_count', $fields)) {
                $tokenCount = (int)ceil(max(1, mb_strlen($content, 'UTF-8')) / 4);
            }
            $isLocked = array_key_exists('is_locked', $fields) ? (!empty($fields['is_locked']) ? 1 : 0) : ((int)$current['is_locked'] === 1 ? 1 : 0);

            $upd = $db->prepare("UPDATE SessionContextBlocks SET block_type=?, content_preview=?, token_count=?, is_locked=?, embedding=NULL, embedding_json=NULL, embedding_model=NULL WHERE id_=?");
            if (!$upd) throw new RuntimeException('No se pudo preparar UPDATE SessionContextBlocks: ' . $db->error);
            $upd->bind_param('ssiii', $blockType, $content, $tokenCount, $isLocked, $id);
            if (!$upd->execute()) throw new RuntimeException('No se pudo actualizar bloque de sesión: ' . $upd->error);
            $upd->close();
            $embeddingResult = traceEditQueueEmbedding($db, 'session_block', $id, $embedding);
            if (in_array($blockType, ['file','file_chunk'], true)) {
                $warnings[] = 'Este nodo representa el índice del adjunto; el archivo original en S3 no fue modificado.';
            }
            $record = ['id'=>$id,'source'=>$source,'session_id'=>(int)$current['session_id_'],'block_type'=>$blockType,'content_preview'=>$content,'token_count'=>$tokenCount,'is_locked'=>(bool)$isLocked,'question_msg_id'=>$current['question_msg_id'] !== null ? (int)$current['question_msg_id'] : null,'answer_msg_id'=>$current['answer_msg_id'] !== null ? (int)$current['answer_msg_id'] : null,'s3_path'=>$current['s3_path']];
        }

        if ($source === 'SourceChunks') {
            $stmt = $db->prepare(
                "SELECT sc.id_, sc.project_id_, sc.source_id_, sc.chunk_type, sc.name, sc.content, sc.token_count, sc.meta, ps.filename
                 FROM SourceChunks sc JOIN Projects p ON p.id_=sc.project_id_
                 LEFT JOIN ProjectSources ps ON ps.id_=sc.source_id_
                 WHERE sc.id_=? AND p.user_id_=? LIMIT 1 FOR UPDATE"
            );
            if (!$stmt) throw new RuntimeException('No se pudo preparar SourceChunks: ' . $db->error);
            $stmt->bind_param('ii', $id, $targetUserId);
            $stmt->execute();
            $current = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$current) throw new RuntimeException('SourceChunk no encontrado o sin permisos');

            $content = (string)($fields['content'] ?? $current['content']);
            if (trim($content) === '') throw new InvalidArgumentException('El contenido del chunk no puede quedar vacío');
            $tokenCount = array_key_exists('token_count', $fields)
                ? traceEditClampInt($fields['token_count'], 0, 2000000, (int)$current['token_count'])
                : (int)ceil(max(1, mb_strlen($content, 'UTF-8')) / 4);
            $metaText = array_key_exists('meta', $fields)
                ? (is_string($fields['meta']) ? $fields['meta'] : json_encode($fields['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                : (string)($current['meta'] ?? '');
            $meta = traceEditJsonObject($metaText, 'meta');
            $checksum = hash('sha256', $content);

            $upd = $db->prepare("UPDATE SourceChunks SET content=?, token_count=?, checksum=?, meta=? WHERE id_=?");
            if (!$upd) throw new RuntimeException('No se pudo preparar UPDATE SourceChunks: ' . $db->error);
            $upd->bind_param('sissi', $content, $tokenCount, $checksum, $meta, $id);
            if (!$upd->execute()) throw new RuntimeException('No se pudo actualizar SourceChunk: ' . $upd->error);
            $upd->close();

            $del = $db->prepare("DELETE FROM ChunkEmbeddings WHERE chunk_id_=?");
            if ($del) { $del->bind_param('i', $id); $del->execute(); $del->close(); }
            $projectId = (int)$current['project_id_'];
            $bump = $db->prepare("UPDATE Projects SET index_gen=index_gen+1 WHERE id_=? AND user_id_=?");
            if ($bump) { $bump->bind_param('ii', $projectId, $targetUserId); $bump->execute(); $bump->close(); }
            $embeddingResult = traceEditQueueEmbedding($db, 'source_chunk', $id, $embedding);
            $warnings[] = 'Se editó el chunk indexado, no el archivo fuente. Su embedding anterior fue invalidado y se solicitó reindexación semántica.';
            $record = ['id'=>$id,'source'=>$source,'project_id'=>$projectId,'source_id'=>(int)$current['source_id_'],'filename'=>$current['filename'],'chunk_type'=>$current['chunk_type'],'name'=>$current['name'],'content'=>$content,'token_count'=>$tokenCount,'checksum'=>$checksum,'meta'=>$meta !== null ? json_decode($meta, true) : null];
        }

        if ($source === 'ChatSessions') {
            $stmt = $db->prepare("SELECT id_, project_id_, title, context_summary, context_level FROM ChatSessions WHERE id_=? AND user_id_=? LIMIT 1 FOR UPDATE");
            if (!$stmt) throw new RuntimeException('No se pudo preparar ChatSessions: ' . $db->error);
            $stmt->bind_param('ii', $id, $targetUserId);
            $stmt->execute();
            $current = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$current) throw new RuntimeException('Sesión no encontrada o sin permisos');

            $summary = trim((string)($fields['context_summary'] ?? ($current['context_summary'] ?? '')));
            $level = traceEditClampInt($fields['context_level'] ?? $current['context_level'], 0, 3, (int)$current['context_level']);
            $upd = $db->prepare("UPDATE ChatSessions SET context_summary=?, context_level=?, context_embedding=NULL, memory_summary_updated_at=NOW() WHERE id_=? AND user_id_=?");
            if (!$upd) throw new RuntimeException('No se pudo preparar UPDATE ChatSessions: ' . $db->error);
            $upd->bind_param('siii', $summary, $level, $id, $targetUserId);
            if (!$upd->execute()) throw new RuntimeException('No se pudo actualizar resumen de sesión: ' . $upd->error);
            $upd->close();
            $record = ['id'=>$id,'source'=>$source,'user_id'=>$targetUserId,'project_id'=>$current['project_id_'] !== null ? (int)$current['project_id_'] : null,'title'=>$current['title'],'context_summary'=>$summary,'context_level'=>$level];
        }

        if ($record === null) throw new RuntimeException('No se pudo resolver el editor del nodo');
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }

    traceEditExit([
        'ok' => true,
        'api_version' => '7.5',
        'historical_trace_immutable' => true,
        'message' => 'Registro vivo actualizado. El trace histórico no fue modificado.',
        'record' => $record,
        'embedding' => $embeddingResult,
        'warnings' => $warnings,
    ]);
} catch (InvalidArgumentException $e) {
    traceEditExit(['ok' => false, 'api_version' => '7.5', 'error' => $e->getMessage()], 400);
} catch (RuntimeException $e) {
    $message = $e->getMessage();
    $status = (stripos($message, 'permis') !== false || stripos($message, 'sin permisos') !== false) ? 403 : 500;
    traceEditExit(['ok' => false, 'api_version' => '7.5', 'error' => $message], $status);
} catch (Throwable $e) {
    error_log('TRACE_NODE_EDIT_7_5: ' . $e->getMessage());
    traceEditExit(['ok' => false, 'api_version' => '7.5', 'error' => 'Error interno actualizando el nodo.'], 500);
}
