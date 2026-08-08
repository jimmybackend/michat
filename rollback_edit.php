<?php
/**
 * rollback_edit.php
 * Revierte un cambio específico en el código a una versión anterior.
 */

header('Content-Type: application/json');

// Configuración de CORS y seguridad básica
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit();
}

// Incluir configuración de base de datos
require_once 'config.php'; // Asumiendo que config.php existe con $pdo

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['session_id']) || !isset($input['edit_id'])) {
        throw new Exception('Datos incompletos: se requiere session_id y edit_id');
    }

    $sessionId = $input['session_id'];
    $editId = $input['edit_id']; // ID del registro en code_versions o similar que queremos revertir
    $userId = $input['user_id'] ?? null; // Si hay autenticación

    // 1. Verificar que la sesión existe
    $stmtSession = $pdo->prepare("SELECT id, project_id FROM chat_sessions WHERE id = ?");
    $stmtSession->execute([$sessionId]);
    $session = $stmtSession->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        throw new Exception('Sesión no encontrada');
    }

    $projectId = $session['project_id'];

    // 2. Obtener la versión anterior (rollback target)
    // Asumimos una tabla 'code_versions' que guarda el historial de cambios
    /*
    CREATE TABLE IF NOT EXISTS code_versions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        version_number INT NOT NULL,
        code_content TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE
    );
    */
    
    // Obtenemos la versión actual y la anterior basada en el edit_id proporcionado
    // El edit_id debería ser el ID de la versión a la que queremos volver (o la actual para buscar la anterior)
    
    $stmtVersion = $pdo->prepare("
        SELECT id, version_number, code_content 
        FROM code_versions 
        WHERE id = ? AND session_id = ?
    ");
    $stmtVersion->execute([$editId, $sessionId]);
    $targetVersion = $stmtVersion->fetch(PDO::FETCH_ASSOC);

    if (!$targetVersion) {
        // Si no encontramos el edit_id como objetivo, quizás se refiere a "revertir EL edit_id"
        // Buscamos la versión ANTERIOR a ese edit_id
        $stmtCurrent = $pdo->prepare("
            SELECT id, version_number, code_content 
            FROM code_versions 
            WHERE id = ? AND session_id = ?
        ");
        $stmtCurrent->execute([$editId, $sessionId]);
        $currentEdit = $stmtCurrent->fetch(PDO::FETCH_ASSOC);

        if ($currentEdit) {
            $prevVersionNum = $currentEdit['version_number'] - 1;
            $stmtPrev = $pdo->prepare("
                SELECT id, version_number, code_content 
                FROM code_versions 
                WHERE session_id = ? AND version_number = ?
            ");
            $stmtPrev->execute([$sessionId, $prevVersionNum]);
            $targetVersion = $stmtPrev->fetch(PDO::FETCH_ASSOC);
        }
    }

    if (!$targetVersion) {
        throw new Exception('No se encontró una versión anterior para realizar el rollback');
    }

    $rollbackCode = $targetVersion['code_content'];
    $newVersionNumber = $targetVersion['version_number'] + 1; // Nueva versión es la siguiente secuencial

    // 3. Insertar el código revertido como una nueva versión
    $stmtInsert = $pdo->prepare("
        INSERT INTO code_versions (session_id, version_number, code_content)
        VALUES (?, ?, ?)
    ");
    $stmtInsert->execute([
        $sessionId,
        $newVersionNumber,
        $rollbackCode
    ]);

    $newEditId = $pdo->lastInsertId();

    // 4. Opcional: Registrar el evento de rollback en chat_messages como mensaje de sistema
    $systemMessage = "Se ha realizado un rollback a la versión #" . $targetVersion['version_number'] . ". Nueva versión creada: #" . $newVersionNumber;
    
    $stmtMsg = $pdo->prepare("
        INSERT INTO chat_messages (session_id, role, content, created_at)
        VALUES (?, 'system', ?, NOW())
    ");
    $stmtMsg->execute([$sessionId, $systemMessage]);

    echo json_encode([
        'success' => true,
        'message' => 'Rollback realizado con éxito',
        'previous_version' => $targetVersion['version_number'],
        'new_version' => $newVersionNumber,
        'new_edit_id' => $newEditId,
        'code_preview' => substr($rollbackCode, 0, 100) . '...' // Preview seguro
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
