<?php
/**
 * process_embedding_queue.php
 * 
 * Procesa la cola de EmbeddingJobs:
 *  1. Lee trabajos pendientes de EmbeddingJobs
 *  2. Obtiene el contenido según target_type (session_block, source_chunk, project_context)
 *  3. Llama a AWS Bedrock (Titan Embed V2) para generar el vector
 *  4. Guarda el vector en la tabla correspondiente
 *  5. Marca el job como completado o fallido
 * 
 * ✅ SMART MEMORY v2.0:
 *  - Para session_block: Nova Micro resume la conversación ANTES de vectorizar.
 *  - Detecta si es el mismo tema (similitud coseno > 75%) y FUSIONA el resumen.
 *  - Si es tema diverso, CREA un nuevo resumen conciso.
 *  - Sobrescribe los 8000 caracteres crudos con el resumen inteligente.
 *  - Registra costos de Nova Micro + Titan en TokenUsage.
 * 
 * ✅ REFACTORIZADO (2026-07-30):
 *  - logTokenUsage() única para todos los registros de costos.
 *  - selectModelForContent() para elegir modelo según contenido (código o general).
 *  - Precios unificados en getModelPricing().
 * 
 * Uso:
 *  - Desde navegador: process_embedding_queue.php?batch=10&key=TU_SECRET
 *  - Desde cron: php process_embedding_queue.php --batch=10 --secret=TU_SECRET
 * 
 * Compatible con PHP 7.x+
 */

// ===== Configuración =====
define('EMBEDDING_SECRET', 'Z1!xC6@vB3#nM8$kL4*jH9^gF2&dS7');
define('DEFAULT_BATCH_SIZE', 10);
define('MAX_EXECUTION_TIME', 300); // 5 minutos máximo
define('EMBEDDING_MODEL', 'amazon.titan-embed-text-v2:0');
define('EMBEDDING_DIMENSIONS', 1024);

// ✅ SMART MEMORY: Configuración de compresión inteligente
define('SMART_MEMORY_MODEL', 'amazon.nova-micro-v1:0'); // Modelo por defecto (ahora se usa selectModelForContent)
define('SIMILARITY_THRESHOLD', 0.75); // 75% de similitud = mismo tema → FUSIONAR
define('SMART_MEMORY_MIN_Q_LENGTH', 15); // Mínimo de caracteres en pregunta para activar IA
define('SMART_MEMORY_MIN_A_LENGTH', 50); // Mínimo de caracteres en respuesta para activar IA

// ===== Modelos para compilación (igual que en compress_session_context) =====
//define('COMPILER_MODEL', 'amazon.nova-micro-v1:0'); // Modelo por defecto para NO técnico
//define('MODEL_FOR_CODE', 'anthropic.claude-3-haiku-20240307-v1:0'); // Modelo para código

// ===== Modelos para compilación (100% ESTABLES, SIN BLOQUEOS LEGACY) =====
define('COMPILER_MODEL', 'amazon.nova-micro-v1:0'); // Texto NO técnico (Ultra barato: $0.035/1M)
define('MODEL_FOR_CODE', 'amazon.nova-lite-v1:0');  // Código PHP/JS/Python (Potente y estable: $0.06/1M)



// ===== Timeouts =====
@ini_set('max_execution_time', MAX_EXECUTION_TIME);
@set_time_limit(MAX_EXECUTION_TIME);
@ini_set('default_socket_timeout', '60');
@ignore_user_abort(true);

// ===== Detectar si es CLI o Web =====
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    
    $secret = isset($_GET['key']) ? trim($_GET['key']) : '';
    if ($secret !== EMBEDDING_SECRET) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Clave inválida']);
        exit;
    }
    
    $batchSize = isset($_GET['batch']) ? max(1, min(50, (int)$_GET['batch'])) : DEFAULT_BATCH_SIZE;
} else {
    $opts = getopt('', ['batch:', 'secret:']);
    $secret = isset($opts['secret']) ? trim($opts['secret']) : '';
    if ($secret !== EMBEDDING_SECRET) {
        fwrite(STDERR, "Error: clave inválida. Usa --secret=TU_SECRET\n");
        exit(1);
    }
    $batchSize = isset($opts['batch']) ? max(1, min(50, (int)$opts['batch'])) : DEFAULT_BATCH_SIZE;
}

// ===== Resultados =====
$results = [
    'ok' => true,
    'processed' => 0,
    'succeeded' => 0,
    'failed' => 0,
    'skipped' => 0,
    'details' => [],
    'duration_ms' => 0,
];
$startTime = microtime(true);

// ===== Helper: responder y salir =====
function finish($results, $isCli, $startTime) {
    $results['duration_ms'] = (int)((microtime(true) - $startTime) * 1000);
    if ($isCli) {
        echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo json_encode($results, JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ===== Cargar bootstrap =====
try {
    $bootstrap = __DIR__ . '/app_bootstrap.php';
    if (!is_file($bootstrap)) $bootstrap = __DIR__ . '/../app_bootstrap.php';
    if (!is_file($bootstrap)) {
        $bases = [
            realpath(__DIR__ . '/../../'),
            realpath(__DIR__ . '/../..'),
            realpath(__DIR__ . '/../../../'),
            realpath(__DIR__ . '/../'),
        ];
        foreach ($bases as $b) {
            if ($b && is_file($b . '/app_bootstrap.php')) {
                $bootstrap = $b . '/app_bootstrap.php';
                break;
            }
        }
    }
    if (!is_file($bootstrap)) throw new RuntimeException('app_bootstrap.php no encontrado.');
    require_once $bootstrap;
} catch (Throwable $e) {
    $results['ok'] = false;
    $results['error'] = 'bootstrap: ' . $e->getMessage();
    finish($results, $isCli, $startTime);
}

// ===== Validar DB =====
if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    $results['ok'] = false;
    $results['error'] = 'DB no disponible';
    finish($results, $isCli, $startTime);
}

// ===== Validar AWS SDK =====
if (!class_exists('Aws\\BedrockRuntime\\BedrockRuntimeClient')) {
    $results['ok'] = false;
    $results['error'] = 'AWS SDK no cargado (vendor/autoload.php)';
    finish($results, $isCli, $startTime);
}

// ===== Locking: evitar ejecución concurrente =====
$lockFile = sys_get_temp_dir() . '/embedding_queue.lock';
$lockFp = fopen($lockFile, 'w');
if (!$lockFp) {
    $results['ok'] = false;
    $results['error'] = 'No se pudo crear archivo de lock';
    finish($results, $isCli, $startTime);
}
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    fclose($lockFp);
    $results['ok'] = true;
    $results['message'] = 'Otro proceso ya está ejecutando la cola de embeddings';
    $results['skipped'] = -1;
    finish($results, $isCli, $startTime);
}

// ===== Inicializar cliente Bedrock =====
try {
    $region = (class_exists('Config') && defined('Config::REGION') && Config::REGION) ? Config::REGION : 'us-east-1';
    
    $ak = getenv('AWS_ACCESS_KEY_ID') ?: (defined('Config::ACCESS_KEY') ? Config::ACCESS_KEY : '');
    $sk = getenv('AWS_SECRET_ACCESS_KEY') ?: (defined('Config::SECRET_KEY') ? Config::SECRET_KEY : '');
    
    if (empty($ak) || empty($sk)) {
        throw new RuntimeException('Faltan credenciales AWS');
    }
    
    $bedrock = new Aws\BedrockRuntime\BedrockRuntimeClient([
        'region'      => $region,
        'version'     => 'latest',
        'credentials' => ['key' => $ak, 'secret' => $sk],
        'http'        => ['connect_timeout' => 10, 'timeout' => 60],
    ]);
} catch (Throwable $e) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    $results['ok'] = false;
    $results['error'] = 'Bedrock init: ' . $e->getMessage();
    finish($results, $isCli, $startTime);
}

// =====================================================================
// ✅ NUEVO: TABLA DE PRECIOS + FUNCIÓN ÚNICA DE REGISTRO DE COSTO
// (Misma lógica que compress_session_context.php)
// =====================================================================
/*function getModelPricing(string $modelId): array {
    $m = strtolower($modelId);

    if (strpos($m, 'titan-embed') !== false) {
        return ['input' => 0.0001, 'output' => 0]; // Precio por 1k tokens (0.0001 USD / 1k)
    }
    if (strpos($m, 'nova-micro') !== false) {
        return ['input' => 0.035, 'output' => 0.14];
    }
    if (strpos($m, 'nova-lite') !== false) {
        return ['input' => 0.06, 'output' => 0.24];
    }
    if (strpos($m, 'nova-pro') !== false) {
        return ['input' => 0.80, 'output' => 3.20];
    }
    if (strpos($m, 'claude-3-5-haiku') !== false || strpos($m, 'claude-3.5-haiku') !== false) {
        return ['input' => 0.80, 'output' => 4.00];
    }
    if (strpos($m, 'haiku') !== false) {
        // Claude 3 Haiku (u otro Haiku no catalogado explícitamente arriba)
        return ['input' => 0.25, 'output' => 1.25];
    }
    if (strpos($m, 'sonnet') !== false) {
        return ['input' => 3.00, 'output' => 15.00];
    }

    // Fallback conservador
    return ['input' => 0.25, 'output' => 1.25];
}

function calculateCost(string $modelId, int $inputTokens, int $outputTokens): float {
    $pricing = getModelPricing($modelId);
    // Para Titan, el precio es por 1k tokens, pero lo normalizamos a 1M para consistencia
    // Titan: 0.0001 por 1k => 0.1 por 1M. Pero en el código original usaban (inputTokens/1000)*0.0001.
    // Para mantener compatibilidad, la función getModelPricing devuelve precio por 1M (como los otros).
    // Entonces para Titan, el precio por 1M sería 0.1 (0.0001 * 1000).
    // Ajustamos la lógica para que si es Titan, usemos precio por 1M.
    $cost = ($inputTokens / 1000000 * $pricing['input']) + ($outputTokens / 1000000 * $pricing['output']);
    return round($cost, 6);
}*/


// =====================================================================
// ✅ TABLA DE PRECIOS BLINDADA (Solo modelos activos y estables en AWS)
// =====================================================================
function getModelPricing(string $modelId): array {
    $m = strtolower($modelId);

    // 1. Modelos de Embedding (Titan)
    if (strpos($m, 'titan-embed') !== false) {
        // Titan Embed V2 cuesta ~$0.0001 por 1k tokens.
        // Normalizado a 1 Millón para la fórmula: 0.0001 * 1000 = 0.10
        return ['input' => 0.10, 'output' => 0.00];
    }
    
    // 2. Modelos Amazon Nova (Nativos de AWS, sin restricciones "Legacy")
    if (strpos($m, 'nova-micro') !== false) {
        return ['input' => 0.035, 'output' => 0.14];
    }
    if (strpos($m, 'nova-lite') !== false) {
        return ['input' => 0.06, 'output' => 0.24];
    }
    if (strpos($m, 'nova-pro') !== false) {
        return ['input' => 0.80, 'output' => 3.20];
    }

    // 3. Fallback de seguridad máximo: 
    // Si por algún error llega un ID de Anthropic u otro desconocido, 
    // forzamos el precio de Nova Micro para evitar cobros sorpresa o fallos.
    return ['input' => 0.035, 'output' => 0.14];
}

function calculateCost(string $modelId, int $inputTokens, int $outputTokens): float {
    $pricing = getModelPricing($modelId);
    
    // Fórmula unificada y precisa: (Tokens / 1,000,000) * Precio por Millón
    $cost = ($inputTokens / 1000000 * $pricing['input']) + ($outputTokens / 1000000 * $pricing['output']);
    
    return round($cost, 6);
}
/**
 * ✅ FUNCIÓN ÚNICA DE REGISTRO DE USO/COSTO DE TOKENS.
 * (Misma que en compress_session_context.php)
 */
function logTokenUsage(
    mysqli $db,
    int $sessionId,
    ?int $msgId,
    string $phase,
    string $modelId,
    int $inputTokens,
    int $outputTokens,
    int $durationMs = 0
): void {
    try {
        $cost = calculateCost($modelId, $inputTokens, $outputTokens);

        $tcId = 0;
        $rs = $db->query("SELECT IFNULL(MAX(id_),0)+1 AS nxt FROM TokenUsage");
        if ($rs) { $tcId = (int)($rs->fetch_assoc()['nxt'] ?? 1); $rs->free(); }

        $sqlTC = "INSERT INTO TokenUsage (id_, session_id_, message_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmtTC = $db->prepare($sqlTC);
        if ($stmtTC) {
            $stmtTC->bind_param("iiissiidi", $tcId, $sessionId, $msgId, $phase, $modelId, $inputTokens, $outputTokens, $cost, $durationMs);
            $stmtTC->execute();
            $stmtTC->close();
        }
    } catch (Throwable $e) {
        @file_put_contents(__DIR__ . '/token_usage_debug.log', "[" . date('Y-m-d H:i:s') . "] logTokenUsage: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    }
}

// =====================================================================
// ✅ NUEVO: SELECCIÓN DE MODELO SEGÚN TIPO DE CONTENIDO (reutilizable)
// =====================================================================
function contentLooksLikeCode(string $text): bool {
    return (bool)preg_match('/\b(function|class|const|let|var|import|export|return|if\s*\(|echo|print|<\?php|=>|<div|<script|error|bug|c[oó]digo|archivo|file|script|variable|array|json|php|js|html|css|sql|query|database|bd|api|endpoint|config|route|controller|model)\b/i', $text);
}

function selectModelForContent(string $text): string {
    return contentLooksLikeCode($text) ? MODEL_FOR_CODE : COMPILER_MODEL;
}

// ===== Obtener trabajos pendientes =====
$db_connection->begin_transaction();

$stmt = $db_connection->prepare("
    SELECT id_, target_type, target_id, model_id, attempts
    FROM EmbeddingJobs
    WHERE status = 'pending' AND attempts < 3
    ORDER BY created_at ASC
    LIMIT ?
");
$batchSizeParam = $batchSize;
$stmt->bind_param('i', $batchSizeParam);
$stmt->execute();
$res = $stmt->get_result();
$jobs = [];
while ($row = $res->fetch_assoc()) {
    $jobs[] = $row;
}
$stmt->close();

// Marcar como processing (para que otro proceso no los tome)
if (!empty($jobs)) {
    $ids = array_column($jobs, 'id_');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    
    $upd = $db_connection->prepare("
        UPDATE EmbeddingJobs 
        SET status = 'processing', updated_at = NOW()
        WHERE id_ IN ($placeholders)
    ");
    $upd->bind_param($types, ...$ids);
    $upd->execute();
    $upd->close();
}

$db_connection->commit();

if (empty($jobs)) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    $results['message'] = 'No hay trabajos pendientes';
    finish($results, $isCli, $startTime);
}

$results['processed'] = count($jobs);

// ===== Función: convertir array de floats a blob binario (float32 little-endian) =====
function floatsToBinaryBlob(array $floats): string {
    $binary = '';
    foreach ($floats as $f) {
        $binary .= pack('g', (float)$f);
    }
    return $binary;
}

// =====================================================================
// ✅ SMART MEMORY: Función de Similitud Coseno
// =====================================================================
function cosineSimilarity(array $vecA, array $vecB): float {
    $dotProduct = 0.0;
    $normA = 0.0;
    $normB = 0.0;
    $count = min(count($vecA), count($vecB));
    for ($i = 0; $i < $count; $i++) {
        $dotProduct += $vecA[$i] * $vecB[$i];
        $normA += $vecA[$i] * $vecA[$i];
        $normB += $vecB[$i] * $vecB[$i];
    }
    if ($normA == 0 || $normB == 0) return 0.0;
    return $dotProduct / (sqrt($normA) * sqrt($normB));
}

// =====================================================================
// ✅ SMART MEMORY: Generar Embedding con Titan (reutilizable)
// =====================================================================
function generateTitanEmbedding($bedrock, string $text, string $modelId): array {
    $embedRes = $bedrock->invokeModel([
        'modelId' => $modelId,
        'contentType' => 'application/json',
        'accept' => 'application/json',
        'body' => json_encode([
            'inputText' => mb_substr($text, 0, 8000),
            'dimensions' => EMBEDDING_DIMENSIONS,
            'normalize' => true,
        ]),
    ]);
    $embedData = json_decode((string)$embedRes['body'], true);
    return [
        'embedding' => $embedData['embedding'] ?? [],
        'inputTokens' => (int)($embedData['inputTextTokenCount'] ?? 0),
    ];
}

// =====================================================================
// ✅ SMART MEMORY: IA de Compresión con Selección Dinámica de Modelo
// Ahora usa selectModelForContent() en lugar de regex interna.
// =====================================================================
function generateSmartSummary($bedrock, string $question, string $answer, ?string $existingSummary = null): array {
    // ✅ Selección de modelo centralizada
    $modelId = selectModelForContent($question . ' ' . $answer);

    if ($existingSummary !== null && trim($existingSummary) !== '') {
        // 🧠 MISMO TEMA: Fusionar resumen existente + nueva información
        $systemPrompt = "Eres un motor de memoria inteligente para un asistente de programación. Tu tarea es FUSIONAR un resumen existente con nueva información del mismo tema.
REGLAS:
1. Mantén los datos técnicos exactos (nombres de funciones, variables, rutas, decisiones).
2. REGLA CRÍTICA: NUNCA omitas valores de variables, rutas de archivos, puertos, IPs o credenciales mencionadas. Preserva los datos técnicos exactos intactos.
3. Elimina redundancias y actualiza el contexto si hay cambios.
4. El resultado debe ser un solo bloque de texto cohesivo, máximo 300 palabras.
5. No uses markdown, solo texto plano.
6. Responde en el mismo idioma que el contenido original.";
        
        $userPrompt = "RESUMEN EXISTENTE:\n{$existingSummary}\n\nNUEVA PREGUNTA:\n{$question}\n\nNUEVA RESPUESTA:\n{$answer}\n\nGenera el resumen fusionado actualizado:";
    } else {
        // 🧠 TEMA DIVERSO: Crear resumen nuevo desde cero
        $systemPrompt = "Eres un motor de memoria inteligente. Tu tarea es CREAR un resumen conciso de una interacción.
REGLAS:
1. Extrae el objetivo, la solución técnica y archivos/funciones clave.
2. REGLA CRÍTICA: NUNCA omitas valores de variables, rutas de archivos, puertos, IPs o credenciales mencionadas. Preserva los datos técnicos exactos intactos.
3. Máximo 250 palabras.
4. No uses markdown, solo texto plano.
5. Responde en el mismo idioma que el contenido original.";
        
        $userPrompt = "PREGUNTA:\n{$question}\n\nRESPUESTA:\n{$answer}\n\nGenera el resumen:";
    }

    $res = $bedrock->converse([
        'modelId' => $modelId,
        'messages' => [['role' => 'user', 'content' => [['text' => $userPrompt]]]],
        'system' => [['text' => $systemPrompt]],
        'inferenceConfig' => ['maxTokens' => 800, 'temperature' => 0.2, 'topP' => 0.9]
    ]);

    $text = '';
    foreach (($res['output']['message']['content'] ?? []) as $block) {
        if (isset($block['text'])) $text .= $block['text'];
    }

    return [
        'text' => trim($text),
        'inputTokens' => (int)($res['usage']['inputTokens'] ?? 0),
        'outputTokens' => (int)($res['usage']['outputTokens'] ?? 0),
        'model' => $modelId
    ];
}

// ===== Función: obtener contenido para generar embedding =====
function getContentForJob(mysqli $db, string $targetType, int $targetId): ?string {
    switch ($targetType) {
        case 'session_block':
            $stmt = $db->prepare("
                SELECT 
                    COALESCE(q.content, '') AS question_text,
                    COALESCE(a.content, '') AS answer_text,
                    COALESCE(scb.content_preview, '') AS preview
                FROM SessionContextBlocks scb
                LEFT JOIN ChatMessages q ON scb.question_msg_id = q.id_
                LEFT JOIN ChatMessages a ON scb.answer_msg_id = a.id_
                WHERE scb.id_ = ?
                LIMIT 1
            ");
            $stmt->bind_param('i', $targetId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $stmt->close();
            
            if (!$row) return null;
            
            $text = '';
            if (!empty($row['question_text'])) $text .= 'Pregunta: ' . $row['question_text'] . "\n\n";
            if (!empty($row['answer_text'])) $text .= 'Respuesta: ' . $row['answer_text'];
            if (empty($text) && !empty($row['preview'])) $text = $row['preview'];
            
            return $text ?: null;
            
        case 'source_chunk':
            $stmt = $db->prepare("
                SELECT content, name 
                FROM SourceChunks 
                WHERE id_ = ? 
                LIMIT 1
            ");
            $stmt->bind_param('i', $targetId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $stmt->close();
            
            if (!$row) return null;
            
            $text = '';
            if (!empty($row['name'])) $text .= '[' . $row['name'] . "]\n";
            $text .= $row['content'];
            
            return $text ?: null;
            
        case 'project_context':
            $stmt = $db->prepare("
                SELECT type, title, content 
                FROM ProjectContext 
                WHERE id_ = ? 
                LIMIT 1
            ");
            $stmt->bind_param('i', $targetId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $stmt->close();
            
            if (!$row) return null;
            
            $text = '';
            if (!empty($row['type'])) $text .= '[' . $row['type'] . '] ';
            if (!empty($row['title'])) $text .= $row['title'] . ': ';
            $text .= $row['content'];
            
            return $text ?: null;
            
        default:
            return null;
    }
}

// ===== Función: guardar embedding en la tabla correspondiente =====
function saveEmbedding(mysqli $db, string $targetType, int $targetId, array $embedding, string $modelId): bool {
    $binary = floatsToBinaryBlob($embedding);
    $json = json_encode($embedding);
    $dimensions = count($embedding);
    
    switch ($targetType) {
        case 'session_block':
            $stmt = $db->prepare("
                UPDATE SessionContextBlocks 
                SET embedding = ?, embedding_json = ?, embedding_model = ?
                WHERE id_ = ?
            ");
            $stmt->bind_param('bssi', $null, $json, $modelId, $targetId);
            $null = $binary;
            $stmt->send_long_data(0, $binary);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            return $affected > 0;
            
        case 'source_chunk':
            $stmt = $db->prepare("
                SELECT id_ FROM ChunkEmbeddings 
                WHERE chunk_id_ = ? AND model_id = ?
                LIMIT 1
            ");
            $stmt->bind_param('is', $targetId, $modelId);
            $stmt->execute();
            $res = $stmt->get_result();
            $existing = $res->fetch_assoc();
            $stmt->close();
            
            if ($existing) {
                $stmt = $db->prepare("
                    UPDATE ChunkEmbeddings 
                    SET embedding = ?, embedding_json = ?, dimensions = ?
                    WHERE id_ = ?
                ");
                $null = $binary;
                $stmt->bind_param('bsii', $null, $json, $dimensions, $existing['id_']);
                $stmt->send_long_data(0, $binary);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                return $affected >= 0;
            } else {
                $nextId = 0;
                $rs = $db->query("SELECT IFNULL(MAX(id_),0)+1 AS nxt FROM ChunkEmbeddings");
                if ($rs) {
                    $row = $rs->fetch_assoc();
                    $nextId = (int)($row['nxt'] ?? 1);
                    $rs->free();
                }
                
                $stmt = $db->prepare("
                    INSERT INTO ChunkEmbeddings 
                    (id_, chunk_id_, model_id, dimensions, embedding, embedding_json)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $null = $binary;
                $stmt->bind_param('iisiis', $nextId, $targetId, $modelId, $dimensions, $null, $json);
                $stmt->send_long_data(4, $binary);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                return $affected > 0;
            }
            
        case 'project_context':
            $stmt = $db->prepare("
                UPDATE ProjectContext 
                SET embedding = ?
                WHERE id_ = ?
            ");
            $stmt->bind_param('si', $json, $targetId);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            return $affected > 0;
            
        default:
            return false;
    }
}

// ===== Procesar cada job =====
foreach ($jobs as $job) {
    $jobId = (int)$job['id_'];
    $targetType = $job['target_type'];
    $targetId = (int)$job['target_id'];
    $modelId = $job['model_id'] ?: EMBEDDING_MODEL;
    $attempts = (int)$job['attempts'] + 1;
    
    $detail = [
        'job_id' => $jobId,
        'target_type' => $targetType,
        'target_id' => $targetId,
        'status' => 'unknown',
    ];
    
    try {
        // =================================================================
        // ✅ SMART MEMORY: FLUJO ESPECIAL PARA session_block
        // =================================================================
        if ($targetType === 'session_block') {
            // 1. OBTENER DATOS DEL BLOQUE
$stmtData = $db_connection->prepare("
    SELECT
        scb.session_id_,
        scb.answer_msg_id,
        scb.block_type,
        scb.content_preview,
        COALESCE(q.content, '') AS question_text,
        COALESCE(a.content, '') AS answer_text
    FROM SessionContextBlocks scb
    LEFT JOIN ChatMessages q ON scb.question_msg_id = q.id_
    LEFT JOIN ChatMessages a ON scb.answer_msg_id = a.id_
    WHERE scb.id_ = ?
    LIMIT 1
");           
            $stmtData->bind_param('i', $targetId);
            $stmtData->execute();
            $rowData = $stmtData->get_result()->fetch_assoc();
            $stmtData->close();

            if (!$rowData) throw new RuntimeException('Bloque de sesión #' . $targetId . ' no encontrado');

            $sessionId = (int)$rowData['session_id_'];
            $msgId = $rowData['answer_msg_id']; // Puede ser null
            $question = (string)$rowData['question_text'];
            $answer = (string)$rowData['answer_text'];

            // 2. OBTENER message_id_ REAL (para TokenUsage)
            $tcMsgId = $msgId;
            if ($tcMsgId === null) {
                // Intentar obtener el último mensaje de asistente
                $stmtAsst = $db_connection->prepare("
                    SELECT id_ FROM ChatMessages 
                    WHERE session_id_ = ? AND role = 'assistant'
                    ORDER BY created_at DESC LIMIT 1
                ");
                $stmtAsst->bind_param('i', $sessionId);
                $stmtAsst->execute();
                $resAsst = $stmtAsst->get_result();
                if ($rowAsst = $resAsst->fetch_assoc()) {
                    $tcMsgId = (int)$rowAsst['id_'];
                }
                $stmtAsst->close();
            }
            if ($tcMsgId === null) {
                $stmtUser = $db_connection->prepare("
                    SELECT id_ FROM ChatMessages 
                    WHERE session_id_ = ? AND role = 'user'
                    ORDER BY created_at DESC LIMIT 1
                ");
                $stmtUser->bind_param('i', $sessionId);
                $stmtUser->execute();
                $resUser = $stmtUser->get_result();
                if ($rowUser = $resUser->fetch_assoc()) {
                    $tcMsgId = (int)$rowUser['id_'];
                }
                $stmtUser->close();
            }


        // =====================================================
        // ✅ NUEVO: bloques de archivos adjuntos (file/file_chunk)
        // Se vectorizan DIRECTO, sin resumen Q&A de SMART MEMORY.
        // =====================================================
        $blockType = (string)($rowData['block_type'] ?? 'level_0');

        if ($blockType === 'file' || $blockType === 'file_chunk') {
            $fileText = (string)($rowData['content_preview'] ?? '');
            if (trim($fileText) === '') {
                throw new RuntimeException('Bloque de archivo sin contenido');
            }
            $fileText = mb_substr($fileText, 0, 30000);

            $embData = generateTitanEmbedding($bedrock, $fileText, $modelId);
            if (empty($embData['embedding'])) {
                throw new RuntimeException('Embedding de archivo vacío');
            }

            $binary = floatsToBinaryBlob($embData['embedding']);
            $json   = json_encode($embData['embedding']);

            $stmtUpd = $db_connection->prepare("
                UPDATE SessionContextBlocks
                SET embedding = ?, embedding_json = ?, embedding_model = ?
                WHERE id_ = ?
            ");
            $null = $binary;
            $stmtUpd->bind_param('bssi', $null, $json, $modelId, $targetId);
            $stmtUpd->send_long_data(0, $binary);
            $stmtUpd->execute();
            $stmtUpd->close();

            $upd = $db_connection->prepare("UPDATE EmbeddingJobs SET status = 'completed', attempts = ?, updated_at = NOW() WHERE id_ = ?");
            $upd->bind_param('ii', $attempts, $jobId);
            $upd->execute();
            $upd->close();

            if ($sessionId && $tcMsgId !== null) {
                logTokenUsage($db_connection, $sessionId, $tcMsgId, 'embedding', $modelId, $embData['inputTokens'], 0);
            }

            $detail['status'] = 'completed_file';
            $detail['dimensions'] = count($embData['embedding']);
            $results['succeeded']++;
            $results['details'][] = $detail;
            continue;
        }

            // 3. FILTRO DE TRIVIALIDAD
            $isTrivial = (mb_strlen($question) < SMART_MEMORY_MIN_Q_LENGTH && mb_strlen($answer) < SMART_MEMORY_MIN_A_LENGTH);

            if ($isTrivial) {
                // Flujo trivial: solo vectorizar sin resumen
                $trivialText = "P: $question\nR: $answer";
                $trivialText = mb_substr($trivialText, 0, 30000);
                $embData = generateTitanEmbedding($bedrock, $trivialText, $modelId);
                $embedding = $embData['embedding'];
                if (empty($embedding)) throw new RuntimeException('Embedding trivial vacío');

                saveEmbedding($db_connection, 'session_block', $targetId, $embedding, $modelId);

                $shortPreview = mb_substr($trivialText, 0, 300);
                $tokenCount = (int)ceil(mb_strlen($trivialText) / 4);
                $stmtUpd = $db_connection->prepare("UPDATE SessionContextBlocks SET content_preview = ?, token_count = ? WHERE id_ = ?");
                $stmtUpd->bind_param('sii', $shortPreview, $tokenCount, $targetId);
                $stmtUpd->execute();
                $stmtUpd->close();

                // Marcar job completado
                $upd = $db_connection->prepare("UPDATE EmbeddingJobs SET status = 'completed', attempts = ?, updated_at = NOW() WHERE id_ = ?");
                $upd->bind_param('ii', $attempts, $jobId);
                $upd->execute();
                $upd->close();

                // Registrar solo el embedding trivial usando logTokenUsage
                if ($sessionId && $tcMsgId !== null) {
                    logTokenUsage($db_connection, $sessionId, $tcMsgId, 'embedding', $modelId, $embData['inputTokens'], 0);
                }

                $detail['status'] = 'completed_trivial';
                $detail['dimensions'] = count($embedding);
                $results['succeeded']++;
                $results['details'][] = $detail;
                continue;
            }

            // 4. SMART MEMORY COMPLETO (NO TRIVIAL)
            // 4a. Embedding del Q&A actual
            $qaText = "Pregunta: $question\nRespuesta: $answer";
            $qaText = mb_substr($qaText, 0, 30000);
            $qaEmbData = generateTitanEmbedding($bedrock, $qaText, $modelId);
            $qaVector = $qaEmbData['embedding'];

            // 4b. Buscar resúmenes previos similares
            $existingSummary = null;
            $existingBlockId = null;
            $maxSim = 0.0;

            $stmtPrev = $db_connection->prepare("
                SELECT id_, content_preview, embedding_json 
                FROM SessionContextBlocks 
                WHERE session_id_ = ? AND id_ != ? 
                  AND embedding_json IS NOT NULL 
                  AND block_type IN ('level_0', 'level_1')
                ORDER BY created_at DESC LIMIT 50
            ");
            $stmtPrev->bind_param('ii', $sessionId, $targetId);
            $stmtPrev->execute();
            $resPrev = $stmtPrev->get_result();
            while ($rowPrev = $resPrev->fetch_assoc()) {
                $prevVector = json_decode($rowPrev['embedding_json'], true);
                if (is_array($prevVector) && count($prevVector) > 0 && !empty($qaVector)) {
                    $sim = cosineSimilarity($qaVector, $prevVector);
                    if ($sim > $maxSim && $sim >= SIMILARITY_THRESHOLD) {
                        $maxSim = $sim;
                        $existingSummary = (string)$rowPrev['content_preview'];
                        $existingBlockId = (int)$rowPrev['id_'];
                    }
                }
            }
            $stmtPrev->close();

            // 4c. Generar resumen con IA (selección dinámica de modelo)
            $smartData = generateSmartSummary($bedrock, $question, $answer, $existingSummary);
            $finalText = $smartData['text'];
            if (empty($finalText)) throw new RuntimeException('La IA no devolvió resumen');

            // 4d. Embedding del resumen inteligente
            $finalEmbData = generateTitanEmbedding($bedrock, $finalText, $modelId);
            $finalEmbedding = $finalEmbData['embedding'];
            if (empty($finalEmbedding)) throw new RuntimeException('Embedding del resumen vacío');

            $binary = floatsToBinaryBlob($finalEmbedding);
            $json = json_encode($finalEmbedding);
            $tokenCount = (int)ceil(mb_strlen($finalText) / 4);

            // 4e. Guardar en BD (fusionar o crear nuevo)
            if ($existingBlockId && $existingSummary) {
                // Mismo tema: actualizar bloque existente y eliminar el actual
                $stmtUpd = $db_connection->prepare("
                    UPDATE SessionContextBlocks 
                    SET content_preview = ?, embedding = ?, embedding_json = ?, embedding_model = ?, token_count = ?
                    WHERE id_ = ?
                ");
                $null = $binary;
                $stmtUpd->bind_param('sbssii', $finalText, $null, $json, $modelId, $tokenCount, $existingBlockId);
                $stmtUpd->send_long_data(1, $binary);
                $stmtUpd->execute();
                $stmtUpd->close();

                $stmtDel = $db_connection->prepare("DELETE FROM SessionContextBlocks WHERE id_ = ?");
                $stmtDel->bind_param('i', $targetId);
                $stmtDel->execute();
                $stmtDel->close();

                $detail['action'] = 'merged_into_block_' . $existingBlockId;
                $detail['similarity'] = round($maxSim, 3);
            } else {
                // Tema diverso: sobrescribir el bloque actual
                $stmtUpd = $db_connection->prepare("
                    UPDATE SessionContextBlocks 
                    SET content_preview = ?, embedding = ?, embedding_json = ?, embedding_model = ?, token_count = ?
                    WHERE id_ = ?
                ");
                $null = $binary;
                $stmtUpd->bind_param('sbssii', $finalText, $null, $json, $modelId, $tokenCount, $targetId);
                $stmtUpd->send_long_data(1, $binary);
                $stmtUpd->execute();
                $stmtUpd->close();

                $detail['action'] = 'new_summary_created';
            }

            // 4f. Marcar job completado
            $upd = $db_connection->prepare("UPDATE EmbeddingJobs SET status = 'completed', attempts = ?, updated_at = NOW() WHERE id_ = ?");
            $upd->bind_param('ii', $attempts, $jobId);
            $upd->execute();
            $upd->close();

            $detail['status'] = 'completed_smart';
            $detail['dimensions'] = count($finalEmbedding);
            $results['succeeded']++;

            // 5. REGISTRAR COSTOS EN TokenUsage (usando logTokenUsage)
            if ($sessionId && $tcMsgId !== null) {
                // Paso A: Embedding inicial del Q&A crudo (Titan)
                logTokenUsage($db_connection, $sessionId, $tcMsgId, 'embedding', $modelId, $qaEmbData['inputTokens'], 0);
                
                // Paso B: Resumen con IA (modelo dinámico)
                $usedModel = $smartData['model'];
                logTokenUsage($db_connection, $sessionId, $tcMsgId, 'compile', $usedModel, $smartData['inputTokens'], $smartData['outputTokens']);
                
                // Paso C: Embedding final del resumen inteligente (Titan)
                logTokenUsage($db_connection, $sessionId, $tcMsgId, 'embedding', $modelId, $finalEmbData['inputTokens'], 0);
            }

            // 6. ACTUALIZAR NIVEL DE CONTEXTO DE LA SESIÓN (si no es trivial)
            if ($sessionId && !$isTrivial) {
                $stmtUpdSess = $db_connection->prepare("
                    UPDATE ChatSessions 
                    SET context_level = 1, 
                        last_compressed_at = NOW()
                    WHERE id_ = ? 
                      AND context_level < 1
                ");
                $stmtUpdSess->bind_param('i', $sessionId);
                $stmtUpdSess->execute();
                $stmtUpdSess->close();
            }

            $results['details'][] = $detail;
            continue; // Saltar al siguiente job
        }

        // =================================================================
        // FLUJO ORIGINAL PARA source_chunk y project_context (sin cambios)
        // =================================================================
        $content = getContentForJob($db_connection, $targetType, $targetId);
        
        if (empty($content)) {
            $errMsg = 'Contenido no encontrado para ' . $targetType . ' #' . $targetId;
            $upd = $db_connection->prepare("
                UPDATE EmbeddingJobs 
                SET status = 'failed', error_message = ?, attempts = ?, updated_at = NOW()
                WHERE id_ = ?
            ");
            $upd->bind_param('sii', $errMsg, $attempts, $jobId);
            $upd->execute();
            $upd->close();
            
            $detail['status'] = 'failed';
            $detail['error'] = $errMsg;
            $results['failed']++;
            $results['details'][] = $detail;
            continue;
        }
        
        $content = mb_substr($content, 0, 30000);
        
        $embedRes = $bedrock->invokeModel([
            'modelId' => $modelId,
            'contentType' => 'application/json',
            'accept' => 'application/json',
            'body' => json_encode([
                'inputText' => $content,
                'dimensions' => EMBEDDING_DIMENSIONS,
                'normalize' => true,
            ]),
        ]);
        
        $embedData = json_decode((string)$embedRes['body'], true);
        $embedding = $embedData['embedding'] ?? [];
        
        if (empty($embedding) || !is_array($embedding)) {
            throw new RuntimeException('Bedrock no devolvió embedding válido');
        }
        
        $saved = saveEmbedding($db_connection, $targetType, $targetId, $embedding, $modelId);
        
        if (!$saved) {
            throw new RuntimeException('No se pudo guardar el embedding en la BD');
        }
        
        $upd = $db_connection->prepare("
            UPDATE EmbeddingJobs 
            SET status = 'completed', attempts = ?, updated_at = NOW()
            WHERE id_ = ?
        ");
        $upd->bind_param('ii', $attempts, $jobId);
        $upd->execute();
        $upd->close();
        
        $detail['status'] = 'completed';
        $detail['dimensions'] = count($embedding);
        $results['succeeded']++;

        // Registrar costo en TokenUsage (solo para estos target_type)
        $inputTokens = (int)($embedData['inputTextTokenCount'] ?? 0);
        $outputTokens = 0;

        try {
            // Obtener session_id_ y message_id_ para el registro
            $sessionIdForLog = null;
            $tcMsgId = null;

            if ($targetType === 'source_chunk') {
                $stmtProj = $db_connection->prepare("SELECT project_id_ FROM SourceChunks WHERE id_ = ? LIMIT 1");
                $stmtProj->bind_param('i', $targetId);
                $stmtProj->execute();
                $resProj = $stmtProj->get_result();
                if ($rowProj = $resProj->fetch_assoc()) {
                    $stmtSess2 = $db_connection->prepare("SELECT id_ FROM ChatSessions WHERE project_id_ = ? ORDER BY id_ DESC LIMIT 1");
                    $stmtSess2->bind_param('i', $rowProj['project_id_']);
                    $stmtSess2->execute();
                    $resSess2 = $stmtSess2->get_result();
                    if ($rowSess2 = $resSess2->fetch_assoc()) {
                        $sessionIdForLog = (int)$rowSess2['id_'];
                    }
                    $stmtSess2->close();
                }
                $stmtProj->close();
            } elseif ($targetType === 'project_context') {
                $stmtPC = $db_connection->prepare("SELECT project_id_ FROM ProjectContext WHERE id_ = ? LIMIT 1");
                $stmtPC->bind_param('i', $targetId);
                $stmtPC->execute();
                $resPC = $stmtPC->get_result();
                if ($rowPC = $resPC->fetch_assoc()) {
                    $stmtSess3 = $db_connection->prepare("SELECT id_ FROM ChatSessions WHERE project_id_ = ? ORDER BY id_ DESC LIMIT 1");
                    $stmtSess3->bind_param('i', $rowPC['project_id_']);
                    $stmtSess3->execute();
                    $resSess3 = $stmtSess3->get_result();
                    if ($rowSess3 = $resSess3->fetch_assoc()) {
                        $sessionIdForLog = (int)$rowSess3['id_'];
                    }
                    $stmtSess3->close();
                }
                $stmtPC->close();
            }

            if (!$sessionIdForLog) {
                $fallbackSess = $db_connection->query("SELECT id_ FROM ChatSessions LIMIT 1");
                if ($fallbackSess && $rowFallback = $fallbackSess->fetch_assoc()) {
                    $sessionIdForLog = (int)$rowFallback['id_'];
                }
                if ($fallbackSess) $fallbackSess->free();
            }

            // Obtener message_id_ (último mensaje de la sesión)
            if ($sessionIdForLog) {
                $stmtLastMsg = $db_connection->prepare("
                    SELECT m.id_ 
                    FROM ChatMessages m
                    WHERE m.session_id_ = ?
                    ORDER BY m.id_ DESC 
                    LIMIT 1
                ");
                $stmtLastMsg->bind_param('i', $sessionIdForLog);
                $stmtLastMsg->execute();
                $resLastMsg = $stmtLastMsg->get_result();
                if ($rowLastMsg = $resLastMsg->fetch_assoc()) {
                    $tcMsgId = (int)$rowLastMsg['id_'];
                }
                $stmtLastMsg->close();
            }

            if ($sessionIdForLog && $tcMsgId !== null) {
                logTokenUsage($db_connection, $sessionIdForLog, $tcMsgId, 'embedding', $modelId, $inputTokens, 0);
            }

        } catch (Throwable $e) {
            $logMsg = "[" . date('Y-m-d H:i:s') . "] " . basename(__FILE__) . " (Job $jobId) | TokenUsage: " . $e->getMessage() . "\n";
            @file_put_contents(__DIR__ . '/token_usage_debug.log', $logMsg, FILE_APPEND | LOCK_EX);
        }
        
        $results['details'][] = $detail;
        continue;

    } catch (Throwable $e) {
        $errMsg = mb_substr($e->getMessage(), 0, 500);
        
        $isBlockNotFound = (strpos($errMsg, 'no encontrado') !== false || strpos($errMsg, 'not found') !== false);
        
        if ($isBlockNotFound) {
            $upd = $db_connection->prepare("
                UPDATE EmbeddingJobs
                SET status = 'failed', error_message = ?, attempts = ?, updated_at = NOW()
                WHERE id_ = ?
            ");
            $upd->bind_param('sii', $errMsg, $attempts, $jobId);
            $upd->execute();
            $upd->close();
            
            $detail['status'] = 'failed';
            $detail['error'] = $errMsg;
            $results['failed']++;
        } else {
            $newStatus = ($attempts >= 3) ? 'failed' : 'pending';
            $upd = $db_connection->prepare("
                UPDATE EmbeddingJobs
                SET status = ?, error_message = ?, attempts = ?, updated_at = NOW()
                WHERE id_ = ?
            ");
            $upd->bind_param('ssii', $newStatus, $errMsg, $attempts, $jobId);
            $upd->execute();
            $upd->close();
            
            $detail['status'] = ($newStatus === 'failed') ? 'failed' : 'retry_later';
            $detail['error'] = $errMsg;
            if ($newStatus === 'failed') {
                $results['failed']++;
            } else {
                $results['skipped']++;
            }
        }
        $results['details'][] = $detail;
    }
}

// ===== Liberar lock =====
flock($lockFp, LOCK_UN);
fclose($lockFp);

// ===== Responder =====
finish($results, $isCli, $startTime);