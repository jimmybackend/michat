<?php
/**
 * process_embedding_queue.php
 * 
 * Procesa la cola de EmbeddingJobs:
 *  1. Lee trabajos pendientes de EmbeddingJobs
 *  2. Obtiene el contenido según target_type (session_block, source_chunk, project_context)
 *  3. Llama a AWS Bedrock con el modelo configurado en embedding_main
 *  4. Guarda el vector en la tabla correspondiente
 *  5. Marca el job como completado o fallido
 * 
 * ✅ MEMORIA SELECTIVA:
 *  - session_block se vectoriza directamente con embedding_main.
 *  - NO resume, NO fusiona temas y NO llama a smart_memory_general/code.
 *  - El Q&A crudo permanece enlazado por question_msg_id/answer_msg_id.
 *  - La compresión jerárquica posterior vive en compress_session_context.php.
 * 
 * Uso:
 *  - Desde navegador autenticado: POST process_embedding_queue.php?batch=10 con X-CSRF-Token
 *  - Desde cron: php process_embedding_queue.php --batch=10 --secret=TU_SECRET
 * 
 * Compatible con PHP 7.x+
 */

// ===== Configuración =====
define('DEFAULT_BATCH_SIZE', 10);
define('MAX_EXECUTION_TIME', 300); // 5 minutos máximo
define('SESSION_RECENT_WINDOW', 5); // Debe coincidir con compress_session_context.php
define('SESSION_COMPRESSION_BATCH', 5); // Debe coincidir con compress_session_context.php
// Los modelos y prompts YA NO se definen en este archivo.
// Fuente de verdad: UserAIAgentConfigs mediante includes/ai_agent_runtime.php.
//
// Reutilización:
//   embedding_main -> vectorización de todos los target_type.
// Smart Memory NO se ejecuta aquí; la consolidación vive en compress_session_context.php.
//



// ===== Timeouts =====
@ini_set('max_execution_time', MAX_EXECUTION_TIME);
@set_time_limit(MAX_EXECUTION_TIME);
@ini_set('default_socket_timeout', '60');
@ignore_user_abort(true);

// ===== Detectar si es CLI o Web =====
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    
    try { require_once __DIR__.'/includes/Security/MaintenanceAccess.php'; MaintenanceAccess::authorizeWeb(); } catch (Throwable $e) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
    
    $batchSize = isset($_GET['batch']) ? max(1, min(50, (int)$_GET['batch'])) : DEFAULT_BATCH_SIZE;
} else {
    $opts = getopt('', ['batch:', 'secret:']);
    try { require_once __DIR__.'/includes/Security/MaintenanceAccess.php'; MaintenanceAccess::authorizeCli($opts); } catch (Throwable $e) {
        fwrite(STDERR, "Error: clave inválida\n");
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

// ===== Runtime dinámico de agentes IA =====
$aiRuntimeFile = __DIR__ . '/includes/ai_agent_runtime.php';
if (!is_file($aiRuntimeFile)) {
    $results['ok'] = false;
    $results['error'] = 'Falta includes/ai_agent_runtime.php';
    finish($results, $isCli, $startTime);
}
require_once $aiRuntimeFile;

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
    $bedrock = Config::getBedrockRuntime([
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
// ✅ MODELOS DINÁMICOS SEGÚN UserAIAgentConfigs
// =====================================================================

function getJobOwnerUserId(mysqli $db, string $targetType, int $targetId): int {
    if ($targetType === 'session_block') {
        $sql = "SELECT cs.user_id_ FROM SessionContextBlocks scb JOIN ChatSessions cs ON cs.id_ = scb.session_id_ WHERE scb.id_ = ? LIMIT 1";
    } elseif ($targetType === 'source_chunk') {
        $sql = "SELECT p.user_id_ FROM SourceChunks sc JOIN Projects p ON p.id_ = sc.project_id_ WHERE sc.id_ = ? LIMIT 1";
    } elseif ($targetType === 'project_context') {
        $sql = "SELECT p.user_id_ FROM ProjectContext pc JOIN Projects p ON p.id_ = pc.project_id_ WHERE pc.id_ = ? LIMIT 1";
    } else {
        return 1;
    }
    $stmt = $db->prepare($sql);
    if (!$stmt) return 1;
    $stmt->bind_param('i', $targetId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $uid = (int)($row['user_id_'] ?? 1);
    return $uid > 0 ? $uid : 1;
}

function getEmbeddingRuntimeConfig(): array {
    $cfg = aiAgentConfig('embedding_main');
    if (!$cfg) throw new RuntimeException("Falta el registro 'embedding_main' en UserAIAgentConfigs");
    if (!aiAgentActive('embedding_main', false)) throw new RuntimeException('embedding_main está desactivado');

    $modelId = aiAgentModel('embedding_main', '');
    if ($modelId === '') throw new RuntimeException('embedding_main no tiene model_id');

    $modelLower = strtolower($modelId);
    $configuredAdapter = strtolower(trim((string)aiAgentExtra('embedding_main', 'adapter', '')));
    $adapter = '';

    // El model_id efectivo manda sobre un adapter antiguo que pudiera quedar
    // guardado en extra_config después de cambiar de proveedor.
    if (strpos($modelLower, 'amazon.titan-embed-text-v2') !== false) {
        $adapter = 'titan_text_v2';
    } elseif (strpos($modelLower, 'amazon.titan-embed-text-v1') !== false) {
        $adapter = 'titan_text_v1';
    } elseif (strpos($modelLower, 'cohere.embed-v4') !== false) {
        $adapter = 'cohere_embed_v4';
    } elseif (
        strpos($modelLower, 'cohere.embed-english-v3') !== false
        || strpos($modelLower, 'cohere.embed-multilingual-v3') !== false
    ) {
        $adapter = 'cohere_embed_v3';
    } elseif ($configuredAdapter !== '') {
        $adapter = $configuredAdapter;
    }

    if (!in_array($adapter, [
        'titan_text_v2',
        'titan_text_v1',
        'cohere_embed_v4',
        'cohere_embed_v3',
    ], true)) {
        throw new RuntimeException("El modelo de embedding '{$modelId}' requiere un adaptador no implementado en process_embedding_queue.php");
    }

    if ($adapter === 'titan_text_v1') {
        $dimensions = 1536;
    } elseif ($adapter === 'cohere_embed_v3') {
        $dimensions = 1024;
    } else {
        $dimensions = max(1, (int)aiAgentExtra('embedding_main', 'dimensions', 1024));
    }

    if ($adapter === 'titan_text_v2' && !in_array($dimensions, [256, 512, 1024], true)) {
        throw new RuntimeException('Titan Text Embeddings V2 requiere 256, 512 o 1024 dimensiones.');
    }
    if ($adapter === 'cohere_embed_v4' && !in_array($dimensions, [256, 512, 1024, 1536], true)) {
        throw new RuntimeException('Cohere Embed v4 requiere 256, 512, 1024 o 1536 dimensiones.');
    }

    $defaultMaxChars = ($adapter === 'cohere_embed_v3') ? 2048 : 8000;
    $inputMaxChars = max(1, (int)aiAgentExtra('embedding_main', 'input_max_chars', $defaultMaxChars));
    if ($adapter === 'cohere_embed_v3') $inputMaxChars = min($inputMaxChars, 2048);

    return [
        'model_id' => $modelId,
        'adapter' => $adapter,
        'dimensions' => $dimensions,
        'normalize' => (bool)aiAgentExtra('embedding_main', 'normalize', true),
        'input_max_chars' => $inputMaxChars,
        'document_input_type' => (string)aiAgentExtra('embedding_main', 'document_input_type', 'search_document'),
        'query_input_type' => (string)aiAgentExtra('embedding_main', 'query_input_type', 'search_query'),
        'truncate' => (string)aiAgentExtra('embedding_main', 'truncate', $adapter === 'cohere_embed_v4' ? 'RIGHT' : 'END'),
        'max_attempts' => max(1, (int)aiAgentValue('embedding_main', 'max_attempts', 3)),
    ];
}

function syncEmbeddingJobModel(mysqli $db, int $jobId, string $targetType, int $targetId, string $modelId): array {
    $stmt = $db->prepare("SELECT id_, status FROM EmbeddingJobs WHERE target_type = ? AND target_id = ? AND model_id = ? AND id_ <> ? LIMIT 1");
    if (!$stmt) return ['ok'=>false,'duplicate_id'=>null];
    $stmt->bind_param('sisi', $targetType, $targetId, $modelId, $jobId);
    $stmt->execute();
    $other = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($other) return ['ok'=>false,'duplicate_id'=>(int)$other['id_'],'duplicate_status'=>(string)$other['status']];

    $upd = $db->prepare("UPDATE EmbeddingJobs SET model_id = ?, updated_at = NOW() WHERE id_ = ?");
    if (!$upd) return ['ok'=>false,'duplicate_id'=>null];
    $upd->bind_param('si', $modelId, $jobId);
    $ok = $upd->execute();
    $upd->close();
    return ['ok'=>$ok,'duplicate_id'=>null];
}


// =====================================================================
// ✅ ESTADO REAL DE ProjectSources SEGÚN EMBEDDINGS DEL MODELO ACTUAL
// =====================================================================
function refreshProjectSourceEmbeddingStatus(mysqli $db, int $sourceId, string $modelId, bool $forceError = false): void {
    if ($sourceId <= 0 || $modelId === '') return;

    $stmt = $db->prepare("\n        SELECT\n            COUNT(DISTINCT sc.id_) AS total_chunks,\n            COUNT(DISTINCT ce.chunk_id_) AS ready_chunks\n        FROM SourceChunks sc\n        LEFT JOIN ChunkEmbeddings ce\n          ON ce.chunk_id_ = sc.id_\n         AND ce.model_id = ?\n        WHERE sc.source_id_ = ?\n    ");
    if (!$stmt) return;
    $stmt->bind_param('si', $modelId, $sourceId);
    $stmt->execute();
    $counts = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $total = (int)($counts['total_chunks'] ?? 0);
    $ready = (int)($counts['ready_chunks'] ?? 0);

    $stmtJobs = $db->prepare("\n        SELECT\n            SUM(ej.status IN ('pending','processing')) AS open_jobs,\n            SUM(ej.status = 'failed') AS failed_jobs\n        FROM EmbeddingJobs ej\n        JOIN SourceChunks sc\n          ON ej.target_type = 'source_chunk'\n         AND ej.target_id = sc.id_\n        WHERE sc.source_id_ = ?\n          AND ej.model_id = ?\n    ");
    $open = 0;
    $failed = 0;
    if ($stmtJobs) {
        $stmtJobs->bind_param('is', $sourceId, $modelId);
        $stmtJobs->execute();
        $jr = $stmtJobs->get_result()->fetch_assoc() ?: [];
        $open = (int)($jr['open_jobs'] ?? 0);
        $failed = (int)($jr['failed_jobs'] ?? 0);
        $stmtJobs->close();
    }

    if ($total > 0 && $ready >= $total && $open === 0) {
        $upd = $db->prepare("UPDATE ProjectSources SET status='indexed', indexed_at=NOW() WHERE id_=?");
        if ($upd) { $upd->bind_param('i', $sourceId); $upd->execute(); $upd->close(); }
        return;
    }

    if ($forceError || ($failed > 0 && $open === 0)) {
        $upd = $db->prepare("UPDATE ProjectSources SET status='error', indexed_at=NULL WHERE id_=?");
        if ($upd) { $upd->bind_param('i', $sourceId); $upd->execute(); $upd->close(); }
        return;
    }

    $upd = $db->prepare("UPDATE ProjectSources SET status='pending', indexed_at=NULL WHERE id_=?");
    if ($upd) { $upd->bind_param('i', $sourceId); $upd->execute(); $upd->close(); }
}

function getSourceIdForChunk(mysqli $db, int $chunkId): int {
    $stmt = $db->prepare("SELECT source_id_ FROM SourceChunks WHERE id_=? LIMIT 1");
    if (!$stmt) return 0;
    $stmt->bind_param('i', $chunkId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['source_id_'] ?? 0);
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
// ✅ EMBEDDING DINÁMICO (modelo + características desde la tabla)
// =====================================================================
function generateConfiguredEmbedding(
    $bedrock,
    string $text,
    ?array $runtime = null,
    string $inputType = 'search_document'
): array {
    $runtime = $runtime ?? getEmbeddingRuntimeConfig();
    $modelId = (string)$runtime['model_id'];
    $adapter = (string)$runtime['adapter'];
    $inputText = mb_substr($text, 0, (int)$runtime['input_max_chars']);
    $dimensions = (int)$runtime['dimensions'];
    $body = [];
    $cohereInputType = null;

    if ($adapter === 'titan_text_v2') {
        $body = [
            'inputText' => $inputText,
            'dimensions' => $dimensions,
            'normalize' => (bool)$runtime['normalize'],
        ];
    } elseif ($adapter === 'titan_text_v1') {
        $body = ['inputText' => $inputText];
    } elseif ($adapter === 'cohere_embed_v3' || $adapter === 'cohere_embed_v4') {
        $isDocument = ($inputType !== 'search_query');
        $cohereInputType = $isDocument
            ? (string)($runtime['document_input_type'] ?? 'search_document')
            : (string)($runtime['query_input_type'] ?? 'search_query');
        if (!in_array($cohereInputType, ['search_document', 'search_query'], true)) {
            $cohereInputType = $isDocument ? 'search_document' : 'search_query';
        }

        if ($adapter === 'cohere_embed_v3') {
            $body = [
                'texts' => [$inputText],
                'input_type' => $cohereInputType,
                'truncate' => (string)($runtime['truncate'] ?? 'END'),
            ];
        } else {
            $body = [
                'texts' => [$inputText],
                'input_type' => $cohereInputType,
                'embedding_types' => ['float'],
                'output_dimension' => $dimensions,
                'truncate' => (string)($runtime['truncate'] ?? 'RIGHT'),
            ];
        }
    } else {
        throw new RuntimeException("Adaptador de embedding no soportado: {$adapter}");
    }

    $embedRes = $bedrock->invokeModel([
        'modelId' => $modelId,
        'contentType' => 'application/json',
        'accept' => 'application/json',
        'body' => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $embedData = json_decode((string)$embedRes['body'], true);
    if (!is_array($embedData)) {
        throw new RuntimeException("Respuesta JSON inválida del modelo de embedding {$modelId}");
    }

    if ($adapter === 'titan_text_v2' || $adapter === 'titan_text_v1') {
        $embedding = $embedData['embedding'] ?? [];
        $inputTokens = (int)($embedData['inputTextTokenCount'] ?? 0);
    } else {
        $embeddings = $embedData['embeddings'] ?? [];
        if (is_array($embeddings) && isset($embeddings['float'])) {
            $embeddings = $embeddings['float'];
        }
        $embedding = (is_array($embeddings) && isset($embeddings[0]) && is_array($embeddings[0]))
            ? $embeddings[0]
            : [];
        // AWS no documenta un contador de tokens en la respuesta nativa de Cohere Embed.
        $inputTokens = 0;
    }

    if (!is_array($embedding) || empty($embedding)) {
        throw new RuntimeException("Bedrock no devolvió embedding válido para {$modelId}");
    }
    if (count($embedding) !== $dimensions) {
        throw new RuntimeException(
            'Dimensión inesperada de embedding: ' . count($embedding) . '; esperada ' . $dimensions
        );
    }

    return [
        'embedding' => $embedding,
        'inputTokens' => $inputTokens,
        'model' => $modelId,
        'adapter' => $adapter,
        'input_type' => $cohereInputType,
        'dimensions' => count($embedding),
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
            return $affected >= 0;
            
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
                $stmt->bind_param('iisibs', $nextId, $targetId, $modelId, $dimensions, $null, $json);
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
    $ownerUserId = getJobOwnerUserId($db_connection, $targetType, $targetId);
    aiRuntimeLoad($db_connection, $ownerUserId);

    $detail = [
        'job_id' => $jobId,
        'target_type' => $targetType,
        'target_id' => $targetId,
        'owner_user_id' => $ownerUserId,
        'status' => 'unknown',
    ];
    $attempts = (int)$job['attempts'] + 1;
    $maxAttempts = 3;

    try {
        $embeddingRuntime = getEmbeddingRuntimeConfig();
        $modelId = (string)$embeddingRuntime['model_id'];
        $maxAttempts = (int)$embeddingRuntime['max_attempts'];
        $detail['embedding_model'] = $modelId;

        $sync = syncEmbeddingJobModel($db_connection, $jobId, $targetType, $targetId, $modelId);
        if (!$sync['ok'] && !empty($sync['duplicate_id'])) {
            $msg = 'Sustituido por job #' . (int)$sync['duplicate_id'] . ' del modelo configurado ' . $modelId;
            $updDup = $db_connection->prepare("UPDATE EmbeddingJobs SET status='completed', error_message=?, updated_at=NOW() WHERE id_=?");
            if ($updDup) { $updDup->bind_param('si', $msg, $jobId); $updDup->execute(); $updDup->close(); }
            $detail['status'] = 'skipped_duplicate_current_model';
            $detail['duplicate_job_id'] = (int)$sync['duplicate_id'];
            $results['skipped']++;
            if ($targetType === 'source_chunk') {
                $sourceIdForStatus = getSourceIdForChunk($db_connection, $targetId);
                refreshProjectSourceEmbeddingStatus($db_connection, $sourceIdForStatus, $modelId, false);
            }
            $results['details'][] = $detail;
            continue;
        }

        // =================================================================
        // ✅ session_block: vectorización directa, sin Smart Memory
        // =================================================================
        if ($targetType === 'session_block') {
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
            if (!$stmtData) {
                throw new RuntimeException('No se pudo preparar lectura de session_block');
            }
            $stmtData->bind_param('i', $targetId);
            $stmtData->execute();
            $rowData = $stmtData->get_result()->fetch_assoc();
            $stmtData->close();

            if (!$rowData) {
                throw new RuntimeException('Bloque de sesión #' . $targetId . ' no encontrado');
            }

            $sessionId = (int)$rowData['session_id_'];
            $tcMsgId = !empty($rowData['answer_msg_id']) ? (int)$rowData['answer_msg_id'] : null;
            $blockType = (string)($rowData['block_type'] ?? 'level_0');

            if ($blockType === 'file' || $blockType === 'file_chunk') {
                $textForEmbedding = trim((string)($rowData['content_preview'] ?? ''));
            } else {
                $question = trim((string)($rowData['question_text'] ?? ''));
                $answer = trim((string)($rowData['answer_text'] ?? ''));

                if ($question !== '' || $answer !== '') {
                    $textForEmbedding = '';
                    if ($question !== '') $textForEmbedding .= "Pregunta: {$question}\n\n";
                    if ($answer !== '') $textForEmbedding .= "Respuesta: {$answer}";
                    $textForEmbedding = trim($textForEmbedding);
                } else {
                    // Compatibilidad con bloques resumen que pudieran encolarse manualmente.
                    $textForEmbedding = trim((string)($rowData['content_preview'] ?? ''));
                }
            }

            if ($textForEmbedding === '') {
                throw new RuntimeException('session_block sin contenido vectorizable');
            }

            $embData = generateConfiguredEmbedding(
                $bedrock,
                mb_substr($textForEmbedding, 0, 30000),
                $embeddingRuntime,
                'search_document'
            );

            $embedding = $embData['embedding'] ?? [];
            if (!$embedding) {
                throw new RuntimeException('Embedding de session_block vacío');
            }

            if (!saveEmbedding($db_connection, 'session_block', $targetId, $embedding, $modelId)) {
                throw new RuntimeException('No se pudo guardar embedding de session_block');
            }

            // Actualizar solo token_count. content_preview permanece determinista
            // y nunca es reemplazado por un resumen generado por IA.
            $tokenCount = (int)ceil(mb_strlen($textForEmbedding) / 4);
            $stmtTok = $db_connection->prepare("
                UPDATE SessionContextBlocks
                SET token_count = ?
                WHERE id_ = ?
            ");
            if ($stmtTok) {
                $stmtTok->bind_param('ii', $tokenCount, $targetId);
                $stmtTok->execute();
                $stmtTok->close();
            }

            $upd = $db_connection->prepare("
                UPDATE EmbeddingJobs
                SET status = 'completed', attempts = ?, error_message = NULL, updated_at = NOW()
                WHERE id_ = ?
            ");
            if ($upd) {
                $upd->bind_param('ii', $attempts, $jobId);
                $upd->execute();
                $upd->close();
            }

            if ($sessionId && ($embData['inputTokens'] ?? 0) > 0) {
                logTokenUsage(
                    $db_connection,
                    $sessionId,
                    $tcMsgId,
                    'embedding',
                    $modelId,
                    (int)$embData['inputTokens'],
                    0
                );
            }

            // El cron de compresión solo debe despertar cuando existan
            // 5 Q&A para consolidar + 5 recientes que deben quedar crudos.
            if ($blockType === 'level_0' && $sessionId > 0) {
                $stmtReady = $db_connection->prepare("
                    SELECT COUNT(*) AS c
                    FROM SessionContextBlocks
                    WHERE session_id_ = ?
                      AND block_type = 'level_0'
                      AND is_locked = 0
                      AND embedding_json IS NOT NULL
                ");
                if ($stmtReady) {
                    $stmtReady->bind_param('i', $sessionId);
                    $stmtReady->execute();
                    $readyRow = $stmtReady->get_result()->fetch_assoc();
                    $stmtReady->close();

                    if ((int)($readyRow['c'] ?? 0) >= (SESSION_RECENT_WINDOW + SESSION_COMPRESSION_BATCH)) {
                        $stmtPending = $db_connection->prepare(
                            "UPDATE ChatSessions SET pending_summary = 1 WHERE id_ = ?"
                        );
                        if ($stmtPending) {
                            $stmtPending->bind_param('i', $sessionId);
                            $stmtPending->execute();
                            $stmtPending->close();
                        }
                    }
                }
            }

            $detail['status'] = ($blockType === 'file' || $blockType === 'file_chunk')
                ? 'completed_file'
                : 'completed_session_block_raw';
            $detail['block_type'] = $blockType;
            $detail['dimensions'] = count($embedding);
            $detail['input_tokens'] = (int)($embData['inputTokens'] ?? 0);
            $detail['smart_memory'] = 'not_used';

            $results['succeeded']++;
            $results['details'][] = $detail;
            continue;
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
            if ($targetType === 'source_chunk') {
                $sourceIdForStatus = getSourceIdForChunk($db_connection, $targetId);
                refreshProjectSourceEmbeddingStatus($db_connection, $sourceIdForStatus, $modelId, true);
            }
            $results['details'][] = $detail;
            continue;
        }
        
        $content = mb_substr($content, 0, 30000);
        
        $embedData = generateConfiguredEmbedding($bedrock, $content, $embeddingRuntime);
        $embedding = $embedData['embedding'];
        
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

        if ($targetType === 'source_chunk') {
            $sourceIdForStatus = getSourceIdForChunk($db_connection, $targetId);
            refreshProjectSourceEmbeddingStatus($db_connection, $sourceIdForStatus, $modelId, false);
            $detail['source_id'] = $sourceIdForStatus ?: null;
        }

        // Registrar costo en TokenUsage (solo para estos target_type)
        $inputTokens = (int)($embedData['inputTokens'] ?? 0);
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

        if ($errMsg === 'embedding_main está desactivado') {
            $upd = $db_connection->prepare("UPDATE EmbeddingJobs SET status='pending', error_message=?, updated_at=NOW() WHERE id_=?");
            if ($upd) { $upd->bind_param('si', $errMsg, $jobId); $upd->execute(); $upd->close(); }
            $detail['status'] = 'skipped_embedding_disabled';
            $detail['error'] = $errMsg;
            $results['skipped']++;
            $results['details'][] = $detail;
            continue;
        }

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
            $newStatus = ($attempts >= $maxAttempts) ? 'failed' : 'pending';
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
            if ($targetType === 'source_chunk') {
                $sourceIdForStatus = getSourceIdForChunk($db_connection, $targetId);
                $statusModelId = isset($modelId) && is_string($modelId) ? $modelId : aiAgentModel('embedding_main', '');
                refreshProjectSourceEmbeddingStatus($db_connection, $sourceIdForStatus, $statusModelId, $newStatus === 'failed');
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
