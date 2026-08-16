<?php
/**
 * get_ai_agents.php
 * 
 * API para obtener todas las configuraciones de agentes IA desde la tabla UserAIAgentConfigs
 */

session_start();
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';

// Validar sesión
if (!isset($_SESSION['usuario']) || empty($_SESSION['usuario'])) {
    echo json_encode([
        'success' => false,
        'message' => 'No autorizado. Debes iniciar sesión.'
    ]);
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 1);

try {
    // Consulta para obtener todos los agentes (admin ve todos, usuarios solo los suyos)
    $is_admin = ($user_id == 1);
    
    if ($is_admin) {
        // Admin puede ver todas las configuraciones (user_id_ = 1 son las globales)
        $query = "SELECT id_, agent_key, agent_group, display_name, system_instruction, 
                         user_prompt_template, model_id, temperature, 
                         max_tokens_output, top_p, seed, extra_config, is_active, sort_order
                  FROM UserAIAgentConfigs 
                  WHERE user_id_ = 1 AND is_active = 1 
                  ORDER BY agent_group ASC, sort_order ASC";
    } else {
        // Usuarios normales ven sus propias configs o las globales
        $query = "SELECT id_, agent_key, agent_group, display_name, system_instruction, 
                         user_prompt_template, model_id, temperature, 
                         max_tokens_output, top_p, seed, extra_config, is_active, sort_order
                  FROM UserAIAgentConfigs 
                  WHERE (user_id_ = ? OR user_id_ = 1) AND is_active = 1 
                  ORDER BY agent_group ASC, sort_order ASC";
    }
    
    $stmt = $db_connection->prepare($query);
    
    if (!$is_admin) {
        $stmt->bind_param("i", $user_id);
    }
    
    if (!$stmt->execute()) {
        throw new Exception("Error al ejecutar consulta: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $agents = [];
    
    while ($row = $result->fetch_assoc()) {
        // Los campos de la tabla UserAIAgentConfigs son los reales, sin embedding_model
        $agents[] = [
            'id_' => (int)$row['id_'],
            'agent_key' => $row['agent_key'] ?? '',
            'agent_group' => $row['agent_group'] ?? 'other',
            'display_name' => $row['display_name'] ?? $row['agent_key'],
            'system_instruction' => $row['system_instruction'] ?? '',
            'user_prompt_template' => $row['user_prompt_template'] ?? '',
            'model_id' => $row['model_id'] ?? null,
            'temperature' => isset($row['temperature']) ? (float)$row['temperature'] : 0.7,
            'max_tokens_output' => isset($row['max_tokens_output']) ? (int)$row['max_tokens_output'] : 2048,
            'top_p' => isset($row['top_p']) ? (float)$row['top_p'] : 1.0,
            'seed' => isset($row['seed']) ? (int)$row['seed'] : 0,
            'extra_config' => $row['extra_config'] ?? '{}',
            'is_active' => isset($row['is_active']) ? (int)$row['is_active'] : 1,
            'sort_order' => isset($row['sort_order']) ? (int)$row['sort_order'] : 0
        ];
    }
    
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'agents' => $agents,
        'count' => count($agents)
    ]);
    
} catch (Exception $e) {
    error_log("GET_AI_AGENTS: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor: ' . $e->getMessage()
    ]);
}
