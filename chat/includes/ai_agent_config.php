<?php
/**
 * ai_agent_config.php
 * 
 * Archivo centralizado para cargar las instrucciones y configuraciones de IA
 * desde la tabla UserAIAgentConfigs de la base de datos.
 * 
 * Reemplaza las variables hardcodeadas en bedrock_chat2.php con valores dinámicos
 * obtenidos de la BD, permitiendo administración centralizada de prompts.
 */

// Prevenir acceso directo y cargar bootstrap si es necesario
if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    // Usar la misma lógica de búsqueda que bedrock_chat2.php
    $bootstrap = __DIR__ . '/app_bootstrap.php';
    if (!is_file($bootstrap)) {
        $bootstrap = __DIR__ . '/../app_bootstrap.php';
    }
    if (!is_file($bootstrap)) {
        $bootstrap = __DIR__ . '/../../app_bootstrap.php';
    }
    
    if (is_file($bootstrap)) {
        require_once $bootstrap;
    } else {
        error_log("AI_AGENT_CONFIG: app_bootstrap.php no encontrado en ninguna ubicación esperada");
    }
}

// Validar que la conexión esté disponible
if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    error_log("AI_AGENT_CONFIG: Conexión a BD no disponible. Las configuraciones no se cargarán automáticamente.");
    // No hacer return/exit aquí para permitir carga manual si es necesario
}

/**
 * Carga todas las instrucciones desde la BD y las asigna a variables globales
 *
 * @param mysqli $db Conexión a la base de datos
 * @param int $user_id ID del usuario (por defecto 1 para configs globales)
 * @return array Array con todas las instrucciones cargadas
 */
function loadAIAgentConfigs(mysqli $db, int $user_id = 1): array {
    $configs = [];
    
    // Consulta principal: obtener todas las configuraciones activas
    $query = "SELECT id_, agent_key, agent_group, display_name, system_instruction, 
                     user_prompt_template, extra_config 
              FROM UserAIAgentConfigs 
              WHERE user_id_ = ? AND is_active = 1 
              ORDER BY sort_order ASC";
    
    $stmt = $db->prepare($query);
    if (!$stmt) {
        error_log("AI_AGENT_CONFIG: Error al preparar consulta - " . $db->error);
        return $configs;
    }
    
    $stmt->bind_param("i", $user_id);
    
    if (!$stmt->execute()) {
        error_log("AI_AGENT_CONFIG: Error al ejecutar consulta - " . $stmt->error);
        $stmt->close();
        return $configs;
    }
    
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $agent_key = $row['agent_key'];
        $configs[$agent_key] = [
            'id_' => $row['id_'],
            'agent_group' => $row['agent_group'],
            'display_name' => $row['display_name'],
            'system_instruction' => $row['system_instruction'] ?? null,
            'user_prompt_template' => $row['user_prompt_template'] ?? null,
            'extra_config' => json_decode($row['extra_config'] ?? '{}', true)
        ];
    }
    
    $stmt->close();
    
    // Asignar variables globales compatibles con bedrock_chat2.php
    // Mapeo de agent_key -> variable global
    
    // === COMPILER DE PROMPTS (prompt_compiler) ===
    if (isset($configs['prompt_compiler'])) {
        $GLOBALS['compilerSystemPrompt_instruction'] = $configs['prompt_compiler']['system_instruction'];
        $GLOBALS['compilerUserPrompt_template_instruction'] = $configs['prompt_compiler']['user_prompt_template'];
    }
    
    // === TEXT BLOCKS PARA COMPILER CONTEXT ===
    if (isset($configs['prompt_compiler_context_project_template'])) {
        $GLOBALS['compilerContext_projectTemplate'] = $configs['prompt_compiler_context_project_template']['system_instruction'];
    }
    if (isset($configs['prompt_compiler_context_project_none'])) {
        $GLOBALS['compilerContext_projectNone'] = $configs['prompt_compiler_context_project_none']['system_instruction'];
    }
    if (isset($configs['prompt_compiler_context_recent_header'])) {
        $GLOBALS['compilerContext_recentHeader'] = $configs['prompt_compiler_context_recent_header']['system_instruction'];
    }
    if (isset($configs['prompt_compiler_context_recent_item_template'])) {
        $GLOBALS['compilerContext_recentItemTemplate'] = $configs['prompt_compiler_context_recent_item_template']['system_instruction'];
    }
    if (isset($configs['prompt_compiler_context_recent_user_label'])) {
        $GLOBALS['compilerContext_userLabel'] = $configs['prompt_compiler_context_recent_user_label']['system_instruction'];
    }
    if (isset($configs['prompt_compiler_context_recent_assistant_label'])) {
        $GLOBALS['compilerContext_assistantLabel'] = $configs['prompt_compiler_context_recent_assistant_label']['system_instruction'];
    }
    if (isset($configs['prompt_compiler_context_session_template'])) {
        $GLOBALS['compilerContext_sessionTemplate'] = $configs['prompt_compiler_context_session_template']['system_instruction'];
    }
    if (isset($configs['prompt_compiler_fallback_template'])) {
        $GLOBALS['compiled_prompt_fallback_instruction'] = $configs['prompt_compiler_fallback_template']['system_instruction'];
    }
    
    // === CHAT MAIN - System Prompt Template ===
    if (isset($configs['chat_main'])) {
        $GLOBALS['chatMain_systemPrompt_template'] = $configs['chat_main']['system_instruction'];
        $GLOBALS['chatMain_extra_config'] = $configs['chat_main']['extra_config'];
    }
    
    // === CHAT MAIN - Text Blocks ===
    if (isset($configs['chat_main_base'])) {
        $GLOBALS['mainSystemPrompt_base_instruction'] = $configs['chat_main_base']['system_instruction'];
    }
    if (isset($configs['chat_main_tool_rules'])) {
        $GLOBALS['mainSystemPrompt_toolUseRules_instruction'] = $configs['chat_main_tool_rules']['system_instruction'];
    }
    if (isset($configs['chat_main_behavior_rules'])) {
        $GLOBALS['mainSystemPrompt_behaviorRules_instruction'] = $configs['chat_main_behavior_rules']['system_instruction'];
    }
    if (isset($configs['chat_main_procedural_template'])) {
        $GLOBALS['mainSystemPrompt_proceduralMemory_template'] = $configs['chat_main_procedural_template']['system_instruction'];
    }
    if (isset($configs['chat_main_procedural_item_template'])) {
        $GLOBALS['mainSystemPrompt_proceduralMemory_itemTemplate'] = $configs['chat_main_procedural_item_template']['system_instruction'];
    }
    
    // ✅ CORREGIDO: extra_config ya es un array, NO hacer json_decode() de nuevo
    if (isset($configs['chat_main_procedural_labels'])) {
        $proceduralLabels = $configs['chat_main_procedural_labels']['extra_config'] ?? [];
        $GLOBALS['mainSystemPrompt_proceduralMemory_typeLabels'] = $proceduralLabels['type_labels'] ?? [];
    }
    
    if (isset($configs['chat_main_session_memory_template'])) {
        $GLOBALS['mainSystemPrompt_sessionMemory_header_instruction'] = $configs['chat_main_session_memory_template']['system_instruction'];
        $GLOBALS['mainSystemPrompt_sessionMemory_dynamic_instruction'] = $configs['chat_main_session_memory_template']['system_instruction'];
    }
    if (isset($configs['chat_main_attachment_template'])) {
        $GLOBALS['mainSystemPrompt_attachment_header_instruction'] = $configs['chat_main_attachment_template']['system_instruction'];
        $GLOBALS['mainSystemPrompt_attachment_dynamic_instruction'] = $configs['chat_main_attachment_template']['system_instruction'];
    }
    if (isset($configs['chat_main_question_memory_template'])) {
        $GLOBALS['mainSystemPrompt_questionMemory_header_instruction'] = $configs['chat_main_question_memory_template']['system_instruction'];
        $GLOBALS['mainSystemPrompt_questionMemory_dynamic_instruction'] = $configs['chat_main_question_memory_template']['system_instruction'];
    }
    if (isset($configs['chat_main_project_instructions_template'])) {
        $GLOBALS['projectInstructions_wrapper_instruction'] = $configs['chat_main_project_instructions_template']['system_instruction'];
        $GLOBALS['mainSystemPrompt_projectInstructions_label_instruction'] = "[INSTRUCCIONES DEL PROYECTO]\n";
        $GLOBALS['mainSystemPrompt_projectInstructions_dynamic_label'] = "[INSTRUCCIONES DEL PROYECTO]\n";
    }
    if (isset($configs['chat_main_primordial_rules_template'])) {
        $GLOBALS['primordialRules_header_instruction'] = $configs['chat_main_primordial_rules_template']['system_instruction'];
    }
    if (isset($configs['chat_main_primordial_rule_item_template'])) {
        $GLOBALS['primordialRules_itemTemplate'] = $configs['chat_main_primordial_rule_item_template']['system_instruction'];
    }
    if (isset($configs['chat_main_rag_context_template'])) {
        $GLOBALS['mainSystemPrompt_ragContext_header_instruction'] = $configs['chat_main_rag_context_template']['system_instruction'];
        $GLOBALS['mainSystemPrompt_ragContext_dynamic_instruction'] = $configs['chat_main_rag_context_template']['system_instruction'];
    }
    
    // === SUMMARIZE QA ===
    if (!isset($GLOBALS['summarizeQA_instruction'])) {
        $GLOBALS['summarizeQA_instruction'] = "Eres un motor de memoria inteligente. Resume la siguiente pregunta y respuesta en un bloque de conocimiento conciso (máximo 250 palabras).\n\nREGLAS:\n1. Detecta el TIPO de contenido y adapta el formato:\n   - Si es PROGRAMACIÓN: incluye objetivo, solución técnica, archivos/funciones clave, decisiones y fragmentos de código relevantes.\n   - Si es HISTORIA/CULTURA/CIENCIA: incluye tema, datos clave, personajes, fechas, lugares.\n   - Si es TRIVIAL o SALUDO: resume en 1 línea.\n2. REGLA CRÍTICA: NUNCA omitas valores de variables, rutas de archivos, puertos, IPs, nombres de funciones o credenciales mencionadas. Preserva los datos técnicos exactos (strings, números, rutas) intactos.\n3. NO uses campos de programación para temas de cultura general.\n4. No uses markdown, solo texto plano.\n5. Responde en el mismo idioma que el contenido original.\n6. Sé conciso pero técnicamente preciso.";
    }
    
    // === ATTACHMENT ONLY MESSAGE ===
    if (!isset($GLOBALS['attachmentOnly_userMessage_instruction'])) {
        $GLOBALS['attachmentOnly_userMessage_instruction'] = "Analiza los archivos adjuntos y respóndeme en español.";
    }
    
    // === ATTACHMENT LABELS ===
    if (!isset($GLOBALS['attachmentFileSummary_label_instruction'])) {
        $GLOBALS['attachmentFileSummary_label_instruction'] = "[RESUMEN DE ARCHIVO ADJUNTO - {FILENAME}]";
    }
    if (!isset($GLOBALS['attachmentFileChunk_label_instruction'])) {
        $GLOBALS['attachmentFileChunk_label_instruction'] = "[FRAGMENTO DE ARCHIVO ADJUNTO - {FILENAME}]";
    }
    
    // === SESSION BUILD CONTEXT LABELS ===
    if (!isset($GLOBALS['buildSessionBaseContext_label_instruction'])) {
        $GLOBALS['buildSessionBaseContext_label_instruction'] = "=== CONTEXTO DE LA CONVERSACIÓN DE ESTA SESIÓN ===\n";
    }
    if (!isset($GLOBALS['buildSessionAttachmentContext_always_label_instruction'])) {
        $GLOBALS['buildSessionAttachmentContext_always_label_instruction'] = "=== ARCHIVOS ADJUNTOS DE ESTA SESIÓN (MODO SIEMPRE) ===\n";
    }
    if (!isset($GLOBALS['buildSessionAttachmentContext_rag_label_instruction'])) {
        $GLOBALS['buildSessionAttachmentContext_rag_label_instruction'] = "=== ARCHIVOS ADJUNTOS RELEVANTES PARA ESTA PREGUNTA ===\n";
    }
    
    error_log("AI_AGENT_CONFIG: Configuraciones cargadas exitosamente. Total: " . count($configs));
    return $configs;
}

/**
 * Obtiene una configuración específica por agent_key
 *
 * @param mysqli $db Conexión a la base de datos
 * @param string $agent_key Clave del agente (ej: 'prompt_compiler', 'chat_main')
 * @param int $user_id ID del usuario
 * @return array|null Configuración del agente o null si no existe
 */
function getAIAgentConfig(mysqli $db, string $agent_key, int $user_id = 1): ?array {
    $query = "SELECT id_, agent_key, agent_group, display_name, system_instruction, 
                     user_prompt_template, model_id, temperature, max_tokens_output, extra_config 
              FROM UserAIAgentConfigs 
              WHERE user_id_ = ? AND agent_key = ? AND is_active = 1";
    
    $stmt = $db->prepare($query);
    if (!$stmt) {
        error_log("AI_AGENT_CONFIG: Error al preparar consulta para {$agent_key} - " . $db->error);
        return null;
    }
    
    $stmt->bind_param("is", $user_id, $agent_key);
    
    if (!$stmt->execute()) {
        error_log("AI_AGENT_CONFIG: Error al ejecutar consulta para {$agent_key} - " . $stmt->error);
        $stmt->close();
        return null;
    }
    
    $result = $stmt->get_result();
    $config = $result->fetch_assoc();
    $stmt->close();
    
    if ($config) {
        $config['extra_config'] = json_decode($config['extra_config'] ?? '{}', true);
    }
    
    return $config;
}

/**
 * Obtiene un text_block específico por agent_key
 * Útil para obtener plantillas individuales (ej: chat_main_base, chat_main_tool_rules)
 *
 * @param mysqli $db Conexión a la base de datos
 * @param string $agent_key Clave del text_block
 * @param int $user_id ID del usuario
 * @return string|null Contenido del text_block o null si no existe
 */
function getTextBlock(mysqli $db, string $agent_key, int $user_id = 1): ?string {
    $config = getAIAgentConfig($db, $agent_key, $user_id);
    return $config ? ($config['system_instruction'] ?? null) : null;
}

// ============================================================================
// AUTO-EJECUCIÓN: Cargar configuraciones si $db_connection está disponible
// ============================================================================
if (isset($db_connection) && $db_connection instanceof mysqli) {
    $GLOBALS['ai_agent_configs'] = loadAIAgentConfigs($db_connection);
    if (!defined('AI_AGENT_CONFIGS_LOADED')) {
        define('AI_AGENT_CONFIGS_LOADED', true);
    }
}
