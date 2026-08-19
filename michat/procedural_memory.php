<?php
/**
 * procedural_memory.php
 * CRUD para UserProceduralMemory.
 *
 * GET    ?action=list              → lista memorias del usuario
 * POST   action=create             → crea una nueva
 * POST   action=update&id=N        → actualiza contenido/tipo/activo
 * POST   action=delete&id=N        → elimina
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/app_bootstrap.php';

function jexit($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function getUserId() {
    $keys = ['user_id_', 'user_id', 'id_usuario', 'id_user', 'id'];
    foreach ($keys as $k) {
        if (isset($_SESSION[$k]) && ctype_digit((string)$_SESSION[$k])) {
            return (int)$_SESSION[$k];
        }
    }
    return 0;
}

$userId = getUserId();
if ($userId <= 0) jexit(['ok' => false, 'error' => 'Sesión inválida'], 401);

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

// =====================================================================
// LISTAR
// =====================================================================
if ($action === 'list') {
    $stmt = $db_connection->prepare("
        SELECT id_, memory_type, content, confidence, is_active,
               source_session_id, created_at, updated_at
        FROM UserProceduralMemory
        WHERE user_id_ = ?
        ORDER BY is_active DESC, confidence DESC, updated_at DESC
        LIMIT 50
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();
    jexit(['ok' => true, 'memories' => $items]);
}

// =====================================================================
// CREAR
// =====================================================================
if ($action === 'create') {
    $content = trim($_POST['content'] ?? '');
    $type = $_POST['memory_type'] ?? 'rule';

    if (mb_strlen($content) < 10) {
        jexit(['ok' => false, 'error' => 'El contenido debe tener al menos 10 caracteres'], 400);
    }
    if (!in_array($type, ['preference','rule','pattern','correction','workflow'])) {
        $type = 'rule';
    }

    $stmt = $db_connection->prepare("
        INSERT INTO UserProceduralMemory (user_id_, memory_type, content, confidence, is_active)
        VALUES (?, ?, ?, 5, 1)
    ");
    $stmt->bind_param('iss', $userId, $type, $content);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        jexit(['ok' => false, 'error' => 'Error al crear: ' . $err], 500);
    }
    $newId = $db_connection->insert_id;
    $stmt->close();

    jexit(['ok' => true, 'id' => $newId, 'mensaje' => 'Memoria creada']);
}

// =====================================================================
// ACTUALIZAR
// =====================================================================
if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) jexit(['ok' => false, 'error' => 'ID inválido'], 400);

    $content = trim($_POST['content'] ?? '');
    $type = $_POST['memory_type'] ?? 'rule';
    $isActive = (int)($_POST['is_active'] ?? 1);

    if (mb_strlen($content) < 10) {
        jexit(['ok' => false, 'error' => 'El contenido debe tener al menos 10 caracteres'], 400);
    }
    if (!in_array($type, ['preference','rule','pattern','correction','workflow'])) {
        $type = 'rule';
    }

    // Verificar que pertenezca al usuario
    $chk = $db_connection->prepare("SELECT id_ FROM UserProceduralMemory WHERE id_ = ? AND user_id_ = ?");
    $chk->bind_param('ii', $id, $userId);
    $chk->execute();
    if ($chk->get_result()->num_rows === 0) {
        $chk->close();
        jexit(['ok' => false, 'error' => 'Memoria no encontrada o no es tuya'], 403);
    }
    $chk->close();

    $stmt = $db_connection->prepare("
        UPDATE UserProceduralMemory
        SET content = ?, memory_type = ?, is_active = ?, updated_at = NOW()
        WHERE id_ = ? AND user_id_ = ?
    ");
    $stmt->bind_param('ssiii', $content, $type, $isActive, $id, $userId);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        jexit(['ok' => false, 'error' => 'Error al actualizar: ' . $err], 500);
    }
    $stmt->close();

    jexit(['ok' => true, 'mensaje' => 'Memoria actualizada']);
}

// =====================================================================
// ELIMINAR
// =====================================================================
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) jexit(['ok' => false, 'error' => 'ID inválido'], 400);

    $chk = $db_connection->prepare("SELECT id_ FROM UserProceduralMemory WHERE id_ = ? AND user_id_ = ?");
    $chk->bind_param('ii', $id, $userId);
    $chk->execute();
    if ($chk->get_result()->num_rows === 0) {
        $chk->close();
        jexit(['ok' => false, 'error' => 'Memoria no encontrada o no es tuya'], 403);
    }
    $chk->close();

    $stmt = $db_connection->prepare("DELETE FROM UserProceduralMemory WHERE id_ = ? AND user_id_ = ?");
    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    $stmt->close();

    jexit(['ok' => true, 'mensaje' => 'Memoria eliminada']);
}

jexit(['ok' => false, 'error' => 'Acción no reconocida: ' . $action], 400);