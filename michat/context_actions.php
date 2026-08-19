<?php
/**
 * context_actions.php
 *
 * Endpoint para crear, editar y eliminar contexto de proyecto y sesión.
 * Debe ser llamado vía fetch desde session_context_viewer.php
 *
 * CORREGIDO: bind_param types y soporte para BLOB
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

// ✅ LOG DE DEPURACIÓN (elimina esta línea en producción)
function debug_log($msg, $data = null) {
    $log = date('Y-m-d H:i:s') . " | " . $msg;
    if ($data !== null) {
        $log .= " | " . (is_string($data) ? substr($data, 0, 200) : json_encode($data));
    }
    error_log($log);
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

// ✅ UTF-8 para la conexión
$db_connection->set_charset('utf8mb4');

function get_user_id_from_session(): int
{
    foreach (['user_id_', 'user_id', 'id_usuario', 'id_user', 'id'] as $k) {
        if (isset($_SESSION[$k]) && ctype_digit((string)$_SESSION[$k])) {
            return (int)$_SESSION[$k];
        }
    }
    return 0;
}

function session_owned(mysqli $db, int $sessionId, int $userId): bool
{
    try {
        $stmt = $db->prepare("SELECT 1 FROM ChatSessions WHERE id_ = ? AND user_id_ = ? LIMIT 1");
        if (!$stmt) return false;
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

function project_owned(mysqli $db, int $projectId, int $userId): bool
{
    $idCols = ['id_', 'id'];
    $userCols = ['user_id_', 'user_id', 'id_usuario', 'id_user', 'usuario_id'];

    foreach ($idCols as $idCol) {
        foreach ($userCols as $userCol) {
            try {
                $stmt = $db->prepare("SELECT 1 FROM Projects WHERE {$idCol} = ? AND {$userCol} = ? LIMIT 1");
                if (!$stmt) continue;
                $stmt->bind_param('ii', $projectId, $userId);
                $stmt->execute();
                $res = $stmt->get_result();
                $ok = $res && $res->num_rows > 0;
                $stmt->close();
                if ($ok) return true;
            } catch (Throwable $e) {
                continue;
            }
        }
    }
    return false;
}

$userId = get_user_id_from_session();
if ($userId <= 0) {
    json_out(['ok' => false, 'error' => 'Usuario inválido'], 401);
}

// ✅ LEER EL JSON DEL BODY
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    debug_log('JSON inválido recibido', $raw);
    $input = $_POST; // fallback
}

debug_log('Action recibida', $input['action'] ?? 'unknown');

$action = trim((string)($input['action'] ?? ''));
if ($action === '') {
    json_out(['ok' => false, 'error' => 'Acción requerida'], 400);
}

try {
    switch ($action) {

        // =====================================================
        // PROYECTO: CREAR
        // =====================================================
        case 'create_project_context': {
            $projectId = (int)($input['project_id'] ?? 0);
            $type = trim((string)($input['type'] ?? 'note'));
            $title = mb_substr(trim((string)($input['title'] ?? '')), 0, 255);
            $content = trim((string)($input['content'] ?? ''));

            if ($projectId <= 0) {
                json_out(['ok' => false, 'error' => 'Proyecto inválido'], 400);
            }

            if (!project_owned($db_connection, $projectId, $userId)) {
                json_out(['ok' => false, 'error' => 'Sin permisos sobre este proyecto'], 403);
            }

            $allowedTypes = ['rule', 'decision', 'fact', 'style', 'todo', 'note'];
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'note';
            }

            $stmt = $db_connection->prepare("
                INSERT INTO ProjectContext (project_id_, type, title, content, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . $db_connection->error);
            }

            $stmt->bind_param('isss', $projectId, $type, $title, $content);
            $ok = $stmt->execute();
            $stmt->close();

            if (!$ok) {
                throw new RuntimeException('Execute failed: ' . $db_connection->error);
            }

            json_out(['ok' => true, 'message' => 'Contexto de proyecto creado']);
        }

        // =====================================================
        // PROYECTO: EDITAR
        // =====================================================
        case 'update_project_context': {
            $id = (int)($input['id'] ?? 0);
            $projectId = (int)($input['project_id'] ?? 0);
            $type = trim((string)($input['type'] ?? 'note'));
            $title = mb_substr(trim((string)($input['title'] ?? '')), 0, 255);
            $content = trim((string)($input['content'] ?? ''));

            if ($id <= 0 || $projectId <= 0) {
                json_out(['ok' => false, 'error' => 'ID o proyecto inválido'], 400);
            }

            if (!project_owned($db_connection, $projectId, $userId)) {
                json_out(['ok' => false, 'error' => 'Sin permisos sobre este proyecto'], 403);
            }

            $allowedTypes = ['rule', 'decision', 'fact', 'style', 'todo', 'note'];
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'note';
            }

            $stmt = $db_connection->prepare("
                UPDATE ProjectContext
                SET type = ?, title = ?, content = ?
                WHERE id_ = ? AND project_id_ = ?
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . $db_connection->error);
            }

            // ✅ CORREGIDO: 'sssii' (3 strings + 2 integers)
            $stmt->bind_param('sssii', $type, $title, $content, $id, $projectId);
            $ok = $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            if (!$ok) {
                throw new RuntimeException('Execute failed: ' . $db_connection->error);
            }

            json_out([
                'ok' => true,
                'message' => 'Contexto actualizado',
                'affected_rows' => $affected
            ]);
        }

        // =====================================================
        // PROYECTO: ELIMINAR UN REGISTRO
        // =====================================================
        case 'delete_project_context': {
            $id = (int)($input['id'] ?? 0);
            $projectId = (int)($input['project_id'] ?? 0);

            if ($id <= 0 || $projectId <= 0) {
                json_out(['ok' => false, 'error' => 'ID o proyecto inválido'], 400);
            }

            if (!project_owned($db_connection, $projectId, $userId)) {
                json_out(['ok' => false, 'error' => 'Sin permisos sobre este proyecto'], 403);
            }

            $stmt = $db_connection->prepare("
                DELETE FROM ProjectContext
                WHERE id_ = ? AND project_id_ = ?
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . $db_connection->error);
            }

            $stmt->bind_param('ii', $id, $projectId);
            $stmt->execute();
            $stmt->close();

            json_out(['ok' => true]);
        }

        // =====================================================
        // PROYECTO: VACIAR TODO SU CONTEXTO
        // =====================================================
        case 'clear_project_context': {
            $projectId = (int)($input['project_id'] ?? 0);

            if ($projectId <= 0) {
                json_out(['ok' => false, 'error' => 'Proyecto inválido'], 400);
            }

            if (!project_owned($db_connection, $projectId, $userId)) {
                json_out(['ok' => false, 'error' => 'Sin permisos sobre este proyecto'], 403);
            }

            $stmt = $db_connection->prepare("
                DELETE FROM ProjectContext WHERE project_id_ = ?
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . $db_connection->error);
            }

            $stmt->bind_param('i', $projectId);
            $stmt->execute();
            $stmt->close();

            json_out(['ok' => true]);
        }

        // =====================================================
        // SESIÓN: EDITAR RESUMEN MAESTRO
        // =====================================================
        case 'update_session_summary': {
            $sessionId = (int)($input['session_id'] ?? 0);
            $summary = trim((string)($input['context_summary'] ?? ''));

            if ($sessionId <= 0) {
                json_out(['ok' => false, 'error' => 'Sesión inválida'], 400);
            }

            if (!session_owned($db_connection, $sessionId, $userId)) {
                json_out(['ok' => false, 'error' => 'Sin permisos sobre esta sesión'], 403);
            }

            $stmt = $db_connection->prepare("
                UPDATE ChatSessions
                SET context_summary = ?
                WHERE id_ = ? AND user_id_ = ?
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . $db_connection->error);
            }

            $stmt->bind_param('sii', $summary, $sessionId, $userId);
            $stmt->execute();
            $stmt->close();

            json_out(['ok' => true]);
        }

        // =====================================================
        // SESIÓN: EDITAR BLOQUE ← AQUÍ ESTABA EL BUG
        // =====================================================
        case 'update_session_block': {
            $id = (int)($input['id'] ?? 0);
            $sessionId = (int)($input['session_id'] ?? 0);
            $blockType = trim((string)($input['block_type'] ?? 'level_0'));
            $contentPreview = trim((string)($input['content_preview'] ?? ''));

            debug_log('update_session_block', [
                'id' => $id,
                'sessionId' => $sessionId,
                'blockType' => $blockType,
                'contentPreview_length' => mb_strlen($contentPreview, 'UTF-8')
            ]);

            if ($id <= 0 || $sessionId <= 0) {
                json_out(['ok' => false, 'error' => 'ID o sesión inválido'], 400);
            }

            if (!session_owned($db_connection, $sessionId, $userId)) {
                json_out(['ok' => false, 'error' => 'Sin permisos sobre esta sesión'], 403);
            }

            $allowedBlockTypes = ['primordial', 'level_0', 'level_1', 'level_2', 'level_3'];
            if (!in_array($blockType, $allowedBlockTypes, true)) {
                $blockType = 'level_0';
            }

            // Calcular tokens si no se envió
            if (isset($input['token_count']) && is_numeric($input['token_count'])) {
                $tokenCount = (int)$input['token_count'];
            } else {
                $tokenCount = $contentPreview === ''
                    ? 0
                    : (int)ceil(mb_strlen($contentPreview, 'UTF-8') / 4);
            }

            // ✅ CORREGIDO: 'ssiii' (2 strings + 3 integers)
            $stmt = $db_connection->prepare("
                UPDATE SessionContextBlocks
                SET block_type = ?, content_preview = ?, token_count = ?
                WHERE id_ = ? AND session_id_ = ?
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . $db_connection->error);
            }

            // ✅ ORDEN CORRECTO DE TIPOS:
            // s = blockType (string)
            // s = contentPreview (string/BLOB)
            // i = tokenCount (integer)
            // i = id (integer)
            // i = sessionId (integer)
            $stmt->bind_param('ssiii', $blockType, $contentPreview, $tokenCount, $id, $sessionId);
            $ok = $stmt->execute();
            $affected = $stmt->affected_rows;
            $error = $stmt->error;
            $stmt->close();

            debug_log('update_session_block result', [
                'ok' => $ok,
                'affected' => $affected,
                'error' => $error
            ]);

            if (!$ok) {
                throw new RuntimeException('Execute failed: ' . $error);
            }

            json_out([
                'ok' => true,
                'message' => 'Bloque actualizado',
                'affected_rows' => $affected,
                'content_length' => mb_strlen($contentPreview, 'UTF-8')
            ]);
        }

        // =====================================================
        // SESIÓN: ELIMINAR UN BLOQUE
        // =====================================================
        case 'delete_session_block': {
            $id = (int)($input['id'] ?? 0);
            $sessionId = (int)($input['session_id'] ?? 0);

            if ($id <= 0 || $sessionId <= 0) {
                json_out(['ok' => false, 'error' => 'ID o sesión inválido'], 400);
            }

            if (!session_owned($db_connection, $sessionId, $userId)) {
                json_out(['ok' => false, 'error' => 'Sin permisos sobre esta sesión'], 403);
            }

            $stmt = $db_connection->prepare("
                DELETE FROM SessionContextBlocks
                WHERE id_ = ? AND session_id_ = ?
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . $db_connection->error);
            }

            $stmt->bind_param('ii', $id, $sessionId);
            $stmt->execute();
            $stmt->close();

            json_out(['ok' => true]);
        }

        // =====================================================
        // SESIÓN: VACIAR TODOS SUS BLOQUES 
        // =====================================================
        case 'clear_session_context': {
            $sessionId = (int)($input['session_id'] ?? 0);

            if ($sessionId <= 0) {
                json_out(['ok' => false, 'error' => 'Sesión inválida'], 400);
            }

            if (!session_owned($db_connection, $sessionId, $userId)) {
                json_out(['ok' => false, 'error' => 'Sin permisos sobre esta sesión'], 403);
            }

            $stmt = $db_connection->prepare("
                DELETE FROM SessionContextBlocks WHERE session_id_ = ?
            ");

            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . $db_connection->error);
            }

            $stmt->bind_param('i', $sessionId);
            $stmt->execute();
            $stmt->close();

            json_out(['ok' => true]);
        }

        default:
            json_out(['ok' => false, 'error' => 'Acción no reconocida: ' . $action], 400);
    }
} catch (Throwable $e) {
    debug_log('ERROR en context_actions', $e->getMessage());
    json_out([
        'ok' => false,
        'error' => 'Error del servidor: ' . $e->getMessage()
    ], 500);
}