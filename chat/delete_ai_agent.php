<?php
/**
 * delete_ai_agent.php
 * 
 * API para eliminar configuraciones de agentes IA de la tabla UserAIAgentConfigs
 * Solo administradores pueden eliminar configuraciones globales
 */

session_start();
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';

// Validar sesión y permisos de administrador
if (!isset($_SESSION['usuario']) || empty($_SESSION['usuario'])) {
    echo json_encode([
        'success' => false,
        'message' => 'No autorizado. Debes iniciar sesión.'
    ]);
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

// Solo admin (user_id = 1) puede eliminar configuraciones
if ($user_id !== 1) {
    echo json_encode([
        'success' => false,
        'message' => 'Acceso denegado. Solo administradores pueden eliminar configuraciones de agentes.'
    ]);
    exit;
}

// Validar método HTTP
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido. Use POST.'
    ]);
    exit;
}

// Leer JSON del body
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || empty($data['id_'])) {
    echo json_encode([
        'success' => false,
        'message' => 'ID de agente requerido'
    ]);
    exit;
}

try {
    $agent_id = (int)$data['id_'];
    
    // Verificar que el agente existe y es global (user_id_ = 1)
    $check_query = "SELECT id_, agent_key FROM UserAIAgentConfigs WHERE id_ = ? AND user_id_ = 1";
    $check_stmt = $db_connection->prepare($check_query);
    $check_stmt->bind_param("i", $agent_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows === 0) {
        throw new Exception("El agente no existe o no tienes permisos para eliminarlo");
    }
    
    $agent_data = $check_result->fetch_assoc();
    $check_stmt->close();
    
    // Eliminar el agente
    $delete_query = "DELETE FROM UserAIAgentConfigs WHERE id_ = ? AND user_id_ = 1";
    $delete_stmt = $db_connection->prepare($delete_query);
    $delete_stmt->bind_param("i", $agent_id);
    
    if (!$delete_stmt->execute()) {
        throw new Exception("Error al eliminar: " . $delete_stmt->error);
    }
    
    $affected_rows = $delete_stmt->affected_rows;
    $delete_stmt->close();
    
    if ($affected_rows > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Agente eliminado correctamente',
            'deleted_id' => $agent_id,
            'deleted_key' => $agent_data['agent_key']
        ]);
    } else {
        throw new Exception("No se pudo eliminar el agente");
    }
    
} catch (Exception $e) {
    error_log("DELETE_AI_AGENT: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error al eliminar: ' . $e->getMessage()
    ]);
}
