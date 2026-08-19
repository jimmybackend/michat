<?php
/**
 * compress_session_context.php
 *
 * Compresión Jerárquica de Contexto + EXTRACCIÓN DE CONTEXTO DE PROYECTO
 *
 * Niveles de compresión:
 *  - Nivel 0: Q&A crudo vectorizado (los consolidados se conservan con is_locked=1)
 *  - Nivel 1: Resumen de cada 5 Q&A (modelo dinámico de Smart Memory)
 *  - Nivel 2: Resumen de 4 bloques nivel 1 (~20 Q&A)
 *  - Nivel 3: Resumen de 4 bloques nivel 2 (~80 Q&A)
 *
 * EXTRACCIÓN DE PROYECTO:
 *  - Sincroniza mensajes "primordiales" a ProjectContext.
 *  - Reutiliza smart_memory_general/code para extraer reglas/decisiones/hechos.
 *
 * ✅ CAMBIOS EN ESTA VERSIÓN:
 *  1. logTokenUsage(): función ÚNICA que calcula el costo y escribe en
 *     TokenUsage. Recibe todo por parámetros. Ya no hay 3 copias de la
 *     lógica de precios/insert regadas por el archivo (antes estaban
 *     duplicadas en compressWithHaiku, extractKnowledgeFromSessions y
 *     generateSessionMetaSummary, incluso con fórmulas ligeramente distintas).
 *  2. selectModelForContent(): la heurística "¿esto es código?" que ya
 *     existía SOLO en compressWithHaiku ahora es una función reutilizable
 *     y se aplica también en extractKnowledgeFromSessions() y
 *     generateSessionMetaSummary(), que antes SIEMPRE usaban Nova Micro
 *     sin importar si el contenido era técnico/código.
 *
 * Uso:
 *  - Cron: * * * * * php compress_session_context.php --secret=TU_SECRET
 *  - Web: compress_session_context.php?key=TU_SECRET&session_id=123
 */
define('COMPRESSION_SECRET', 'Z1!xC6@vB3#nM8$kL4*jH9^gF2&dS7');
define('MAX_SESSIONS_PER_RUN', 10);
define('RECENT_WINDOW', 5); // Últimos N level_0 quedan desbloqueados
define('LEVEL0_BATCH_SIZE', 5); // Consolidar solo grupos completos de 5 Q&A

// Los modelos y las instrucciones de IA ya no se definen aquí.
// Fuente de verdad: UserAIAgentConfigs.
// Reutilizamos smart_memory_general / smart_memory_code para todas las tareas
// de memoria, y cada tarea conserva su prompt propio como text_block.

@ini_set('max_execution_time', '600');
@set_time_limit(600);
@ignore_user_abort(true);

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    $secret = isset($_GET['key']) ? trim($_GET['key']) : '';
    $specificSessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
} else {
    $opts = getopt('', ['secret:', 'session_id:']);
    $secret = isset($opts['secret']) ? trim($opts['secret']) : '';
    $specificSessionId = isset($opts['session_id']) ? (int)$opts['session_id'] : 0;
}

if ($secret !== COMPRESSION_SECRET) {
    if ($isCli) {
        fwrite(STDERR, "Error: clave inválida\n");
        exit(1);
    } else {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Clave inválida']);
        exit;
    }
}

$results = [
    'ok' => true,
    'sessions_processed' => 0,
    'level_0_to_1' => 0,
    'level_1_to_2' => 0,
    'level_2_to_3' => 0,
    'synced_primordial' => 0,
    'extracted_knowledge' => 0,
    'errors' => [],
    'duration_ms' => 0,
];

$startTime = microtime(true);

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
    $results['errors'][] = 'bootstrap: ' . $e->getMessage();
    finish($results, $isCli, $startTime);
}

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    $results['ok'] = false;
    $results['errors'][] = 'DB no disponible';
    finish($results, $isCli, $startTime);
}

// ===== Runtime dinámico de agentes IA =====
$aiRuntimeFile = __DIR__ . '/includes/ai_agent_runtime.php';
if (!is_file($aiRuntimeFile)) {
    $results['ok'] = false;
    $results['errors'][] = 'Falta includes/ai_agent_runtime.php';
    finish($results, $isCli, $startTime);
}
require_once $aiRuntimeFile;

// ✅ CORRECCIÓN CRÍTICA: Forzar utf8mb4 para evitar errores de collation
if (!$db_connection->set_charset('utf8mb4')) {
    $results['errors'][] = 'Error charset utf8mb4: ' . $db_connection->error;
    finish($results, $isCli, $startTime);
}

if (!class_exists('Aws\\BedrockRuntime\\BedrockRuntimeClient')) {
    $results['ok'] = false;
    $results['errors'][] = 'AWS SDK no cargado';
    finish($results, $isCli, $startTime);
}

// ===== Locking =====
$lockFile = sys_get_temp_dir() . '/compression_queue.lock';
$lockFp = fopen($lockFile, 'w');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    $results['message'] = 'Otro proceso ya está ejecutando la compresión';
    finish($results, $isCli, $startTime);
}

// ===== Inicializar Bedrock =====
try {
    $region = (class_exists('Config') && defined('Config::REGION') && Config::REGION) ? Config::REGION : 'us-east-1';
    $ak = getenv('AWS_ACCESS_KEY_ID') ?: (defined('Config::ACCESS_KEY') ? Config::ACCESS_KEY : '');
    $sk = getenv('AWS_SECRET_ACCESS_KEY') ?: (defined('Config::SECRET_KEY') ? Config::SECRET_KEY : '');
    if (empty($ak) || empty($sk)) throw new RuntimeException('Faltan credenciales AWS');
    
    $bedrock = new Aws\BedrockRuntime\BedrockRuntimeClient([
        'region'      => $region,
        'version'     => 'latest',
        'credentials' => ['key' => $ak, 'secret' => $sk],
        'http'        => ['connect_timeout' => 20, 'timeout' => 120],
    ]);
} catch (Throwable $e) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    $results['ok'] = false;
    $results['errors'][] = 'Bedrock init: ' . $e->getMessage();
    finish($results, $isCli, $startTime);
}

// =======================================================================
// ✅ NUEVO (1): TABLA DE PRECIOS + FUNCIÓN ÚNICA DE REGISTRO DE COSTO
// =======================================================================

/**
 * Devuelve el precio por 1,000,000 de tokens (input/output) para un modelo.
 * Centraliza TODOS los precios en un solo lugar. Si agregas un modelo nuevo,
 * solo lo agregas aquí y automáticamente aplica en cualquier función que
 * llame a logTokenUsage().
 */
// ==========================================
// 2. FUNCIÓN DE PRECIOS (Blindada contra errores)
// ==========================================
function getModelPricing(string $modelId): array {
    $m = strtolower($modelId);

    // Modelos Amazon Nova (Los más estables y sin bloqueos "Legacy")
    if (strpos($m, 'nova-micro') !== false) {
        return ['input' => 0.035, 'output' => 0.14];
    }
    if (strpos($m, 'nova-lite') !== false) {
        return ['input' => 0.06, 'output' => 0.24];
    }
    if (strpos($m, 'nova-pro') !== false) {
        return ['input' => 0.80, 'output' => 3.20];
    }

    // Fallback de seguridad: Si por alguna razón llega un ID de Anthropic,
    // forzamos el precio de Nova Lite para evitar cobros sorpresa, 
    // pero lo ideal es que tu código ya no envíe IDs de Anthropic.
    if (strpos($m, 'claude') !== false || strpos($m, 'anthropic') !== false) {
        return ['input' => 0.06, 'output' => 0.24]; 
    }

    // Fallback final conservador
    return ['input' => 0.06, 'output' => 0.24];
}

function calculateCost(string $modelId, int $inputTokens, int $outputTokens): float {
    $pricing = getModelPricing($modelId);
    
    // Cálculo preciso por millón de tokens
    $cost = ($inputTokens / 1000000 * $pricing['input']) + ($outputTokens / 1000000 * $pricing['output']);
    
    return round($cost, 6);
}
/*
function getModelPricing(string $modelId): array {
    $m = strtolower($modelId);

    if (strpos($m, 'nova-micro') !== false) {
        return ['input' => 0.035, 'output' => 0.14];
    }
    if (strpos($m, 'nova-lite') !== false) {
        return ['input' => 0.06, 'output' => 0.24];
    }
    if (strpos($m, 'nova-pro') !== false) {
        return ['input' => 0.80, 'output' => 3.20];
    }
    if (strpos($m, 'claude-3-haiku') !== false || strpos($m, 'claude-3-haiku') !== false) {
        return ['input' => 0.80, 'output' => 4.00];
    }
    if (strpos($m, 'haiku') !== false) {
        // Claude 3 Haiku (u otro Haiku no catalogado explícitamente arriba)
        return ['input' => 0.25, 'output' => 1.25];
    }
    if (strpos($m, 'sonnet') !== false) {
        return ['input' => 3.00, 'output' => 15.00];
    }

    // Fallback conservador si el modelo no está catalogado (evita costo $0 silencioso)
    return ['input' => 0.25, 'output' => 1.25];
}

function calculateCost(string $modelId, int $inputTokens, int $outputTokens): float {
    $pricing = getModelPricing($modelId);
    $cost = ($inputTokens / 1000000 * $pricing['input']) + ($outputTokens / 1000000 * $pricing['output']);
    return round($cost, 6);
}*/

/**
 * ✅ FUNCIÓN ÚNICA DE REGISTRO DE USO/COSTO DE TOKENS.
 *
 * Esta es la ÚNICA función en todo el script que escribe en TokenUsage.
 * Se le pasan los datos ya calculados (sessionId, msgId, phase, modelId,
 * tokens) y ella sola calcula el costo (via calculateCost) y hace el INSERT.
 *
 * Antes esta lógica estaba copiada y pegada 3 veces (compressWithHaiku,
 * extractKnowledgeFromSessions, generateSessionMetaSummary) con fórmulas de
 * precio ligeramente distintas entre sí. Ahora todo pasa por un solo punto.
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

// =======================================================================
// ✅ RUNTIME DINÁMICO PARA TAREAS DE MEMORIA
// =======================================================================
function contentLooksLikeCode(string $text): bool {
    return (bool)preg_match('/\b(function|class|const|let|var|import|export|return|if\s*\(|echo|print|<\?php|=>|<div|<script|error|bug|c[oó]digo|archivo|file|script|variable|array|json|php|js|html|css|sql|query|database|bd|api|endpoint|config|route|controller|model)\b/i', $text);
}

function selectMemoryAgentKey(string $text): string {
    return contentLooksLikeCode($text) ? 'smart_memory_code' : 'smart_memory_general';
}

/**
 * Ejecuta una tarea de memoria usando:
 * - modelo: smart_memory_general o smart_memory_code
 * - instrucciones/plantilla/parámetros: promptKey específico de la tarea
 *
 * Si el modelo o la tarea están desactivados, devuelve null y el proceso continúa
 * sin hacer esa llamada de IA.
 */
function runConfiguredMemoryTask($bedrock, string $selectionText, string $promptKey, array $vars): ?array {
    $memoryAgentKey = selectMemoryAgentKey($selectionText);
    if (!aiAgentConfig($memoryAgentKey)) throw new RuntimeException("Falta el registro {$memoryAgentKey} en UserAIAgentConfigs");
    if (!aiAgentConfig($promptKey)) throw new RuntimeException("Falta el registro {$promptKey} en UserAIAgentConfigs");
    if (!aiAgentActive($memoryAgentKey, false) || !aiAgentActive($promptKey, false)) return null;

    $modelId = aiAgentModel($memoryAgentKey, '');
    if ($modelId === '') throw new RuntimeException("{$memoryAgentKey} no tiene model_id");

    $systemPrompt = aiAgentInstruction($promptKey, '');
    $userTemplate = aiAgentUserTemplate($promptKey, '');
    if ($systemPrompt === '' && $userTemplate === '') {
        throw new RuntimeException("{$promptKey} no tiene instrucciones ni plantilla");
    }

    $userPrompt = aiRenderTemplate($userTemplate, $vars);
    if ($userPrompt === '') $userPrompt = aiRenderTemplate($systemPrompt, $vars);

    $infer = [
        'maxTokens' => max(100, (int)aiAgentExtra($promptKey, 'max_tokens', 1000)),
        'temperature' => (float)aiAgentExtra($promptKey, 'temperature', 0.2),
        'topP' => (float)aiAgentExtra($promptKey, 'top_p', 0.9),
    ];
    $seed = max(0, (int)aiAgentValue($memoryAgentKey, 'seed', 0));
    if ($seed > 0) $infer['seed'] = $seed;

    $params = [
        'modelId' => $modelId,
        'messages' => [['role'=>'user','content'=>[['text'=>$userPrompt]]]],
        'inferenceConfig' => $infer,
    ];
    if ($systemPrompt !== '') $params['system'] = [['text'=>$systemPrompt]];

    $res = $bedrock->converse($params);
    $out = '';
    foreach (($res['output']['message']['content'] ?? []) as $block) if (isset($block['text'])) $out .= $block['text'];

    return [
        'text' => trim($out),
        'model' => $modelId,
        'agent_key' => $memoryAgentKey,
        'prompt_key' => $promptKey,
        'inputTokens' => (int)($res['usage']['inputTokens'] ?? 0),
        'outputTokens' => (int)($res['usage']['outputTokens'] ?? 0),
    ];
}

function enqueueEmbeddingJobFromRuntime(mysqli $db, string $targetType, int $targetId): bool {
    if (!aiAgentActive('embedding_main', false)) return false;
    $modelId = aiAgentModel('embedding_main', '');
    if ($modelId === '') return false;

    $stmt = $db->prepare("INSERT IGNORE INTO EmbeddingJobs (target_type, target_id, model_id, status, attempts) VALUES (?, ?, ?, 'pending', 0)");
    if (!$stmt) return false;
    $stmt->bind_param('sis', $targetType, $targetId, $modelId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

// ===== Función: Generar resumen fusionando los content_preview existentes =====
function compressWithHaiku($bedrock, array $blocks, string $targetLevel, mysqli $db, int $sessionId): string {
    $allText = '';
    $blockText = '';
    foreach ($blocks as $idx => $block) {
        $allText .= ($block['content_preview'] ?? '') . ' ' . ($block['question_text'] ?? '') . ' ' . ($block['answer_text'] ?? '');
        $blockText .= "--- Bloque " . ($idx + 1) . " ---\n";

        // Para level_0 usamos siempre el Q&A íntegro enlazado en ChatMessages.
        // content_preview es solo una vista rápida y no una memoria resumida.
        if (!empty($block['question_text']) || !empty($block['answer_text'])) {
            if (!empty($block['question_text'])) $blockText .= "Pregunta: " . $block['question_text'] . "\n";
            if (!empty($block['answer_text'])) $blockText .= "Respuesta: " . $block['answer_text'] . "\n";
        } elseif (!empty($block['content_preview'])) {
            $blockText .= "Resumen pre-existente: " . $block['content_preview'] . "\n";
        }
        $blockText .= "\n";
    }

    $ai = runConfiguredMemoryTask($bedrock, $allText, 'context_compression_prompt', [
        'blocks' => $blockText,
        'target_level' => $targetLevel,
    ]);
    if ($ai === null || trim((string)$ai['text']) === '') return '';

    logTokenUsage($db, $sessionId, null, 'compile', (string)$ai['model'], (int)$ai['inputTokens'], (int)$ai['outputTokens']);
    return trim((string)$ai['text']);
}


// ===== Función: Obtener sesiones que necesitan compresión =====
// ✅ ANTI-CICLO: ahora se apoya en el flag ChatSessions.pending_summary, que
// process_embedding_queue.php la enciende cuando una sesión acumula
// RECENT_WINDOW + LEVEL0_BATCH_SIZE bloques level_0 ya vectorizados. Se evita el JOIN + GROUP BY + COUNT sobre todas las
// sesiones abiertas, y una sesión sale de la cola en cuanto el cron la procesa
// y apaga el flag (ver el foreach principal más abajo).
function getSessionsNeedingCompression(mysqli $db, int $limit, int $specificSessionId = 0): array {
    if ($specificSessionId > 0) {
        $sql = "SELECT cs.id_, cs.user_id_, cs.context_level, cs.last_compressed_at FROM ChatSessions cs WHERE cs.status='open' AND cs.id_ = ? LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $specificSessionId);
    } else {
        $sql = "SELECT cs.id_, cs.user_id_, cs.context_level, cs.last_compressed_at FROM ChatSessions cs WHERE cs.status='open' AND cs.pending_summary=1 ORDER BY cs.last_compressed_at ASC LIMIT ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $limit);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $sessions = [];
    while ($row = $res->fetch_assoc()) $sessions[] = $row;
    $stmt->close();
    return $sessions;
}

// ===== Función: Comprimir nivel 0 → nivel 1 =====
function compressLevel0ToLevel1(mysqli $db, $bedrock, int $sessionId): int {
    $sql = "
        SELECT scb.id_, scb.content_preview, scb.question_msg_id, scb.answer_msg_id,
        q.content AS question_text, a.content AS answer_text
        FROM SessionContextBlocks scb
        LEFT JOIN ChatMessages q ON scb.question_msg_id = q.id_
        LEFT JOIN ChatMessages a ON scb.answer_msg_id = a.id_
        WHERE scb.session_id_ = ? 
          AND scb.block_type = 'level_0' 
          AND scb.is_locked = 0
          AND scb.embedding_json IS NOT NULL -- ✅ CRÍTICO: Solo comprimir bloques YA procesados por la cola de embeddings
        ORDER BY scb.created_at ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $res = $stmt->get_result();
    $allBlocks = [];
    while ($row = $res->fetch_assoc()) $allBlocks[] = $row;
    $stmt->close();

    if (count($allBlocks) < (RECENT_WINDOW + LEVEL0_BATCH_SIZE)) return 0;

    $compressibleCount = count($allBlocks) - RECENT_WINDOW;
    $fullBatchCount = intdiv($compressibleCount, LEVEL0_BATCH_SIZE);
    if ($fullBatchCount <= 0) return 0;

    // Solo grupos completos: nunca crear un level_1 con 1-4 Q&A.
    $blocksToCompress = array_slice(
        $allBlocks,
        0,
        $fullBatchCount * LEVEL0_BATCH_SIZE
    );
    $chunks = array_chunk($blocksToCompress, LEVEL0_BATCH_SIZE);
    $compressedCount = 0;

    foreach ($chunks as $chunk) {
        $summary = compressWithHaiku($bedrock, $chunk, 'level_1', $db, $sessionId);
        if (empty($summary)) continue;

        $sourceIds = array_map(function($b) {
            return 'q' . ($b['question_msg_id'] ?? '?') . '_a' . ($b['answer_msg_id'] ?? '?');
        }, $chunk);
        $tokenCount = (int)ceil(mb_strlen($summary) / 4);
        
        $fullSummary = $summary; 
        $sourceIdsJson = json_encode($sourceIds);

        $nextId = 0;
        $rs = $db->query("SELECT IFNULL(MAX(id_),0)+1 AS nxt FROM SessionContextBlocks");
        if ($rs) { $nextId = (int)($rs->fetch_assoc()['nxt'] ?? 1); $rs->free(); }

        $stmtInsert = $db->prepare("INSERT INTO SessionContextBlocks (id_, session_id_, block_type, content_preview, source_ids, token_count, is_locked) VALUES (?, ?, 'level_1', ?, ?, ?, 0)");
        $stmtInsert->bind_param('iissi', $nextId, $sessionId, $fullSummary, $sourceIdsJson, $tokenCount);
        $stmtInsert->execute();
        $stmtInsert->close();

        // NO borrar los level_0: son la memoria histórica vectorizada.
        // is_locked=1 significa "ya consolidado"; la compresión no lo procesa otra vez,
        // pero la memoria selectiva puede seguir recuperándolo por embedding.
        $idsToLock = array_column($chunk, 'id_');
        if (!empty($idsToLock)) {
            $placeholders = implode(',', array_fill(0, count($idsToLock), '?'));
            $types = str_repeat('i', count($idsToLock));
            $stmtLock = $db->prepare("UPDATE SessionContextBlocks SET is_locked = 1 WHERE id_ IN ($placeholders)");
            $stmtLock->bind_param($types, ...$idsToLock);
            $stmtLock->execute();
            $stmtLock->close();
        }
        $compressedCount++;
    }
    return $compressedCount;
}

// ===== Función: Comprimir nivel 1 → nivel 2 =====
function compressLevel1ToLevel2(mysqli $db, $bedrock, int $sessionId): int {
    $sql = "SELECT id_, content_preview, source_ids FROM SessionContextBlocks WHERE session_id_ = ? AND block_type = 'level_1' ORDER BY created_at ASC";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $res = $stmt->get_result();
    $blocks = [];
    while ($row = $res->fetch_assoc()) $blocks[] = $row;
    $stmt->close();

    if (count($blocks) < 4) return 0;

    $chunk = array_slice($blocks, 0, 4);
    $contentForHaiku = [];
    foreach ($chunk as $idx => $block) {
        $contentForHaiku[] = ['id_' => $block['id_'], 'question_text' => "Summary block " . ($idx + 1), 'answer_text' => $block['content_preview']];
    }

    $summary = compressWithHaiku($bedrock, $contentForHaiku, 'level_2', $db, $sessionId);
    if (empty($summary)) return 0;

    $allSourceIds = [];
    foreach ($chunk as $block) {
        $ids = json_decode($block['source_ids'], true);
        if (is_array($ids)) $allSourceIds = array_merge($allSourceIds, $ids);
    }

    $tokenCount = (int)ceil(mb_strlen($summary) / 4);
    
    // Guardamos el resumen COMPLETO, no truncado a 300 caracteres
    $fullSummary = $summary;
    $sourceIdsJson = json_encode($allSourceIds);

    $nextId = 0;
    $rs = $db->query("SELECT IFNULL(MAX(id_),0)+1 AS nxt FROM SessionContextBlocks");
    if ($rs) { $nextId = (int)($rs->fetch_assoc()['nxt'] ?? 1); $rs->free(); }

    $stmtInsert = $db->prepare("INSERT INTO SessionContextBlocks (id_, session_id_, block_type, content_preview, source_ids, token_count, is_locked) VALUES (?, ?, 'level_2', ?, ?, ?, 0)");
    $stmtInsert->bind_param('iissi', $nextId, $sessionId, $fullSummary, $sourceIdsJson, $tokenCount);
    $stmtInsert->execute();
    $stmtInsert->close();

    $idsToDelete = array_column($chunk, 'id_');
    $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
    $types = str_repeat('i', count($idsToDelete));
    $stmtDel = $db->prepare("DELETE FROM SessionContextBlocks WHERE id_ IN ($placeholders)");
    $stmtDel->bind_param($types, ...$idsToDelete);
    $stmtDel->execute();
    $stmtDel->close();

    return 1;
}

// ===== Función: Comprimir nivel 2 → nivel 3 =====
function compressLevel2ToLevel3(mysqli $db, $bedrock, int $sessionId): int {
    $sql = "SELECT id_, content_preview, source_ids FROM SessionContextBlocks WHERE session_id_ = ? AND block_type = 'level_2' ORDER BY created_at ASC";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $res = $stmt->get_result();
    $blocks = [];
    while ($row = $res->fetch_assoc()) $blocks[] = $row;
    $stmt->close();

    if (count($blocks) < 4) return 0;

    $chunk = array_slice($blocks, 0, 4);
    $contentForHaiku = [];
    foreach ($chunk as $idx => $block) {
        $contentForHaiku[] = ['id_' => $block['id_'], 'question_text' => "Macro summary " . ($idx + 1), 'answer_text' => $block['content_preview']];
    }

    $summary = compressWithHaiku($bedrock, $contentForHaiku, 'level_3', $db, $sessionId);
    if (empty($summary)) return 0;

    $allSourceIds = [];
    foreach ($chunk as $block) {
        $ids = json_decode($block['source_ids'], true);
        if (is_array($ids)) $allSourceIds = array_merge($allSourceIds, $ids);
    }

    $tokenCount = (int)ceil(mb_strlen($summary) / 4);
    
    // Guardamos el resumen COMPLETO, no truncado a 300 caracteres
    $fullSummary = $summary;
    $sourceIdsJson = json_encode($allSourceIds);

    $nextId = 0;
    $rs = $db->query("SELECT IFNULL(MAX(id_),0)+1 AS nxt FROM SessionContextBlocks");
    if ($rs) { $nextId = (int)($rs->fetch_assoc()['nxt'] ?? 1); $rs->free(); }

    $stmtInsert = $db->prepare("INSERT INTO SessionContextBlocks (id_, session_id_, block_type, content_preview, source_ids, token_count, is_locked) VALUES (?, ?, 'level_3', ?, ?, ?, 0)");
    $stmtInsert->bind_param('iissi', $nextId, $sessionId, $fullSummary, $sourceIdsJson, $tokenCount);
    $stmtInsert->execute();
    $stmtInsert->close();

    $idsToDelete = array_column($chunk, 'id_');
    $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
    $types = str_repeat('i', count($idsToDelete));
    $stmtDel = $db->prepare("DELETE FROM SessionContextBlocks WHERE id_ IN ($placeholders)");
    $stmtDel->bind_param($types, ...$idsToDelete);
    $stmtDel->execute();
    $stmtDel->close();

    return 1;
}

// =======================================================================
// FUNCIONES: EXTRACCIÓN DE CONTEXTO DE PROYECTO
// =======================================================================

/**
 * Sincroniza los mensajes marcados como "primordiales" (estrella dorada) 
 * directamente a la tabla ProjectContext como reglas absolutas.
 */
function syncPrimordialRules(mysqli $db): int {
    $sql = "
        SELECT cm.id_, cm.content, cm.created_at, cs.project_id_, cs.user_id_
        FROM ChatMessages cm
        JOIN ChatSessions cs ON cm.session_id_ = cs.id_
        WHERE cm.is_primordial = 1 AND cm.role = 'assistant' AND cs.project_id_ IS NOT NULL AND cs.status = 'open'
        AND NOT EXISTS (
            SELECT 1 FROM ProjectContext pc
            WHERE pc.project_id_ = cs.project_id_ AND pc.source_chunk_id = cm.id_
        )
    ";
    $res = $db->query($sql);
    $count = 0;
    while ($row = $res->fetch_assoc()) {
        $projectId = (int)$row['project_id_'];
        $userId = max(1, (int)$row['user_id_']);
        $msgId = (int)$row['id_'];
        $content = trim((string)$row['content']);
        if ($content === '') continue;

        $title = mb_substr($content, 0, 50) . (mb_strlen($content) > 50 ? '...' : '');
        $type = 'rule';
        $stmtIns = $db->prepare("INSERT INTO ProjectContext (project_id_, type, title, content, source_chunk_id) VALUES (?, ?, ?, ?, ?)");
        if ($stmtIns) {
            $stmtIns->bind_param('isssi', $projectId, $type, $title, $content, $msgId);
            if ($stmtIns->execute()) {
                $count++;
                $newProjectContextId = (int)$stmtIns->insert_id;
                aiRuntimeLoad($db, $userId);
                enqueueEmbeddingJobFromRuntime($db, 'project_context', $newProjectContextId);
            }
            $stmtIns->close();
        }
    }
    $res->free();
    return $count;
}

/**
 * Extrae conocimiento tácito (reglas, decisiones, hechos, tareas)
 * de los bloques de sesión comprimidos usando el modelo compilador.
 *
 * ✅ CAMBIO: el modelo se obtiene de smart_memory_general/code según el contenido.
 * ✅ CAMBIO: el registro de costo ahora pasa por logTokenUsage().
 */
function extractKnowledgeFromSessions(mysqli $db, $bedrock): int {
    $sql = "
        SELECT scb.id_, scb.session_id_, scb.answer_msg_id, scb.content_preview, cs.project_id_, cs.user_id_
        FROM SessionContextBlocks scb
        JOIN ChatSessions cs ON scb.session_id_ = cs.id_
        WHERE scb.block_type IN ('level_1','level_2','level_3')
          AND cs.project_id_ IS NOT NULL AND cs.status='open'
          AND NOT EXISTS (
              SELECT 1 FROM ProjectContext pc
              WHERE pc.project_id_ = cs.project_id_ AND pc.source_chunk_id = scb.id_
          )
        ORDER BY scb.created_at DESC
        LIMIT 30
    ";
    $res = $db->query($sql);
    $blocksByProject=[];
    while ($row=$res->fetch_assoc()) {
        $pid=(int)$row['project_id_'];
        if (!isset($blocksByProject[$pid])) $blocksByProject[$pid]=[];
        $blocksByProject[$pid][]=$row;
    }
    $res->free();
    $totalExtracted=0;

    foreach ($blocksByProject as $projectId=>$blocks) {
        if (!$blocks) continue;
        $userId=max(1,(int)($blocks[0]['user_id_'] ?? 1));
        aiRuntimeLoad($db,$userId);

        $textForModel=''; $blockIds=[];
        foreach ($blocks as $idx=>$b) {
            $textForModel .= '--- Bloque '.($idx+1).' (ID: '.$b['id_'].") ---\n".$b['content_preview']."\n";
            $blockIds[]=(int)$b['id_'];
        }

        try {
            $ai = runConfiguredMemoryTask($bedrock, $textForModel, 'project_knowledge_extractor_prompt', ['blocks'=>$textForModel]);
            if ($ai === null) continue;
            $aiText=(string)$ai['text'];
            if ((int)$ai['inputTokens']>0 || (int)$ai['outputTokens']>0) {
                $rawMsgId=$blocks[0]['answer_msg_id'] ?? null;
                $tcMsgId=($rawMsgId!==null && $rawMsgId>0)?(int)$rawMsgId:null;
                logTokenUsage($db,(int)$blocks[0]['session_id_'],$tcMsgId,'compile',(string)$ai['model'],(int)$ai['inputTokens'],(int)$ai['outputTokens']);
            }

            $jsonStr=trim($aiText);
            if (preg_match('/\[[\s\S]*\]/',$jsonStr,$matches)) $jsonStr=$matches[0];
            $jsonStr=preg_replace('/^```json\s*/i','',$jsonStr);
            $jsonStr=preg_replace('/\s*```$/i','',$jsonStr);
            $items=json_decode($jsonStr,true);

            if ($items === null) {
                foreach ($blockIds as $bId) {
                    $stmtMark=$db->prepare("INSERT INTO ProjectContext (project_id_, type, title, content, source_chunk_id) VALUES (?, 'note', 'Error de procesamiento', 'El bloque no pudo ser analizado correctamente.', ?)");
                    if ($stmtMark) { $stmtMark->bind_param('ii',$projectId,$bId); $stmtMark->execute(); $stmtMark->close(); }
                }
                continue;
            }

            if (is_array($items)) {
                foreach ($items as $item) {
                    $type=$item['type'] ?? 'fact';
                    if (!in_array($type,['rule','decision','fact','style','todo','note'],true)) $type='fact';
                    $title=trim((string)($item['title'] ?? 'Sin título'));
                    $content=trim((string)($item['content'] ?? ''));
                    if ($content==='' || mb_strlen($content)<20) continue;
                    $check=$db->prepare("SELECT id_ FROM ProjectContext WHERE project_id_=? AND content=? LIMIT 1");
                    $check->bind_param('is',$projectId,$content); $check->execute(); $exists=$check->get_result()->fetch_assoc(); $check->close();
                    if ($exists) continue;
                    $sourceId=$blockIds[0] ?? null;
                    $ins=$db->prepare("INSERT INTO ProjectContext (project_id_, type, title, content, source_chunk_id) VALUES (?, ?, ?, ?, ?)");
                    if ($ins) {
                        $ins->bind_param('isssi',$projectId,$type,$title,$content,$sourceId);
                        if ($ins->execute()) {
                            $totalExtracted++;
                            enqueueEmbeddingJobFromRuntime($db,'project_context',(int)$ins->insert_id);
                        }
                        $ins->close();
                    }
                }
            }

            foreach ($blockIds as $bId) {
                $stmtMark=$db->prepare("INSERT INTO ProjectContext (project_id_, type, title, content, source_chunk_id) VALUES (?, 'note', 'Analizado', 'Bloque ya procesado por el extractor de conocimiento.', ?)");
                if ($stmtMark) { $stmtMark->bind_param('ii',$projectId,$bId); $stmtMark->execute(); $stmtMark->close(); }
            }
        } catch (Throwable $e) {
            error_log("Error extrayendo conocimiento para proyecto $projectId: ".$e->getMessage());
        }
    }
    return $totalExtracted;
}


// =======================================================================
// FUNCIÓN: GENERAR RESUMEN MAESTRO (META-RESUMEN) CON IA
// =======================================================================
/**
 * Genera un resumen maestro y coherente de toda la sesión a partir de los bloques.
 * Registra el uso de tokens y costos vía logTokenUsage().
 *
 * ✅ CAMBIO: el modelo ya NO es siempre COMPILER_MODEL; se decide con
 * selectModelForContent() según si los bloques contienen código/temas técnicos.
 */
function generateSessionMetaSummary(mysqli $db, $bedrock, int $sessionId, array $blocks, string $previousSummary = ''): string {
    if (empty($blocks)) return '';
    $blockText=''; $selectionText=$previousSummary;
    foreach ($blocks as $idx=>$block) {
        $blockText .= '--- Bloque '.($idx+1).' ('.strtoupper((string)$block['block_type']).") ---\n";
        $blockText .= (($block['content_preview'] ?? '') ?: 'Sin contenido')."\n";
        $selectionText .= ' '.($block['content_preview'] ?? '');
    }

    $previousSection = '';
    if ($previousSummary !== '') {
        $previousSection = "RESUMEN ANTERIOR (Ya existe, debes integrarlo y actualizarlo con la nueva información, no lo repitas tal cual, fusionalo en una sola narrativa):\n" . $previousSummary . "\n\n";
    }

    $ai=runConfiguredMemoryTask($bedrock,$selectionText,'session_meta_summary_prompt',[
        'previous_section'=>$previousSection,
        'blocks'=>$blockText,
    ]);
    if ($ai===null || trim((string)$ai['text'])==='') return '';
    logTokenUsage($db,$sessionId,null,'compile',(string)$ai['model'],(int)$ai['inputTokens'],(int)$ai['outputTokens']);
    return trim((string)$ai['text']);
}

// =======================================================================
// ✅ MEMORIA PROCEDURAL: Detección automática de patrones del usuario
// Analiza los bloques de sesión y extrae reglas, preferencias y correcciones.
// Los costos de la IA se registran en TokenUsage vía logTokenUsage().
// =======================================================================
function extractProceduralMemory(mysqli $db, $bedrock, int $sessionId, int $userId, ?string $forceSessionIds = null): int {
    if ($forceSessionIds !== null) {
        $ids=array_filter(array_map('intval',explode(',',$forceSessionIds)));
        if (!$ids) return 0;
        $placeholders=implode(',',array_fill(0,count($ids),'?')); $types=str_repeat('i',count($ids));
        $sql="SELECT scb.id_, scb.content_preview, scb.session_id_, scb.created_at FROM SessionContextBlocks scb WHERE scb.session_id_ IN ($placeholders) AND scb.block_type IN ('level_0','level_1') AND scb.is_locked=0 ORDER BY scb.created_at DESC LIMIT 20";
        $stmt=$db->prepare($sql); $stmt->bind_param($types,...$ids);
    } else {
        $sql="SELECT scb.id_, scb.content_preview, scb.session_id_, scb.created_at FROM SessionContextBlocks scb WHERE scb.session_id_=? AND scb.block_type IN ('level_0','level_1') AND scb.is_locked=0 AND NOT EXISTS (SELECT 1 FROM UserProceduralMemory upm WHERE upm.source_session_id=scb.session_id_ AND upm.user_id_=?) ORDER BY scb.created_at DESC LIMIT 10";
        $stmt=$db->prepare($sql); $stmt->bind_param('ii',$sessionId,$userId);
    }
    if (!$stmt) return 0;
    $stmt->execute(); $res=$stmt->get_result(); $blocks=[]; while ($row=$res->fetch_assoc()) $blocks[]=$row; $stmt->close();
    if (!$blocks) return 0;
    $allText=''; foreach ($blocks as $b) $allText .= $b['content_preview']."\n";
    if (mb_strlen($allText)<100) return 0;

    aiRuntimeLoad($db,$userId);
    try {
        $ai=runConfiguredMemoryTask($bedrock,$allText,'procedural_memory_extractor_prompt',['conversation'=>mb_substr($allText,0,6000)]);
        if ($ai===null) return 0;
        $logSessionId=(int)($blocks[0]['session_id_'] ?? $sessionId);
        if ((int)$ai['inputTokens']>0 || (int)$ai['outputTokens']>0) {
            logTokenUsage($db,$logSessionId,null,'compile',(string)$ai['model'],(int)$ai['inputTokens'],(int)$ai['outputTokens']);
        }

        $jsonStr=trim((string)$ai['text']);
        if (preg_match('/\[[\s\S]*\]/',$jsonStr,$matches)) $jsonStr=$matches[0];
        $jsonStr=preg_replace('/^```json\s*/i','',$jsonStr); $jsonStr=preg_replace('/\s*```$/i','',$jsonStr);
        $patterns=json_decode($jsonStr,true);
        if (!is_array($patterns) || !$patterns) return 0;

        $saved=0;
        foreach ($patterns as $p) {
            $type=$p['type'] ?? 'rule';
            if (!in_array($type,['preference','rule','pattern','correction','workflow'],true)) $type='rule';
            $content=trim((string)($p['content'] ?? '')); if (mb_strlen($content)<15) continue;
            $check=$db->prepare("SELECT id_, confidence FROM UserProceduralMemory WHERE user_id_=? AND content=? AND is_active=1 LIMIT 1");
            if (!$check) continue; $check->bind_param('is',$userId,$content); $check->execute(); $existing=$check->get_result()->fetch_assoc(); $check->close();
            if ($existing) {
                $newConfidence=min((int)$existing['confidence']+1,10);
                $upd=$db->prepare("UPDATE UserProceduralMemory SET confidence=?, updated_at=NOW() WHERE id_=?");
                if ($upd) { $upd->bind_param('ii',$newConfidence,$existing['id_']); $upd->execute(); $upd->close(); }
            } else {
                $ins=$db->prepare("INSERT INTO UserProceduralMemory (user_id_, memory_type, content, source_session_id, confidence) VALUES (?, ?, ?, ?, 1)");
                if ($ins) { $ins->bind_param('issi',$userId,$type,$content,$logSessionId); if ($ins->execute()) $saved++; $ins->close(); }
            }
        }
        return $saved;
    } catch (Throwable $e) {
        error_log("Error extrayendo memoria procedural para sesión $sessionId: ".$e->getMessage());
        return 0;
    }
}

// ===== Procesar sesiones (Compresión) =====
$sessions = getSessionsNeedingCompression($db_connection, MAX_SESSIONS_PER_RUN, $specificSessionId);
if (empty($sessions)) {
    $results['message'] = 'No hay sesiones que necesiten compresión (se requieren al menos ' . (RECENT_WINDOW + LEVEL0_BATCH_SIZE) . ' bloques level_0 embebidos y desbloqueados: se consolidan ' . LEVEL0_BATCH_SIZE . ' y se conservan los últimos ' . RECENT_WINDOW . ' recientes).';
} else {
    foreach ($sessions as $session) {
        $sessionId = (int)$session['id_'];
        $sessionUserId = max(1, (int)($session['user_id_'] ?? 1));
        try {
            aiRuntimeLoad($db_connection, $sessionUserId);
            // 1. Ejecutar compresiones jerárquicas
            $compressedL1 = compressLevel0ToLevel1($db_connection, $bedrock, $sessionId);
            $compressedL2 = compressLevel1ToLevel2($db_connection, $bedrock, $sessionId);
            $compressedL3 = compressLevel2ToLevel3($db_connection, $bedrock, $sessionId);
            
            $results['level_0_to_1'] += $compressedL1;
            $results['level_1_to_2'] += $compressedL2;
            $results['level_2_to_3'] += $compressedL3;
            
            
// ✅ MEMORIA PROCEDURAL: Detectar patrones del usuario con el mismo runtime de la sesión
$proceduralSaved = extractProceduralMemory($db_connection, $bedrock, $sessionId, $sessionUserId);
if (!isset($results['procedural_memory_saved'])) $results['procedural_memory_saved'] = 0;
$results['procedural_memory_saved'] += $proceduralSaved;
            
            $newLevel = (int)$session['context_level'];
            if ($compressedL3 > 0) $newLevel = 3; 
            elseif ($compressedL2 > 0) $newLevel = 2;
            elseif ($compressedL1 > 0) $newLevel = 1;
            

            // 2. Leer bloques activos para el meta-resumen.
            // Los level_0 históricos bloqueados quedan exclusivamente para memoria selectiva.
$stmtSummary = $db_connection->prepare("
    SELECT block_type, content_preview, token_count
    FROM SessionContextBlocks
    WHERE session_id_ = ?
      AND block_type NOT IN ('file', 'file_chunk')
      AND NOT (block_type = 'level_0' AND is_locked = 1)
    ORDER BY created_at ASC
    LIMIT 30
");

            $stmtSummary->bind_param('i', $sessionId);
            $stmtSummary->execute();
            $resSummary = $stmtSummary->get_result();
            $allBlocksForMeta = [];
            $totalTokens = 0;
            while ($block = $resSummary->fetch_assoc()) {
                $allBlocksForMeta[] = $block;
                $totalTokens += (int)$block['token_count'];
            }
            $stmtSummary->close();
            
            // 3. Obtener el resumen previo existente en ChatSessions
            $prevSummary = '';
            $stmtPrev = $db_connection->prepare("SELECT context_summary FROM ChatSessions WHERE id_ = ?");
            $stmtPrev->bind_param('i', $sessionId);
            $stmtPrev->execute();
            $resPrev = $stmtPrev->get_result();
            if ($rowPrev = $resPrev->fetch_assoc()) {
                $prevSummary = $rowPrev['context_summary'] ?? '';
            }
            $stmtPrev->close();

            // 3b. ✅ ANTI-CICLO: ¿Hay bloques realmente nuevos desde la última compresión?
            // Sin esto, una sesión estancada en el límite de RECENT_WINDOW nunca sale
            // de la cola: se regenera el mismo resumen con Bedrock en cada corrida del
            // cron y el UPDATE de last_compressed_at solo la reordena, no la libera.
            $hayBloquesNuevos = false;
            if ($session['last_compressed_at'] === null) {
                // Nunca se comprimió: si existe al menos un bloque, es "nuevo" para el resumen.
                $hayBloquesNuevos = !empty($allBlocksForMeta);
            } else {
                $stmtCheck = $db_connection->prepare(
                    "SELECT COUNT(*) AS c FROM SessionContextBlocks WHERE session_id_ = ? AND created_at > ?"
                );
                $stmtCheck->bind_param('is', $sessionId, $session['last_compressed_at']);
                $stmtCheck->execute();
                $rowCheck = $stmtCheck->get_result()->fetch_assoc();
                $hayBloquesNuevos = ((int)($rowCheck['c'] ?? 0)) > 0;
                $stmtCheck->close();
            }

            $huboCompresion = ($compressedL1 + $compressedL2 + $compressedL3) > 0;

            if (!$huboCompresion && !$hayBloquesNuevos) {
                // Nada nuevo: sacar la sesión de la cola hasta que bedrock_chat vuelva a marcarla.
                $stmtClear = $db_connection->prepare("UPDATE ChatSessions SET pending_summary = 0 WHERE id_ = ?");
                if ($stmtClear) { $stmtClear->bind_param('i',$sessionId); $stmtClear->execute(); $stmtClear->close(); }
                continue;
            }

            // 4. Generar resumen maestro (Fusionando previo + nuevos bloques)
            $metaSummary = '';
            if (!empty($allBlocksForMeta)) {
                $metaSummary = generateSessionMetaSummary($db_connection, $bedrock, $sessionId, $allBlocksForMeta, $prevSummary);
            }
            
            // 5. Actualizar ChatSessions
            if (!empty($metaSummary)) {
                $contextSummary = "🧠 Memoria Consolidada (~{$totalTokens} tokens):\n" . $metaSummary;
                $contextSummary = mb_substr($contextSummary, 0, 15000);
                $finalLevel = max($newLevel, 1);
                
                $stmtUpdSummary = $db_connection->prepare("UPDATE ChatSessions SET context_summary = ?, context_level = ?, last_compressed_at = NOW() WHERE id_ = ?");
                $stmtUpdSummary->bind_param('sii', $contextSummary, $finalLevel, $sessionId);
                $stmtUpdSummary->execute();
                $stmtUpdSummary->close();
            }

            // Procesamiento exitoso: liberar bandera anti-ciclo.
            $stmtClear = $db_connection->prepare("UPDATE ChatSessions SET pending_summary = 0 WHERE id_ = ?");
            if ($stmtClear) { $stmtClear->bind_param('i',$sessionId); $stmtClear->execute(); $stmtClear->close(); }
            $results['sessions_processed']++;
        } catch (Throwable $e) {
            $results['errors'][] = "Procesamiento sesión $sessionId: " . $e->getMessage();
        }
    }
}

// =======================================================================
// EJECUTAR EXTRACCIÓN DE CONTEXTO DE PROYECTO
// =======================================================================
try {
    $results['synced_primordial'] = syncPrimordialRules($db_connection);
    $results['extracted_knowledge'] = extractKnowledgeFromSessions($db_connection, $bedrock);
} catch (Throwable $e) {
    $results['errors'][] = 'Extracción contexto proyecto: ' . $e->getMessage();
}

// ===== Liberar lock =====
flock($lockFp, LOCK_UN);
fclose($lockFp);

// ===== Responder =====
finish($results, $isCli, $startTime);

function finish($results, $isCli, $startTime) {
    $results['duration_ms'] = (int)((microtime(true) - $startTime) * 1000);
    if ($isCli) {
        echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo json_encode($results, JSON_UNESCAPED_UNICODE);
    }
    exit;
}