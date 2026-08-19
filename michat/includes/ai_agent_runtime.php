<?php
/**
 * includes/ai_agent_runtime.php
 *
 * Capa compartida para leer UserAIAgentConfigs desde cualquier proceso.
 *
 * Precedencia efectiva:
 *   1. configuración del usuario actual (user_id_ = usuario)
 *   2. configuración global (user_id_ = 1)
 *
 * No inicia sesión, no carga bootstrap y no abre conexiones.
 * El archivo que lo incluya debe proporcionar un mysqli válido y user_id.
 */

declare(strict_types=1);

if (!isset($GLOBALS['AI_AGENT_CONFIGS']) || !is_array($GLOBALS['AI_AGENT_CONFIGS'])) {
    $GLOBALS['AI_AGENT_CONFIGS'] = [];
}

if (!function_exists('loadDynamicAIAgentConfigs')) {
    function loadDynamicAIAgentConfigs(mysqli $db, int $userId): array
    {
        $configs = [];

        $sql = "SELECT
                    id_, user_id_, agent_key, agent_group, display_name, description,
                    model_id, fallback_model_id, model_ladder_json,
                    system_instruction, user_prompt_template,
                    temperature, max_tokens_prompt, max_tokens_output,
                    top_p, seed, max_attempts, extra_config,
                    token_usage_phase, is_active, sort_order
                FROM UserAIAgentConfigs
                WHERE user_id_ IN (1, ?)
                ORDER BY agent_key ASC, (user_id_ = ?) DESC, user_id_ ASC, id_ ASC";

        $stmt = $db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('No se pudo cargar UserAIAgentConfigs: ' . $db->error);
        }

        $stmt->bind_param('ii', $userId, $userId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Error ejecutando UserAIAgentConfigs: ' . $error);
        }

        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $key = trim((string)($row['agent_key'] ?? ''));
            if ($key === '' || isset($configs[$key])) {
                continue;
            }

            $extra = json_decode((string)($row['extra_config'] ?? ''), true);
            $ladder = json_decode((string)($row['model_ladder_json'] ?? ''), true);

            $row['_extra'] = is_array($extra) ? $extra : [];
            $row['_model_ladder'] = is_array($ladder) ? $ladder : [];
            $configs[$key] = $row;
        }

        $stmt->close();
        return $configs;
    }
}

if (!function_exists('aiRuntimeLoad')) {
    function aiRuntimeLoad(mysqli $db, int $userId): array
    {
        $configs = loadDynamicAIAgentConfigs($db, $userId);
        $GLOBALS['AI_AGENT_CONFIGS'] = $configs;
        return $configs;
    }
}

if (!function_exists('aiAgentConfig')) {
    function aiAgentConfig(string $agentKey): ?array
    {
        $configs = $GLOBALS['AI_AGENT_CONFIGS'] ?? [];
        return isset($configs[$agentKey]) && is_array($configs[$agentKey])
            ? $configs[$agentKey]
            : null;
    }
}

if (!function_exists('aiAgentValue')) {
    function aiAgentValue(string $agentKey, string $field, $default = null)
    {
        $cfg = aiAgentConfig($agentKey);
        if (
            !$cfg
            || !array_key_exists($field, $cfg)
            || $cfg[$field] === null
            || $cfg[$field] === ''
        ) {
            return $default;
        }
        return $cfg[$field];
    }
}

if (!function_exists('aiAgentActive')) {
    function aiAgentActive(string $agentKey, bool $default = true): bool
    {
        $cfg = aiAgentConfig($agentKey);
        if (!$cfg || !array_key_exists('is_active', $cfg)) {
            return $default;
        }
        return ((int)$cfg['is_active']) === 1;
    }
}

if (!function_exists('aiAgentModel')) {
    function aiAgentModel(string $agentKey, string $default = ''): string
    {
        return trim((string)aiAgentValue($agentKey, 'model_id', $default));
    }
}

if (!function_exists('aiAgentInstruction')) {
    function aiAgentInstruction(string $agentKey, string $default = ''): string
    {
        $cfg = aiAgentConfig($agentKey);
        if (!$cfg) {
            return $default;
        }

        // Esto permite activar/desactivar también bloques de instrucciones.
        if (isset($cfg['is_active']) && (int)$cfg['is_active'] !== 1) {
            return '';
        }

        $value = $cfg['system_instruction'] ?? null;
        return ($value === null || $value === '') ? $default : (string)$value;
    }
}

if (!function_exists('aiAgentUserTemplate')) {
    function aiAgentUserTemplate(string $agentKey, string $default = ''): string
    {
        $cfg = aiAgentConfig($agentKey);
        if (!$cfg) {
            return $default;
        }

        if (isset($cfg['is_active']) && (int)$cfg['is_active'] !== 1) {
            return '';
        }

        $value = $cfg['user_prompt_template'] ?? null;
        return ($value === null || $value === '') ? $default : (string)$value;
    }
}

if (!function_exists('aiAgentExtra')) {
    function aiAgentExtra(string $agentKey, string $name, $default = null)
    {
        $cfg = aiAgentConfig($agentKey);
        if (!$cfg) {
            return $default;
        }

        $extra = $cfg['_extra'] ?? [];
        if (!is_array($extra) || !array_key_exists($name, $extra)) {
            return $default;
        }

        return $extra[$name];
    }
}

if (!function_exists('aiRenderTemplate')) {
    function aiRenderTemplate(?string $template, array $vars = []): string
    {
        $template = (string)$template;
        if ($template === '') {
            return '';
        }

        $replace = [];
        foreach ($vars as $key => $value) {
            $replace['{{' . $key . '}}'] = (string)$value;
        }

        return strtr($template, $replace);
    }
}

if (!function_exists('aiRuntimeSnapshot')) {
    function aiRuntimeSnapshot(?array $keys = null): array
    {
        $keys = $keys ?? [
            'chat_main',
            'prompt_compiler',
            'embedding_main',
            'smart_memory_general',
            'smart_memory_code',
        ];

        $out = [];
        foreach ($keys as $key) {
            $cfg = aiAgentConfig((string)$key);
            $out[(string)$key] = [
                'model_id' => $cfg['model_id'] ?? null,
                'is_active' => isset($cfg['is_active']) ? (int)$cfg['is_active'] : null,
                'source_user_id' => isset($cfg['user_id_']) ? (int)$cfg['user_id_'] : null,
            ];
        }

        return $out;
    }
}
