<?php
/**
 * ai_data_api.php
 *
 * API para visualizar/editar datos internos de IA que no estaban expuestos:
 * - ChatSessions.meta / context_summary / context_level / pending_summary
 * - ProjectContext completo (source_chunk_id, embedding, updated_at)
 * - SourceChunks + ChunkEmbeddings
 * - PromptCompilations
 * - PhaseCache
 * - ToolCalls
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function json_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['usuario']) || empty($_SESSION['usuario'])) {
    json_out(['ok' => false, 'error' => 'No autorizado'], 401);
}

try {
    $bootstrap = __DIR__ . '/app_bootstrap.php';

    if (!is_file($bootstrap)) {
        throw new RuntimeException('app_bootstrap.php no encontrado en la raíz.');
    }

    require_once $bootstrap;
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Error de bootstrap: ' . $e->getMessage()], 500);
}

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    json_out(['ok' => false, 'error' => 'DB no disponible'], 500);
}

$db_connection->set_charset('utf8mb4');

function db(): mysqli
{
    global $db_connection;
    return $db_connection;
}

function get_user_id(): int
{
    foreach (['user_id_', 'user_id', 'id_usuario', 'id_user', 'id'] as $k) {
        if (isset($_SESSION[$k]) && ctype_digit((string)$_SESSION[$k])) {
            return (int)$_SESSION[$k];
        }
    }

    return 0;
}

function owns_session(int $sessionId, int $userId): bool
{
    if ($sessionId <= 0 || $userId <= 0) {
        return false;
    }

    try {
        $stmt = db()->prepare("SELECT 1 FROM ChatSessions WHERE id_ = ? AND user_id_ = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ii', $sessionId, $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $ok = $res && $res->num_rows > 0;
        $stmt->close();

        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

function owns_project(int $projectId, int $userId): bool
{
    if ($projectId <= 0 || $userId <= 0) {
        return false;
    }

    $userCols = ['user_id_', 'user_id', 'id_usuario', 'id_user'];

    foreach ($userCols as $userCol) {
        try {
            $stmt = db()->prepare("SELECT 1 FROM Projects WHERE id_ = ? AND {$userCol} = ? LIMIT 1");
            if (!$stmt) {
                continue;
            }

            $stmt->bind_param('ii', $projectId, $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            $ok = $res && $res->num_rows > 0;
            $stmt->close();

            if ($ok) {
                return true;
            }
        } catch (Throwable $e) {
            continue;
        }
    }

    return false;
}

function pretty_json($value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    $decoded = is_string($value) ? json_decode($value, true) : $value;

    if (json_last_error() === JSON_ERROR_NONE) {
        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    return (string)$value;
}

function validate_json_text(string $text, string $fieldName): ?string
{
    $text = trim($text);

    if ($text === '') {
        return null;
    }

    $decoded = json_decode($text, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("El campo {$fieldName} no es JSON válido: " . json_last_error_msg());
    }

    return json_encode($decoded, JSON_UNESCAPED_UNICODE);
}

function project_context_allowed_types(): array
{
    return ['rule', 'decision', 'fact', 'style', 'todo', 'note'];
}

function prompt_compilation_allowed_status(): array
{
    return ['pending', 'approved', 'rejected'];
}

// =====================================================================
// GET: LISTADO GENERAL
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $userId = get_user_id();

    if ($userId <= 0) {
        json_out(['ok' => false, 'error' => 'Usuario inválido'], 401);
    }

    $sessionId = (int)($_GET['session_id'] ?? 0);
    $projectId = (int)($_GET['project_id'] ?? 0);

    $response = [
        'ok' => true,
        'session' => null,
        'project_id' => $projectId,
        'project_name' => null,
        'project_context' => [],
        'source_chunks' => [],
        'prompt_compilations' => [],
        'phase_cache' => [],
        'tool_calls' => []
    ];

    try {
        // ------------------------------------------------------------
        // SESIÓN
        // ------------------------------------------------------------
        if ($sessionId > 0) {
            if (!owns_session($sessionId, $userId)) {
                json_out(['ok' => false, 'error' => 'Sin permisos sobre esta sesión'], 403);
            }

            $stmt = db()->prepare("
                SELECT
                    id_,
                    project_id_,
                    title,
                    model_id,
                    provider,
                    status,
                    meta,
                    context_summary,
                    context_level,
                    last_compressed_at,
                    pending_summary,
                    created_at,
                    updated_at,
                    COALESCE(CHAR_LENGTH(context_embedding), 0) AS context_embedding_length
                FROM ChatSessions
                WHERE id_ = ? AND user_id_ = ?
                LIMIT 1
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . db()->error);
            }

            $stmt->bind_param('ii', $sessionId, $userId);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($row = $res->fetch_assoc()) {
                $row['meta_pretty'] = pretty_json($row['meta']);
                $response['session'] = $row;

                if ($projectId <= 0 && !empty($row['project_id_'])) {
                    $projectId = (int)$row['project_id_'];
                    $response['project_id'] = $projectId;
                }
            }

            $stmt->close();
        }

        // ------------------------------------------------------------
        // PROYECTO
        // ------------------------------------------------------------
        if ($projectId > 0) {
            if (!owns_project($projectId, $userId)) {
                $projectId = 0;
                $response['project_id'] = 0;
            } else {
                $stmt = db()->prepare("
                    SELECT name
                    FROM Projects
                    WHERE id_ = ? AND user_id_ = ?
                    LIMIT 1
                ");

                if ($stmt) {
                    $stmt->bind_param('ii', $projectId, $userId);
                    $stmt->execute();
                    $res = $stmt->get_result();

                    if ($row = $res->fetch_assoc()) {
                        $response['project_name'] = $row['name'] ?? null;
                    }

                    $stmt->close();
                }
            }
        }

        // ------------------------------------------------------------
        // PROJECT CONTEXT COMPLETO
        // ------------------------------------------------------------
        if ($projectId > 0) {
            $stmt = db()->prepare("
                SELECT
                    id_,
                    project_id_,
                    type,
                    title,
                    content,
                    source_chunk_id,
                    LEFT(embedding, 2000) AS embedding_preview,
                    COALESCE(CHAR_LENGTH(embedding), 0) AS embedding_length,
                    created_at,
                    updated_at
                FROM ProjectContext
                WHERE project_id_ = ?
                ORDER BY FIELD(type, 'rule', 'decision', 'fact', 'style', 'todo', 'note'), created_at DESC
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . db()->error);
            }

            $stmt->bind_param('i', $projectId);
            $stmt->execute();
            $res = $stmt->get_result();

            while ($row = $res->fetch_assoc()) {
                $response['project_context'][] = $row;
            }

            $stmt->close();

            // --------------------------------------------------------
            // SOURCE CHUNKS + EMBEDDINGS
            // --------------------------------------------------------
            $stmt = db()->prepare("
                SELECT
                    sc.id_,
                    sc.source_id_,
                    sc.chunk_type,
                    sc.name,
                    sc.parent_name,
                    LEFT(sc.signature, 255) AS signature_preview,
                    LEFT(sc.content, 2000) AS content_preview,
                    COALESCE(CHAR_LENGTH(sc.content), 0) AS content_length,
                    sc.start_line,
                    sc.end_line,
                    sc.token_count,
                    sc.checksum,
                    sc.meta,
                    sc.created_at,
                    sc.updated_at,
                    ps.filename AS source_filename
                FROM SourceChunks sc
                LEFT JOIN ProjectSources ps ON sc.source_id_ = ps.id_
                WHERE sc.project_id_ = ?
                ORDER BY sc.id_ DESC
                LIMIT 300
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . db()->error);
            }

            $stmt->bind_param('i', $projectId);
            $stmt->execute();
            $res = $stmt->get_result();

            $chunks = [];
            while ($row = $res->fetch_assoc()) {
                $row['meta_pretty'] = pretty_json($row['meta']);
                $row['embeddings'] = [];
                $chunks[(int)$row['id_']] = $row;
            }

            $stmt->close();

            if (!empty($chunks)) {
                $stmt = db()->prepare("
                    SELECT
                        ce.id_,
                        ce.chunk_id_,
                        ce.model_id,
                        ce.dimensions,
                        LEFT(ce.embedding_json, 1000) AS embedding_json_preview,
                        COALESCE(CHAR_LENGTH(ce.embedding_json), 0) AS embedding_json_length,
                        COALESCE(LENGTH(ce.embedding), 0) AS embedding_bytes,
                        ce.created_at
                    FROM ChunkEmbeddings ce
                    INNER JOIN SourceChunks sc ON ce.chunk_id_ = sc.id_
                    WHERE sc.project_id_ = ?
                    ORDER BY ce.id_ DESC
                    LIMIT 1000
                ");

                if (!$stmt) {
                    throw new RuntimeException('Prepare failed: ' . db()->error);
                }

                $stmt->bind_param('i', $projectId);
                $stmt->execute();
                $res = $stmt->get_result();

                while ($row = $res->fetch_assoc()) {
                    $chunkId = (int)$row['chunk_id_'];

                    if (isset($chunks[$chunkId])) {
                        $chunks[$chunkId]['embeddings'][] = $row;
                    }
                }

                $stmt->close();
            }

            $response['source_chunks'] = array_values($chunks);

            // --------------------------------------------------------
            // PHASE CACHE
            // --------------------------------------------------------
            $stmt = db()->prepare("
                SELECT
                    id_,
                    cache_key,
                    phase,
                    LEFT(payload, 2000) AS payload_preview,
                    COALESCE(CHAR_LENGTH(payload), 0) AS payload_length,
                    hit_count,
                    expires_at,
                    created_at
                FROM PhaseCache
                WHERE project_id_ = ?
                ORDER BY id_ DESC
                LIMIT 200
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . db()->error);
            }

            $stmt->bind_param('i', $projectId);
            $stmt->execute();
            $res = $stmt->get_result();

            while ($row = $res->fetch_assoc()) {
                $response['phase_cache'][] = $row;
            }

            $stmt->close();
        }

        // ------------------------------------------------------------
        // PROMPT COMPILATIONS
        // ------------------------------------------------------------
        if ($sessionId > 0) {
            $stmt = db()->prepare("
                SELECT
                    id_,
                    user_msg_id,
                    LEFT(compiled_prompt, 2000) AS compiled_preview,
                    COALESCE(CHAR_LENGTH(compiled_prompt), 0) AS compiled_length,
                    used_context_ids,
                    used_code_refs,
                    notes_for_user,
                    was_edited_by_user,
                    status,
                    created_at
                FROM PromptCompilations
                WHERE session_id_ = ?
                ORDER BY id_ DESC
                LIMIT 100
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . db()->error);
            }

            $stmt->bind_param('i', $sessionId);
            $stmt->execute();
            $res = $stmt->get_result();

            while ($row = $res->fetch_assoc()) {
                $response['prompt_compilations'][] = $row;
            }

            $stmt->close();
        }

        // ------------------------------------------------------------
        // TOOL CALLS
        // ------------------------------------------------------------
        if ($sessionId > 0 || $projectId > 0) {
            if ($sessionId > 0 && $projectId > 0) {
                $sql = "
                    SELECT
                        id_,
                        session_id_,
                        project_id_,
                        message_id_,
                        tool,
                        LEFT(params, 1000) AS params_preview,
                        COALESCE(CHAR_LENGTH(params), 0) AS params_length,
                        target_path,
                        LEFT(result, 1000) AS result_preview,
                        COALESCE(CHAR_LENGTH(result), 0) AS result_length,
                        status,
                        duration_ms,
                        created_at
                    FROM ToolCalls
                    WHERE session_id_ = ? OR project_id_ = ?
                    ORDER BY id_ DESC
                    LIMIT 100
                ";

                $stmt = db()->prepare($sql);
                if (!$stmt) {
                    throw new RuntimeException('Prepare failed: ' . db()->error);
                }

                $stmt->bind_param('ii', $sessionId, $projectId);
            } elseif ($sessionId > 0) {
                $sql = "
                    SELECT
                        id_,
                        session_id_,
                        project_id_,
                        message_id_,
                        tool,
                        LEFT(params, 1000) AS params_preview,
                        COALESCE(CHAR_LENGTH(params), 0) AS params_length,
                        target_path,
                        LEFT(result, 1000) AS result_preview,
                        COALESCE(CHAR_LENGTH(result), 0) AS result_length,
                        status,
                        duration_ms,
                        created_at
                    FROM ToolCalls
                    WHERE session_id_ = ?
                    ORDER BY id_ DESC
                    LIMIT 100
                ";

                $stmt = db()->prepare($sql);
                if (!$stmt) {
                    throw new RuntimeException('Prepare failed: ' . db()->error);
                }

                $stmt->bind_param('i', $sessionId);
            } else {
                $sql = "
                    SELECT
                        id_,
                        session_id_,
                        project_id_,
                        message_id_,
                        tool,
                        LEFT(params, 1000) AS params_preview,
                        COALESCE(CHAR_LENGTH(params), 0) AS params_length,
                        target_path,
                        LEFT(result, 1000) AS result_preview,
                        COALESCE(CHAR_LENGTH(result), 0) AS result_length,
                        status,
                        duration_ms,
                        created_at
                    FROM ToolCalls
                    WHERE project_id_ = ?
                    ORDER BY id_ DESC
                    LIMIT 100
                ";

                $stmt = db()->prepare($sql);
                if (!$stmt) {
                    throw new RuntimeException('Prepare failed: ' . db()->error);
                }

                $stmt->bind_param('i', $projectId);
            }

            $stmt->execute();
            $res = $stmt->get_result();

            while ($row = $res->fetch_assoc()) {
                $response['tool_calls'][] = $row;
            }

            $stmt->close();
        }

        json_out($response);
    } catch (Throwable $e) {
        json_out(['ok' => false, 'error' => 'Error del servidor: ' . $e->getMessage()], 500);
    }
}

// =====================================================================
// POST: ACCIONES
// =====================================================================
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    $input = $_POST;
}

$action = trim((string)($input['action'] ?? ''));
$userId = get_user_id();

if ($userId <= 0) {
    json_out(['ok' => false, 'error' => 'Usuario inválido'], 401);
}

if ($action === '') {
    json_out(['ok' => false, 'error' => 'Acción requerida'], 400);
}

try {
    switch ($action) {

        // =============================================================
        // SESIÓN: EDITAR META / RESUMEN / NIVEL / PENDING
        // =============================================================
        case 'update_session_data': {
            $sessionId = (int)($input['session_id'] ?? 0);

            if ($sessionId <= 0 || !owns_session($sessionId, $userId)) {
                json_out(['ok' => false, 'error' => 'Sesión inválida o sin permisos'], 403);
            }

            $meta = trim((string)($input['meta'] ?? ''));
            if ($meta !== '') {
                $meta = validate_json_text($meta, 'meta');
            } else {
                $meta = null;
            }

            $contextSummary = trim((string)($input['context_summary'] ?? ''));
            $contextLevel = max(0, min(3, (int)($input['context_level'] ?? 0)));
            $pendingSummary = !empty($input['pending_summary']) ? 1 : 0;

            $stmt = db()->prepare("
                UPDATE ChatSessions
                SET meta = ?, context_summary = ?, context_level = ?, pending_summary = ?
                WHERE id_ = ? AND user_id_ = ?
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . db()->error);
            }

            $stmt->bind_param('ssiiii', $meta, $contextSummary, $contextLevel, $pendingSummary, $sessionId, $userId);
            $stmt->execute();
            $stmt->close();

            json_out(['ok' => true, 'message' => 'Sesión actualizada']);
        }

        // =============================================================
        // PROYECTO: CREAR PROJECT CONTEXT
        // =============================================================
        case 'create_project_context': {
            $projectId = (int)($input['project_id'] ?? 0);

            if ($projectId <= 0 || !owns_project($projectId, $userId)) {
                json_out(['ok' => false, 'error' => 'Proyecto inválido o sin permisos'], 403);
            }

            $type = trim((string)($input['type'] ?? 'note'));
            if (!in_array($type, project_context_allowed_types(), true)) {
                $type = 'note';
            }

            $title = trim((string)($input['title'] ?? ''));
            $title = $title === '' ? null : mb_substr($title, 0, 255);

            $content = trim((string)($input['content'] ?? ''));

            $sourceChunkIdRaw = trim((string)($input['source_chunk_id'] ?? ''));
            $sourceChunkId = ($sourceChunkIdRaw === '' || (int)$sourceChunkIdRaw <= 0) ? null : (int)$sourceChunkIdRaw;

            $embedding = trim((string)($input['embedding'] ?? ''));
            $embedding = $embedding === '' ? null : $embedding;

            $stmt = db()->prepare("
                INSERT INTO ProjectContext (project_id_, type, title, content, source_chunk_id, embedding)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . db()->error);
            }

            $stmt->bind_param('isssis', $projectId, $type, $title, $content, $sourceChunkId, $embedding);
            $stmt->execute();
            $stmt->close();

            json_out(['ok' => true, 'message' => 'Contexto de proyecto creado']);
        }

        // =============================================================
        // PROYECTO: EDITAR PROJECT CONTEXT
        // =============================================================
        case 'update_project_context': {
            $id = (int)($input['id'] ?? 0);
            $projectId = (int)($input['project_id'] ?? 0);

            if ($id <= 0 || $projectId <= 0 || !owns_project($projectId, $userId)) {
                json_out(['ok' => false, 'error' => 'ID o proyecto inválido'], 400);
            }

            $type = trim((string)($input['type'] ?? 'note'));
            if (!in_array($type, project_context_allowed_types(), true)) {
                $type = 'note';
            }

            $title = trim((string)($input['title'] ?? ''));
            $title = $title === '' ? null : mb_substr($title, 0, 255);

            $content = trim((string)($input['content'] ?? ''));

            $sourceChunkIdRaw = trim((string)($input['source_chunk_id'] ?? ''));
            $sourceChunkId = ($sourceChunkIdRaw === '' || (int)$sourceChunkIdRaw <= 0) ? null : (int)$sourceChunkIdRaw;

            $updateEmbedding = !empty($input['update_embedding']);

            if ($updateEmbedding) {
                $embedding = trim((string)($input['embedding'] ?? ''));
                $embedding = $embedding === '' ? null : $embedding;

                $stmt = db()->prepare("
                    UPDATE ProjectContext
                    SET type = ?, title = ?, content = ?, source_chunk_id = ?, embedding = ?
                    WHERE id_ = ? AND project_id_ = ?
                ");

                if (!$stmt) {
                    throw new RuntimeException('Prepare failed: ' . db()->error);
                }

                $stmt->bind_param('sssisii', $type, $title, $content, $sourceChunkId, $embedding, $id, $projectId);
            } else {
                $stmt = db()->prepare("
                    UPDATE ProjectContext
                    SET type = ?, title = ?, content = ?, source_chunk_id = ?
                    WHERE id_ = ? AND project_id_ = ?
                ");

                if (!$stmt) {
                    throw new RuntimeException('Prepare failed: ' . db()->error);
                }

                $stmt->bind_param('sssiii', $type, $title, $content, $sourceChunkId, $id, $projectId);
            }

            $stmt->execute();
            $stmt->close();

            json_out(['ok' => true, 'message' => 'Contexto de proyecto actualizado']);
        }

        // =============================================================
        // PROYECTO: ELIMINAR PROJECT CONTEXT
        // =============================================================
        case 'delete_project_context': {
            $id = (int)($input['id'] ?? 0);
            $projectId = (int)($input['project_id'] ?? 0);

            if ($id <= 0 || $projectId <= 0 || !owns_project($projectId, $userId)) {
                json_out(['ok' => false, 'error' => 'ID o proyecto inválido'], 400);
            }

            $stmt = db()->prepare("
                DELETE FROM ProjectContext
                WHERE id_ = ? AND project_id_ = ?
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . db()->error);
            }

            $stmt->bind_param('ii', $id, $projectId);
            $stmt->execute();
            $stmt->close();

            json_out(['ok' => true, 'message' => 'Contexto de proyecto eliminado']);
        }

        // =============================================================
        // PROYECTO: OBTENER EMBEDDING COMPLETO DE PROJECT CONTEXT
        // =============================================================
        case 'get_project_context_embedding': {
            $id = (int)($input['id'] ?? 0);
            $projectId = (int)($input['project_id'] ?? 0);

            if ($id <= 0 || $projectId <= 0 || !owns_project($projectId, $userId)) {
                json_out(['ok' => false, 'error' => 'ID o proyecto inválido'], 400);
            }

            $stmt = db()->prepare("
                SELECT embedding
                FROM ProjectContext
                WHERE id_ = ? AND project_id_ = ?
                LIMIT 1
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . db()->error);
            }

            $stmt->bind_param('ii', $id, $projectId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $stmt->close();

            if (!$row) {
                json_out(['ok' => false, 'error' => 'Registro no encontrado'], 404);
            }

            json_out([
                'ok' => true,
                'embedding' => $row['embedding']
            ]);
        }

        // =============================================================
        // SOURCE CHUNK: OBTENER CONTENIDO COMPLETO
        // =============================================================
        case 'get_source_chunk_full': {
            $id = (int)($input['id'] ?? 0);
            $projectId = (int)($input['project_id'] ?? 0);

            if ($id <= 0 || $projectId <= 0 || !owns_project($projectId, $userId)) {
                json_out(['ok' => false, 'error' => 'ID o proyecto inválido'], 400);
            }

            $stmt = db()->prepare("
                SELECT content, meta, token_count, name
                FROM SourceChunks
                WHERE id_ = ? AND project_id_ = ?
                LIMIT 1
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . db()->error);
            }

            $stmt->bind_param('ii', $id, $projectId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $stmt->close();

            if (!$row) {
                json_out(['ok' => false, 'error' => 'Chunk no encontrado'], 404);
            }

            json_out([
                'ok' => true,
                'content' => $row['content'],
                'meta' => $row['meta'],
                'meta_pretty' => pretty_json($row['meta']),
                'token_count' => $row['token_count'],
                'name' => $row['name']
            ]);
        }

        // =============================================================
        // SOURCE CHUNK: EDITAR CONTENIDO / META / TOKENS
        // =============================================================
        case 'update_source_chunk': {
            $id = (int)($input['id'] ?? 0);
            $projectId = (int)($input['project_id'] ?? 0);

            if ($id <= 0 || $projectId <= 0 || !owns_project($projectId, $userId)) {
                json_out(['ok' => false, 'error' => 'ID o proyecto inválido'], 400);
            }

            $content = (string)($input['content'] ?? '');
            if (trim($content) === '') {
                json_out(['ok' => false, 'error' => 'El contenido del chunk no puede estar vacío'], 400);
            }

            $meta = trim((string)($input['meta'] ?? ''));
            if ($meta !== '') {
                $meta = validate_json_text($meta, 'meta');
            } else {
                $meta = null;
            }

            if (isset($input['token_count']) && is_numeric($input['token_count'])) {
                $tokenCount = (int)$input['token_count'];
            } else {
                $tokenCount = (int)ceil(mb_strlen($content, 'UTF-8') / 4);
            }

            $checksum = hash('sha256', $content);

            $stmt = db()->prepare("
                UPDATE SourceChunks
                SET content = ?, token_count = ?, checksum = ?, meta = ?
                WHERE id_ = ? AND project_id_ = ?
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . db()->error);
            }

            $stmt->bind_param('sissii', $content, $tokenCount, $checksum, $meta, $id, $projectId);
            $stmt->execute();
            $stmt->close();

            json_out(['ok' => true, 'message' => 'Chunk actualizado']);
        }

        // =============================================================
        // CHUNK EMBEDDING: OBTENER JSON COMPLETO
        // =============================================================
        case 'get_chunk_embedding_full': {
            $id = (int)($input['id'] ?? 0);
            $projectId = (int)($input['project_id'] ?? 0);

            if ($id <= 0 || $projectId <= 0 || !owns_project($projectId, $userId)) {
                json_out(['ok' => false, 'error' => 'ID o proyecto inválido'], 400);
            }

            $stmt = db()->prepare("
                SELECT ce.embedding_json, ce.dimensions, ce.model_id
                FROM ChunkEmbeddings ce
                INNER JOIN SourceChunks sc ON ce.chunk_id_ = sc.id_
                WHERE ce.id_ = ? AND sc.project_id_ = ?
                LIMIT 1
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . db()->error);
            }

            $stmt->bind_param('ii', $id, $projectId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $stmt->close();

            if (!$row) {
                json_out(['ok' => false, 'error' => 'Embedding no encontrado'], 404);
            }

            json_out([
                'ok' => true,
                'embedding_json' => $row['embedding_json'],
                'embedding_json_pretty' => pretty_json($row['embedding_json']),
                'dimensions' => $row['dimensions'],
                'model_id' => $row['model_id']
            ]);
        }

        // =============================================================
        // CHUNK EMBEDDING: EDITAR JSON Y REGENERAR BINARIO
        // =============================================================
        case 'update_chunk_embedding_json': {
            $id = (int)($input['id'] ?? 0);
            $projectId = (int)($input['project_id'] ?? 0);

            if ($id <= 0 || $projectId <= 0 || !owns_project($projectId, $userId)) {
                json_out(['ok' => false, 'error' => 'ID o proyecto inválido'], 400);
            }

            $embeddingJson = trim((string)($input['embedding_json'] ?? ''));

            if ($embeddingJson === '') {
                json_out(['ok' => false, 'error' => 'El embedding JSON no puede estar vacío'], 400);
            }

            $decoded = json_decode($embeddingJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                json_out(['ok' => false, 'error' => 'Embedding JSON inválido: ' . json_last_error_msg()], 400);
            }

            if (!is_array($decoded) || count($decoded) === 0) {
                json_out(['ok' => false, 'error' => 'El embedding debe ser un arreglo JSON de números'], 400);
            }

            $floats = [];
            foreach ($decoded as $value) {
                if (!is_numeric($value)) {
                    json_out(['ok' => false, 'error' => 'Todos los valores del embedding deben ser numéricos'], 400);
                }

                $floats[] = (float)$value;
            }

            $dimensions = count($floats);

            if ($dimensions > 65535) {
                json_out(['ok' => false, 'error' => 'Demasiadas dimensiones para el campo dimensions'], 400);
            }

            $binary = '';
            foreach ($floats as $float) {
                $packed = pack('g', $float); // float32 little-endian
                if ($packed === false) {
                    throw new RuntimeException('No se pudo empaquetar el vector binario.');
                }

                $binary .= $packed;
            }

            $normalizedJson = json_encode($floats, JSON_UNESCAPED_UNICODE);

            $stmt = db()->prepare("
                UPDATE ChunkEmbeddings ce
                INNER JOIN SourceChunks sc ON ce.chunk_id_ = sc.id_
                SET ce.embedding_json = ?, ce.embedding = ?, ce.dimensions = ?
                WHERE ce.id_ = ? AND sc.project_id_ = ?
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . db()->error);
            }

            $stmt->bind_param('ssiii', $normalizedJson, $binary, $dimensions, $id, $projectId);
            $stmt->execute();

            if ($stmt->affected_rows === 0) {
                $stmt->close();
                json_out(['ok' => false, 'error' => 'No se encontró el embedding o no hubo cambios'], 404);
            }

            $stmt->close();

            json_out(['ok' => true, 'message' => 'Embedding actualizado']);
        }

        // =============================================================
        // PROMPT COMPILATION: OBTENER COMPLETO
        // =============================================================
        case 'get_prompt_compilation_full': {
            $id = (int)($input['id'] ?? 0);
            $sessionId = (int)($input['session_id'] ?? 0);

            if ($id <= 0 || $sessionId <= 0 || !owns_session($sessionId, $userId)) {
                json_out(['ok' => false, 'error' => 'Sesión o compilación inválida'], 400);
            }

            $stmt = db()->prepare("
                SELECT compiled_prompt, notes_for_user, status
                FROM PromptCompilations
                WHERE id_ = ? AND session_id_ = ?
                LIMIT 1
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . db()->error);
            }

            $stmt->bind_param('ii', $id, $sessionId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $stmt->close();

            if (!$row) {
                json_out(['ok' => false, 'error' => 'Compilación no encontrada'], 404);
            }

            json_out([
                'ok' => true,
                'compiled_prompt' => $row['compiled_prompt'],
                'notes_for_user' => $row['notes_for_user'],
                'status' => $row['status']
            ]);
        }

        // =============================================================
        // PROMPT COMPILATION: EDITAR
        // =============================================================
        case 'update_prompt_compilation': {
            $id = (int)($input['id'] ?? 0);
            $sessionId = (int)($input['session_id'] ?? 0);

            if ($id <= 0 || $sessionId <= 0 || !owns_session($sessionId, $userId)) {
                json_out(['ok' => false, 'error' => 'Sesión o compilación inválida'], 400);
            }

            $compiledPrompt = (string)($input['compiled_prompt'] ?? '');
            $notes = trim((string)($input['notes_for_user'] ?? ''));
            $status = trim((string)($input['status'] ?? 'pending'));

            if (!in_array($status, prompt_compilation_allowed_status(), true)) {
                $status = 'pending';
            }

            $stmt = db()->prepare("
                UPDATE PromptCompilations
                SET compiled_prompt = ?, notes_for_user = ?, status = ?
                WHERE id_ = ? AND session_id_ = ?
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . db()->error);
            }

            $stmt->bind_param('sssii', $compiledPrompt, $notes, $status, $id, $sessionId);
            $stmt->execute();
            $stmt->close();

            json_out(['ok' => true, 'message' => 'Prompt compilado actualizado']);
        }

        // =============================================================
        // PHASE CACHE: ELIMINAR UNA ENTRADA
        // =============================================================
        case 'delete_phase_cache': {
            $id = (int)($input['id'] ?? 0);
            $projectId = (int)($input['project_id'] ?? 0);

            if ($id <= 0 || $projectId <= 0 || !owns_project($projectId, $userId)) {
                json_out(['ok' => false, 'error' => 'ID o proyecto inválido'], 400);
            }

            $stmt = db()->prepare("
                DELETE FROM PhaseCache
                WHERE id_ = ? AND project_id_ = ?
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . db()->error);
            }

            $stmt->bind_param('ii', $id, $projectId);
            $stmt->execute();
            $stmt->close();

            json_out(['ok' => true, 'message' => 'Entrada de caché eliminada']);
        }

        // =============================================================
        // PHASE CACHE: LIMPIAR TODO EL PROYECTO
        // =============================================================
        case 'clear_phase_cache': {
            $projectId = (int)($input['project_id'] ?? 0);

            if ($projectId <= 0 || !owns_project($projectId, $userId)) {
                json_out(['ok' => false, 'error' => 'Proyecto inválido o sin permisos'], 403);
            }

            $stmt = db()->prepare("
                DELETE FROM PhaseCache
                WHERE project_id_ = ?
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . db()->error);
            }

            $stmt->bind_param('i', $projectId);
            $stmt->execute();
            $stmt->close();

            json_out(['ok' => true, 'message' => 'Caché de fases limpiada']);
        }

        // =============================================================
        // TOOL CALL: ELIMINAR
        // =============================================================
        case 'delete_tool_call': {
            $id = (int)($input['id'] ?? 0);

            if ($id <= 0) {
                json_out(['ok' => false, 'error' => 'ID inválido'], 400);
            }

            $stmt = db()->prepare("
                SELECT session_id_, project_id_
                FROM ToolCalls
                WHERE id_ = ?
                LIMIT 1
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . db()->error);
            }

            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $stmt->close();

            if (!$row) {
                json_out(['ok' => false, 'error' => 'ToolCall no encontrado'], 404);
            }

            $allowed = false;

            if (!empty($row['session_id_']) && owns_session((int)$row['session_id_'], $userId)) {
                $allowed = true;
            }

            if (!$allowed && !empty($row['project_id_']) && owns_project((int)$row['project_id_'], $userId)) {
                $allowed = true;
            }

            if (!$allowed) {
                json_out(['ok' => false, 'error' => 'Sin permisos para eliminar este ToolCall'], 403);
            }

            $stmt = db()->prepare("
                DELETE FROM ToolCalls
                WHERE id_ = ?
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . db()->error);
            }

            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            json_out(['ok' => true, 'message' => 'ToolCall eliminado']);
        }

        default:
            json_out(['ok' => false, 'error' => 'Acción no reconocida: ' . $action], 400);
    }
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Error del servidor: ' . $e->getMessage()], 500);
}