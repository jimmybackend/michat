<?php
/**
 * save_ai_agent.php
 * 
 * API para guardar/actualizar configuraciones de agentes IA en la tabla UserAIAgentConfigs
 * Solo administradores pueden modificar las configuraciones globales
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

// Solo admin (user_id = 1) puede guardar configuraciones
if ($user_id !== 1) {
    echo json_encode([
        'success' => false,
        'message' => 'Acceso denegado. Solo administradores pueden modificar configuraciones de agentes.'
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

if (!$data) {
    echo json_encode([
        'success' => false,
        'message' => 'Datos inválidos. Se espera JSON.'
    ]);
    exit;
}

// Validar CSRF token (opcional pero recomendado)
$csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!empty($csrf_token) && $csrf_token !== ($_SESSION['csrf_token'] ?? '')) {
    // No bloquear si el token no coincide por ahora, solo loguear
    error_log("SAVE_AI_AGENT: CSRF token mismatch");
}

try {
    // Validar campos requeridos (agent_key siempre es requerido, model_id solo para agentes de chat)
    if (empty($data['agent_key'])) {
        throw new Exception("El campo 'agent_key' es requerido");
    }
    
    // model_id es el único campo de modelo en UserAIAgentConfigs
    $model_id = isset($data['model_id']) && !empty($data['model_id']) ? trim($data['model_id']) : null;
    
    // Si es agente de chat, model_id es requerido
    $is_chat_agent = in_array($data['agent_group'] ?? '', ['chat_main', 'prompt_compiler']);
    if ($is_chat_agent && empty($model_id)) {
        throw new Exception("El campo 'model_id' es requerido para agentes de chat");
    }
    
    // Sanitizar datos
    $id_ = isset($data['id_']) && !empty($data['id_']) ? (int)$data['id_'] : null;
    $agent_key = trim($data['agent_key']);
    $agent_group = trim($data['agent_group'] ?? 'other');
    $display_name = trim($data['display_name'] ?? '');
    $sort_order = (int)($data['sort_order'] ?? 0);
    $temperature = (float)($data['temperature'] ?? 0.7);
    $max_tokens_output = (int)($data['max_tokens_output'] ?? 2048);
    $top_p = (float)($data['top_p'] ?? 0.9);
    $seed = (int)($data['seed'] ?? 0);
    $system_instruction = $data['system_instruction'] ?? null;
    $user_prompt_template = $data['user_prompt_template'] ?? null;
    $extra_config = is_array($data['extra_config']) 
        ? json_encode($data['extra_config'], JSON_UNESCAPED_UNICODE) 
        : (is_string($data['extra_config']) ? $data['extra_config'] : '{}');
    $is_active = isset($data['is_active']) && $data['is_active'] ? 1 : 0;
    
    // Validar que extra_config sea JSON válido
    if (is_string($extra_config)) {
        json_decode($extra_config);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Extra config debe ser JSON válido: " . json_last_error_msg());
        }
    }
    
    // Verificar si existe para decidir entre INSERT o UPDATE
    $check_query = "SELECT id_ FROM UserAIAgentConfigs WHERE agent_key = ? AND user_id_ = 1";
    $check_stmt = $db_connection->prepare($check_query);
    $check_stmt->bind_param("s", $agent_key);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $exists = $check_result->num_rows > 0;
    $check_stmt->close();
    
    if ($exists && $id_) {
        // Actualizar existente
        $query = "UPDATE UserAIAgentConfigs 
                  SET agent_group = ?, display_name = ?, sort_order = ?, model_id = ?, 
                      temperature = ?, max_tokens_output = ?, 
                      top_p = ?, seed = ?, system_instruction = ?, user_prompt_template = ?, 
                      extra_config = ?, is_active = ?, updated_at = NOW()
                  WHERE id_ = ? AND user_id_ = 1";
        
        $stmt = $db_connection->prepare($query);
        $stmt->bind_param(
            "ssissddssssi",
            $agent_group,
            $display_name,
            $sort_order,
            $model_id,
            $temperature,
            $max_tokens_output,
            $top_p,
            $seed,
            $system_instruction,
            $user_prompt_template,
            $extra_config,
            $is_active,
            $id_
        );
        
        $action = 'updated';
        
    } elseif (!$exists) {
        // Insertar nuevo
        $query = "INSERT INTO UserAIAgentConfigs 
                  (user_id_, agent_key, agent_group, display_name, sort_order, model_id, 
                   temperature, max_tokens_output, top_p, seed, 
                   system_instruction, user_prompt_template, extra_config, is_active, 
                   created_at, updated_at)
                  VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $stmt = $db_connection->prepare($query);
        $stmt->bind_param(
            "sssissddssssi",
            $agent_key,
            $agent_group,
            $display_name,
            $sort_order,
            $model_id,
            $temperature,
            $max_tokens_output,
            $top_p,
            $seed,
            $system_instruction,
            $user_prompt_template,
            $extra_config,
            $is_active
        );
        
        $action = 'created';
        
    } else {
        // Intento de actualizar con ID que no coincide con agent_key
        throw new Exception("El ID proporcionado no coincide con el agent_key especificado");
    }
    
    if (!$stmt->execute()) {
        throw new Exception("Error al guardar en BD: " . $stmt->error);
    }
    
    $affected_id = $id_ ?: $db_connection->insert_id;
    
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'message' => 'Agente ' . $action . ' correctamente',
        'action' => $action,
        'id' => $affected_id,
        'agent_key' => $agent_key
    ]);
    
} catch (Exception $e) {
    error_log("SAVE_AI_AGENT: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error al guardar: ' . $e->getMessage()
    ]);
}
