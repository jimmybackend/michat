<?php
declare(strict_types=1);

/**
 * delete_ai_agent.php
 * Adapter HTTP para DELETE de configuraciones GLOBAL.
 */

session_start();
header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/includes/Chat/ChatIdentity.php';
require_once __DIR__ . '/includes/AI/AIAgentConfigRepository.php';
require_once __DIR__ . '/includes/AI/AIAgentConfigService.php';

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (empty($_SESSION['usuario'])) {
    respond(['success' => false, 'message' => 'No autorizado. Debes iniciar sesión.'], 401);
}

$userId = ChatIdentity::resolveUserId($db_connection);
if ($userId <= 0) respond(['success'=>false,'message'=>'Sesión inválida.'],401);
if (!ChatIdentity::canManageGlobalAiConfiguration()) respond(['success'=>false,'message'=>'Acceso denegado.'],403);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['success' => false, 'message' => 'Método no permitido. Use POST.'], 405);
}

$csrfToken = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$sessionToken = (string)($_SESSION['csrf_token'] ?? '');
if ($csrfToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $csrfToken)) {
    respond(['success' => false, 'message' => 'Token CSRF inválido o ausente.'], 403);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    respond(['success' => false, 'message' => 'Datos inválidos. Se espera JSON.'], 400);
}

$agentId = filter_var($data['id_'] ?? null, FILTER_VALIDATE_INT);
if ($agentId === false || $agentId <= 0) {
    respond(['success' => false, 'message' => 'ID de agente inválido.'], 422);
}

try {
    $agent=(new AIAgentConfigService(new AIAgentConfigRepository($db_connection)))->deleteGlobal((int)$agentId);
    respond([
        'success'=>true,'message'=>'Agente eliminado correctamente.','action'=>'deleted',
        'id_'=>(int)$agentId,'agent_key'=>(string)$agent['agent_key'],'display_name'=>(string)$agent['display_name'],
    ]);
} catch (OutOfBoundsException $e) {
    respond(['success'=>false,'message'=>'El agente GLOBAL no existe.'],404);
} catch (Throwable $e) {
    error_log('DELETE_AI_AGENT: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Error interno al eliminar la configuración.'], 500);
}
