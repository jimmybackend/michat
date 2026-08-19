<?php
/**
 * session_attachment_inspector.php
 * 
 * Endpoint que devuelve JSON con todos los datos de SessionContextBlocks
 * de tipo 'file' y 'file_chunk' para una sesión específica.
 * 
 * Uso: GET session_attachment_inspector.php?session_id=123
 */

// ✅ Capturar TODOS los errores y convertirlos a JSON
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function jexit(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

try {
    // Cargar bootstrap con manejo de errores
    $bootstrap = __DIR__ . '/app_bootstrap.php';
    if (!is_file($bootstrap)) {
        $bootstrap = __DIR__ . '/../app_bootstrap.php';
    }
    
    if (!is_file($bootstrap)) {
        jexit([
            'ok' => false, 
            'error' => 'app_bootstrap.php no encontrado',
            'debug' => [
                'current_dir' => __DIR__,
                'tried_paths' => [
                    __DIR__ . '/app_bootstrap.php',
                    __DIR__ . '/../app_bootstrap.php'
                ]
            ]
        ], 500);
    }
    
    require_once $bootstrap;

    $aiRuntimeFile = __DIR__ . '/includes/ai_agent_runtime.php';
    if (is_file($aiRuntimeFile)) {
        require_once $aiRuntimeFile;
    }
    
    function getSessionUserId(): int {
        $keys = ['user_id_', 'user_id', 'id_usuario', 'id_user', 'id'];
        foreach ($keys as $key) {
            if (isset($_SESSION[$key]) && ctype_digit((string)$_SESSION[$key])) {
                return (int)$_SESSION[$key];
            }
        }
        return 0;
    }
    
    if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
        jexit([
            'ok' => false, 
            'error' => 'Base de datos no disponible',
            'debug' => 'db_connection no está definida o no es mysqli'
        ], 500);
    }
    
    $userId = getSessionUserId();
    if ($userId <= 0) {
        jexit([
            'ok' => false, 
            'error' => 'Sesión inválida',
            'debug' => 'No se pudo obtener user_id de $_SESSION',
            'session_keys' => array_keys($_SESSION)
        ], 401);
    }
    
    $currentEmbeddingModel = '';
    if (function_exists('aiRuntimeLoad')) {
        try {
            aiRuntimeLoad($db_connection, $userId);
            if (aiAgentActive('embedding_main', false)) {
                $currentEmbeddingModel = aiAgentModel('embedding_main', '');
            }
        } catch (Throwable $e) {
            error_log('session_attachment_inspector aiRuntime: ' . $e->getMessage());
        }
    }

    $sessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
    if ($sessionId <= 0) {
        jexit([
            'ok' => false, 
            'error' => 'session_id inválido',
            'debug' => 'session_id debe ser un número mayor a 0'
        ], 400);
    }
    
    // Validar que la sesión pertenece al usuario
    $stmt = $db_connection->prepare("
        SELECT id_, user_id_, title
        FROM ChatSessions
        WHERE id_ = ? AND user_id_ = ?
        LIMIT 1
    ");
    
    if (!$stmt) {
        jexit([
            'ok' => false, 
            'error' => 'Error preparando consulta de sesión',
            'debug' => $db_connection->error
        ], 500);
    }
    
    $stmt->bind_param('ii', $sessionId, $userId);
    
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        jexit([
            'ok' => false, 
            'error' => 'Error ejecutando consulta de sesión',
            'debug' => $error
        ], 500);
    }
    
    $result = $stmt->get_result();
    $session = $result->fetch_assoc();
    $stmt->close();
    
    if (!$session) {
        jexit([
            'ok' => false, 
            'error' => 'La sesión no existe o no pertenece al usuario',
            'debug' => "session_id=$sessionId, user_id=$userId"
        ], 403);
    }
    
    // ✅ Verificar que las columnas necesarias existen
    $checkStmt = $db_connection->prepare("
        SELECT 
            id_,
            block_type,
            content_preview,
            s3_path,
            source_ids,
            token_count,
            embedding_model,
            created_at,
            embedding,
            embedding_json
        FROM SessionContextBlocks
        WHERE session_id_ = ?
          AND block_type IN ('file', 'file_chunk')
        LIMIT 1
    ");
    
    if (!$checkStmt) {
        jexit([
            'ok' => false,
            'error' => 'Error en consulta de verificación',
            'debug' => $db_connection->error,
            'hint' => 'Verifica que la tabla SessionContextBlocks tenga todas las columnas necesarias'
        ], 500);
    }
    
    $checkStmt->bind_param('i', $sessionId);
    
    if (!$checkStmt->execute()) {
        $error = $checkStmt->error;
        $checkStmt->close();
        jexit([
            'ok' => false,
            'error' => 'Error ejecutando consulta de verificación',
            'debug' => $error
        ], 500);
    }
    
    $checkStmt->close();
    
    // Obtener todos los bloques de tipo file y file_chunk
    $stmt = $db_connection->prepare("
        SELECT 
            id_,
            block_type,
            content_preview,
            s3_path,
            source_ids,
            token_count,
            embedding_model,
            created_at,
            CASE 
                WHEN embedding IS NOT NULL THEN 1 
                ELSE 0 
            END as has_embedding,
            CASE 
                WHEN embedding_json IS NOT NULL THEN JSON_LENGTH(embedding_json)
                ELSE 0 
            END as embedding_dimensions
        FROM SessionContextBlocks
        WHERE session_id_ = ?
          AND block_type IN ('file', 'file_chunk')
        ORDER BY s3_path, block_type, created_at ASC
    ");
    
    if (!$stmt) {
        jexit([
            'ok' => false, 
            'error' => 'Error preparando consulta de bloques',
            'debug' => $db_connection->error
        ], 500);
    }
    
    $stmt->bind_param('i', $sessionId);
    
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        jexit([
            'ok' => false, 
            'error' => 'Error ejecutando consulta de bloques',
            'debug' => $error
        ], 500);
    }
    
    $result = $stmt->get_result();
    $blocks = [];
    
    while ($row = $result->fetch_assoc()) {
        // Parsear source_ids si es JSON
        $sourceIds = [];
        if (!empty($row['source_ids'])) {
            $decoded = json_decode($row['source_ids'], true);
            if (is_array($decoded)) {
                $sourceIds = $decoded;
            }
        }
        
        $blocks[] = [
            'id' => (int)$row['id_'],
            'block_type' => $row['block_type'],
            'content_preview' => $row['content_preview'] ?? '',
            's3_path' => $row['s3_path'] ?? '',
            'filename' => $sourceIds['filename'] ?? basename($row['s3_path'] ?? 'archivo'),
            'files3_id' => $sourceIds['files3_id'] ?? null,
            'chunk_info' => isset($sourceIds['chunk']) ? [
                'current' => (int)$sourceIds['chunk'],
                'total' => (int)($sourceIds['total'] ?? 0)
            ] : null,
            'token_count' => (int)($row['token_count'] ?? 0),
            'has_embedding' => ((bool)$row['has_embedding']) && ($currentEmbeddingModel === '' || (string)($row['embedding_model'] ?? '') === $currentEmbeddingModel),
            'has_any_embedding' => (bool)$row['has_embedding'],
            'stale_embedding' => ((bool)$row['has_embedding']) && $currentEmbeddingModel !== '' && (string)($row['embedding_model'] ?? '') !== $currentEmbeddingModel,
            'embedding_dimensions' => (int)$row['embedding_dimensions'],
            'embedding_model' => $row['embedding_model'] ?? null,
            'created_at' => $row['created_at'],
        ];
    }
    
    $stmt->close();
    
    // Agrupar por archivo (s3_path)
    $grouped = [];
    foreach ($blocks as $block) {
        $key = $block['s3_path'] ?: 'unknown_' . $block['id'];
        
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                's3_path' => $block['s3_path'],
                'filename' => $block['filename'],
                'files3_id' => $block['files3_id'],
                'summary' => null,
                'chunks' => []
            ];
        }
        
        if ($block['block_type'] === 'file') {
            $grouped[$key]['summary'] = $block;
        } elseif ($block['block_type'] === 'file_chunk') {
            $grouped[$key]['chunks'][] = $block;
        }
    }
    
    // Convertir a array indexado
    $files = array_values($grouped);
    
    // Calcular estadísticas
    $totalChunks = 0;
    $chunksWithEmbedding = 0;
    $staleEmbeddings = 0;
    $totalTokens = 0;
    
    foreach ($files as $file) {
        $totalChunks += count($file['chunks']);
        foreach ($file['chunks'] as $chunk) {
            if ($chunk['has_embedding']) {
                $chunksWithEmbedding++;
            }
            if (!empty($chunk['stale_embedding'])) {
                $staleEmbeddings++;
            }
            $totalTokens += $chunk['token_count'];
        }
        if ($file['summary']) {
            $totalTokens += $file['summary']['token_count'];
            if (!empty($file['summary']['stale_embedding'])) {
                $staleEmbeddings++;
            }
        }
    }
    
    jexit([
        'ok' => true,
        'session_id' => $sessionId,
        'session_title' => $session['title'] ?? 'Sesión #' . $sessionId,
        'current_embedding_model' => $currentEmbeddingModel !== '' ? $currentEmbeddingModel : null,
        'stats' => [
            'total_files' => count($files),
            'total_chunks' => $totalChunks,
            'chunks_with_embedding' => $chunksWithEmbedding,
            'chunks_pending' => $totalChunks - $chunksWithEmbedding,
            'stale_embeddings' => $staleEmbeddings,
            'total_tokens' => $totalTokens
        ],
        'files' => $files
    ]);
    
} catch (Throwable $e) {
    jexit([
        'ok' => false,
        'error' => 'Excepción no capturada',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 5)
    ], 500);
}