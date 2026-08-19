<?php
declare(strict_types=1);

/**
 * get_ai_agents.php
 * Lista configuraciones de UserAIAgentConfigs y grupos disponibles.
 * - Filtro opcional: ?agent_group=chat
 * - Orden obligatorio: id_ ASC
 */

session_start();
header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (empty($_SESSION['usuario'])) {
    respond([
        'success' => false,
        'message' => 'No autorizado. Debes iniciar sesión.'
    ], 401);
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    respond([
        'success' => false,
        'message' => 'Sesión inválida: no se encontró user_id.'
    ], 401);
}

$isAdmin = ($userId === 1);
$groupFilter = trim((string)($_GET['agent_group'] ?? ''));

if (mb_strlen($groupFilter) > 50) {
    respond([
        'success' => false,
        'message' => 'El filtro agent_group excede 50 caracteres.'
    ], 422);
}

try {
    // Los grupos se obtienen de la columna real agent_group, nunca de una lista hardcodeada.
    if ($isAdmin) {
        $groupsSql = "SELECT DISTINCT agent_group
                      FROM UserAIAgentConfigs
                      WHERE user_id_ = 1
                      ORDER BY agent_group ASC";
        $groupsStmt = $db_connection->prepare($groupsSql);
    } else {
        $groupsSql = "SELECT DISTINCT agent_group
                      FROM UserAIAgentConfigs
                      WHERE (user_id_ = ? OR user_id_ = 1)
                      ORDER BY agent_group ASC";
        $groupsStmt = $db_connection->prepare($groupsSql);
        $groupsStmt->bind_param('i', $userId);
    }

    if (!$groupsStmt->execute()) {
        throw new RuntimeException('Error al consultar grupos: ' . $groupsStmt->error);
    }

    $groups = [];
    $groupsResult = $groupsStmt->get_result();
    while ($row = $groupsResult->fetch_assoc()) {
        $value = trim((string)($row['agent_group'] ?? ''));
        if ($value !== '') {
            $groups[] = $value;
        }
    }
    $groupsStmt->close();

    $columns = "id_, user_id_, agent_key, agent_group, display_name, description,
                model_id, fallback_model_id, model_ladder_json,
                system_instruction, user_prompt_template,
                temperature, max_tokens_prompt, max_tokens_output, top_p, seed,
                max_attempts, extra_config, token_usage_phase,
                is_active, sort_order, created_at, updated_at";

    if ($isAdmin) {
        if ($groupFilter !== '') {
            $sql = "SELECT {$columns}
                    FROM UserAIAgentConfigs
                    WHERE user_id_ = 1 AND agent_group = ?
                    ORDER BY id_ ASC";
            $stmt = $db_connection->prepare($sql);
            $stmt->bind_param('s', $groupFilter);
        } else {
            $sql = "SELECT {$columns}
                    FROM UserAIAgentConfigs
                    WHERE user_id_ = 1
                    ORDER BY id_ ASC";
            $stmt = $db_connection->prepare($sql);
        }
    } else {
        if ($groupFilter !== '') {
            $sql = "SELECT {$columns}
                    FROM UserAIAgentConfigs
                    WHERE (user_id_ = ? OR user_id_ = 1) AND agent_group = ?
                    ORDER BY id_ ASC";
            $stmt = $db_connection->prepare($sql);
            $stmt->bind_param('is', $userId, $groupFilter);
        } else {
            $sql = "SELECT {$columns}
                    FROM UserAIAgentConfigs
                    WHERE (user_id_ = ? OR user_id_ = 1)
                    ORDER BY id_ ASC";
            $stmt = $db_connection->prepare($sql);
            $stmt->bind_param('i', $userId);
        }
    }

    if (!$stmt->execute()) {
        throw new RuntimeException('Error al ejecutar consulta: ' . $stmt->error);
    }

    $agents = [];
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $agents[] = [
            'id_' => (int)$row['id_'],
            'user_id_' => (int)$row['user_id_'],
            'agent_key' => (string)$row['agent_key'],
            'agent_group' => (string)$row['agent_group'],
            'display_name' => (string)$row['display_name'],
            'description' => $row['description'],
            'model_id' => (string)$row['model_id'],
            'fallback_model_id' => $row['fallback_model_id'],
            'model_ladder_json' => $row['model_ladder_json'],
            'system_instruction' => $row['system_instruction'],
            'user_prompt_template' => $row['user_prompt_template'],
            'temperature' => $row['temperature'] !== null ? (float)$row['temperature'] : null,
            'max_tokens_prompt' => $row['max_tokens_prompt'] !== null ? (int)$row['max_tokens_prompt'] : null,
            'max_tokens_output' => $row['max_tokens_output'] !== null ? (int)$row['max_tokens_output'] : null,
            'top_p' => $row['top_p'] !== null ? (float)$row['top_p'] : null,
            'seed' => $row['seed'] !== null ? (int)$row['seed'] : 0,
            'max_attempts' => (int)$row['max_attempts'],
            'extra_config' => $row['extra_config'],
            'token_usage_phase' => $row['token_usage_phase'],
            'is_active' => (int)$row['is_active'],
            'sort_order' => (int)$row['sort_order'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
    $stmt->close();

    respond([
        'success' => true,
        'agents' => $agents,
        'groups' => $groups,
        'count' => count($agents),
        'filter' => $groupFilter,
        'order' => 'id_ ASC'
    ]);

} catch (Throwable $e) {
    error_log('GET_AI_AGENTS: ' . $e->getMessage());
    respond([
        'success' => false,
        'message' => 'Error interno al consultar configuraciones.'
    ], 500);
}
