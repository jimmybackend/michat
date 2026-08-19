<?php
declare(strict_types=1);

/**
 * delete_ai_agent.php
 * DELETE de configuraciones globales (user_id_ = 1).
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
    respond(['success' => false, 'message' => 'No autorizado. Debes iniciar sesión.'], 401);
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId !== 1) {
    respond([
        'success' => false,
        'message' => 'Acceso denegado. Solo administradores pueden eliminar configuraciones globales.'
    ], 403);
}

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
    $checkStmt = $db_connection->prepare(
        'SELECT id_, agent_key, display_name FROM UserAIAgentConfigs WHERE id_ = ? AND user_id_ = 1 LIMIT 1'
    );
    $checkStmt->bind_param('i', $agentId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();

    if ($result->num_rows === 0) {
        $checkStmt->close();
        respond(['success' => false, 'message' => 'El agente no existe.'], 404);
    }

    $agent = $result->fetch_assoc();
    $checkStmt->close();

    $deleteStmt = $db_connection->prepare(
        'DELETE FROM UserAIAgentConfigs WHERE id_ = ? AND user_id_ = 1'
    );
    $deleteStmt->bind_param('i', $agentId);

    if (!$deleteStmt->execute()) {
        throw new RuntimeException('Error al eliminar: ' . $deleteStmt->error);
    }

    if ($deleteStmt->affected_rows !== 1) {
        $deleteStmt->close();
        throw new RuntimeException('La eliminación no afectó exactamente un registro.');
    }
    $deleteStmt->close();

    respond([
        'success' => true,
        'message' => 'Agente eliminado correctamente.',
        'action' => 'deleted',
        'id_' => (int)$agentId,
        'agent_key' => (string)$agent['agent_key'],
        'display_name' => (string)$agent['display_name']
    ]);

} catch (Throwable $e) {
    error_log('DELETE_AI_AGENT: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Error interno al eliminar la configuración.'], 500);
}
