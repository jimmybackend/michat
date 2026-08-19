<?php
/**
 * force_procedural_extraction.php
 * Fuerza la extracción de memoria procedural para todas las sesiones del usuario.
 * Registra costos en TokenUsage.
 *
 * Uso: POST force_procedural_extraction.php
 */
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/app_bootstrap.php';

function jexit($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function getUserId() {
    foreach (['user_id_', 'user_id', 'id_usuario', 'id_user', 'id'] as $k) {
        if (isset($_SESSION[$k]) && ctype_digit((string)$_SESSION[$k])) {
            return (int)$_SESSION[$k];
        }
    }
    return 0;
}

$userId = getUserId();
if ($userId <= 0) jexit(['ok' => false, 'error' => 'Sesión inválida'], 401);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jexit(['ok' => false, 'error' => 'Método no permitido'], 405);

@ini_set('max_execution_time', '300');
@set_time_limit(300);

// Inicializar Bedrock
try {
    $bedrock = Config::getBedrockRuntime(['http' => ['connect_timeout' => 20, 'timeout' => 120]]);
} catch (Throwable $e) {
    jexit(['ok' => false, 'error' => 'Bedrock init: ' . $e->getMessage()], 500);
}

// Funciones necesarias (copiadas de compress_session_context.php)
function getModelPricing(string $modelId): array {
    $m = strtolower($modelId);
    if (strpos($m, 'nova-micro') !== false) return ['input' => 0.035, 'output' => 0.14];
    if (strpos($m, 'nova-lite') !== false) return ['input' => 0.06, 'output' => 0.24];
    if (strpos($m, 'nova-pro') !== false) return ['input' => 0.80, 'output' => 3.20];
    if (strpos($m, 'claude') !== false || strpos($m, 'anthropic') !== false) return ['input' => 0.06, 'output' => 0.24];
    return ['input' => 0.06, 'output' => 0.24];
}

function calculateCost(string $modelId, int $inputTokens, int $outputTokens): float {
    $pricing = getModelPricing($modelId);
    return round(($inputTokens / 1000000 * $pricing['input']) + ($outputTokens / 1000000 * $pricing['output']), 6);
}

function logTokenUsage(mysqli $db, int $sessionId, ?int $msgId, string $phase, string $modelId, int $inputTokens, int $outputTokens, int $durationMs = 0): void {
    try {
        $cost = calculateCost($modelId, $inputTokens, $outputTokens);
        $tcId = 0;
        $rs = $db->query("SELECT IFNULL(MAX(id_),0)+1 AS nxt FROM TokenUsage");
        if ($rs) { $tcId = (int)($rs->fetch_assoc()['nxt'] ?? 1); $rs->free(); }
        $stmtTC = $db->prepare("INSERT INTO TokenUsage (id_, session_id_, message_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmtTC) {
            $stmtTC->bind_param("iiissiidi", $tcId, $sessionId, $msgId, $phase, $modelId, $inputTokens, $outputTokens, $cost, $durationMs);
            $stmtTC->execute();
            $stmtTC->close();
        }
    } catch (Throwable $e) {
        error_log('logTokenUsage force_procedural: ' . $e->getMessage());
    }
}

function contentLooksLikeCode(string $text): bool {
    return (bool)preg_match('/\b(function|class|const|let|var|import|export|return|if\s*\(|echo|print|<\?php|=>|<div|<script|error|bug|c[oó]digo|archivo|file|script|variable|array|json|php|js|html|css|sql|query|database|bd|api|endpoint|config|route|controller|model)\b/i', $text);
}

function selectModelForContent(string $text): string {
    return contentLooksLikeCode($text) ? 'amazon.nova-lite-v1:0' : 'amazon.nova-micro-v1:0';
}

function extractProceduralMemoryForce(mysqli $db, $bedrock, int $sessionId, int $userId): int {
    $stmt = $db->prepare("
        SELECT scb.id_, scb.content_preview, scb.session_id_
        FROM SessionContextBlocks scb
        WHERE scb.session_id_ = ?
          AND scb.block_type IN ('level_0', 'level_1')
          AND scb.is_locked = 0
        ORDER BY scb.created_at DESC
        LIMIT 15
    ");
    if (!$stmt) return 0;
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $res = $stmt->get_result();
    $blocks = [];
    while ($row = $res->fetch_assoc()) $blocks[] = $row;
    $stmt->close();

    if (empty($blocks)) return 0;

    $allText = '';
    foreach ($blocks as $b) $allText .= $b['content_preview'] . "\n";
    if (mb_strlen($allText) < 100) return 0;

    $modelId = selectModelForContent($allText);

    $systemPrompt = "Eres un detector de PATRONES PROCEDURALES del usuario. Analiza la conversación y detecta SOLO:

1. CORRECCIONES: El usuario corrigió a la IA
2. PREFERENCIAS EXPLÍCITAS: 'Siempre usa...', 'Nunca hagas...'
3. REGLAS DE FORMATO: 'Responde en español', 'Sé conciso'
4. PATRONES DE TRABAJO: 'Primero haz X, luego Y'
5. ESTILO: 'No uses emojis', 'Usa tono formal'

REGLAS ESTRICTAS:
- SOLO detecta patrones que el usuario ESTABLECIÓ EXPLÍCITAMENTE.
- NO inventes patrones.
- Si no hay ningún patrón claro, devuelve exactamente: []
- Máximo 3 patrones por análisis.
- Devuelve ÚNICAMENTE un array JSON válido.

Formato:
[{\"type\": \"rule|preference|correction|workflow|pattern\", \"content\": \"descripción clara\"}]";

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

        $jsonStr = trim($aiText);
        if (preg_match('/\[[\s\S]*\]/', $jsonStr, $matches)) $jsonStr = $matches[0];
        $jsonStr = preg_replace('/^```json\s*/i', '', $jsonStr);
        $jsonStr = preg_replace('/\s*```$/i', '', $jsonStr);

        $patterns = json_decode($jsonStr, true);
        if (!is_array($patterns) || empty($patterns)) return 0;

        $saved = 0;
        foreach ($patterns as $p) {
            $type = $p['type'] ?? 'rule';
            if (!in_array($type, ['preference','rule','pattern','correction','workflow'])) $type = 'rule';
            $content = trim($p['content'] ?? '');
            if (mb_strlen($content) < 15) continue;

            $checkStmt = $db->prepare("SELECT id_, confidence FROM UserProceduralMemory WHERE user_id_ = ? AND content = ? AND is_active = 1 LIMIT 1");
            if (!$checkStmt) continue;
            $checkStmt->bind_param('is', $userId, $content);
            $checkStmt->execute();
            $existing = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();

            if ($existing) {
                $newConf = min((int)$existing['confidence'] + 1, 10);
                $upd = $db->prepare("UPDATE UserProceduralMemory SET confidence = ?, updated_at = NOW() WHERE id_ = ?");
                if ($upd) { $upd->bind_param('ii', $newConf, $existing['id_']); $upd->execute(); $upd->close(); }
            } else {
                $ins = $db->prepare("INSERT INTO UserProceduralMemory (user_id_, memory_type, content, source_session_id, confidence) VALUES (?, ?, ?, ?, 1)");
                if ($ins) { $ins->bind_param('issi', $userId, $type, $content, $sessionId); if ($ins->execute()) $saved++; $ins->close(); }
            }
        }
        return $saved;
    } catch (Throwable $e) {
        error_log("Error force procedural sesión $sessionId: " . $e->getMessage());
        return 0;
    }
}

// Obtener todas las sesiones del usuario con bloques
$stmt = $db_connection->prepare("
    SELECT scb.session_id_, MAX(scb.created_at) AS last_activity
    FROM SessionContextBlocks scb
    JOIN ChatSessions cs ON cs.id_ = scb.session_id_
    WHERE cs.user_id_ = ?
      AND scb.block_type IN ('level_0', 'level_1')
      AND scb.is_locked = 0
    GROUP BY scb.session_id_
    ORDER BY last_activity DESC
    LIMIT 50
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();
$sessions = [];
while ($row = $res->fetch_assoc()) $sessions[] = (int)$row['session_id_'];
$stmt->close();

if (empty($sessions)) {
    jexit(['ok' => true, 'mensaje' => 'No hay sesiones con bloques para analizar.', 'total_saved' => 0, 'sessions_analyzed' => 0]);
}

$totalSaved = 0;
$sessionsAnalyzed = 0;

foreach ($sessions as $sid) {
    $saved = extractProceduralMemoryForce($db_connection, $bedrock, $sid, $userId);
    $totalSaved += $saved;
    $sessionsAnalyzed++;
}

jexit([
    'ok' => true,
    'mensaje' => "Se analizaron $sessionsAnalyzed sesiones. Se detectaron $totalSaved patrón(es) nuevo(s).",
    'total_saved' => $totalSaved,
    'sessions_analyzed' => $sessionsAnalyzed
]);