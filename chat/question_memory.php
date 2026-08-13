<?php
/**
 * question_memory.php — Memoria Selectiva de Preguntas Anteriores
 *
 * Busca en preguntas previas del usuario, extrae fragmentos relevantes
 * de las respuestas originales y los inyecta como contexto.
 *
 * Flujo:
 *   1. Extrae palabras clave de la pregunta actual
 *   2. Busca preguntas candidatas (pre-filtro SQL)
 *   3. Nova Micro decide cuáles son relevantes
 *   4. Lee la respuesta original internamente
 *   5. Extrae fragmentos ±N líneas
 *   6. Si hay muchos → Nova Micro reduce
 *   7. Guarda/actualiza resumen level_0
 *   8. Actualiza resumen de sesión y proyecto
 *   9. Devuelve texto de memoria para inyectar al system prompt
 */

// ===== Constantes =====
if (!defined('QM_ENABLED'))              define('QM_ENABLED', true);
if (!defined('QM_MODEL'))                define('QM_MODEL', 'amazon.nova-micro-v1:0');
if (!defined('QM_MAX_CANDIDATES'))       define('QM_MAX_CANDIDATES', 20);
if (!defined('QM_MAX_SELECTED'))         define('QM_MAX_SELECTED', 3);
if (!defined('QM_WINDOW_LINES'))         define('QM_WINDOW_LINES', 5);
if (!defined('QM_MAX_DIRECT_FRAGMENTS')) define('QM_MAX_DIRECT_FRAGMENTS', 3);
if (!defined('QM_MAX_FRAGMENT_CHARS'))   define('QM_MAX_FRAGMENT_CHARS', 4000);
if (!defined('QM_MAX_CONTEXT_CHARS'))    define('QM_MAX_CONTEXT_CHARS', 8000);
if (!defined('QM_EMBEDDING_MODEL'))      define('QM_EMBEDDING_MODEL', 'amazon.titan-embed-text-v2:0');

// =====================================================================
// FUNCIÓN PRINCIPAL
// =====================================================================
function qm_retrieve_memory_context(
    mysqli $db,
    $bedrock,
    int $sessionId,
    int $userId,
    int $projectId,
    string $userText,
    ?int $savedUserTextId,
    array $options = []
): string {

//error_log("QM: INICIO - session=$sessionId, text=" . mb_substr($userText, 0, 50));

    if (!QM_ENABLED) return '';
    $userText = trim($userText);
    if ($userText === '') return '';

    $seed       = (int)($options['seed'] ?? 0);
    $temperature = (float)($options['temperature'] ?? 0.0);
    $topP        = (float)($options['top_p'] ?? 0.1);
    $scope       = (string)($options['scope'] ?? 'project');
    $maxCandidates = (int)($options['max_candidates'] ?? QM_MAX_CANDIDATES);
    $windowLines   = (int)($options['window_lines'] ?? QM_WINDOW_LINES);

    // 1. Extraer palabras clave
    $keywords = qm_extract_keywords($userText);
    if (empty($keywords)) return '';

    // 2. Buscar preguntas candidatas
    $candidates = qm_search_candidate_questions(
        $db, $userId, $sessionId, $projectId,
        $savedUserTextId, $keywords, $scope, $maxCandidates
    );
    //error_log("QM: CANDIDATOS ENCONTRADOS: " . count($candidates));
    if (empty($candidates)) {
        //error_log("QM: SIN CANDIDATOS - saliendo");
        return '';
    }
    // 3. Nova Micro selecciona cuáles son relevantes
    $relevantIds = qm_select_relevant_with_ai(
        $db, $bedrock, $sessionId, $savedUserTextId,
        $userText, $candidates, $seed, $temperature, $topP
    );
     
    //error_log("QM: RELEVANTES: " . implode(',', $relevantIds));
    if (empty($relevantIds)) {
        //error_log("QM: SIN RELEVANTES - saliendo");
        return '';
    }
    

    // 4. Para cada pregunta relevante: extraer fragmentos
    $memoryBlocks = [];
    foreach ($relevantIds as $qMsgId) {
        $block = qm_process_question(
            $db, $bedrock, $sessionId, $projectId,
            $savedUserTextId, $qMsgId, $userText,
            $keywords, $windowLines, $seed, $temperature, $topP
        );
        if ($block !== null) {
            $memoryBlocks[] = $block;
        }
        if (count($memoryBlocks) >= QM_MAX_SELECTED) break;
    }

    if (empty($memoryBlocks)) return '';

    // 5. Actualizar resumen de sesión
    qm_update_session_summary(
        $db, $bedrock, $sessionId, $userText,
        $memoryBlocks, $seed, $temperature, $topP
    );

    // 6. Actualizar resumen de proyecto (si hay proyecto)
    if ($projectId > 0) {
        qm_update_project_summary(
            $db, $bedrock, $projectId, $sessionId,
            $userText, $memoryBlocks, $seed, $temperature, $topP
        );
    }
    // 7. Construir texto final de memoria
    $result = qm_build_memory_text($db, $sessionId, $projectId, $memoryBlocks);
    //error_log("QM: RESULTADO FINAL (" . mb_strlen($result) . " chars): " . mb_substr($result, 0, 200));
    return $result;
}

// =====================================================================
// EXTRAER PALABRAS CLAVE
// =====================================================================
function qm_extract_keywords(string $text): array {
    $stopwords = [
        'el','la','los','las','un','una','unos','unas','de','del','al','a',
        'en','con','por','para','que','qué','como','cómo','cuál','cuáles',
        'dónde','donde','cuándo','cuando','quién','quienes','es','son',
        'está','están','fue','ser','estar','hay','tiene','tienen','este',
        'esta','estos','estas','ese','esa','esos','esas','aquel','aquella',
        'mi','tu','su','mis','tus','sus','nuestro','nuestra','me','te',
        'se','nos','le','lo','les','y','o','u','ni','pero','sin','sobre',
        'entre','desde','hasta','según','the','a','an','is','are','was',
        'were','be','been','being','have','has','had','do','does','did',
        'will','would','shall','should','may','might','must','can','could',
        'i','you','he','she','it','we','they','my','your','his','her',
        'its','our','their','this','that','these','those','what','which',
        'who','whom','where','when','why','how','not','no','yes','and',
        'or','but','if','then','than','so','of','in','on','at','to','for',
        'with','about','against','between','through','during','before',
        'after','above','below','up','down','out','off','over','under',
        'again','further','once','here','there','all','both','each','few',
        'more','most','other','some','such','only','own','same','too',
        'very','just','also','into','muy','más','menos','ya','aún','todavía',
    ];

    $text = mb_strtolower($text, 'UTF-8');
    // Extraer palabras, números, IPs, rutas, variables
    preg_match_all('/[a-záéíóúñü0-9_\.\/\\\\:\-]{2,}/u', $text, $matches);
    $words = $matches[0] ?? [];

    $keywords = [];
    foreach ($words as $w) {
        $w = trim($w, '.,;:!?()[]{}"\'');
        if (mb_strlen($w) < 2) continue;
        if (in_array($w, $stopwords)) continue;
        if (is_numeric($w) && (int)$w < 2) continue;
        $keywords[] = $w;
    }

    return array_values(array_unique($keywords));
}

// =====================================================================
// BUSCAR PREGUNTAS CANDIDATAS
// =====================================================================
function qm_search_candidate_questions(
    mysqli $db,
    int $userId,
    int $sessionId,
    int $projectId,
    ?int $excludeMsgId,
    array $keywords,
    string $scope,
    int $maxCandidates
): array {

    // Construir condiciones LIKE
    $likes = [];
    $params = [];
    $types = '';

    // Condiciones base
    $types .= 'i'; $params[] = $userId; // user_id_

    if ($scope === 'session' || $projectId <= 0) {
        $types .= 'i'; $params[] = $sessionId;
        $sessionFilter = 'AND cm.session_id_ = ?';
    } else {
        // Buscar en todas las sesiones del proyecto
        $types .= 'i'; $params[] = $projectId;
        $sessionFilter = 'AND cs.project_id_ = ?';
    }

    // Excluir la pregunta actual
    if ($excludeMsgId && $excludeMsgId > 0) {
        $types .= 'i'; $params[] = $excludeMsgId;
        $excludeFilter = 'AND cm.id_ != ?';
    } else {
        $excludeFilter = '';
    }

    // Pre-filtro por palabras clave (hasta 5 keywords para no saturar)
    $kwSlice = array_slice($keywords, 0, 5);
    $orParts = [];
    foreach ($kwSlice as $kw) {
        $orParts[] = 'cm.content LIKE ?';
        $types .= 's';
        $params[] = '%' . $kw . '%';
    }

    $kwFilter = '';
    if (!empty($orParts)) {
        $kwFilter = 'AND (' . implode(' OR ', $orParts) . ')';
    }

    $sql = "SELECT cm.id_, cm.content, cm.session_id_, cs.title AS session_title
            FROM ChatMessages cm
            JOIN ChatSessions cs ON cs.id_ = cm.session_id_
            WHERE cm.user_id_ = ?
            AND cm.role = 'user'
            AND cm.content_type = 'text'
            AND cm.content != ''
            {$sessionFilter}
            {$excludeFilter}
            {$kwFilter}
            ORDER BY cm.id_ DESC
            LIMIT ?";

    $types .= 'i'; $params[] = $maxCandidates;

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        //error_log('qm_search_candidate_questions prepare error: ' . $db->error);
        return [];
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $candidates = [];
    while ($row = $res->fetch_assoc()) {
        $candidates[] = [
            'id' => (int)$row['id_'],
            'content' => mb_substr(trim((string)$row['content']), 0, 200),
            'session_id' => (int)$row['session_id_'],
            'session_title' => (string)($row['session_title'] ?? ''),
        ];
    }
    $stmt->close();

    // Si el pre-filtro no devolvió nada, tomar las últimas preguntas como fallback
    if (empty($candidates)) {
        $sqlFallback = "SELECT cm.id_, cm.content, cm.session_id_
                        FROM ChatMessages cm
                        WHERE cm.user_id_ = ?
                        AND cm.role = 'user'
                        AND cm.content_type = 'text'
                        AND cm.content != ''
                        AND cm.id_ != ?
                        ORDER BY cm.id_ DESC
                        LIMIT 10";
        $stmtFb = $db->prepare($sqlFallback);
        if ($stmtFb) {
            $exId = $excludeMsgId ?: 0;
            $stmtFb->bind_param('ii', $userId, $exId);
            $stmtFb->execute();
            $resFb = $stmtFb->get_result();
            while ($row = $resFb->fetch_assoc()) {
                $candidates[] = [
                    'id' => (int)$row['id_'],
                    'content' => mb_substr(trim((string)$row['content']), 0, 200),
                    'session_id' => (int)$row['session_id_'],
                    'session_title' => '',
                ];
            }
            $stmtFb->close();
        }
    }

    return $candidates;
}

// =====================================================================
// NOVA MICRO SELECCIONA PREGUNTAS RELEVANTES
// =====================================================================
function qm_select_relevant_with_ai(
    mysqli $db,
    $bedrock,
    int $sessionId,
    ?int $msgId,
    string $userText,
    array $candidates,
    int $seed,
    float $temperature,
    float $topP
): array {

    if (count($candidates) <= 2) {
        // Si hay 2 o menos, usarlas todas sin llamar a IA
        return array_column($candidates, 'id');
    }

    // Construir lista de candidatos
    $listText = '';
    foreach ($candidates as $c) {
        $listText .= "ID {$c['id']}: {$c['content']}\n";
    }

    $prompt = "Pregunta actual del usuario: \"{$userText}\"

Preguntas anteriores del usuario:
{$listText}

¿Cuál(es) pregunta(s) anterior(es) podrían contener la respuesta o información relevante para la pregunta actual?
Devuelve ÚNICAMENTE un JSON válido con este formato exacto:
{\"relevant_ids\": [47, 52]}
Si ninguna es relevante, devuelve: {\"relevant_ids\": []}
No agregues nada más.";

    $inferConfig = [
        'maxTokens' => 100,
        'temperature' => 0.0,
        'topP' => 0.1,
    ];
    if ($seed > 0) $inferConfig['seed'] = $seed;

    $startTime = microtime(true);

    try {
        $res = $bedrock->converse([
            'modelId' => QM_MODEL,
            'messages' => [['role' => 'user', 'content' => [['text' => $prompt]]]],
            'system' => [['text' => 'Eres un clasificador de relevancia. Solo devuelves JSON.']],
            'inferenceConfig' => $inferConfig,
        ]);

        $durationMs = (int)((microtime(true) - $startTime) * 1000);
        $inputTokens = (int)($res['usage']['inputTokens'] ?? 0);
        $outputTokens = (int)($res['usage']['outputTokens'] ?? 0);

        // Registrar en TokenUsage
        $validMsgId = getValidMessageId($db, $msgId, $sessionId);
        logTokenUsage($db, $sessionId, $validMsgId, 'rag', QM_MODEL, $inputTokens, $outputTokens, $durationMs);

        $text = '';
        foreach (($res['output']['message']['content'] ?? []) as $block) {
            if (isset($block['text'])) $text .= $block['text'];
        }

        // Parsear JSON
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/i', '', $text);
        $decoded = json_decode($text, true);

        if (is_array($decoded) && isset($decoded['relevant_ids']) && is_array($decoded['relevant_ids'])) {
            $validIds = array_column($candidates, 'id');
            $filtered = array_filter($decoded['relevant_ids'], function($id) use ($validIds) {
                return in_array((int)$id, $validIds);
            });
            return array_slice(array_values(array_unique(array_map('intval', $filtered))), 0, QM_MAX_SELECTED);
        }

    } catch (Throwable $e) {
       // error_log('qm_select_relevant_with_ai error: ' . $e->getMessage());
    }

    // Fallback: devolver las primeras 2 candidatas
    return array_slice(array_column($candidates, 'id'), 0, 2);
}

// =====================================================================
// PROCESAR UNA PREGUNTA RELEVANTE
// =====================================================================
function qm_process_question(
    mysqli $db,
    $bedrock,
    int $sessionId,
    int $projectId,
    ?int $currentMsgId,
    int $questionMsgId,
    string $userText,
    array $keywords,
    int $windowLines,
    int $seed,
    float $temperature,
    float $topP
): ?array {

    // 1. Obtener la respuesta del asistente para esa pregunta
    $answerData = qm_get_answer_for_question($db, $questionMsgId);
    if ($answerData === null) return null;

    $answerMsgId = $answerData['id'];
    $answerContent = $answerData['content'];
    $questionContent = $answerData['question_content'];
    $answerSessionId = $answerData['session_id'];

    // 2. Buscar resumen existente
    $existingSummary = qm_get_existing_summary($db, $questionMsgId, $answerMsgId);

    // 3. Extraer fragmentos de la respuesta original
    $fragments = qm_extract_fragments($answerContent, $keywords, $windowLines);

    // 4. Si hay muchos fragmentos, reducir con IA
    $finalFragment = '';
    if (empty($fragments)) {
        // Sin coincidencias: usar los primeros 500 chars como contexto mínimo
        $finalFragment = mb_substr($answerContent, 0, 500);
    } elseif (count($fragments) <= QM_MAX_DIRECT_FRAGMENTS
              && mb_strlen(implode("\n", $fragments)) <= QM_MAX_FRAGMENT_CHARS) {
        $finalFragment = implode("\n---\n", $fragments);
    } else {
        // Muchos fragmentos → Nova Micro extrae lo útil
        $finalFragment = qm_reduce_fragments_with_ai(
            $db, $bedrock, $sessionId, $currentMsgId,
            $userText, $fragments, $seed, $temperature, $topP
        );
    }

    if (trim($finalFragment) === '') return null;

    // 5. Guardar/actualizar resumen level_0
    $summaryText = qm_build_question_summary(
        $questionContent, $finalFragment, $userText
    );
    qm_save_or_update_summary(
        $db, $answerSessionId, $questionMsgId, $answerMsgId, $summaryText
    );

    return [
        'question_id' => $questionMsgId,
        'answer_id' => $answerMsgId,
        'session_id' => $answerSessionId,
        'question_text' => mb_substr($questionContent, 0, 150),
        'fragment' => mb_substr($finalFragment, 0, 1500),
        'existing_summary' => $existingSummary,
    ];
}

// =====================================================================
// OBTENER RESPUESTA DEL ASISTENTE PARA UNA PREGUNTA
// =====================================================================
function qm_get_answer_for_question(mysqli $db, int $questionMsgId): ?array {
    // Obtener la pregunta primero
    $stmtQ = $db->prepare(
        "SELECT id_, session_id_, content FROM ChatMessages WHERE id_ = ? AND role = 'user' LIMIT 1"
    );
    if (!$stmtQ) return null;
    $stmtQ->bind_param('i', $questionMsgId);
    $stmtQ->execute();
    $resQ = $stmtQ->get_result();
    $qRow = $resQ->fetch_assoc();
    $stmtQ->close();
    if (!$qRow) return null;

    // Buscar la siguiente respuesta del asistente después de esa pregunta
    $stmtA = $db->prepare(
        "SELECT id_, content FROM ChatMessages
         WHERE session_id_ = ? AND role = 'assistant' AND id_ > ?
         AND content != '' AND content IS NOT NULL
         ORDER BY id_ ASC LIMIT 1"
    );
    if (!$stmtA) return null;
    $stmtA->bind_param('ii', $qRow['session_id_'], $questionMsgId);
    $stmtA->execute();
    $resA = $stmtA->get_result();
    $aRow = $resA->fetch_assoc();
    $stmtA->close();
    if (!$aRow) return null;

    return [
        'id' => (int)$aRow['id_'],
        'content' => (string)$aRow['content'],
        'question_content' => (string)$qRow['content'],
        'session_id' => (int)$qRow['session_id_'],
    ];
}

// =====================================================================
// EXTRAER FRAGMENTOS ±N LÍNEAS
// =====================================================================
function qm_extract_fragments(string $answer, array $keywords, int $windowLines): array {
    $lines = explode("\n", $answer);
    $totalLines = count($lines);
    if ($totalLines === 0) return [];

    // Encontrar líneas con coincidencias
    $matchLines = [];
    foreach ($keywords as $kw) {
        $kwLower = mb_strtolower($kw, 'UTF-8');
        foreach ($lines as $i => $line) {
            if (mb_stripos($line, $kwLower) !== false) {
                $matchLines[] = $i;
            }
        }
    }

    if (empty($matchLines)) return [];

    $matchLines = array_values(array_unique($matchLines));
    sort($matchLines);

    // Crear ventanas y mezclar las que se pisan
    $windows = [];
    foreach ($matchLines as $lineIdx) {
        $start = max(0, $lineIdx - $windowLines);
        $end = min($totalLines - 1, $lineIdx + $windowLines);
        $windows[] = ['start' => $start, 'end' => $end];
    }

    // Mezclar ventanas superpuestas
    $merged = [];
    foreach ($windows as $w) {
        if (empty($merged)) {
            $merged[] = $w;
            continue;
        }
        $last = &$merged[count($merged) - 1];
        if ($w['start'] <= $last['end'] + 1) {
            $last['end'] = max($last['end'], $w['end']);
        } else {
            $merged[] = $w;
        }
    }

    // Extraer texto de cada ventana
    $fragments = [];
    foreach ($merged as $w) {
        $slice = array_slice($lines, $w['start'], $w['end'] - $w['start'] + 1);
        $frag = trim(implode("\n", $slice));
        if ($frag !== '') {
            $fragments[] = $frag;
        }
    }

    return $fragments;
}

// =====================================================================
// REDUCIR FRAGMENTOS CON IA
// =====================================================================
function qm_reduce_fragments_with_ai(
    mysqli $db,
    $bedrock,
    int $sessionId,
    ?int $msgId,
    string $userText,
    array $fragments,
    int $seed,
    float $temperature,
    float $topP
): string {

    $fragmentsText = '';
    foreach ($fragments as $i => $f) {
        $fragmentsText .= "[FRAGMENTO " . ($i + 1) . "]\n" . mb_substr($f, 0, 600) . "\n\n";
    }

    $prompt = "Pregunta actual del usuario: \"{$userText}\"

Fragmentos encontrados en respuestas anteriores:
{$fragmentsText}

Extrae ÚNICAMENTE la información de estos fragmentos que es necesaria para responder la pregunta actual.
Preserva datos exactos: números, rutas, variables, puertos, IPs, nombres de archivos.
No expliques, no inventes, no agregues información que no esté en los fragmentos.
Devuelve solo el contexto útil en texto plano:";

$inferConfig = [
    'maxTokens' => 600,
    'temperature' => 0.0,
    'topP' => 0.1,
];
    if ($seed > 0) $inferConfig['seed'] = $seed;

    $startTime = microtime(true);

    try {
        $res = $bedrock->converse([
            'modelId' => QM_MODEL,
            'messages' => [['role' => 'user', 'content' => [['text' => $prompt]]]],
            'system' => [['text' => 'Eres un extractor de información. Solo devuelves el texto extraído.']],
            'inferenceConfig' => $inferConfig,
        ]);

        $durationMs = (int)((microtime(true) - $startTime) * 1000);
        $inputTokens = (int)($res['usage']['inputTokens'] ?? 0);
        $outputTokens = (int)($res['usage']['outputTokens'] ?? 0);

        $validMsgId = getValidMessageId($db, $msgId, $sessionId);
        logTokenUsage($db, $sessionId, $validMsgId, 'rag', QM_MODEL, $inputTokens, $outputTokens, $durationMs);

        $text = '';
        foreach (($res['output']['message']['content'] ?? []) as $block) {
            if (isset($block['text'])) $text .= $block['text'];
        }

        return trim($text) ?: implode("\n---\n", array_slice($fragments, 0, 2));

    } catch (Throwable $e) {
       // error_log('qm_reduce_fragments_with_ai error: ' . $e->getMessage());
        return implode("\n---\n", array_slice($fragments, 0, 2));
    }
}

// =====================================================================
// BUSCAR RESUMEN EXISTENTE
// =====================================================================
function qm_get_existing_summary(mysqli $db, int $questionMsgId, int $answerMsgId): ?string {
    $stmt = $db->prepare(
        "SELECT content_preview FROM SessionContextBlocks
         WHERE question_msg_id = ? AND answer_msg_id = ?
         AND block_type IN ('level_0','level_1','level_2','level_3')
         ORDER BY id_ DESC LIMIT 1"
    );
    if (!$stmt) return null;
    $stmt->bind_param('ii', $questionMsgId, $answerMsgId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    return $row ? (string)$row['content_preview'] : null;
}

// =====================================================================
// GUARDAR O ACTUALIZAR RESUMEN
// =====================================================================
function qm_save_or_update_summary(
    mysqli $db,
    int $sessionId,
    int $questionMsgId,
    int $answerMsgId,
    string $summaryText
): void {
    $tokenCount = (int)ceil(mb_strlen($summaryText) / 4);

    // Verificar si ya existe
    $stmtChk = $db->prepare(
        "SELECT id_ FROM SessionContextBlocks
         WHERE question_msg_id = ? AND answer_msg_id = ? AND is_memory_summary = 1
         LIMIT 1"
    );
    if ($stmtChk) {
        $stmtChk->bind_param('ii', $questionMsgId, $answerMsgId);
        $stmtChk->execute();
        $resChk = $stmtChk->get_result();
        $existing = $resChk->fetch_assoc();
        $stmtChk->close();

        if ($existing) {
            $stmtUpd = $db->prepare(
                "UPDATE SessionContextBlocks
                 SET content_preview = ?, token_count = ?, is_locked = 1,
                     memory_hits = memory_hits + 1, last_memory_used_at = NOW()
                 WHERE id_ = ?"
            );
            if ($stmtUpd) {
                $stmtUpd->bind_param('sii', $summaryText, $tokenCount, $existing['id_']);
                $stmtUpd->execute();
                $stmtUpd->close();
            }
            return;
        }
    }

    // INSERT nuevo (solo este bloque, sin el primero erroneo)
    $blockId = next_id($db, 'SessionContextBlocks', 'id_');
    $stmtIns = $db->prepare(
        "INSERT INTO SessionContextBlocks (
            id_, session_id_, block_type, question_msg_id, answer_msg_id,
            content_preview, is_locked, token_count, is_memory_summary,
            memory_hits, last_memory_used_at
        ) VALUES (?, ?, 'level_0', ?, ?, ?, 1, ?, 1, 1, NOW())"
    );
    if ($stmtIns) {
        $stmtIns->bind_param('iiissi',
            $blockId, $sessionId, $questionMsgId, $answerMsgId,
            $summaryText, $tokenCount
        );
        $stmtIns->execute();
        $stmtIns->close();
    }
}


// =====================================================================
// CONSTRUIR RESUMEN DE PREGUNTA
// =====================================================================
function qm_build_question_summary(string $question, string $fragment, string $currentQuestion): string {
    $summary = "[MEMORIA DE PREGUNTA ANTERIOR]\n";
    $summary .= "Pregunta original: " . $question . "\n";
    $summary .= "Consultada porque el usuario ahora pregunta: " . $currentQuestion . "\n";
    $summary .= "Información relevante:\n" . $fragment;
    return $summary;
}

// =====================================================================
// ACTUALIZAR RESUMEN DE SESIÓN
// =====================================================================
function qm_update_session_summary(
    mysqli $db,
    $bedrock,
    int $sessionId,
    string $userText,
    array $memoryBlocks,
    int $seed,
    float $temperature,
    float $topP
): void {
    // Obtener resumen actual
    $stmtS = $db->prepare("SELECT context_summary FROM ChatSessions WHERE id_ = ? LIMIT 1");
    if (!$stmtS) return;
    $stmtS->bind_param('i', $sessionId);
    $stmtS->execute();
    $resS = $stmtS->get_result();
    $sessionRow = $resS->fetch_assoc();
    $stmtS->close();

    $currentSummary = trim((string)($sessionRow['context_summary'] ?? ''));

    // Construir nueva información
    $newInfo = "Nueva consulta del usuario: \"{$userText}\"\n";
    foreach ($memoryBlocks as $mb) {
        $newInfo .= "- Se consultó la pregunta #{$mb['question_id']}: \"{$mb['question_text']}\"\n";
        $newInfo .= "  Dato útil: " . mb_substr($mb['fragment'], 0, 200) . "\n";
    }

    $prompt = "Resumen actual de la sesión:
{$currentSummary}

{$newInfo}

Reescribe el resumen de sesión como un MAPA DE INTERESES del usuario.
Formato:
- Tema: [tema]
- Preguntas relevantes: [IDs]
- Datos clave: [valores exactos]
- Último enfoque: [qué está haciendo ahora]

Máximo 15 líneas. Preserva datos exactos (puertos, IPs, rutas, variables).
Devuelve solo el resumen:";

    $inferConfig = ['maxTokens' => 300, 'temperature' => 0.0, 'topP' => 0.1];
    if ($seed > 0) $inferConfig['seed'] = $seed;

    $startTime = microtime(true);

    try {
        $res = $bedrock->converse([
            'modelId' => QM_MODEL,
            'messages' => [['role' => 'user', 'content' => [['text' => $prompt]]]],
            'system' => [['text' => 'Eres un generador de resúmenes de sesión. Solo devuelves el resumen.']],
            'inferenceConfig' => $inferConfig,
        ]);

        $durationMs = (int)((microtime(true) - $startTime) * 1000);
        $inputTokens = (int)($res['usage']['inputTokens'] ?? 0);
        $outputTokens = (int)($res['usage']['outputTokens'] ?? 0);

        $validMsgId = getValidMessageId($db, null, $sessionId);
        logTokenUsage($db, $sessionId, $validMsgId, 'summarize', QM_MODEL, $inputTokens, $outputTokens, $durationMs);

        $newSummary = '';
        foreach (($res['output']['message']['content'] ?? []) as $block) {
            if (isset($block['text'])) $newSummary .= $block['text'];
        }
        $newSummary = trim($newSummary);

        if ($newSummary !== '') {
            // Generar embedding del nuevo resumen
            $embeddingJson = null;
            try {
                $embData = generateTitanEmbedding($bedrock, $newSummary, QM_EMBEDDING_MODEL);
                if (!empty($embData['embedding'])) {
                    $embeddingJson = json_encode($embData['embedding']);
                    if (!empty($embData['inputTokens'])) {
                        logTokenUsage($db, $sessionId, $validMsgId, 'embedding', QM_EMBEDDING_MODEL, (int)$embData['inputTokens'], 0, 0);
                    }
                }
            } catch (Throwable $e) {
                //error_log('qm session embedding error: ' . $e->getMessage());
            }

            // UPDATE ChatSessions
            $stmtUpd = $db->prepare(
                "UPDATE ChatSessions
                 SET context_summary = ?, context_embedding = ?, memory_summary_updated_at = NOW()
                 WHERE id_ = ?"
            );
            if ($stmtUpd) {
                $stmtUpd->bind_param('ssi', $newSummary, $embeddingJson, $sessionId);
                $stmtUpd->execute();
                $stmtUpd->close();
            }
        }

    } catch (Throwable $e) {
        //error_log('qm_update_session_summary error: ' . $e->getMessage());
    }
}

// =====================================================================
// ACTUALIZAR RESUMEN DE PROYECTO
// =====================================================================
function qm_update_project_summary(
    mysqli $db,
    $bedrock,
    int $projectId,
    int $sessionId,
    string $userText,
    array $memoryBlocks,
    int $seed,
    float $temperature,
    float $topP
): void {
    // Buscar resumen maestro existente
    $stmtP = $db->prepare(
        "SELECT id_, content FROM ProjectContext
         WHERE project_id_ = ? AND type = 'note' AND title = 'RESUMEN MAESTRO DEL PROYECTO'
         LIMIT 1"
    );
    if (!$stmtP) return;
    $stmtP->bind_param('i', $projectId);
    $stmtP->execute();
    $resP = $stmtP->get_result();
    $projRow = $resP->fetch_assoc();
    $stmtP->close();

    $currentProjSummary = $projRow ? trim((string)$projRow['content']) : '';
    $projCtxId = $projRow ? (int)$projRow['id_'] : 0;

    // Construir nueva info
    $newInfo = "Nueva consulta en sesión #{$sessionId}: \"{$userText}\"\n";
    foreach ($memoryBlocks as $mb) {
        $newInfo .= "- Dato: " . mb_substr($mb['fragment'], 0, 150) . "\n";
    }

    $prompt = "Resumen actual del proyecto:
{$currentProjSummary}

{$newInfo}

Actualiza el resumen del proyecto como un MAPA DE CONOCIMIENTO.
Solo incluye información durable (configuraciones, decisiones, datos técnicos).
No incluyas saludos ni preguntas triviales.
Formato:
- Tema: [tema]
- Datos: [valores exactos]
- Sesiones relevantes: [números]
Máximo 20 líneas. Devuelve solo el resumen:";

    $inferConfig = ['maxTokens' => 350, 'temperature' => 0.0, 'topP' => 0.1];
    if ($seed > 0) $inferConfig['seed'] = $seed;

    $startTime = microtime(true);

    try {
        $res = $bedrock->converse([
            'modelId' => QM_MODEL,
            'messages' => [['role' => 'user', 'content' => [['text' => $prompt]]]],
            'system' => [['text' => 'Eres un generador de resúmenes de proyecto. Solo devuelves el resumen.']],
            'inferenceConfig' => $inferConfig,
        ]);

        $durationMs = (int)((microtime(true) - $startTime) * 1000);
        $inputTokens = (int)($res['usage']['inputTokens'] ?? 0);
        $outputTokens = (int)($res['usage']['outputTokens'] ?? 0);

        $validMsgId = getValidMessageId($db, null, $sessionId);
        logTokenUsage($db, $sessionId, $validMsgId, 'summarize', QM_MODEL, $inputTokens, $outputTokens, $durationMs);

        $newProjSummary = '';
        foreach (($res['output']['message']['content'] ?? []) as $block) {
            if (isset($block['text'])) $newProjSummary .= $block['text'];
        }
        $newProjSummary = trim($newProjSummary);

        if ($newProjSummary === '') return;

        // Generar embedding
        $embeddingJson = null;
        try {
            $embData = generateTitanEmbedding($bedrock, $newProjSummary, QM_EMBEDDING_MODEL);
            if (!empty($embData['embedding'])) {
                $embeddingJson = json_encode($embData['embedding']);
                if (!empty($embData['inputTokens'])) {
                    logTokenUsage($db, $sessionId, $validMsgId, 'embedding', QM_EMBEDDING_MODEL, (int)$embData['inputTokens'], 0, 0);
                }
            }
        } catch (Throwable $e) {
            //error_log('qm project embedding error: ' . $e->getMessage());
        }

        if ($projCtxId > 0) {
            // UPDATE
            $stmtUpd = $db->prepare(
                "UPDATE ProjectContext SET content = ?, embedding = ? WHERE id_ = ?"
            );
            if ($stmtUpd) {
                $stmtUpd->bind_param('ssi', $newProjSummary, $embeddingJson, $projCtxId);
                $stmtUpd->execute();
                $stmtUpd->close();
            }
        } else {
            // INSERT
            $newId = next_id($db, 'ProjectContext', 'id_');
            $type = 'note';
            $title = 'RESUMEN MAESTRO DEL PROYECTO';
            $sourceChunkId = null;
            $stmtIns = $db->prepare(
                "INSERT INTO ProjectContext (id_, project_id_, type, title, content, source_chunk_id, embedding)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            if ($stmtIns) {
                $stmtIns->bind_param('iisssis', $newId, $projectId, $type, $title, $newProjSummary, $sourceChunkId, $embeddingJson);
                $stmtIns->execute();
                $stmtIns->close();
            }
        }

    } catch (Throwable $e) {
        //error_log('qm_update_project_summary error: ' . $e->getMessage());
    }
}

// =====================================================================
// CONSTRUIR TEXTO FINAL DE MEMORIA 
// =====================================================================
function qm_build_memory_text(mysqli $db, int $sessionId, int $projectId, array $memoryBlocks): string {
    $out = '';

    // Resumen de proyecto (si existe)
    if ($projectId > 0) {
        $stmtP = $db->prepare(
            "SELECT content FROM ProjectContext
             WHERE project_id_ = ? AND type = 'note' AND title = 'RESUMEN MAESTRO DEL PROYECTO'
             LIMIT 1"
        );
        if ($stmtP) {
            $stmtP->bind_param('i', $projectId);
            $stmtP->execute();
            $resP = $stmtP->get_result();
            if ($pRow = $resP->fetch_assoc()) {
                $out .= "[RESUMEN DE PROYECTO]\n" . trim((string)$pRow['content']) . "\n\n";
            }
            $stmtP->close();
        }
    }

    // Resumen de sesión (si existe)
    $stmtS = $db->prepare("SELECT context_summary FROM ChatSessions WHERE id_ = ? LIMIT 1");
    if ($stmtS) {
        $stmtS->bind_param('i', $sessionId);
        $stmtS->execute();
        $resS = $stmtS->get_result();
        if (($sRow = $resS->fetch_assoc()) && !empty($sRow['context_summary'])) {
            $out .= "[RESUMEN DE SESIÓN]\n" . trim((string)$sRow['context_summary']) . "\n\n";
        }
        $stmtS->close();
    }

    // Fragmentos de preguntas anteriores
    foreach ($memoryBlocks as $mb) {
        $out .= "[PREGUNTA ANTERIOR RELEVANTE #{$mb['question_id']}]\n";
        $out .= "Pregunta: {$mb['question_text']}\n";
        if (!empty($mb['existing_summary'])) {
            $out .= "Resumen de contexto: " . mb_substr($mb['existing_summary'], 0, 300) . "\n";
        }
        $out .= "Fragmento exacto:\n{$mb['fragment']}\n\n";
    }

    // Limitar longitud total
    if (mb_strlen($out) > QM_MAX_CONTEXT_CHARS) {
        $out = mb_substr($out, 0, QM_MAX_CONTEXT_CHARS);
    }

    return trim($out);
}