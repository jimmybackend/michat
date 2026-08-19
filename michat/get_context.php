<?php
// get_context.php (EN LA CARPETA RAÍZ)
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

// ✅ 1. Verificación de seguridad
if (!isset($_SESSION['usuario']) || empty($_SESSION['usuario'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

// ✅ 2. Bootstrap (en la raíz, igual que en tus otros archivos)
try {
    $bootstrap = __DIR__ . '/app_bootstrap.php';
    if (!is_file($bootstrap)) {
        throw new RuntimeException('app_bootstrap.php no encontrado en la raíz.');
    }
    require_once $bootstrap;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error de bootstrap: ' . $e->getMessage()]);
    exit;
}

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB no disponible']);
    exit;
}

// ✅ 3. Obtener parámetros
$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$sessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;

$response = [
    'ok' => true,
    'project_context' => [],
    'session_context' => []
];

try {
    // ✅ 4. Obtener contexto del Proyecto (Reglas, decisiones, hechos, etc.)
    if ($projectId > 0) {
        $stmt = $db_connection->prepare("
            SELECT id_, type, title, content, created_at 
            FROM ProjectContext 
            WHERE project_id_ = ? 
            ORDER BY FIELD(type, 'rule', 'decision', 'fact', 'style', 'todo', 'note'), created_at DESC
        ");
        $stmt->bind_param('i', $projectId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $response['project_context'][] = $row;
        }
        $stmt->close();
    }

 
// ✅ 5. Obtener contexto de la Sesión
if ($sessionId > 0) {
    // --- 🆕 NUEVO: Obtener el Resumen Maestro (ChatSessions) ---
    $stmtSession = $db_connection->prepare("
        SELECT title, context_summary, context_level, last_compressed_at 
        FROM ChatSessions 
        WHERE id_ = ? LIMIT 1
    ");
    $stmtSession->bind_param('i', $sessionId);
    $stmtSession->execute();
    $resSession = $stmtSession->get_result();
    if ($rowSession = $resSession->fetch_assoc()) {
        $response['session_summary'] = $rowSession; // Lo enviamos al frontend
    }
    $stmtSession->close();

    // --- Original: Obtener bloques individuales ---
    $stmt = $db_connection->prepare("
        SELECT id_, block_type, content_preview, s3_path, token_count, created_at
        FROM SessionContextBlocks
        WHERE session_id_ = ?
        ORDER BY FIELD(block_type, 'primordial', 'level_0', 'level_1', 'level_2', 'level_3') ASC, created_at DESC
    ");
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $response['session_context'][] = $row;
    }
    $stmt->close();
}

} catch (Exception $e) {
    $response['ok'] = false;
    $response['error'] = 'Error de base de datos: ' . $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);