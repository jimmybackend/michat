<?php
/**
 * compress_session_context.php
 *
 * Compresión Jerárquica de Contexto + EXTRACCIÓN DE CONTEXTO DE PROYECTO
 *
 * Niveles de compresión:
 *  - Nivel 0: Q&A crudo (últimos 5 se mantienen sin comprimir)
 *  - Nivel 1: Resumen de cada 5 Q&A (generado por Haiku)
 *  - Nivel 2: Resumen de 4 bloques nivel 1 (~20 Q&A)
 *  - Nivel 3: Resumen de 4 bloques nivel 2 (~80 Q&A)
 *
 * EXTRACCIÓN DE PROYECTO:
 *  - Sincroniza mensajes "primordiales" a ProjectContext.
 *  - Usa el modelo compilador (dinámico) para extraer reglas/decisiones/hechos.
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
//define('COMPILER_MODEL', 'amazon.nova-micro-v1:0'); // Modelo por defecto para contenido NO técnico
//define('MODEL_FOR_CODE', 'anthropic.claude-3-haiku-20240307-v1:0'); // Modelo para contenido técnico/código
define('MAX_SESSIONS_PER_RUN', 10);
define('RECENT_WINDOW', 5); // Últimos N bloques level_0 que NO se comprimen
// ==========================================
// 1. DEFINICIÓN DE MODELOS (100% Estables en AWS Bedrock us-east-1)
// ==========================================
define('COMPILER_MODEL', 'amazon.nova-micro-v1:0');       // Texto no técnico
define('MODEL_FOR_CODE', 'amazon.nova-lite-v1:0');        // Código (PHP, JS, Python, etc.)

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
// ✅ NUEVO (2): SELECCIÓN DE MODELO SEGÚN TIPO DE CONTENIDO (reutilizable)
// =======================================================================

/**
 * Heurística: ¿el texto parece código / contenido técnico?
 * (Antes esta regex vivía únicamente dentro de compressWithHaiku.)
 */
function contentLooksLikeCode(string $text): bool {
    return (bool)preg_match('/\b(function|class|const|let|var|import|export|return|if\s*\(|echo|print|<\?php|=>|<div|<script|error|bug|c[oó]digo|archivo|file|script|variable|array|json|php|js|html|css|sql|query|database|bd|api|endpoint|config|route|controller|model)\b/i', $text);
}

/**
 * Devuelve el modelo a usar según el contenido:
 *  - Código / técnico  -> MODEL_FOR_CODE (Claude 3.5 Haiku, precisión quirúrgica)
 *  - Texto general     -> COMPILER_MODEL (Nova Micro, ahorro)
 *
 * Se usa ahora en las 3 funciones que llaman al modelo compilador
 * (compressWithHaiku, extractKnowledgeFromSessions, generateSessionMetaSummary),
 * en vez de forzar siempre COMPILER_MODEL como pasaba antes.
 */
function selectModelForContent(string $text): string {
    return contentLooksLikeCode($text) ? MODEL_FOR_CODE : COMPILER_MODEL;
}

// ===== Función: Generar resumen fusionando los content_preview existentes =====
function compressWithHaiku($bedrock, array $blocks, string $targetLevel, mysqli $db, int $sessionId): string {
    // 1. Concatenamos todo el texto de los bloques para decidir el modelo
    $allText = '';
    foreach ($blocks as $block) {
        $allText .= ($block['content_preview'] ?? '') . ' ' . ($block['question_text'] ?? '') . ' ' . ($block['answer_text'] ?? '');
    }

    // ✅ Selección de modelo centralizada
    $modelId = selectModelForContent($allText);

    // 2. Prompt con reglas críticas
    $content = "Tu tarea es fusionar los siguientes bloques de conversación (que ya están pre-resumidos) en un solo resumen coherente, fluido y conciso de la sesión. No repitas información.
    
REGLAS CRÍTICAS DE PRESERVACIÓN:
1. Preserva términos técnicos, nombres, fechas y decisiones de arquitectura.
2. REGLA CRÍTICA: NUNCA omitas valores de variables, rutas de archivos, puertos, IPs o credenciales mencionadas. Preserva los datos técnicos exactos (strings, números, rutas) intactos.
3. Si hay código o comandos, mantén la sintaxis exacta.

A continuación, los bloques a fusionar:\n";

    foreach ($blocks as $idx => $block) {
        $content .= "--- Bloque " . ($idx + 1) . " ---\n";
        if (!empty($block['content_preview'])) {
            $content .= "Resumen pre-existente: " . $block['content_preview'] . "\n";
        } else {
            if (!empty($block['question_text'])) $content .= "Pregunta: " . $block['question_text'] . "\n";
            if (!empty($block['answer_text'])) $content .= "Respuesta: " . $block['answer_text'] . "\n";
        }
        $content .= "\n";
    }
    
    $content .= "\nGenera el resumen final unificado de la sesión en el mismo idioma que el contenido original, aplicando estrictamente las reglas críticas:";
    
    // 3. Llamada a Bedrock con el modelo dinámico
    $res = $bedrock->converse([
        'modelId' => $modelId,
        'messages' => [['role' => 'user', 'content' => [['text' => $content]]]],
        'inferenceConfig' => ['maxTokens' => 1500, 'temperature' => 0.2, 'topP' => 0.9] // Temp 0.2 para máxima precisión factual
    ]);
    
    $summary = '';
    foreach (($res['output']['message']['content'] ?? []) as $block) {
        if (isset($block['text'])) $summary .= $block['text'];
    }
    
    $inputTokens = (int)($res['usage']['inputTokens'] ?? 0);
    $outputTokens = (int)($res['usage']['outputTokens'] ?? 0);

    // ✅ Registro de costo: una sola llamada, sin lógica de precios aquí
    logTokenUsage($db, $sessionId, null, 'compile', $modelId, $inputTokens, $outputTokens);
    
    return trim($summary);
}


// ===== Función: Obtener sesiones que necesitan compresión =====
// ✅ ANTI-CICLO: ahora se apoya en el flag ChatSessions.pending_summary, que
// bedrock_chat2.php enciende cuando una sesión acumula más de RECENT_WINDOW
// bloques level_0 nuevos. Se evita el JOIN + GROUP BY + COUNT sobre todas las
// sesiones abiertas, y una sesión sale de la cola en cuanto el cron la procesa
// y apaga el flag (ver el foreach principal más abajo).
function getSessionsNeedingCompression(mysqli $db, int $limit): array {
    $sql = "
        SELECT cs.id_, cs.context_level, cs.last_compressed_at
        FROM ChatSessions cs
        WHERE cs.status = 'open'
        AND cs.pending_summary = 1
        ORDER BY cs.last_compressed_at ASC
        LIMIT ?
    ";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $limit);
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
        SELECT scb.id_, scb.content_preview,
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

    if (count($allBlocks) <= RECENT_WINDOW) return 0;

    $blocksToCompress = array_slice($allBlocks, 0, count($allBlocks) - RECENT_WINDOW);
    if (empty($blocksToCompress)) return 0;

    $chunks = array_chunk($blocksToCompress, 5);
    $compressedCount = 0;

    foreach ($chunks as $chunk) {
        $summary = compressWithHaiku($bedrock, $chunk, 'level_1', $db, $sessionId);
        if (empty($summary)) continue;

        $sourceIds = array_map(function($b) { return 'q' . ($b['id_'] ?? '?') . '_a' . ($b['id_'] ?? '?'); }, $chunk);
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

        $idsToDelete = array_column($chunk, 'id_');
        if (!empty($idsToDelete)) {
            $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
            $types = str_repeat('i', count($idsToDelete));
            $stmtDel = $db->prepare("DELETE FROM SessionContextBlocks WHERE id_ IN ($placeholders)");
            $stmtDel->bind_param($types, ...$idsToDelete);
            $stmtDel->execute();
            $stmtDel->close();
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
        SELECT cm.id_, cm.content, cm.created_at, cs.project_id_
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
        $msgId = (int)$row['id_'];
        $content = trim($row['content']);
        if (empty($content)) continue;

        $title = mb_substr($content, 0, 50) . (mb_strlen($content) > 50 ? '...' : '');
        $type = 'rule'; // Los primordiales son reglas absolutas

        $stmtIns = $db->prepare("INSERT INTO ProjectContext (project_id_, type, title, content, source_chunk_id) VALUES (?, ?, ?, ?, ?)");
        if ($stmtIns) {
            $stmtIns->bind_param('isssi', $projectId, $type, $title, $content, $msgId);
            if ($stmtIns->execute()) {
                $count++;
                $newProjectContextId = $stmtIns->insert_id;

                // Encolar trabajo de embedding para este nuevo contexto
                $jobId = 0;
                $rsJob = $db->query("SELECT IFNULL(MAX(id_),0)+1 AS nxt FROM EmbeddingJobs");
                if ($rsJob) { $jobId = (int)($rsJob->fetch_assoc()['nxt'] ?? 1); $rsJob->free(); }

                $stmtJob = $db->prepare("INSERT INTO EmbeddingJobs (id_, target_type, target_id, model_id, status, attempts) VALUES (?, 'project_context', ?, 'amazon.titan-embed-text-v2:0', 'pending', 0)");
                if ($stmtJob) {
                    $stmtJob->bind_param('ii', $jobId, $newProjectContextId);
                    $stmtJob->execute();
                    $stmtJob->close();
                }
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
 * ✅ CAMBIO: el modelo ya NO es siempre COMPILER_MODEL; se decide con
 * selectModelForContent() según si el texto a analizar es técnico/código.
 * ✅ CAMBIO: el registro de costo ahora pasa por logTokenUsage().
 */
function extractKnowledgeFromSessions(mysqli $db, $bedrock): int {
    // ✅ CRÍTICO: Solo extraer de bloques comprimidos (level_1+) 
    // Y que NO hayan sido procesados antes (NOT EXISTS).
    $sql = "
        SELECT scb.id_, scb.session_id_, scb.answer_msg_id, scb.content_preview, cs.project_id_
        FROM SessionContextBlocks scb
        JOIN ChatSessions cs ON scb.session_id_ = cs.id_
        WHERE scb.block_type IN ('level_1', 'level_2', 'level_3') 
          AND cs.project_id_ IS NOT NULL 
          AND cs.status = 'open'
          AND NOT EXISTS (
              SELECT 1 FROM ProjectContext pc 
              WHERE pc.project_id_ = cs.project_id_ AND pc.source_chunk_id = scb.id_
          )
        ORDER BY scb.created_at DESC
        LIMIT 30
    ";
    
    $res = $db->query($sql);
    $blocksByProject = [];
    while ($row = $res->fetch_assoc()) {
        $pid = (int)$row['project_id_'];
        if (!isset($blocksByProject[$pid])) $blocksByProject[$pid] = [];
        $blocksByProject[$pid][] = $row;
    }
    $res->free();
    
    $totalExtracted = 0;
    
    foreach ($blocksByProject as $projectId => $blocks) {
        if (empty($blocks)) continue;
        
        $textForModel = "";
        $blockIds = [];
        foreach ($blocks as $idx => $b) {
            $textForModel .= "--- Bloque " . ($idx + 1) . " (ID: " . $b['id_'] . ") ---\n" . $b['content_preview'] . "\n";
            $blockIds[] = (int)$b['id_'];
        }

        // ✅ Selección de modelo centralizada (antes: siempre COMPILER_MODEL)
        $modelId = selectModelForContent($textForModel);

        $systemPrompt = "Eres un extractor de conocimiento técnico experto. Analiza los siguientes bloques de conversaciones de un proyecto de software.
REGLAS ESTRICTAS:
1. Extrae SOLO información valiosa y reutilizable: reglas de negocio, decisiones de arquitectura, hechos técnicos importantes.
2. IGNORA mensajes genéricos de confirmación, saludos, errores de conexión o bloques <thinking>.
3. NO extraigas código completo, solo describe qué hace y por qué es importante.
4. Si no hay nada relevante, devuelve exactamente esto: []
Devuelve ÚNICAMENTE un array JSON válido. No incluyas explicaciones, ni markdown, ni texto antes o después del array.
Formato de cada objeto:
- \"type\": 'rule', 'decision', 'fact', 'todo'
- \"title\": título corto (máx 50 caracteres)
- \"content\": descripción detallada (máx 500 caracteres)";

        $userPrompt = "Bloques a analizar:\n" . $textForModel;

        try {
            $aiRes = $bedrock->converse([
                'modelId' => $modelId,
                'messages' => [['role' => 'user', 'content' => [['text' => $userPrompt]]]],
                'system' => [['text' => $systemPrompt]],
                'inferenceConfig' => ['maxTokens' => 1500, 'temperature' => 0.2, 'topP' => 0.9]
            ]);
            
            $aiText = '';
            foreach (($aiRes['output']['message']['content'] ?? []) as $block) {
                if (isset($block['text'])) $aiText .= $block['text'];
            }

            // ✅ Registro de tokens vía función única (antes: bloque duplicado con su propia fórmula de costo)
            $inputTokens = (int)($aiRes['usage']['inputTokens'] ?? 0);
            $outputTokens = (int)($aiRes['usage']['outputTokens'] ?? 0);

            if ($inputTokens > 0 || $outputTokens > 0) {
                $rawMsgId = $blocks[0]['answer_msg_id'] ?? null;
                $tcMsgId = ($rawMsgId !== null && $rawMsgId > 0) ? (int)$rawMsgId : null;
                $logSessionId = (int)($blocks[0]['session_id_'] ?? 0);

                logTokenUsage($db, $logSessionId, $tcMsgId, 'compile', $modelId, $inputTokens, $outputTokens);
            }

            // ✅ LIMPIEZA DE JSON ROBUSTA
            $jsonStr = trim($aiText);
            if (preg_match('/\[[\s\S]*\]/', $jsonStr, $matches)) {
                $jsonStr = $matches[0];
            }
            $jsonStr = preg_replace('/^```json\s*/i', '', $jsonStr);
            $jsonStr = preg_replace('/\s*```$/i', '', $jsonStr);
            
            $items = json_decode($jsonStr, true);

            if ($items === null) {
                // ❌ Error de JSON: Marcamos como procesados para no volver a intentarlo con el mismo error
                foreach ($blockIds as $bId) {
                    $stmtMark = $db->prepare("INSERT INTO ProjectContext (project_id_, type, title, content, source_chunk_id) VALUES (?, 'note', 'Error de procesamiento', 'El bloque no pudo ser analizado correctamente.', ?)");
                    if ($stmtMark) {
                        $stmtMark->bind_param('ii', $projectId, $bId);
                        $stmtMark->execute();
                        $stmtMark->close();
                    }
                }
            } else {
                // ✅ Ya sea array vacío o con elementos, procesamos la extracción
                if (is_array($items) && count($items) > 0) {
                    foreach ($items as $item) {
                        $type = $item['type'] ?? 'fact';
                        if (!in_array($type, ['rule', 'decision', 'fact', 'style', 'todo', 'note'])) {
                            $type = 'fact';
                        }
                        
                        $title = trim($item['title'] ?? 'Sin título');
                        $content = trim($item['content'] ?? '');
                        if (empty($content) || mb_strlen($content) < 20) continue;

                        $checkStmt = $db->prepare("SELECT id_ FROM ProjectContext WHERE project_id_ = ? AND content = ? LIMIT 1");
                        $checkStmt->bind_param('is', $projectId, $content);
                        $checkStmt->execute();
                        $exists = $checkStmt->get_result()->fetch_assoc();
                        $checkStmt->close();
                        if ($exists) continue;

                        $sourceId = $blockIds[0] ?? null;
                        $stmtIns = $db->prepare("INSERT INTO ProjectContext (project_id_, type, title, content, source_chunk_id) VALUES (?, ?, ?, ?, ?)");
                        if ($stmtIns) {
                            $stmtIns->bind_param('isssi', $projectId, $type, $title, $content, $sourceId);
                            if ($stmtIns->execute()) {
                                $totalExtracted++;
                                $newProjectContextId = $stmtIns->insert_id;
                                
                                $jobId = 0;
                                $rsJob = $db->query("SELECT IFNULL(MAX(id_),0)+1 AS nxt FROM EmbeddingJobs");
                                if ($rsJob) { 
                                    $jobId = (int)($rsJob->fetch_assoc()['nxt'] ?? 1); 
                                    $rsJob->free(); 
                                }
                                
                                $stmtJob = $db->prepare("INSERT INTO EmbeddingJobs (id_, target_type, target_id, model_id, status, attempts) VALUES (?, 'project_context', ?, 'amazon.titan-embed-text-v2:0', 'pending', 0)");
                                if ($stmtJob) {
                                    $stmtJob->bind_param('ii', $jobId, $newProjectContextId);
                                    $stmtJob->execute();
                                    $stmtJob->close();
                                }
                            }
                            $stmtIns->close();
                        }
                    }
                }
                
                // ✅ CRÍTICO: Marcar TODOS los bloques del lote como "ya analizados" 
                // para que la condición NOT EXISTS los ignore en el futuro y se rompa el bucle.
                foreach ($blockIds as $bId) {
                    $stmtMark = $db->prepare("INSERT INTO ProjectContext (project_id_, type, title, content, source_chunk_id) VALUES (?, 'note', 'Analizado', 'Bloque ya procesado por el extractor de conocimiento.', ?)");
                    if ($stmtMark) {
                        $stmtMark->bind_param('ii', $projectId, $bId);
                        $stmtMark->execute();
                        $stmtMark->close();
                    }
                }
            }
        } catch (Throwable $e) {
            error_log("Error extrayendo conocimiento para proyecto $projectId: " . $e->getMessage());
        }
    }
    
    return $totalExtracted;
}

// =======================================================================
// ✅ MEMORIA PROCEDURAL: Detectar patrones, preferencias y correcciones
// del usuario a través de sus conversaciones. A diferencia de is_primordial
// (manual, por proyecto), esto es AUTOMÁTICO y GLOBAL (todas las sesiones).
// =======================================================================
function extractProceduralMemory(mysqli $db, $bedrock, int $sessionId, int $userId): int {
    // 1. Obtener los últimos bloques de conversación de esta sesión
    //    que aún no han sido analizados para memoria procedural
    $sql = "
        SELECT scb.id_, scb.content_preview, scb.created_at
        FROM SessionContextBlocks scb
        WHERE scb.session_id_ = ?
          AND scb.block_type IN ('level_0', 'level_1')
          AND scb.is_locked = 0
          AND NOT EXISTS (
              SELECT 1 FROM UserProceduralMemory upm
              WHERE upm.source_session_id = ?
                AND upm.user_id_ = ?
          )
        ORDER BY scb.created_at DESC
        LIMIT 10
    ";

    $stmt = $db->prepare($sql);
    $stmt->bind_param('ii', $sessionId, $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $blocks = [];
    while ($row = $res->fetch_assoc()) {
        $blocks[] = $row;
    }
    $stmt->close();

    if (empty($blocks)) return 0;

    // 2. Concatenar contenido para análisis
    $allText = '';
    foreach ($blocks as $b) {
        $allText .= $b['content_preview'] . "\n";
    }

    if (mb_strlen($allText) < 100) return 0; // Muy poco texto para analizar

    // 3. Usar IA para detectar patrones procedurales
    $modelId = selectModelForContent($allText);

    $systemPrompt = "Eres un detector de PATRONES PROCEDURALES del usuario. Analiza la conversación y detecta SOLO:

1. CORRECCIONES: El usuario corrigió a la IA (ej: 'No, te dije que...', 'Eso está mal, debería ser...')
2. PREFERENCIAS EXPLÍCITAS: 'Siempre usa...', 'Nunca hagas...', 'Prefiero que...'
3. REGLAS DE FORMATO: 'Responde en español', 'Usa markdown', 'Sé conciso'
4. PATRONES DE TRABAJO: 'Primero haz X, luego Y', 'Siempre verifica antes de...'
5. ESTILO: 'No uses emojis', 'Usa tono formal', 'Explica paso a paso'

REGLAS ESTRICTAS:
- SOLO detecta patrones que el usuario ESTABLECIÓ EXPLÍCITAMENTE.
- NO inventes patrones. NO detectes preferencias implícitas.
- Si no hay ningún patrón claro, devuelve exactamente: []
- Máximo 3 patrones por análisis.
- Devuelve ÚNICAMENTE un array JSON válido, sin markdown ni explicaciones.

Formato:
[{\"type\": \"rule|preference|correction|workflow|pattern\", \"content\": \"descripción clara de la regla en español\"}]";

    $userPrompt = "CONVERSACIÓN A ANALIZAR:\n" . mb_substr($allText, 0, 6000) . "\n\nDetecta patrones procedurales:";

    try {
        $res = $bedrock->converse([
            'modelId' => $modelId,
            'messages' => [['role' => 'user', 'content' => [['text' => $userPrompt]]]],
            'system' => [['text' => $systemPrompt]],
            'inferenceConfig' => ['maxTokens' => 500, 'temperature' => 0.1, 'topP' => 0.9]
        ]);

        $aiText = '';
        foreach (($res['output']['message']['content'] ?? []) as $block) {
            if (isset($block['text'])) $aiText .= $block['text'];
        }

        $inputTokens = (int)($res['usage']['inputTokens'] ?? 0);
        $outputTokens = (int)($res['usage']['outputTokens'] ?? 0);

        if ($inputTokens > 0 || $outputTokens > 0) {
            logTokenUsage($db, $sessionId, null, 'compile', $modelId, $inputTokens, $outputTokens);
        }

        // Limpiar JSON
        $jsonStr = trim($aiText);
        if (preg_match('/\[[\s\S]*\]/', $jsonStr, $matches)) {
            $jsonStr = $matches[0];
        }
        $jsonStr = preg_replace('/^```json\s*/i', '', $jsonStr);
        $jsonStr = preg_replace('/\s*```$/i', '', $jsonStr);

        $patterns = json_decode($jsonStr, true);

        if (!is_array($patterns) || empty($patterns)) return 0;

        // 4. Guardar patrones detectados (con deduplicación)
        $saved = 0;
        foreach ($patterns as $p) {
            $type = $p['type'] ?? 'rule';
            if (!in_array($type, ['preference','rule','pattern','correction','workflow'])) {
                $type = 'rule';
            }
            $content = trim($p['content'] ?? '');
            if (mb_strlen($content) < 15) continue;

            // Verificar si ya existe un patrón similar
            $checkStmt = $db->prepare("SELECT id_, confidence FROM UserProceduralMemory WHERE user_id_ = ? AND content = ? AND is_active = 1 LIMIT 1");
            $checkStmt->bind_param('is', $userId, $content);
            $checkStmt->execute();
            $existing = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();

            if ($existing) {
                // Incrementar confianza (se observó de nuevo)
                $newConfidence = min((int)$existing['confidence'] + 1, 10);
                $updStmt = $db->prepare("UPDATE UserProceduralMemory SET confidence = ?, updated_at = NOW() WHERE id_ = ?");
                $updStmt->bind_param('ii', $newConfidence, $existing['id_']);
                $updStmt->execute();
                $updStmt->close();
            } else {
                // Insertar nuevo patrón
                $insStmt = $db->prepare("INSERT INTO UserProceduralMemory (user_id_, memory_type, content, source_session_id, confidence) VALUES (?, ?, ?, ?, 1)");
                $insStmt->bind_param('issi', $userId, $type, $content, $sessionId);
                $insStmt->execute();
                $insStmt->close();
                $saved++;
            }
        }

        return $saved;

    } catch (Throwable $e) {
        error_log("Error extrayendo memoria procedural para sesión $sessionId: " . $e->getMessage());
        return 0;
    }
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
    
    $content = "Tu tarea es crear un resumen maestro, coherente y fluido de toda la sesión de conversación.\n";
    
    // Si ya existe un resumen, lo usamos como base y pedimos a la IA que lo fusione
    if (!empty($previousSummary)) {
        $content .= "RESUMEN ANTERIOR (Ya existe, debes integrarlo y actualizarlo con la nueva información, no lo repitas tal cual, fusionalo en una sola narrativa):\n" . $previousSummary . "\n\n";
    }
    
    $content .= "NUEVOS BLOQUES DE CONVERSACIÓN (Integra esta nueva información al resumen maestro de forma fluida):\n";

    // ✅ Texto acumulado para decidir el modelo (previo + bloques nuevos)
    $allTextForModelSelection = $previousSummary;

    foreach ($blocks as $idx => $block) {
        $content .= "--- Bloque " . ($idx + 1) . " (" . strtoupper($block['block_type']) . ") ---\n";
        $content .= ($block['content_preview'] ?: 'Sin contenido') . "\n";
        $allTextForModelSelection .= ' ' . ($block['content_preview'] ?? '');
    }
    $content .= "\nGenera el resumen maestro final unificado en el mismo idioma que el contenido original. Debe ser un texto fluido y bien redactado, NO una lista de viñetas.";

    // ✅ Selección de modelo centralizada (antes: siempre COMPILER_MODEL)
    $modelId = selectModelForContent($allTextForModelSelection);

    try {
        $res = $bedrock->converse([
            'modelId' => $modelId,
            'messages' => [['role' => 'user', 'content' => [['text' => $content]]]],
            'inferenceConfig' => ['maxTokens' => 2000, 'temperature' => 0.3, 'topP' => 0.9]
        ]);
        
        $summary = '';
        foreach (($res['output']['message']['content'] ?? []) as $block) {
            if (isset($block['text'])) $summary .= $block['text'];
        }
        
        $inputTokens = (int)($res['usage']['inputTokens'] ?? 0);
        $outputTokens = (int)($res['usage']['outputTokens'] ?? 0);

        // ✅ Registro de costo vía función única (antes: fórmula manual duplicada aquí)
        logTokenUsage($db, $sessionId, null, 'compile', $modelId, $inputTokens, $outputTokens);

        return trim($summary);
    } catch (Throwable $e) {
        @file_put_contents(__DIR__ . '/token_usage_debug.log', "[" . date('Y-m-d H:i:s') . "] MetaSummary Error: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        return '';
    }
}

// =======================================================================
// ✅ MEMORIA PROCEDURAL: Detección automática de patrones del usuario
// Analiza los bloques de sesión y extrae reglas, preferencias y correcciones.
// Los costos de la IA se registran en TokenUsage vía logTokenUsage().
// =======================================================================
function extractProceduralMemory(mysqli $db, $bedrock, int $sessionId, int $userId, ?string $forceSessionIds = null): int {
    // Si se pasan IDs forzados, solo analizar esas sesiones
    if ($forceSessionIds !== null) {
        $ids = array_filter(array_map('intval', explode(',', $forceSessionIds)));
        if (empty($ids)) return 0;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $sql = "
            SELECT scb.id_, scb.content_preview, scb.session_id_, scb.created_at
            FROM SessionContextBlocks scb
            WHERE scb.session_id_ IN ($placeholders)
              AND scb.block_type IN ('level_0', 'level_1')
              AND scb.is_locked = 0
            ORDER BY scb.created_at DESC
            LIMIT 20
        ";
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$ids);
    } else {
        // Flujo normal (cron): solo bloques que no han sido analizados para memoria procedural
        $sql = "
            SELECT scb.id_, scb.content_preview, scb.session_id_, scb.created_at
            FROM SessionContextBlocks scb
            WHERE scb.session_id_ = ?
              AND scb.block_type IN ('level_0', 'level_1')
              AND scb.is_locked = 0
              AND NOT EXISTS (
                  SELECT 1 FROM UserProceduralMemory upm
                  WHERE upm.source_session_id = scb.session_id_ AND upm.user_id_ = ?
              )
            ORDER BY scb.created_at DESC
            LIMIT 10
        ";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('ii', $sessionId, $userId);
    }

    if (!$stmt) return 0;
    $stmt->execute();
    $res = $stmt->get_result();
    $blocks = [];
    while ($row = $res->fetch_assoc()) {
        $blocks[] = $row;
    }
    $stmt->close();

    if (empty($blocks)) return 0;

    // Concatenar contenido para análisis
    $allText = '';
    foreach ($blocks as $b) {
        $allText .= $b['content_preview'] . "\n";
    }

    if (mb_strlen($allText) < 100) return 0;

    // Selección de modelo según contenido
    $modelId = selectModelForContent($allText);

    $systemPrompt = "Eres un detector de PATRONES PROCEDURALES del usuario. Analiza la conversación y detecta SOLO:

1. CORRECCIONES: El usuario corrigió a la IA (ej: 'No, te dije que...', 'Eso está mal, debería ser...')
2. PREFERENCIAS EXPLÍCITAS: 'Siempre usa...', 'Nunca hagas...', 'Prefiero que...'
3. REGLAS DE FORMATO: 'Responde en español', 'Usa markdown', 'Sé conciso'
4. PATRONES DE TRABAJO: 'Primero haz X, luego Y', 'Siempre verifica antes de...'
5. ESTILO: 'No uses emojis', 'Usa tono formal', 'Explica paso a paso'

REGLAS ESTRICTAS:
- SOLO detecta patrones que el usuario ESTABLECIÓ EXPLÍCITAMENTE.
- NO inventes patrones. NO detectes preferencias implícitas.
- Si no hay ningún patrón claro, devuelve exactamente: []
- Máximo 3 patrones por análisis.
- Devuelve ÚNICAMENTE un array JSON válido, sin markdown ni explicaciones.

Formato:
[{\"type\": \"rule|preference|correction|workflow|pattern\", \"content\": \"descripción clara de la regla en español\"}]";

    $userPrompt = "CONVERSACIÓN A ANALIZAR:\n" . mb_substr($allText, 0, 6000) . "\n\nDetecta patrones procedurales:";

    try {
        $res = $bedrock->converse([
            'modelId' => $modelId,
            'messages' => [['role' => 'user', 'content' => [['text' => $userPrompt]]]],
            'system' => [['text' => $systemPrompt]],
            'inferenceConfig' => ['maxTokens' => 500, 'temperature' => 0.1, 'topP' => 0.9]
        ]);

        $aiText = '';
        foreach (($res['output']['message']['content'] ?? []) as $block) {
            if (isset($block['text'])) $aiText .= $block['text'];
        }

        $inputTokens = (int)($res['usage']['inputTokens'] ?? 0);
        $outputTokens = (int)($res['usage']['outputTokens'] ?? 0);

        // ✅ REGISTRAR COSTO EN TokenUsage
        $logSessionId = (int)($blocks[0]['session_id_'] ?? $sessionId);
        if ($inputTokens > 0 || $outputTokens > 0) {
            logTokenUsage($db, $logSessionId, null, 'compile', $modelId, $inputTokens, $outputTokens);
        }

        // Limpiar JSON
        $jsonStr = trim($aiText);
        if (preg_match('/\[[\s\S]*\]/', $jsonStr, $matches)) {
            $jsonStr = $matches[0];
        }
        $jsonStr = preg_replace('/^```json\s*/i', '', $jsonStr);
        $jsonStr = preg_replace('/\s*```$/i', '', $jsonStr);

        $patterns = json_decode($jsonStr, true);

        if (!is_array($patterns) || empty($patterns)) return 0;

        // Guardar patrones detectados (con deduplicación)
        $saved = 0;
        foreach ($patterns as $p) {
            $type = $p['type'] ?? 'rule';
            if (!in_array($type, ['preference','rule','pattern','correction','workflow'])) {
                $type = 'rule';
            }
            $content = trim($p['content'] ?? '');
            if (mb_strlen($content) < 15) continue;

            // Verificar si ya existe un patrón similar
            $checkStmt = $db->prepare("SELECT id_, confidence FROM UserProceduralMemory WHERE user_id_ = ? AND content = ? AND is_active = 1 LIMIT 1");
            if (!$checkStmt) continue;
            $checkStmt->bind_param('is', $userId, $content);
            $checkStmt->execute();
            $existing = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();

            if ($existing) {
                $newConfidence = min((int)$existing['confidence'] + 1, 10);
                $updStmt = $db->prepare("UPDATE UserProceduralMemory SET confidence = ?, updated_at = NOW() WHERE id_ = ?");
                if ($updStmt) {
                    $updStmt->bind_param('ii', $newConfidence, $existing['id_']);
                    $updStmt->execute();
                    $updStmt->close();
                }
            } else {
                $insStmt = $db->prepare("INSERT INTO UserProceduralMemory (user_id_, memory_type, content, source_session_id, confidence) VALUES (?, ?, ?, ?, 1)");
                if ($insStmt) {
                    $insStmt->bind_param('issi', $userId, $type, $content, $logSessionId);
                    if ($insStmt->execute()) {
                        $saved++;
                    }
                    $insStmt->close();
                }
            }
        }

        return $saved;

    } catch (Throwable $e) {
        error_log("Error extrayendo memoria procedural para sesión $sessionId: " . $e->getMessage());
        return 0;
    }
}

// ===== Procesar sesiones (Compresión) =====
$sessions = getSessionsNeedingCompression($db_connection, MAX_SESSIONS_PER_RUN);
if (empty($sessions)) {
    $results['message'] = 'No hay sesiones que necesiten compresión (Recuerda: se requieren más de ' . RECENT_WINDOW . ' bloques level_0 para activar la compresión y guardar los últimos ' . RECENT_WINDOW . ' en crudo).';
} else {
    foreach ($sessions as $session) {
        $sessionId = (int)$session['id_'];
        try {
            // 1. Ejecutar compresiones jerárquicas
            $compressedL1 = compressLevel0ToLevel1($db_connection, $bedrock, $sessionId);
            $compressedL2 = compressLevel1ToLevel2($db_connection, $bedrock, $sessionId);
            $compressedL3 = compressLevel2ToLevel3($db_connection, $bedrock, $sessionId);
            
            $results['level_0_to_1'] += $compressedL1;
            $results['level_1_to_2'] += $compressedL2;
            $results['level_2_to_3'] += $compressedL3;
            
            
// ✅ MEMORIA PROCEDURAL: Detectar patrones del usuario
$stmtUser = $db_connection->prepare("SELECT user_id_ FROM ChatSessions WHERE id_ = ?");
$stmtUser->bind_param('i', $sessionId);
$stmtUser->execute();
$rowUser = $stmtUser->get_result()->fetch_assoc();
$stmtUser->close();

if ($rowUser && !empty($rowUser['user_id_'])) {
    $proceduralSaved = extractProceduralMemory($db_connection, $bedrock, $sessionId, (int)$rowUser['user_id_']);
    if (!isset($results['procedural_memory_saved'])) {
        $results['procedural_memory_saved'] = 0;
    }
    $results['procedural_memory_saved'] += $proceduralSaved;
}
            
            $newLevel = (int)$session['context_level'];
            if ($compressedL3 > 0) $newLevel = 3; 
            elseif ($compressedL2 > 0) $newLevel = 2;
            elseif ($compressedL1 > 0) $newLevel = 1;
            

            // 2. Leer todos los bloques actuales

/*
¿Hay algún riesgo con la compresión y los adjuntos? 
Hay un punto que conviene verificar. En compress_session_context.php, la consulta para generar el meta-resumen maestro es: 

Esto se elimino:
$stmtSummary = $db_connection->prepare("SELECT block_type, content_preview, token_count FROM SessionContextBlocks WHERE session_id_ = ? AND block_type <> 'file_chunk' ORDER BY created_at ASC LIMIT 30");

Esto incluye bloques file (resúmenes semánticos) en el meta-resumen maestro. Eso puede ser positivo (el meta-resumen sabe qué archivos hay) pero también puede inflar el context_summary con información de adjuntos que el RAG ya maneja por separado.
Si quieres que el meta-resumen maestro NO incluya resúmenes de adjuntos (para que el RAG los maneje exclusivamente), cambia esa línea a:
Esto se usa: 
$stmtSummary = $db_connection->prepare("SELECT block_type, content_preview, token_count FROM SessionContextBlocks WHERE session_id_ = ? AND block_type NOT IN ('file', 'file_chunk') ORDER BY created_at ASC LIMIT 30");
*/
$stmtSummary = $db_connection->prepare("SELECT block_type, content_preview, token_count FROM SessionContextBlocks WHERE session_id_ = ? AND block_type NOT IN ('file', 'file_chunk') ORDER BY created_at ASC LIMIT 30");

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
                // Nada nuevo que resumir: no llamar a Bedrock, no tocar last_compressed_at.
                // Así la sesión no vuelve a la cabeza de la cola (ORDER BY last_compressed_at ASC)
                // solo porque le tocó turno; queda esperando contenido genuinamente nuevo.
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