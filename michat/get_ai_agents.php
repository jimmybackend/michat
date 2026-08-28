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

require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/includes/Chat/ChatIdentity.php';
require_once __DIR__ . '/includes/ai_agent_runtime.php';
require_once __DIR__ . '/includes/AI/AIAgentConfigRepository.php';

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

$userId = ChatIdentity::resolveUserId($db_connection);
if ($userId <= 0) {
    respond([
        'success' => false,
        'message' => 'Sesión inválida: no se encontró user_id.'
    ], 401);
}

$isAdmin = ChatIdentity::canManageGlobalAiConfiguration();
$groupFilter = trim((string)($_GET['agent_group'] ?? ''));

if (mb_strlen($groupFilter) > 50) {
    respond([
        'success' => false,
        'message' => 'El filtro agent_group excede 50 caracteres.'
    ], 422);
}

try {
    if ($isAdmin) {
        $rows = (new AIAgentConfigRepository($db_connection))->listGlobals($groupFilter);
    } else {
        $rows = array_values(aiRuntimeLoad($db_connection, $userId));
        if ($groupFilter !== '') $rows = array_values(array_filter($rows, static fn(array $row): bool => (string)($row['agent_group'] ?? '') === $groupFilter));
        usort($rows, static fn(array $a,array $b):int => ((int)$a['id_']) <=> ((int)$b['id_']));
    }
    $groups=[];
    $groupRows=$isAdmin?(new AIAgentConfigRepository($db_connection))->listGlobals():array_values(aiRuntimeLoad($db_connection,$userId));
    foreach($groupRows as$row){$group=trim((string)($row['agent_group']??''));if($group!==''&&!in_array($group,$groups,true))$groups[]=$group;}
    sort($groups,SORT_STRING);
    $agents=[];
    foreach($rows as$row){
        $agents[]=[
            'id_'=>(int)$row['id_'],'scope'=>(string)$row['scope'],
            'user_id_'=>$row['user_id_']===null?null:(int)$row['user_id_'],
            'agent_key'=>(string)$row['agent_key'],'agent_group'=>(string)$row['agent_group'],
            'display_name'=>(string)$row['display_name'],'description'=>$row['description'],
            'model_id'=>(string)$row['model_id'],'fallback_model_id'=>$row['fallback_model_id'],
            'model_ladder_json'=>$row['model_ladder_json'],'system_instruction'=>$row['system_instruction'],
            'user_prompt_template'=>$row['user_prompt_template'],
            'temperature'=>$row['temperature']!==null?(float)$row['temperature']:null,
            'max_tokens_prompt'=>$row['max_tokens_prompt']!==null?(int)$row['max_tokens_prompt']:null,
            'max_tokens_output'=>$row['max_tokens_output']!==null?(int)$row['max_tokens_output']:null,
            'top_p'=>$row['top_p']!==null?(float)$row['top_p']:null,'seed'=>$row['seed']!==null?(int)$row['seed']:0,
            'max_attempts'=>(int)$row['max_attempts'],'extra_config'=>$row['extra_config'],
            'token_usage_phase'=>$row['token_usage_phase'],'is_active'=>(int)$row['is_active'],
            'sort_order'=>(int)$row['sort_order'],'created_at'=>$row['created_at'],'updated_at'=>$row['updated_at'],
        ];
    }
    respond(['success'=>true,'agents'=>$agents,'groups'=>$groups,'count'=>count($agents),'filter'=>$groupFilter,'order'=>'id_ ASC']);

} catch (Throwable $e) {
    error_log('GET_AI_AGENTS: ' . $e->getMessage());
    respond([
        'success' => false,
        'message' => 'Error interno al consultar configuraciones.'
    ], 500);
}
