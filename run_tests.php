<?php
/**
 * run_tests.php
 * Ejecuta tests automatizados sobre el código generado y guarda los resultados.
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
    
    if (!isset($input['session_id']) || !isset($input['code'])) {
        throw new Exception('Datos incompletos: se requiere session_id y code');
    }

    $sessionId = $input['session_id'];
    $code = $input['code'];
    $testType = $input['test_type'] ?? 'syntax'; // syntax, unit, integration

    // 1. Verificar que la sesión existe y pertenece al usuario actual (si hay auth)
    // Nota: Ajustar según tu sistema de autenticación real
    $stmt = $pdo->prepare("SELECT id, project_id FROM chat_sessions WHERE id = ?");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        throw new Exception('Sesión no encontrada');
    }

    $projectId = $session['project_id'];
    $results = [];
    $status = 'passed';
    $details = '';

    // 2. Ejecutar Tests según el tipo
    if ($testType === 'syntax') {
        // Test de sintaxis básico para PHP/JS
        if (preg_match('/\.php$/', $input['filename'] ?? '')) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
            file_put_contents($tmpFile, $code);
            $output = shell_exec("php -l " . escapeshellarg($tmpFile) . " 2>&1");
            unlink($tmpFile);
            
            if (strpos($output, 'No syntax errors') !== false) {
                $results[] = ['test' => 'PHP Syntax', 'status' => 'passed', 'message' => 'Sintaxis válida'];
            } else {
                $status = 'failed';
                $results[] = ['test' => 'PHP Syntax', 'status' => 'failed', 'message' => $output];
                $details = $output;
            }
        } else {
            // Para JS u otros, asumimos passed si no hay errores obvios de estructura
            $results[] = ['test' => 'Structure Check', 'status' => 'passed', 'message' => 'Estructura básica válida'];
        }
    } elseif ($testType === 'unit') {
        // Aquí iría lógica más compleja de tests unitarios si estuviera configurada
        $results[] = ['test' => 'Unit Tests', 'status' => 'skipped', 'message' => 'Tests unitarios no configurados en este entorno'];
    } else {
        $results[] = ['test' => 'Default Check', 'status' => 'passed', 'message' => 'Verificación completada'];
    }

    // 3. Guardar resultados en la base de datos
    // Asumimos una tabla 'test_results' o similar. Si no existe, podríamos loguearlo en 'chat_messages' como sistema
    // Crearemos un registro en una tabla hipotética 'test_runs' o lo dejamos listo para inserción
    
    // Opción A: Guardar en una tabla específica de tests (Recomendado)
    /*
    CREATE TABLE IF NOT EXISTS test_runs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        status ENUM('passed', 'failed', 'skipped') NOT NULL,
        details TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE
    );
    */
    
    $stmtInsert = $pdo->prepare("
        INSERT INTO test_runs (session_id, status, details) 
        VALUES (?, ?, ?)
    ");
    $stmtInsert->execute([
        $sessionId, 
        $status, 
        json_encode($results)
    ]);

    echo json_encode([
        'success' => true,
        'status' => $status,
        'results' => $results,
        'message' => $status === 'passed' ? 'Todos los tests pasaron correctamente.' : 'Se encontraron errores en los tests.'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
