<?php
// tools.php
// Ejecuta herramientas sobre las fuentes del proyecto: grep, view, search, str_replace
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

@ini_set('max_execution_time', '120');
@set_time_limit(120);

function jexit($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function resolve_root_candidates(): array {
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? (string)$_SERVER['DOCUMENT_ROOT'] : '';
    $rootFromDoc = $docRoot !== '' ? realpath($docRoot . '/..') : false;
    $candidates = [];
    foreach ([$rootFromDoc, realpath(__DIR__ . '/../../'), realpath(__DIR__ . '/../..'), realpath(__DIR__)] as $p) {
        if ($p && is_dir($p)) $candidates[$p] = true;
    }
    return array_keys($candidates);
}

function find_file_in_candidates(string $filename, array $bases, array $subfolders): ?string {
    $filename = ltrim($filename, '/');
    foreach ($bases as $base) {
        foreach ($subfolders as $sub) {
            $sub = ($sub === '' ? '' : '/' . trim($sub,'/'));
            $try = rtrim($base,'/') . $sub . '/' . $filename;
            if (is_file($try)) return $try;
        }
    }
    return null;
}

// ✅ Función auxiliar para similitud coseno (para búsqueda semántica en MySQL)
function cosineSimilarity(array $vecA, array $vecB): float {
    $dotProduct = 0.0; $normA = 0.0; $normB = 0.0;
    $count = min(count($vecA), count($vecB));
    for ($i = 0; $i < $count; $i++) {
        $dotProduct += $vecA[$i] * $vecB[$i];
        $normA += $vecA[$i] * $vecA[$i];
        $normB += $vecB[$i] * $vecB[$i];
    }
    return ($normA == 0 || $normB == 0) ? 0.0 : $dotProduct / (sqrt($normA) * sqrt($normB));
}

// ✅ Función para registrar el uso de herramientas
function logToolCall(mysqli $db, int $sessionId, int $msgId, string $tool, array $params, string $result, string $status, int $durationMs) {
    try {
        $sql = "INSERT INTO ToolCalls (session_id_, message_id_, tool, params, result, status, duration_ms) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($sql);
        $paramsJson = json_encode($params, JSON_UNESCAPED_UNICODE);
        $stmt->bind_param('iissssi', $sessionId, $msgId, $tool, $paramsJson, $result, $status, $durationMs);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // Fallo silencioso en el log para no romper la herramienta
    }
}


try {
    $bootstrap = __DIR__ . '/app_bootstrap.php';
    if (!is_file($bootstrap)) $bootstrap = __DIR__ . '/../app_bootstrap.php';
    if (!is_file($bootstrap)) {
        $bootstrap = find_file_in_candidates('app_bootstrap.php', resolve_root_candidates(), ['', 'public_html', 'api']);
    }
    if (!$bootstrap || !is_file($bootstrap)) throw new RuntimeException('app_bootstrap.php no encontrado.');
    require_once $bootstrap;
} catch (Throwable $e) {
    jexit(['ok' => false, 'error' => 'bootstrap: ' . $e->getMessage()], 500);
}

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    jexit(['ok'=>false,'error'=>'DB no disponible'], 500);
}

$have_s3 = false;
try {
    $s3Path = __DIR__ . '/S3Manager.php';
    if (!is_file($s3Path)) $s3Path = __DIR__ . '/../S3Manager.php';
    if (is_file($s3Path)) { require_once $s3Path; $have_s3 = true; }
} catch (Throwable $e) {}

$user_id = $_SESSION['user_id'] ?? 0;
$tool = isset($_POST['tool']) ? trim($_POST['tool']) : '';
$project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
$message_id = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;

if ($tool === '' || $project_id <= 0) {
    jexit(['ok'=>false,'error'=>'Faltan parámetros: tool, project_id'], 400);
}

// Verificar proyecto
$stmtCheck = $db_connection->prepare("SELECT id_, name, root_prefix FROM Projects WHERE id_ = ? AND user_id_ = ?");
$stmtCheck->bind_param('ii', $project_id, $user_id);
$stmtCheck->execute();
$resCheck = $stmtCheck->get_result();
if ($resCheck->num_rows === 0) {
    $stmtCheck->close();
    jexit(['ok'=>false,'error'=>'Proyecto no encontrado o sin permisos'], 404);
}
$project = $resCheck->fetch_assoc();
$stmtCheck->close();

$startTime = microtime(true);
$resultData = [];
$status = 'ok';
$errorMsg = '';

try {
    /* ============================
    GREP: Buscar texto en chunks
    ============================ */
    if ($tool === 'grep') {
        $pattern = isset($_POST['pattern']) ? trim($_POST['pattern']) : '';
        if ($pattern === '') throw new Exception('Falta pattern');
        
        $sql = "SELECT sc.id_, sc.name, sc.chunk_type, sc.content, sc.start_line, sc.end_line, ps.filename
                FROM SourceChunks sc
                JOIN ProjectSources ps ON ps.id_ = sc.source_id_
                WHERE sc.project_id_ = ? AND sc.content LIKE ?
                LIMIT 30";
        $stmt = $db_connection->prepare($sql);
        $likePattern = '%' . $pattern . '%';
        $stmt->bind_param('is', $project_id, $likePattern);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $matches = [];
        while ($row = $res->fetch_assoc()) {
            $lines = explode("\n", $row['content']);
            $matchingLines = [];
            foreach ($lines as $i => $line) {
                if (stripos($line, $pattern) !== false) {
                    $matchingLines[] = ['line' => $row['start_line'] + $i, 'text' => trim($line)];
                }
            }
            if (!empty($matchingLines)) {
                $matches[] = [
                    'file' => $row['filename'],
                    'chunk_name' => $row['name'] ?: 'bloque',
                    'lines' => $matchingLines
                ];
            }
        }
        $stmt->close();
        $resultData = ['matches' => $matches, 'total_files' => count($matches)];
    }

    /* ============================
    VIEW: Ver contenido de un chunk
    ============================ */
    elseif ($tool === 'view') {
        $chunk_id = isset($_POST['chunk_id']) ? (int)$_POST['chunk_id'] : 0;
        if ($chunk_id <= 0) throw new Exception('chunk_id inválido');
        
        $sql = "SELECT sc.*, ps.filename FROM SourceChunks sc
                JOIN ProjectSources ps ON ps.id_ = sc.source_id_
                WHERE sc.id_ = ? AND sc.project_id_ = ?";
        $stmt = $db_connection->prepare($sql);
        $stmt->bind_param('ii', $chunk_id, $project_id);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($row = $res->fetch_assoc()) {
            $resultData = [
                'file' => $row['filename'],
                'name' => $row['name'],
                'type' => $row['chunk_type'],
                'lines' => $row['start_line'] . '-' . $row['end_line'],
                'content' => $row['content']
            ];
        } else {
            throw new Exception('Chunk no encontrado');
        }
        $stmt->close();
    }

    /* ============================
    SEARCH: Búsqueda semántica real con Embeddings
    ============================ */
    elseif ($tool === 'search') {
        $query = isset($_POST['query']) ? trim($_POST['query']) : '';
        if ($query === '') throw new Exception('Falta query');

        // 1. Obtener embedding de la query
        $region = defined('Config::REGION') ? Config::REGION : 'us-east-1';
        $creds = ['key' => getenv('AWS_ACCESS_KEY_ID') ?: Config::ACCESS_KEY, 'secret' => getenv('AWS_SECRET_ACCESS_KEY') ?: Config::SECRET_KEY];
        $bedrock = new Aws\BedrockRuntime\BedrockRuntimeClient(['region' => $region, 'version' => 'latest', 'credentials' => $creds]);
        
        $embedRes = $bedrock->invokeModel([
            'modelId' => 'amazon.titan-embed-text-v2:0',
            'contentType' => 'application/json', 'accept' => 'application/json',
            'body' => json_encode(['inputText' => mb_substr($query, 0, 8000)])
        ]);
        
// ✅ REGISTRO DE COSTOS: Embedding de búsqueda
$inputTokens = (int)($embedData['inputTextTokenCount'] ?? 0);
$outputTokens = 0; // Los modelos de embedding no generan tokens de salida de texto
try {
    $tcPhase = 'embedding';
    $tcModel = 'amazon.titan-embed-text-v2:0';
    $tcCost = ($inputTokens / 1000) * 0.0001; // Precio Titan Embed V2
    $tcDuration = 0; 
    $tcMsgId = $message_id ?: 0;
    
    $sqlTC = "INSERT INTO TokenUsage (session_id_, message_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmtTC = $db_connection->prepare($sqlTC);
    if ($stmtTC) {
        $stmtTC->bind_param("iissiddi", $session_id, $tcMsgId, $tcPhase, $tcModel, $inputTokens, $outputTokens, $tcCost, $tcDuration);
        $stmtTC->execute();
        $stmtTC->close();
    }
} catch (Throwable $e) {
    // ✅ LOG SEGURO: Escribe el error en un archivo local sin romper el flujo principal ni el JSON
    $logMsg = "[" . date('Y-m-d H:i:s') . "] " . basename(__FILE__) . " | " . $e->getMessage() . "\n";
    @file_put_contents(__DIR__ . '/token_usage_debug.log', $logMsg, FILE_APPEND | LOCK_EX);
}
        
        
        $queryVector = json_decode($embedRes['body']->getContent(), true)['embedding'] ?? [];

        if (empty($queryVector)) throw new Exception('No se pudo generar el embedding de la búsqueda');

        // 2. Obtener chunks del proyecto con sus embeddings
        $sql = "SELECT sc.id_, sc.name, sc.content, sc.start_line, sc.end_line, ps.filename, ce.embedding_json
                FROM SourceChunks sc
                JOIN ProjectSources ps ON ps.id_ = sc.source_id_
                JOIN ChunkEmbeddings ce ON ce.chunk_id_ = sc.id_
                WHERE sc.project_id_ = ?
                LIMIT 100"; // Limitamos a 100 para no saturar la memoria PHP
        $stmt = $db_connection->prepare($sql);
        $stmt->bind_param('i', $project_id);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $scored = [];
        while ($row = $res->fetch_assoc()) {
            $dbVector = json_decode($row['embedding_json'], true);
            if (is_array($dbVector) && count($dbVector) > 0) {
                $score = cosineSimilarity($queryVector, $dbVector);
                if ($score > 0.35) { // Umbral mínimo de relevancia
                    $scored[] = [
                        'score' => $score,
                        'file' => $row['filename'],
                        'name' => $row['name'],
                        'lines' => $row['start_line'] . '-' . $row['end_line'],
                        'preview' => mb_substr($row['content'], 0, 300) . '...'
                    ];
                }
            }
        }
        $stmt->close();

        // 3. Ordenar por score descendente
        usort($scored, function($a, $b) { return $b['score'] <=> $a['score']; });
        $resultData = ['results' => array_slice($scored, 0, 5)]; // Top 5
    }

    /* ============================
    STR_REPLACE: Reemplazar texto en S3
    ============================ */
    elseif ($tool === 'str_replace') {
        $source_id = isset($_POST['source_id']) ? (int)$_POST['source_id'] : 0;
        $old_text = isset($_POST['old_text']) ? $_POST['old_text'] : '';
        $new_text = isset($_POST['new_text']) ? $_POST['new_text'] : '';
        
        if ($source_id <= 0 || $old_text === '') throw new Exception('Faltan parámetros: source_id, old_text');
        if (!$have_s3 || !class_exists('S3Manager')) throw new Exception('S3Manager no disponible');
        
        $sql = "SELECT ps.*, p.root_prefix FROM ProjectSources ps
                JOIN Projects p ON p.id_ = ps.project_id_
                WHERE ps.id_ = ? AND ps.project_id_ = ?";
        $stmt = $db_connection->prepare($sql);
        $stmt->bind_param('ii', $source_id, $project_id);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if (!($source = $res->fetch_assoc())) {
            $stmt->close();
            throw new Exception('Fuente no encontrada');
        }
        $stmt->close();
        
        $manager = new S3Manager();
        $s3 = Config::getS3();
        $bucket = $manager->getBucket();
        
        $result = $s3->getObject(['Bucket' => $bucket, 'Key' => $source['s3_key']]);
        $content = (string)$result['Body'];
        
        $count = 0;
        $newContent = str_replace($old_text, $new_text, $content, $count);
        
        if ($count === 0) throw new Exception('El texto a reemplazar no se encontró en el archivo');
        
        $s3->putObject([
            'Bucket' => $bucket, 'Key' => $source['s3_key'], 'Body' => $newContent,
            'ContentType' => $source['mime_type'] ?: 'text/plain', 'ACL' => 'private'
        ]);
        
        $upd = $db_connection->prepare("UPDATE ProjectSources SET status = 'stale' WHERE id_ = ?");
        $upd->bind_param('i', $source_id);
        $upd->execute();
        $upd->close();
        
        $resultData = ['replacements' => $count, 'file' => $source['filename'], 'status' => 'marked_for_reindex'];
    }
    else {
        throw new Exception('Herramienta no válida: ' . $tool);
    }

} catch (Throwable $e) {
    $status = 'error';
    $errorMsg = $e->getMessage();
    $resultData = ['error' => $errorMsg];
}

$duration = round((microtime(true) - $startTime) * 1000);

// Registrar la llamada a la herramienta
logToolCall($db_connection, $session_id, $message_id, $tool, $_POST, json_encode($resultData, JSON_UNESCAPED_UNICODE), $status, $duration);

if ($status === 'error') {
    jexit(['ok' => false, 'error' => $errorMsg, 'tool' => $tool, 'duration_ms' => $duration], 400);
}

jexit([
    'ok' => true,
    'tool' => $tool,
    'data' => $resultData,
    'duration_ms' => $duration
]);