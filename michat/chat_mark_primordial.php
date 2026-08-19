<?php
/**
 * chat_mark_primordial.php
 * Endpoint para marcar/desmarcar mensajes como Primordiales (Pilar 2 de Metacognición)
 * 
 * Recibe:
 *   - message_id (int): ID del mensaje en ChatMessages
 *   - action (string): 'mark' o 'unmark'
 * 
 * Devuelve:
 *   - JSON: { ok: true/false, error?: string }
 */

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

// Verificación de seguridad
if (!isset($_SESSION['usuario']) || empty($_SESSION['usuario'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

// Cargar bootstrap (conexión a BD)
try {
    $bootstrap = __DIR__ . '/app_bootstrap.php';
    if (!is_file($bootstrap)) $bootstrap = __DIR__ . '/../app_bootstrap.php';
    if (!$bootstrap || !is_file($bootstrap)) {
        throw new RuntimeException('app_bootstrap.php no encontrado.');
    }
    require_once $bootstrap;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Bootstrap: ' . $e->getMessage()]);
    exit;
}

// Validar conexión
if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Base de datos no disponible']);
    exit;
}

// Parámetros
$message_id = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
$action = isset($_POST['action']) ? trim($_POST['action']) : '';

if ($message_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'message_id inválido']);
    exit;
}

if ($action !== 'mark' && $action !== 'unmark') {
    echo json_encode(['ok' => false, 'error' => 'action debe ser "mark" o "unmark"']);
    exit;
}

try {
    // 1. Verificar que el mensaje existe y pertenece al usuario
    $user_id = 0;
    if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
        $user_id = (int)$_SESSION['user_id'];
    }

    $stmtCheck = $db_connection->prepare("SELECT id_, session_id_, role FROM ChatMessages WHERE id_ = ?");
    if (!$stmtCheck) {
        throw new Exception('Error preparando consulta: ' . $db_connection->error);
    }
    $stmtCheck->bind_param('i', $message_id);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();
    
    if (!$resCheck || !$resCheck->num_rows) {
        $stmtCheck->close();
        echo json_encode(['ok' => false, 'error' => 'Mensaje no encontrado']);
        exit;
    }
    
    $msgData = $resCheck->fetch_assoc();
    $stmtCheck->close();

    // 2. Actualizar is_primordial en ChatMessages
    $new_value = ($action === 'mark') ? 1 : 0;
    
    $stmtUpdate = $db_connection->prepare("UPDATE ChatMessages SET is_primordial = ? WHERE id_ = ?");
    if (!$stmtUpdate) {
        throw new Exception('Error preparando UPDATE: ' . $db_connection->error);
    }
    $stmtUpdate->bind_param('ii', $new_value, $message_id);
    
    if (!$stmtUpdate->execute()) {
        $stmtUpdate->close();
        throw new Exception('Error ejecutando UPDATE: ' . $db_connection->error);
    }
    $stmtUpdate->close();

    // 3. Actualizar is_locked en SessionContextBlocks (si existe un bloque asociado)
    //    Esto evita que la compresión de contexto elimine este mensaje
    $stmtBlock = $db_connection->prepare(
        "UPDATE SessionContextBlocks SET is_locked = ? WHERE answer_msg_id = ? OR question_msg_id = ?"
    );
    if ($stmtBlock) {
        $stmtBlock->bind_param('iii', $new_value, $message_id, $message_id);
        $stmtBlock->execute();
        $stmtBlock->close();
    }

    // 4. Si se está marcando como primordial, asegurar que el bloque sea de tipo 'primordial'
    if ($action === 'mark') {
        $stmtType = $db_connection->prepare(
            "UPDATE SessionContextBlocks SET block_type = 'primordial' WHERE answer_msg_id = ? OR question_msg_id = ?"
        );
        if ($stmtType) {
            $stmtType->bind_param('ii', $message_id, $message_id);
            $stmtType->execute();
            $stmtType->close();
        }
    }

    // Respuesta exitosa
    echo json_encode([
        'ok' => true,
        'message_id' => $message_id,
        'action' => $action,
        'is_primordial' => $new_value
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}