<?php

// bedrock_chat2.php — Chat directo a Amazon Bedrock (Converse)
// con soporte de adjuntos, OCR (Textract), RAG, Tool Use (Function Calling) y Metacognición (Fase 1)

// ===== Salida JSON y sesión =====
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

// La activación de la compresión jerárquica la decide process_embedding_queue.php
// cuando existen suficientes level_0 ya vectorizados.

// ===== Timeouts PHP =====
@ini_set('max_execution_time', '600');
@set_time_limit(600);
@ini_set('default_socket_timeout', '240');
@ignore_user_abort(true);

// ===== Acumulador de notas/errores =====
$errors = [];
$requestStartedAt = microtime(true);

// ===== Helpers =====
function jexit($arr, $code=200){ http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

function next_id(mysqli $db, $table, $col){
  $table = preg_replace('/[^A-Za-z0-9_]+/','',$table);
  $col   = preg_replace('/[^A-Za-z0-9_]+/','',$col);
  $rs = $db->query("SELECT IFNULL(MAX($col),0)+1 AS nxt FROM $table");
  if(!$rs) return 1;
  $row = $rs->fetch_assoc();
  return (int)($row['nxt'] ?? 1);
}


// =====================================================================
// ACTIVIDAD REAL DEL AGENTE (telemetría operacional, no chain-of-thought)
// =====================================================================
function activityNormalizeValue($value, int $depth = 0) {
  if ($depth > 8) return '[profundidad omitida]';
  if (is_array($value)) {
    $out = [];
    $count = 0;
    foreach ($value as $k => $v) {
      if ($count++ >= 200) { $out['_truncated_items'] = true; break; }
      $out[$k] = activityNormalizeValue($v, $depth + 1);
    }
    return $out;
  }
  if (is_object($value)) return activityNormalizeValue((array)$value, $depth + 1);
  if (is_string($value)) {
    // Nunca exponer bloques de razonamiento privado si un modelo los devolviera.
    $value = preg_replace('/<thinking>[\s\S]*?<\/thinking>/i', '[razonamiento privado omitido]', $value);
    if (mb_strlen($value) > 120000) return mb_substr($value, 0, 120000) . "\n[truncado para telemetría]";
    return $value;
  }
  if (is_bool($value) || is_int($value) || is_float($value) || $value === null) return $value;
  return (string)$value;
}

function activityEmit(
  mysqli $db,
  string $traceId,
  int $sessionId,
  int $userId,
  string $phase,
  string $eventKey,
  string $status,
  string $title,
  ?string $summary = null,
  $details = null,
  ?string $modelId = null,
  ?int $durationMs = null
): void {
  if ($traceId === '' || $sessionId <= 0 || $userId <= 0) return;
  if (!preg_match('/^[A-Za-z0-9_-]{16,36}$/', $traceId)) return;
  if (!in_array($status, ['started','completed','info','waiting','error'], true)) $status = 'info';
  try {
    $detailsJson = null;
    if ($details !== null) {
      $detailsJson = json_encode(activityNormalizeValue($details), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      if ($detailsJson === false) $detailsJson = json_encode(['error'=>'No se pudo serializar details_json']);
    }
    $stmt = $db->prepare("INSERT INTO ChatActivityEvents
      (trace_id, session_id_, user_id_, phase, event_key, status, title, summary, details_json, model_id, duration_ms)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) return; // SQL todavía no instalado: el chat no debe romperse.
    $stmt->bind_param('siisssssssi', $traceId, $sessionId, $userId, $phase, $eventKey, $status, $title, $summary, $detailsJson, $modelId, $durationMs);
    $stmt->execute();
    $stmt->close();
  } catch (Throwable $e) {
    error_log('CHAT_ACTIVITY: ' . $e->getMessage());
  }
}

function activityDurationMs(float $startedAt): int {
  return max(0, (int)round((microtime(true) - $startedAt) * 1000));
}

function detect_content_type_from_mime($mime){
  $m = strtolower((string)$mime);
  if (strpos($m,'image/') === 0) return 'image';
  if (strpos($m,'video/') === 0) return 'video';
  if (strpos($m,'audio/') === 0) return 'audio';
  if (strpos($m,'text/')  === 0) return 'text';
  return 'file';
}

function safe_filename($name){
  $b = basename((string)$name);
  return preg_replace('/[^A-Za-z0-9._-]+/','_',$b);
}

function resolve_root_candidates(): array {
  $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? (string)$_SERVER['DOCUMENT_ROOT'] : '';
  $rootFromDoc = $docRoot !== '' ? realpath($docRoot . '/..') : false;
  $candidates = [];
  foreach ([
    $rootFromDoc,
    realpath(__DIR__ . '/../../'),
    realpath(__DIR__ . '/../..'),
    realpath(__DIR__ . '/../../../'),
    realpath(__DIR__ . '/../'),
    realpath(__DIR__),
  ] as $p) {
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

// ===== Función para calcular similitud coseno entre dos vectores (MySQL fallback) =====
function cosineSimilarity(array $vecA, array $vecB): float {
    $dotProduct = 0.0; $normA = 0.0; $normB = 0.0;
    if (count($vecA) !== count($vecB) || count($vecA) === 0) return 0.0;
    $count = count($vecA);
    for ($i = 0; $i < $count; $i++) {
        $dotProduct += $vecA[$i] * $vecB[$i];
        $normA += $vecA[$i] * $vecA[$i];
        $normB += $vecB[$i] * $vecB[$i];
    }
    if ($normA == 0 || $normB == 0) return 0.0;
    return $dotProduct / (sqrt($normA) * sqrt($normB));
}

// =====================================================================
// ✅ RAG DE ARCHIVOS ADJUNTOS DE SESIÓN (helpers)
// =====================================================================
// Estos valores son únicamente fallbacks de seguridad. La configuración
// efectiva se lee de agent_key='embedding_main' en UserAIAgentConfigs.
if (!defined('ATTACHMENT_EMBEDDING_DIMENSIONS_FALLBACK')) define('ATTACHMENT_EMBEDDING_DIMENSIONS_FALLBACK', 1024);
if (!defined('ATTACHMENT_RAG_THRESHOLD_FALLBACK')) define('ATTACHMENT_RAG_THRESHOLD_FALLBACK', 0.25);
if (!defined('ATTACHMENT_RAG_RELATED_FILE_THRESHOLD_FALLBACK')) define('ATTACHMENT_RAG_RELATED_FILE_THRESHOLD_FALLBACK', 0.20);
if (!defined('ATTACHMENT_RAG_TOP_FALLBACK')) define('ATTACHMENT_RAG_TOP_FALLBACK', 4);
if (!defined('ATTACHMENT_RAG_MAX_CHARS_FALLBACK')) define('ATTACHMENT_RAG_MAX_CHARS_FALLBACK', 12000);

if (!function_exists('getModelPricing')) {
    function getModelPricing(string $modelId): array {
        $m = strtolower($modelId);

        if (strpos($m, 'titan-embed') !== false) {
            return ['input' => 0.10, 'output' => 0.00];
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

        return ['input' => 0.035, 'output' => 0.14];
    }
}

if (!function_exists('calculateCost')) {
    function calculateCost(string $modelId, int $inputTokens, int $outputTokens): float {
        $pricing = getModelPricing($modelId);
        $cost = ($inputTokens / 1000000 * $pricing['input']) + ($outputTokens / 1000000 * $pricing['output']);
        return round($cost, 6);
    }
}

if (!function_exists('logTokenUsage')) {
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
            $tcId = next_id($db, 'TokenUsage', 'id_');

            $stmt = $db->prepare("
                INSERT INTO TokenUsage (
                    id_, session_id_, message_id_, phase, model_id,
                    input_tokens, output_tokens, estimated_cost_usd, duration_ms
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if ($stmt) {
                $stmt->bind_param(
                    "iiissiidi",
                    $tcId,
                    $sessionId,
                    $msgId,
                    $phase,
                    $modelId,
                    $inputTokens,
                    $outputTokens,
                    $cost,
                    $durationMs
                );
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('logTokenUsage bedrock_chat2: ' . $e->getMessage());
        }
    }
}

if (!function_exists('generateTitanEmbedding')) {
    /**
     * Adaptador unificado de embeddings para consultas RAG.
     *
     * El nombre histórico generateTitanEmbedding() se conserva para no romper
     * llamadas existentes, pero el modelo real siempre sale de embedding_main.
     *
     * Adaptadores soportados:
     *  - Amazon Titan Text Embeddings V2 / V1
     *  - Cohere Embed v4
     *  - Cohere Embed English v3 / Multilingual v3
     *
     * En Bedrock Chat este helper se usa principalmente para búsquedas, por eso
     * el input_type por defecto es search_query. La cola de indexación usa
     * search_document al crear los vectores almacenados.
     */
    function generateTitanEmbedding(
        $bedrock,
        string $text,
        ?string $modelId = null,
        string $inputType = 'search_query'
    ): array {
        if (!aiAgentActive('embedding_main', false)) {
            return ['embedding' => [], 'inputTokens' => 0, 'disabled' => true];
        }

        $modelId = trim((string)($modelId ?: aiAgentModel('embedding_main', '')));
        if ($modelId === '') {
            throw new RuntimeException("embedding_main no tiene model_id configurado");
        }

        $modelLower = strtolower($modelId);
        $configuredAdapter = strtolower(trim((string)aiAgentExtra('embedding_main', 'adapter', '')));
        $adapter = '';

        // El model_id manda sobre un adapter antiguo que pudiera quedar en extra_config.
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
            throw new RuntimeException("El modelo de embedding '{$modelId}' no tiene un adaptador implementado.");
        }

        $defaultMaxChars = ($adapter === 'cohere_embed_v3') ? 2048 : 8000;
        $inputMaxChars = max(100, (int)aiAgentExtra('embedding_main', 'input_max_chars', $defaultMaxChars));
        if ($adapter === 'cohere_embed_v3') {
            // AWS documenta 512 tokens / ~2048 caracteres por texto para Embed v3.
            $inputMaxChars = min($inputMaxChars, 2048);
        }
        $inputText = mb_substr($text, 0, $inputMaxChars);

        $dimensions = max(1, (int)aiAgentExtra(
            'embedding_main',
            'dimensions',
            $adapter === 'titan_text_v1' ? 1536 : 1024
        ));

        $body = [];
        $cohereInputType = null;

        if ($adapter === 'titan_text_v2') {
            if (!in_array($dimensions, [256, 512, 1024], true)) {
                throw new RuntimeException('Titan Text Embeddings V2 requiere 256, 512 o 1024 dimensiones.');
            }
            $body = [
                'inputText' => $inputText,
                'dimensions' => $dimensions,
                'normalize' => (bool)aiAgentExtra('embedding_main', 'normalize', true),
            ];
        } elseif ($adapter === 'titan_text_v1') {
            $dimensions = 1536;
            $body = ['inputText' => $inputText];
        } else {
            $isDocument = ($inputType === 'search_document');
            $cohereInputType = (string)aiAgentExtra(
                'embedding_main',
                $isDocument ? 'document_input_type' : 'query_input_type',
                $isDocument ? 'search_document' : 'search_query'
            );
            if (!in_array($cohereInputType, ['search_document', 'search_query'], true)) {
                $cohereInputType = $isDocument ? 'search_document' : 'search_query';
            }

            if ($adapter === 'cohere_embed_v3') {
                $dimensions = 1024;
                $body = [
                    'texts' => [$inputText],
                    'input_type' => $cohereInputType,
                    'truncate' => (string)aiAgentExtra('embedding_main', 'truncate', 'END'),
                ];
            } else {
                if (!in_array($dimensions, [256, 512, 1024, 1536], true)) {
                    throw new RuntimeException('Cohere Embed v4 requiere 256, 512, 1024 o 1536 dimensiones.');
                }
                $body = [
                    'texts' => [$inputText],
                    'input_type' => $cohereInputType,
                    'embedding_types' => ['float'],
                    'output_dimension' => $dimensions,
                    'truncate' => (string)aiAgentExtra('embedding_main', 'truncate', 'RIGHT'),
                ];
            }
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
            // AWS puede devolver [[floats]] o, cuando usa embeddings por tipo,
            // {"float": [[floats]]}. Aceptamos ambas formas.
            if (is_array($embeddings) && isset($embeddings['float'])) {
                $embeddings = $embeddings['float'];
            }
            $embedding = (is_array($embeddings) && isset($embeddings[0]) && is_array($embeddings[0]))
                ? $embeddings[0]
                : [];
            // La respuesta nativa documentada por AWS para Cohere Embed no expone
            // inputTextTokenCount; no inventamos ese dato.
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
            'disabled' => false,
        ];
    }
}

if (!function_exists('getSessionAttachmentMode')) {
    function getSessionAttachmentMode(?string $metaJson): string {
        $meta = json_decode((string)$metaJson, true);
        if (!is_array($meta)) $meta = [];

        $mode = strtolower(trim((string)($meta['attachment_rag_mode'] ?? 'rag')));

        return ($mode === 'always') ? 'always' : 'rag';
    }
}

if (!function_exists('getAttachmentFilenameFromBlock')) {
    function getAttachmentFilenameFromBlock(array $block): string {
        $filename = '';

        if (!empty($block['source_ids'])) {
            $source = json_decode((string)$block['source_ids'], true);

            if (is_array($source) && !empty($source['filename'])) {
                $filename = basename((string)$source['filename']);
            }
        }

        if ($filename === '' && !empty($block['s3_path'])) {
            $filename = basename((string)$block['s3_path']);
        }

        return $filename !== '' ? $filename : 'archivo adjunto';
    }
}

/**
 * Detecta preguntas EXPLÍCITAMENTE meta-cognitivas sobre el historial de la sesión.
 * No usa IA: es una decisión determinista para no convertir cualquier seguimiento
 * normal en una consulta permanente del historial crudo.
 */
if (!function_exists('isSessionMetaCognitiveQuery')) {
    function isSessionMetaCognitiveQuery(string $query): bool {
        $q = mb_strtolower(trim($query), 'UTF-8');
        if ($q === '') return false;

        // Normalizar acentos para que variantes con/sin tilde se comporten igual.
        $q = strtr($q, [
            'á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u', 'ü'=>'u', 'ñ'=>'n'
        ]);
        $q = preg_replace('/\s+/u', ' ', $q);

        $patterns = [
            '/\bque (?:te )?he preguntado\b/u',
            '/\bque (?:te )?pregunte\b/u',
            '/\bque preguntas (?:te )?he (?:hecho|realizado)\b/u',
            '/\b(?:lista|muestra|dime|recuerdame) (?:mis|las) preguntas\b/u',
            '/\bhistorial de (?:mis )?preguntas\b/u',
            '/\bde que hemos hablado\b/u',
            '/\bde que hablamos\b/u',
            '/\bque hemos (?:hablado|tratado|conversado)\b/u',
            '/\bque temas (?:hemos )?(?:tratado|hablado|visto|conversado)\b/u',
            '/\b(?:resume|resumeme|hazme un resumen de) (?:esta |la )?(?:conversacion|sesion|charla|chat)\b/u',
            '/\bresumen de (?:esta |la )?(?:conversacion|sesion|charla|chat)\b/u',
            '/\bque recuerdas de (?:esta |la )?(?:conversacion|sesion|charla|chat)\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $q)) return true;
        }
        return false;
    }
}

if (!function_exists('buildSessionBaseContext')) {
    function buildSessionBaseContext(
        mysqli $db,
        int $sessionId,
        array $sessionData,
        string $queryText = '',
        ?array &$telemetry = null
    ): string {
        $isMetaQuery = isSessionMetaCognitiveQuery($queryText);
        $telemetry = [
            'meta_query_detected' => $isMetaQuery,
            'source' => 'none',
            'included_block_types' => [],
            'block_counts' => [],
            'blocks_total' => 0,
            'context_chars' => 0,
        ];

        // Las ramas y los resúmenes consolidados de ChatSessions tienen prioridad.
        // Esto NO es el level_0 crudo y por tanto puede acompañar preguntas normales.
        if (!empty($sessionData['context_summary'])) {
            $contextSummary = trim((string)$sessionData['context_summary']);
            if ($contextSummary !== '') {
                $telemetry['source'] = 'chat_sessions.context_summary';
                $telemetry['context_chars'] = mb_strlen($contextSummary);
                return $contextSummary;
            }
        }

        $blocks = [];

        if ($isMetaQuery) {
            // Preguntas sobre la conversación necesitan los Q&A recientes aún no
            // consolidados, además de los niveles jerárquicos. Los level_0 bloqueados
            // ya fueron consolidados en level_1+ y se excluyen para evitar duplicación.
            $stmt = $db->prepare("
                SELECT block_type, content_preview
                FROM SessionContextBlocks
                WHERE session_id_ = ?
                  AND (
                        block_type IN ('level_1', 'level_2', 'level_3')
                     OR (block_type = 'level_0' AND is_locked = 0)
                  )
                ORDER BY created_at ASC
                LIMIT 30
            ");
            $telemetry['source'] = 'meta_recent_plus_consolidated';
            $telemetry['included_block_types'] = ['level_0_unlocked', 'level_1', 'level_2', 'level_3'];
        } else {
            // Preguntas normales: NUNCA inyectar level_0 por esta ruta.
            // Los Q&A crudos solo pueden entrar mediante Memoria Selectiva.
            $stmt = $db->prepare("
                SELECT block_type, content_preview
                FROM SessionContextBlocks
                WHERE session_id_ = ?
                  AND block_type IN ('level_1', 'level_2', 'level_3')
                ORDER BY created_at ASC
                LIMIT 30
            ");
            $telemetry['source'] = 'consolidated_levels_only';
            $telemetry['included_block_types'] = ['level_1', 'level_2', 'level_3'];
        }

        if (!$stmt) return '';

        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $blocks[] = $row;
            $type = (string)($row['block_type'] ?? 'unknown');
            $telemetry['block_counts'][$type] = (int)($telemetry['block_counts'][$type] ?? 0) + 1;
        }

        $stmt->close();
        $telemetry['blocks_total'] = count($blocks);

        if (empty($blocks)) return '';

        $out = "=== CONTEXTO DE LA CONVERSACIÓN DE ESTA SESIÓN ===\n";

        foreach ($blocks as $idx => $block) {
            $out .= ($idx + 1) . ". " . mb_substr((string)($block['content_preview'] ?? ''), 0, 300) . "\n";
        }

        $telemetry['context_chars'] = mb_strlen($out);
        return $out;
    }
}

if (!function_exists('buildSessionAttachmentContext')) {
    function buildSessionAttachmentContext(
        mysqli $db,
        $bedrock,
        int $sessionId,
        string $queryText,
        string $mode,
        ?array $precomputedQueryVector = null,
        ?int $logMsgId = null,
        ?array &$telemetry = null
    ): string {
        $queryText = trim((string)$queryText);
        $blocks = [];
        $telemetry = [
            'mode' => $mode,
            'query' => $queryText,
            'embedding_model' => aiAgentModel('embedding_main', ''),
            'candidates' => 0,
            'threshold' => (float)aiAgentExtra('embedding_main', 'attachment_rag_threshold', ATTACHMENT_RAG_THRESHOLD_FALLBACK),
            'top_scores' => [],
            'selected' => [],
            'context' => '',
        ];

        // =========================================================
        // MODO "always": incluye adjuntos sin filtro de relevancia
        // =========================================================
        if ($mode === 'always') {
            $stmt = $db->prepare("
                SELECT id_, block_type, content_preview, s3_path, source_ids
                FROM SessionContextBlocks
                WHERE session_id_ = ?
                  AND block_type IN ('file', 'file_chunk')
                ORDER BY block_type ASC, created_at ASC
                LIMIT 30
            ");

            if (!$stmt) return '';

            $stmt->bind_param('i', $sessionId);
            $stmt->execute();
            $res = $stmt->get_result();

            while ($row = $res->fetch_assoc()) {
                $blocks[] = $row;
            }

            $stmt->close();
            $telemetry['candidates'] = count($blocks);

            if (empty($blocks)) return '';

            $out = "=== ARCHIVOS ADJUNTOS DE ESTA SESIÓN (MODO SIEMPRE) ===\n";
            $chars = mb_strlen($out);

            foreach ($blocks as $block) {
                $content = trim((string)($block['content_preview'] ?? ''));
                if ($content === '') continue;

                $filename = getAttachmentFilenameFromBlock($block);

                $label = ($block['block_type'] === 'file')
                    ? "[RESUMEN DE ARCHIVO ADJUNTO - {$filename}]"
                    : "[FRAGMENTO DE ARCHIVO ADJUNTO - {$filename}]";

                $part = $label . "\n" . $content . "\n\n";

                if ($chars + mb_strlen($part) > max(1000, (int)aiAgentExtra('embedding_main', 'attachment_rag_max_chars', ATTACHMENT_RAG_MAX_CHARS_FALLBACK))) {
                    break;
                }

                $out .= $part;
                $chars += mb_strlen($part);
                $telemetry['selected'][] = [
                    'block_id' => (int)$block['id_'],
                    'block_type' => (string)$block['block_type'],
                    'filename' => $filename,
                    'content' => $content,
                ];
            }

            $telemetry['context'] = trim($out);
            return $telemetry['context'];
        }

        // =========================================================
        // MODO "rag": solo adjuntos relevantes
        // =========================================================
        if ($queryText === '') {
            return '';
        }

        // Si embedding_main está desactivado, el modo RAG se omite.
        // El modo 'always' de arriba sigue funcionando sin embeddings.
        if (!aiAgentActive('embedding_main', false)) {
            return '';
        }

        $embeddingModel = aiAgentModel('embedding_main', '');
        $stmt = $db->prepare("
            SELECT id_, block_type, content_preview, s3_path, source_ids, embedding_json, embedding_model
            FROM SessionContextBlocks
            WHERE session_id_ = ?
              AND block_type IN ('file', 'file_chunk')
              AND embedding_json IS NOT NULL
              AND embedding_model = ?
            ORDER BY created_at ASC
            LIMIT 200
        ");

        if (!$stmt) return '';

        $stmt->bind_param('is', $sessionId, $embeddingModel);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $blocks[] = $row;
        }

        $stmt->close();
        $telemetry['candidates'] = count($blocks);

        if (empty($blocks)) return '';

        $queryVector = is_array($precomputedQueryVector) ? $precomputedQueryVector : [];

        if (empty($queryVector)) {
            try {
                $emb = generateTitanEmbedding($bedrock, $queryText, $embeddingModel);
                $queryVector = $emb['embedding'] ?? [];

                if (!empty($emb['inputTokens']) && $emb['inputTokens'] > 0) {
                    logTokenUsage(
                        $db,
                        $sessionId,
                        $logMsgId,
                        (string)aiAgentValue('embedding_main', 'token_usage_phase', 'rag'),
                        $embeddingModel,
                        (int)$emb['inputTokens'],
                        0
                    );
                }
            } catch (Throwable $e) {
                error_log('Attachment RAG embedding error: ' . $e->getMessage());
                return '';
            }
        }

        if (empty($queryVector)) return '';

        $scored = [];

        foreach ($blocks as $block) {
            $vec = json_decode((string)($block['embedding_json'] ?? ''), true);

            if (!is_array($vec) || empty($vec) || count($vec) !== count($queryVector)) {
                continue;
            }

            $score = cosineSimilarity($queryVector, $vec);
            $block['score'] = (float)$score;
            $scored[] = $block;
        }

        if (empty($scored)) return '';

        usort($scored, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        foreach (array_slice($scored, 0, 12) as $candidate) {
            $telemetry['top_scores'][] = [
                'block_id' => (int)$candidate['id_'],
                'block_type' => (string)$candidate['block_type'],
                'filename' => getAttachmentFilenameFromBlock($candidate),
                'score' => round((float)$candidate['score'], 6),
            ];
        }

        $chunks = [];
        $files = [];

        foreach ($scored as $row) {
            if ($row['block_type'] === 'file_chunk') {
                if ($row['score'] >= (float)aiAgentExtra('embedding_main', 'attachment_rag_threshold', ATTACHMENT_RAG_THRESHOLD_FALLBACK)) {
                    $chunks[] = $row;
                }
            } else {
                $files[] = $row;
            }
        }

        $selected = [];

        // 1) Hasta 3 fragmentos relevantes.
        foreach (array_slice($chunks, 0, 3) as $chunk) {
            $selected[$chunk['id_']] = $chunk;
        }

        // 2) Si hay fragmentos seleccionados, agregar el resumen del mismo archivo
        //    si también pasa un umbral ligeramente menor.
        if (!empty($selected)) {
            $selectedPaths = [];

            foreach ($selected as $sel) {
                if (!empty($sel['s3_path'])) {
                    $selectedPaths[(string)$sel['s3_path']] = true;
                }
            }

            foreach ($files as $file) {
                if (count($selected) >= max(1, (int)aiAgentExtra('embedding_main', 'attachment_rag_top', ATTACHMENT_RAG_TOP_FALLBACK))) break;

                if (empty($file['s3_path']) || !isset($selectedPaths[(string)$file['s3_path']])) {
                    continue;
                }

                if ($file['score'] >= (float)aiAgentExtra('embedding_main', 'attachment_related_file_threshold', ATTACHMENT_RAG_RELATED_FILE_THRESHOLD_FALLBACK)) {
                    $selected[$file['id_']] = $file;
                }
            }
        }

        // 3) Completar con resúmenes relevantes si todavía hay espacio.
        foreach ($files as $file) {
            if (count($selected) >= max(1, (int)aiAgentExtra('embedding_main', 'attachment_rag_top', ATTACHMENT_RAG_TOP_FALLBACK))) break;

            if (isset($selected[$file['id_']])) {
                continue;
            }

            if ($file['score'] >= (float)aiAgentExtra('embedding_main', 'attachment_rag_threshold', ATTACHMENT_RAG_THRESHOLD_FALLBACK)) {
                $selected[$file['id_']] = $file;
            }
        }

        // 4) Si no quedó nada, no inyectar adjuntos.
        if (empty($selected)) {
            return '';
        }

        usort($selected, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $out = "=== ARCHIVOS ADJUNTOS RELEVANTES PARA ESTA PREGUNTA ===\n";
        $chars = mb_strlen($out);

        foreach ($selected as $row) {
            $content = trim((string)($row['content_preview'] ?? ''));
            if ($content === '') continue;

            $filename = getAttachmentFilenameFromBlock($row);

            $label = ($row['block_type'] === 'file')
                ? "[RESUMEN DE ARCHIVO ADJUNTO - {$filename}]"
                : "[FRAGMENTO DE ARCHIVO ADJUNTO - {$filename}]";

            $part = $label . "\n" . $content . "\n\n";

            if ($chars + mb_strlen($part) > max(1000, (int)aiAgentExtra('embedding_main', 'attachment_rag_max_chars', ATTACHMENT_RAG_MAX_CHARS_FALLBACK))) {
                break;
            }

            $out .= $part;
            $chars += mb_strlen($part);
            $telemetry['selected'][] = [
                'block_id' => (int)$row['id_'],
                'block_type' => (string)$row['block_type'],
                'filename' => $filename,
                'score' => round((float)($row['score'] ?? 0), 6),
                'content' => $content,
            ];
        }

        $telemetry['context'] = trim($out);
        return $telemetry['context'];
    }
}

// =====================================================================
// ✅ MEMORIA SELECTIVA DE PREGUNTAS ANTERIORES
// =====================================================================

/**
 * Normaliza texto para comparación léxica de líneas.
 */
function normalizeMemorySearchText(string $text): string {
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}_]+/u', ' ', $text);
    return trim((string)$text);
}

/**
 * Obtiene términos útiles de la pregunta actual. No llama a ninguna IA.
 */
function memorySearchTerms(string $query): array {
    $normalized = normalizeMemorySearchText($query);
    if ($normalized === '') return [];

    $stop = array_flip([
        'que','qué','como','cómo','cual','cuál','cuales','cuáles','cuando','cuándo',
        'donde','dónde','quien','quién','para','por','con','sin','una','uno','unos','unas',
        'del','las','los','este','esta','esto','ese','esa','eso','aqui','aquí','ahi','ahí',
        'me','mi','mis','tu','tus','su','sus','se','la','el','lo','de','en','y','o','u',
        'es','son','ser','fue','han','hay','al','un','the','and','or','for','with','from',
        'this','that','what','how','when','where','who','why','is','are','was','were'
    ]);

    $terms = [];
    foreach (preg_split('/\s+/u', $normalized) ?: [] as $term) {
        $term = trim($term);
        if ($term === '' || mb_strlen($term, 'UTF-8') < 3 || isset($stop[$term])) continue;
        $terms[$term] = true;
    }
    return array_keys($terms);
}

/**
 * Extrae una ventana útil de la respuesta alrededor de la línea con mayor
 * coincidencia léxica con la pregunta actual. Si no hay coincidencia por línea,
 * usa el inicio de la respuesta; la selección del Q&A ya fue semántica.
 */
function extractQuestionMemoryWindow(
    string $answer,
    string $query,
    int $windowLines
): string {
    $answer = trim($answer);
    if ($answer === '') return '';

    $lines = preg_split('/\R/u', $answer) ?: [$answer];
    $lines = array_values($lines);
    if (count($lines) <= (($windowLines * 2) + 1)) {
        return $answer;
    }

    $terms = memorySearchTerms($query);
    $bestIndex = 0;
    $bestScore = -1;

    foreach ($lines as $idx => $line) {
        $normalizedLine = normalizeMemorySearchText((string)$line);
        $score = 0;
        foreach ($terms as $term) {
            if ($term !== '' && mb_strpos($normalizedLine, $term, 0, 'UTF-8') !== false) {
                $score++;
            }
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestIndex = $idx;
        }
    }

    $start = max(0, $bestIndex - $windowLines);
    $length = min(count($lines) - $start, ($windowLines * 2) + 1);
    return trim(implode("\n", array_slice($lines, $start, $length)));
}

/**
 * Busca Q&A level_0 ya vectorizados. No usa LLM:
 *  - scope=session: solo la sesión actual
 *  - scope=project: todas las sesiones del mismo proyecto y usuario
 *
 * Retorna contexto listo para chat_main y metadatos para el frontend.
 */
function buildSelectiveQuestionMemory(
    mysqli $db,
    $bedrock,
    int $sessionId,
    int $userId,
    int $projectId,
    string $queryText,
    string $scope,
    int $maxCandidates,
    int $windowLines,
    ?array $precomputedQueryVector = null,
    ?int $logMsgId = null
): array {
    $result = [
        'context' => '',
        'used' => false,
        'question_ids' => [],
        'block_ids' => [],
        'fragments' => 0,
        'candidates' => 0,
        'reindex_queued' => 0,
        'scope' => $scope,
        'embedding_model' => aiAgentModel('embedding_main', ''),
        'threshold' => null,
        'top_limit' => null,
        'candidate_scores' => [],
        'matches' => [],
    ];

    $queryText = trim($queryText);
    if ($queryText === '' || !aiAgentActive('embedding_main', false)) {
        return $result;
    }

    $embeddingModel = aiAgentModel('embedding_main', '');
    if ($embeddingModel === '') return $result;

    $scope = ($scope === 'project') ? 'project' : 'session';
    if ($scope === 'project' && $projectId <= 0) {
        $scope = 'session';
        $result['scope'] = 'session';
    }

    $maxCandidates = max(5, min(50, $maxCandidates));
    $windowLines = max(2, min(15, $windowLines));

    // Si embedding_main cambió (Titan ↔ Cohere, o dimensiones/modelo),
    // encolar silenciosamente los level_0 recientes que todavía tienen
    // un vector de otro modelo. La respuesta actual usa solo vectores compatibles;
    // las siguientes búsquedas los irán recuperando cuando el worker termine.
    if ($scope === 'project') {
        $sqlStale = "
            SELECT scb.id_
            FROM SessionContextBlocks scb
            JOIN ChatSessions cs ON cs.id_ = scb.session_id_
            WHERE scb.block_type = 'level_0'
              AND scb.question_msg_id IS NOT NULL
              AND scb.answer_msg_id IS NOT NULL
              AND cs.user_id_ = ?
              AND cs.project_id_ = ?
              AND (
                    scb.embedding_json IS NULL
                 OR scb.embedding_model IS NULL
                 OR scb.embedding_model <> ?
              )
            ORDER BY scb.created_at DESC
            LIMIT ?
        ";
        $stmtStale = $db->prepare($sqlStale);
        if ($stmtStale) {
            $stmtStale->bind_param('iisi', $userId, $projectId, $embeddingModel, $maxCandidates);
        }
    } else {
        $sqlStale = "
            SELECT scb.id_
            FROM SessionContextBlocks scb
            JOIN ChatSessions cs ON cs.id_ = scb.session_id_
            WHERE scb.block_type = 'level_0'
              AND scb.question_msg_id IS NOT NULL
              AND scb.answer_msg_id IS NOT NULL
              AND cs.user_id_ = ?
              AND scb.session_id_ = ?
              AND (
                    scb.embedding_json IS NULL
                 OR scb.embedding_model IS NULL
                 OR scb.embedding_model <> ?
              )
            ORDER BY scb.created_at DESC
            LIMIT ?
        ";
        $stmtStale = $db->prepare($sqlStale);
        if ($stmtStale) {
            $stmtStale->bind_param('iisi', $userId, $sessionId, $embeddingModel, $maxCandidates);
        }
    }

    if (!empty($stmtStale)) {
        $stmtStale->execute();
        $staleRes = $stmtStale->get_result();
        $staleIds = [];
        while ($staleRow = $staleRes->fetch_assoc()) {
            $staleIds[] = (int)$staleRow['id_'];
        }
        $stmtStale->close();

        foreach ($staleIds as $staleId) {
            $stmtQueue = $db->prepare("
                INSERT IGNORE INTO EmbeddingJobs
                    (target_type, target_id, model_id, status, attempts)
                VALUES ('session_block', ?, ?, 'pending', 0)
            ");
            if ($stmtQueue) {
                $stmtQueue->bind_param('is', $staleId, $embeddingModel);
                $stmtQueue->execute();
                if ($stmtQueue->affected_rows > 0) {
                    $result['reindex_queued']++;
                }
                $stmtQueue->close();
            }
        }
    }

    if ($scope === 'project') {
        $sql = "
            SELECT
                scb.id_, scb.session_id_, scb.question_msg_id, scb.answer_msg_id,
                scb.embedding_json, scb.embedding_model, scb.memory_hits,
                scb.last_memory_used_at, scb.created_at,
                COALESCE(q.content, '') AS question_text,
                COALESCE(a.content, '') AS answer_text
            FROM SessionContextBlocks scb
            JOIN ChatSessions cs ON cs.id_ = scb.session_id_
            LEFT JOIN ChatMessages q ON q.id_ = scb.question_msg_id
            LEFT JOIN ChatMessages a ON a.id_ = scb.answer_msg_id
            WHERE scb.block_type = 'level_0'
              AND scb.embedding_json IS NOT NULL
              AND scb.embedding_model = ?
              AND scb.question_msg_id IS NOT NULL
              AND scb.answer_msg_id IS NOT NULL
              AND cs.user_id_ = ?
              AND cs.project_id_ = ?
            ORDER BY scb.created_at DESC
            LIMIT ?
        ";
        $stmt = $db->prepare($sql);
        if (!$stmt) return $result;
        $stmt->bind_param('siii', $embeddingModel, $userId, $projectId, $maxCandidates);
    } else {
        $sql = "
            SELECT
                scb.id_, scb.session_id_, scb.question_msg_id, scb.answer_msg_id,
                scb.embedding_json, scb.embedding_model, scb.memory_hits,
                scb.last_memory_used_at, scb.created_at,
                COALESCE(q.content, '') AS question_text,
                COALESCE(a.content, '') AS answer_text
            FROM SessionContextBlocks scb
            JOIN ChatSessions cs ON cs.id_ = scb.session_id_
            LEFT JOIN ChatMessages q ON q.id_ = scb.question_msg_id
            LEFT JOIN ChatMessages a ON a.id_ = scb.answer_msg_id
            WHERE scb.block_type = 'level_0'
              AND scb.embedding_json IS NOT NULL
              AND scb.embedding_model = ?
              AND scb.question_msg_id IS NOT NULL
              AND scb.answer_msg_id IS NOT NULL
              AND cs.user_id_ = ?
              AND scb.session_id_ = ?
            ORDER BY scb.created_at DESC
            LIMIT ?
        ";
        $stmt = $db->prepare($sql);
        if (!$stmt) return $result;
        $stmt->bind_param('siii', $embeddingModel, $userId, $sessionId, $maxCandidates);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $candidates = [];
    while ($row = $res->fetch_assoc()) {
        $candidates[] = $row;
    }
    $stmt->close();
    $result['candidates'] = count($candidates);

    if (!$candidates) return $result;

    $queryVector = is_array($precomputedQueryVector) ? $precomputedQueryVector : [];
    if (!$queryVector) {
        try {
            $emb = generateTitanEmbedding($bedrock, $queryText, $embeddingModel, 'search_query');
            $queryVector = $emb['embedding'] ?? [];
            if (($emb['inputTokens'] ?? 0) > 0) {
                logTokenUsage(
                    $db,
                    $sessionId,
                    $logMsgId,
                    (string)aiAgentValue('embedding_main', 'token_usage_phase', 'rag'),
                    $embeddingModel,
                    (int)$emb['inputTokens'],
                    0
                );
            }
        } catch (Throwable $e) {
            error_log('QUESTION_MEMORY_EMBEDDING: ' . $e->getMessage());
            return $result;
        }
    }
    if (!$queryVector) return $result;

    $scored = [];
    foreach ($candidates as $row) {
        $vec = json_decode((string)($row['embedding_json'] ?? ''), true);
        if (!is_array($vec) || !$vec || count($vec) !== count($queryVector)) continue;
        $row['score'] = cosineSimilarity($queryVector, $vec);
        $scored[] = $row;
    }
    if (!$scored) return $result;

    usort($scored, static function(array $a, array $b): int {
        return ($b['score'] <=> $a['score']);
    });

    $threshold = (float)aiAgentExtra('embedding_main', 'question_memory_threshold', 0.30);
    $top = max(1, min(5, (int)aiAgentExtra('embedding_main', 'question_memory_top', 3)));
    $maxChars = max(1000, (int)aiAgentExtra('embedding_main', 'question_memory_max_chars', 8000));
    $result['threshold'] = $threshold;
    $result['top_limit'] = $top;
    foreach (array_slice($scored, 0, 15) as $candidate) {
        $result['candidate_scores'][] = [
            'block_id' => (int)$candidate['id_'],
            'session_id' => (int)$candidate['session_id_'],
            'question_msg_id' => (int)$candidate['question_msg_id'],
            'score' => round((float)$candidate['score'], 6),
            'question' => mb_substr((string)($candidate['question_text'] ?? ''), 0, 500),
        ];
    }

    $parts = [];
    $usedBlockIds = [];
    $usedQuestionIds = [];
    $chars = 0;

    foreach ($scored as $row) {
        if (count($parts) >= $top) break;
        if ((float)$row['score'] < $threshold) continue;

        $question = trim((string)($row['question_text'] ?? ''));
        $answer = trim((string)($row['answer_text'] ?? ''));
        if ($question === '' || $answer === '') continue;

        $fragment = extractQuestionMemoryWindow($answer, $queryText, $windowLines);
        if ($fragment === '') continue;

        $part =
            "[PREGUNTA ANTERIOR RELEVANTE · similitud " . number_format((float)$row['score'], 3, '.', '') . "]\n" .
            "Pregunta: " . mb_substr($question, 0, 1200) . "\n" .
            "Fragmento útil de la respuesta:\n" . mb_substr($fragment, 0, 3500) . "\n";

        if ($chars + mb_strlen($part) > $maxChars) break;

        $parts[] = $part;
        $chars += mb_strlen($part);
        $usedBlockIds[] = (int)$row['id_'];
        $usedQuestionIds[] = (int)$row['question_msg_id'];
        $result['matches'][] = [
            'block_id' => (int)$row['id_'],
            'session_id' => (int)$row['session_id_'],
            'question_msg_id' => (int)$row['question_msg_id'],
            'answer_msg_id' => (int)$row['answer_msg_id'],
            'score' => round((float)$row['score'], 6),
            'question' => $question,
            'fragment' => $fragment,
            'previous_memory_hits' => (int)($row['memory_hits'] ?? 0),
            'last_memory_used_at' => $row['last_memory_used_at'] ?? null,
        ];
    }

    if (!$parts) return $result;

    $placeholders = implode(',', array_fill(0, count($usedBlockIds), '?'));
    $types = str_repeat('i', count($usedBlockIds));
    $upd = $db->prepare("
        UPDATE SessionContextBlocks
        SET memory_hits = COALESCE(memory_hits, 0) + 1,
            last_memory_used_at = NOW()
        WHERE id_ IN ($placeholders)
    ");
    if ($upd) {
        $upd->bind_param($types, ...$usedBlockIds);
        $upd->execute();
        $upd->close();
    }

    $result['context'] =
        "=== MEMORIA SELECTIVA DE PREGUNTAS ANTERIORES ===\n" .
        "Usa estos fragmentos solo como contexto histórico relevante. Si contradicen instrucciones actuales o reglas primordiales, prevalecen las instrucciones actuales.\n\n" .
        implode("\n", $parts);
    $result['used'] = true;
    $result['question_ids'] = $usedQuestionIds;
    $result['block_ids'] = $usedBlockIds;
    $result['fragments'] = count($parts);

    return $result;
}

// =====================================================================
// ✅ HELPER: Obtener un message_id_ válido para TokenUsage
// Si el ID proporcionado no existe en ChatMessages, busca el último
// mensaje de la sesión. Si tampoco hay, retorna NULL (no 0).
// =====================================================================
function getValidMessageId(mysqli $db, $candidateMsgId, int $sessionId): ?int {
    // 1. Verificar si el candidato existe
    if ($candidateMsgId && (int)$candidateMsgId > 0) {
        $chk = $db->prepare("SELECT id_ FROM ChatMessages WHERE id_ = ? LIMIT 1");
        if ($chk) {
            $chk->bind_param('i', $candidateMsgId);
            $chk->execute();
            $res = $chk->get_result();
            if ($res->num_rows > 0) {
                $chk->close();
                return (int)$candidateMsgId;
            }
            $chk->close();
        }
    }

    // 2. Fallback: buscar el último mensaje de la sesión
    $chkLast = $db->prepare("SELECT id_ FROM ChatMessages WHERE session_id_ = ? ORDER BY id_ DESC LIMIT 1");
    if ($chkLast) {
        $chkLast->bind_param('i', $sessionId);
        $chkLast->execute();
        $resLast = $chkLast->get_result();
        if ($rowLast = $resLast->fetch_assoc()) {
            $chkLast->close();
            return (int)$rowLast['id_'];
        }
        $chkLast->close();
    }

    // 3. No hay ningún mensaje → NULL (la FK lo acepta)
    return null;
}

// ====================================================================
// FUNCIONES DE HERRAMIENTAS (TOOL USE)
// ====================================================================
function execute_tool_grep($args, $projectId, $db) {
    $pattern = $args['pattern'] ?? '';
    if ($pattern === '') return json_encode(['error' => 'Falta el parámetro "pattern"']);
    
    // ✅ CORREGIDO: Buscar tanto en el contenido COMO en el nombre del archivo
    $sql = "SELECT sc.id_ as chunk_id, sc.source_id_, sc.name, sc.content, sc.start_line, sc.end_line, ps.filename, ps.s3_key, ps.status
            FROM SourceChunks sc JOIN ProjectSources ps ON ps.id_ = sc.source_id_
            WHERE sc.project_id_ = ? AND (sc.content LIKE ? OR ps.filename LIKE ?) LIMIT 10";
    
    $stmt = $db->prepare($sql);
    $like = '%' . $pattern . '%';
    
    // ✅ CORREGIDO: 3 parámetros (int, string, string)
    $stmt->bind_param('iss', $projectId, $like, $like); 
    $stmt->execute();
    $res = $stmt->get_result();
    
    $matches = [];
    while ($row = $res->fetch_assoc()) {
        $lines = explode("\n", $row['content']);
        $matching = [];
        foreach ($lines as $i => $line) {
            if (stripos($line, $pattern) !== false) {
                $matching[] = ['line' => $row['start_line'] + $i, 'text' => trim($line)];
            }
        }
        
        // ✅ CORREGIDO: Mostrar el resultado si hay coincidencia en el contenido O en el nombre
        if (!empty($matching) || stripos($row['filename'], $pattern) !== false) {
            $matches[] = [
                'chunk_id' => (int)$row['chunk_id'], 
                'source_id' => (int)$row['source_id_'],
                'file' => $row['filename'], 
                's3_key' => $row['s3_key'],       // <-- NUEVO: Para que la IA sepa la ruta real
                'status' => $row['status'],        // <-- NUEVO: Para que sepa si está indexado
                'chunk' => $row['name'], 
                'lines' => $matching,
                'match_type' => (stripos($row['filename'], $pattern) !== false) ? 'nombre_archivo' : 'contenido'
            ];
        }
    }
    $stmt->close();
    return json_encode(['matches' => $matches, 'total' => count($matches)], JSON_UNESCAPED_UNICODE);
}

function execute_tool_view($args, $projectId, $db) {
    $chunk_id = (int)($args['chunk_id'] ?? 0);
    if ($chunk_id <= 0) return json_encode(['error' => 'chunk_id inválido']);
    
    $sql = "SELECT sc.content, sc.name, sc.start_line, sc.end_line, ps.filename 
            FROM SourceChunks sc JOIN ProjectSources ps ON ps.id_ = sc.source_id_ 
            WHERE sc.id_ = ? AND sc.project_id_ = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('ii', $chunk_id, $projectId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $stmt->close();
        return json_encode([
            'file' => $row['filename'], 'name' => $row['name'], 
            'lines' => $row['start_line'].'-'.$row['end_line'], 'content' => $row['content']
        ], JSON_UNESCAPED_UNICODE);
    }
    $stmt->close();
    return json_encode(['error' => 'Chunk no encontrado']);
}

function execute_tool_search($args, $projectId, $db, $bedrockClient) {
    $query = $args['query'] ?? '';
    if ($query === '') return json_encode(['error' => 'Falta el parámetro "query"']);
    
    if (!aiAgentActive('embedding_main', false)) {
        return json_encode(['error' => 'La búsqueda semántica está desactivada porque embedding_main.is_active = 0'], JSON_UNESCAPED_UNICODE);
    }

    try {
        $embeddingModel = aiAgentModel('embedding_main', '');
        $embedData = generateTitanEmbedding($bedrockClient, $query, $embeddingModel);
        $queryVector = $embedData['embedding'] ?? [];
    } catch (Throwable $e) {
        return json_encode(['error' => 'No se pudo generar el embedding: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    if (empty($queryVector)) return json_encode(['error' => 'No se pudo generar el embedding']);

    $sql = "SELECT sc.id_ as chunk_id, sc.source_id_, sc.name, sc.content, ps.filename, ce.embedding_json, ce.model_id
            FROM SourceChunks sc
            JOIN ProjectSources ps ON ps.id_ = sc.source_id_
            JOIN ChunkEmbeddings ce ON ce.chunk_id_ = sc.id_
            WHERE sc.project_id_ = ? AND ce.model_id = ?
            LIMIT 100";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('is', $projectId, $embeddingModel);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $scored = [];
    while ($row = $res->fetch_assoc()) {
        $dbVector = json_decode($row['embedding_json'], true);
        if (is_array($dbVector) && count($dbVector) > 0 && count($dbVector) === count($queryVector)) {
            $score = cosineSimilarity($queryVector, $dbVector);
            if ($score > (float)aiAgentExtra('embedding_main', 'semantic_search_threshold', 0.35)) {
                $scored[] = [
                    'chunk_id' => (int)$row['chunk_id'],
                    'source_id' => (int)$row['source_id_'],
                    'file' => $row['filename'], 
                    'name' => $row['name'], 
                    'score' => round($score, 3),
                    'preview' => mb_substr($row['content'], 0, 300).'...'
                ];
            }
        }
    }
    $stmt->close();
    usort($scored, function($a, $b) { return $b['score'] <=> $a['score']; });
    return json_encode(['results' => array_slice($scored, 0, 5)], JSON_UNESCAPED_UNICODE);
}

function execute_tool_str_replace($args, $projectId, $db) {
    try {
        $source_id = (int)($args['source_id'] ?? 0);
        $old_text = $args['old_text'] ?? '';
        $new_text = $args['new_text'] ?? '';
        
        if ($source_id <= 0 || $old_text === '') {
            return json_encode(['error' => 'Faltan parámetros: source_id, old_text']);
        }
        if (!class_exists('S3Manager')) {
            return json_encode(['error' => 'S3Manager no disponible']);
        }
        
        // 1. Obtener información del archivo
        $sql = "SELECT ps.*, p.root_prefix FROM ProjectSources ps JOIN Projects p ON p.id_ = ps.project_id_ WHERE ps.id_ = ? AND ps.project_id_ = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param('ii', $source_id, $projectId);
        $stmt->execute();
        $res = $stmt->get_result();
        if (!($source = $res->fetch_assoc())) {
            $stmt->close();
            return json_encode(['error' => 'Fuente no encontrada']);
        }
        $stmt->close();

        // 2. Leer contenido actual de S3
        $manager = new S3Manager();
        $s3 = Config::getS3();
        $bucket = $manager->getBucket();

        $result = $s3->getObject(['Bucket' => $bucket, 'Key' => $source['s3_key']]);
        $content = (string)$result['Body'];

        // 3. Realizar el reemplazo
        $count = 0;
        $newContent = str_replace($old_text, $new_text, $content, $count);
        if ($count === 0) {
            return json_encode(['error' => 'El texto a reemplazar no se encontró en el archivo. Asegúrate de que coincida exactamente, incluyendo espacios y saltos de línea.']);
        }

        // 4. Guardar el nuevo contenido en S3
        $s3->putObject([
            'Bucket' => $bucket, 
            'Key' => $source['s3_key'], 
            'Body' => $newContent, 
            'ContentType' => $source['mime_type'] ?: 'text/plain', 
            'ACL' => 'private'
        ]);

        // 5. Preparar inmediatamente chunks + EmbeddingJobs con la misma
        //    infraestructura que index_project_sources.php/code_edit.php.
        $indexResult = ['ok'=>false, 'error'=>'ProjectIndexer no disponible'];
        if (function_exists('indexProjectSourceContent')) {
            $indexResult = indexProjectSourceContent(
                $db,
                null,
                (int)$projectId,
                $source_id,
                (string)$source['filename'],
                $newContent
            );
        } else {
            $upd = $db->prepare("UPDATE ProjectSources SET status='stale', indexed_at=NULL WHERE id_=?");
            if ($upd) { $upd->bind_param('i',$source_id); $upd->execute(); $upd->close(); }
        }

        return json_encode([
            'replacements' => $count,
            'file' => $source['filename'],
            'status' => !empty($indexResult['ok']) ? 'embedding_queued' : 'marked_for_reindex',
            'indexed' => (bool)($indexResult['indexed'] ?? false),
            'index_queued' => (bool)($indexResult['queued'] ?? false),
            'index_jobs' => (int)($indexResult['jobs'] ?? 0),
            'embedding_model' => $indexResult['model'] ?? null,
            'needs_indexing' => empty($indexResult['ok']),
            'index_error' => empty($indexResult['ok']) ? ($indexResult['error'] ?? null) : null,
        ], JSON_UNESCAPED_UNICODE);     
        
    } catch (Throwable $e) {
        return json_encode(['error' => 'Error en str_replace: ' . $e->getMessage()]);
    }
}

function execute_tool_code_edit($args, $projectId, $sessionId, $db) {
    $targetFilename = $args['target_filename'] ?? '';
    $action = in_array(($args['action'] ?? 'write'), ['write', 'read', 'delete'], true) ? $args['action'] : 'write';
    $instruction = $args['instruction'] ?? '';

    if ($targetFilename === '') {
        return json_encode(['error' => 'Falta target_filename'], JSON_UNESCAPED_UNICODE);
    }
    if ($action === 'write' && $instruction === '') {
        return json_encode(['error' => "Falta instruction (obligatoria cuando action='write')"], JSON_UNESCAPED_UNICODE);
    }

    // Construir URL absoluta a code_edit.php
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $uri = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    $url = $protocol . $host . $uri . '/code_edit.php';

    $postData = http_build_query([
        'session_id' => $sessionId,
        'project_id' => $projectId,
        'target_filename' => $targetFilename,
        'action' => $action,
        'instruction' => $instruction
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 280,
        CURLOPT_FOLLOWLOCATION => true, // Evita fallos silenciosos si el servidor redirige http->https
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_COOKIE => $_SERVER['HTTP_COOKIE'] ?? '' // Mantiene la sesión activa
    ]);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (!$data || !isset($data['ok']) || !$data['ok']) {
            return json_encode(['error' => $data['error'] ?? 'Error desconocido en code_edit.php'], JSON_UNESCAPED_UNICODE);
        }

        if ($action === 'read') {
            return json_encode([
                'success' => true,
                'filename' => $data['filename'] ?? $targetFilename,
                'size_bytes' => $data['size_bytes'] ?? null,
                'content' => $data['content'] ?? ''
            ], JSON_UNESCAPED_UNICODE);
        }
        if ($action === 'delete') {
            return json_encode([
                'success' => true,
                'message' => $data['message'] ?? "✅ Archivo '{$targetFilename}' eliminado."
            ], JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'success' => true,
            'message' => "✅ Archivo '{$targetFilename}' " . ($data['new_version'] === '1' ? 'creado' : 'editado') . " exitosamente.",
            'version' => $data['new_version'],
            'model_used' => $data['model_used'] ?? 'unknown',
            'summary' => $data['diff_summary'] ?? null,
            'indexed' => $data['indexed'] ?? false,
            'index_queued' => $data['index_queued'] ?? false,
            'index_jobs' => $data['index_jobs'] ?? 0,
            'embedding_model' => $data['embedding_model'] ?? null,
            'needs_indexing' => $data['needs_indexing'] ?? false
        ], JSON_UNESCAPED_UNICODE);
    }

    return json_encode(['error' => 'code_edit.php respondió con HTTP ' . $httpCode . ($curlErr ? " (curl: {$curlErr})" : '') . ': ' . ($response ?: 'sin respuesta')], JSON_UNESCAPED_UNICODE);
}

// ===== Cargar bootstrap (autoload + Config + db) =====
try {
  $bootstrap = __DIR__ . '/app_bootstrap.php';
  if (!is_file($bootstrap)) $bootstrap = __DIR__ . '/../app_bootstrap.php';
  if (!is_file($bootstrap)) {
    $bases = resolve_root_candidates();
    $bootstrap = find_file_in_candidates('app_bootstrap.php', $bases, ['', 'public_html', 'api', 'app', 'www']);
  }
  if (!$bootstrap || !is_file($bootstrap)) throw new RuntimeException('app_bootstrap.php no encontrado.');
  require_once $bootstrap;
} catch (Throwable $e) {
  $errors[] = 'bootstrap: ' . $e->getMessage();
}

// ===== S3Manager =====
$have_s3 = false;
try {
  $s3Path = __DIR__ . '/S3Manager.php';
  if (!is_file($s3Path)) $s3Path = __DIR__ . '/../S3Manager.php';
  if (!is_file($s3Path)) {
    $bases = resolve_root_candidates();
    $s3Path = find_file_in_candidates('S3Manager.php', $bases, ['', 'bd', 'config', 'app', 'includes', 'lib']);
  }
  if ($s3Path && is_file($s3Path)) {
    require_once $s3Path;
    $have_s3 = true;
  } else {
    $errors[] = 'S3Manager.php no encontrado.';
  }
} catch (Throwable $e) {
  $errors[] = 'S3Manager: ' . $e->getMessage();
}

// ===== Validar DB =====
if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'DB no disponible','details'=>$errors], JSON_UNESCAPED_UNICODE);
  exit;
}

// ===== Runtime dinámico de agentes IA =====
$aiRuntimeHelper = __DIR__ . '/includes/ai_agent_runtime.php';
if (!is_file($aiRuntimeHelper)) {
    $aiRuntimeHelper = __DIR__ . '/../includes/ai_agent_runtime.php';
}
if (!is_file($aiRuntimeHelper)) {
    jexit([
        'ok' => false,
        'error' => 'No se encontró includes/ai_agent_runtime.php. Este archivo es requerido para la configuración dinámica de IA.'
    ], 500);
}
require_once $aiRuntimeHelper;

$projectIndexerHelper = __DIR__ . '/includes/ProjectIndexer.php';
if (is_file($projectIndexerHelper)) {
    require_once $projectIndexerHelper;
}

// ===== AWS SDK cargado? =====
$aws_sdk_loaded = class_exists('Aws\\BedrockRuntime\\BedrockRuntimeClient') || class_exists('Aws\\Textract\\TextractClient');
if (!$aws_sdk_loaded) $errors[] = 'AWS SDK no está cargado (vendor/autoload.php). Revisa app_bootstrap.php';

// ===== Memory Context Router + Context Builder + Ranking + Writer (Fase 4.1) =====
$memoryContextRouter = null;

$memoryRouterPath = __DIR__ . '/includes/MemoryContextRouter.php';
$contextBuilderBootstrap = __DIR__ . '/includes/Memory/bootstrap.php';
$memoryWriterBootstrap = __DIR__ . '/includes/MemoryWrite/bootstrap.php';
$pipelineFeaturePath = __DIR__ . '/includes/Pipeline/PipelineFeatureFlags.php';

if (is_file($memoryRouterPath)) {
    require_once $memoryRouterPath;
    $memoryContextRouter = new MemoryContextRouter();
}
if (is_file($contextBuilderBootstrap)) {
    require_once $contextBuilderBootstrap;
}
if (is_file($memoryWriterBootstrap)) {
    require_once $memoryWriterBootstrap;
}
if (is_file($pipelineFeaturePath)) {
    require_once $pipelineFeaturePath;
}
if (!$memoryContextRouter || !class_exists('ContextBuilder') || !class_exists('MemoryRoute') || !class_exists('ContextRanker') || !class_exists('MemoryWriter') || !class_exists('PipelineFeatureFlags')) {
    jexit([
        'ok' => false,
        'error' => 'Faltan componentes del pipeline: Router, ContextBuilder/Ranker, MemoryWriter o PipelineFeatureFlags.'
    ], 500);
}

// ===== Parámetros =====
$session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
if ($session_id <= 0) jexit(['ok'=>false,'error'=>'session_id inválido'], 400);

// Fase 4.1: la identidad del chat se deriva únicamente de la sesión autenticada.
// El user_id enviado por el navegador es sólo una comprobación de consistencia;
// nunca se utiliza como fallback y tampoco se usa Config::DEFAULT_USER_ID.
$chatIdentityPath = __DIR__ . '/includes/Chat/ChatIdentity.php';
if (!is_file($chatIdentityPath)) {
    jexit(['ok'=>false,'error'=>'No se encontró includes/Chat/ChatIdentity.php'], 500);
}
require_once $chatIdentityPath;
$user_id = ChatIdentity::resolveUserId($db_connection);
if ($user_id <= 0) {
    jexit(['ok'=>false,'error'=>'Sesión de usuario no válida'], 401);
}
if (isset($_POST['user_id']) && is_numeric($_POST['user_id']) && (int)$_POST['user_id'] !== $user_id) {
    jexit(['ok'=>false,'error'=>'user_id no coincide con la sesión autenticada'], 403);
}

// Cargar TODA la configuración efectiva una sola vez por petición.
// Esto incluye agentes de IA y text_block usados para construir prompts.
try {
    aiRuntimeLoad($db_connection, $user_id);
} catch (Throwable $e) {
    error_log('AI_AGENT_CONFIG_LOAD: ' . $e->getMessage());
    jexit([
        'ok' => false,
        'error' => 'No se pudo cargar la configuración dinámica de UserAIAgentConfigs.',
        'details' => $e->getMessage(),
    ], 500);
}

// ===== Fase 5 · Feature Flags del pipeline =====
$pipelineFlags = new PipelineFeatureFlags($db_connection, $user_id);
$pipelineConfigured = $pipelineFlags->all();
$embeddingAvailable = aiAgentActive('embedding_main', false) && aiAgentModel('embedding_main', '') !== '';
$pipelineEffective = $pipelineConfigured;
$pipelineEffective['prompt_compiler'] = !empty($pipelineConfigured['prompt_compiler'])
    && aiAgentActive('prompt_compiler', false)
    && aiAgentModel('prompt_compiler', '') !== '';
$pipelineEffective['project_rag'] = !empty($pipelineConfigured['project_rag']) && $embeddingAvailable;
$pipelineEffective['question_memory_read'] = !empty($pipelineConfigured['question_memory_read']) && $embeddingAvailable;
// attachment_rag puede seguir funcionando en modo 'always' aun sin embeddings.
$pipelineEffective['attachment_rag'] = !empty($pipelineConfigured['attachment_rag']);

$text = isset($_POST['text']) ? trim((string)$_POST['text']) : '';
// ✅ INICIALIZAR $compilation_id ANTES del debounce para evitar "Undefined variable"  
$compilation_id = isset($_POST['compilation_id']) ? (int)$_POST['compilation_id'] : 0;
// Fase 6: una petición de fallback es una continuación legítima del mismo turno.
// Debe saltarse el compilador y reutilizar el mensaje original ya persistido.
$compiler_fallback_requested = isset($_POST['compiler_fallback']) && (string)$_POST['compiler_fallback'] === '1';
// ========================================================================
// ✅ BANDERA ANTI-DOBLE ENVÍO (DEBOUNCE) PARA EVITAR REGISTROS DUPLICADOS
// CORREGIDO: NO se aplica en la Fase 2 (compilation_id > 0) porque es
// una continuación legítima del mismo flujo, no un doble envío.
// ========================================================================
if ($text !== '' && $compilation_id === 0 && !$compiler_fallback_requested) {
    // Solo aplicamos debounce en la Fase 1 (compile_only o chat normal)
    // La Fase 2 (compilation_id > 0) es la aprobación del prompt compilado
    // y SIEMPRE debe pasar, aunque sea el mismo texto en menos de 4 segundos.
    
    $debounce_key = 'chat_debounce_' . $session_id;
    $now = time();
    
    if (isset($_SESSION[$debounce_key])) {
        $last_time = $_SESSION[$debounce_key]['time'];
        $last_text = $_SESSION[$debounce_key]['text'];
        
        // Si es exactamente el mismo texto y han pasado menos de 4 segundos, bloqueamos.
        if ($last_text === $text && ($now - $last_time) < 4) {
            jexit([
                'ok' => false, 
                'error' => 'Solicitud duplicada detectada. Tu mensaje anterior ya se está procesando, por favor espera unos segundos.'
            ], 429);
        }
    }
    
    // Actualizamos la bandera con la nueva solicitud válida
    $_SESSION[$debounce_key] = ['time' => $now, 'text' => $text];
}
// ========================================================================
// ========================================================================
// A partir de aquí ya no necesitamos escribir en $_SESSION. Liberar el lock
// permite que chat_activity_poll.php consulte en paralelo mientras Bedrock trabaja.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$auto = (isset($_POST['auto']) && (string)$_POST['auto'] === '1');

// El modelo principal YA NO se toma de $_POST['model'].
// index.php actualiza chat_main.model_id y este backend lee esa configuración.
if (!aiAgentConfig('chat_main')) {
    jexit(['ok' => false, 'error' => "No existe agent_key='chat_main' en UserAIAgentConfigs para este usuario ni como global."], 500);
}
if (!aiAgentActive('chat_main', false)) {
    jexit(['ok' => false, 'error' => "El agente chat_main está desactivado en UserAIAgentConfigs."], 503);
}
$model_id = aiAgentModel('chat_main', '');
if ($model_id === '') {
    jexit(['ok'=>false,'error'=>'chat_main no tiene model_id configurado en UserAIAgentConfigs'], 500);
}

// Mantener compatibilidad con preferencias por petición para parámetros numéricos.
// Si no llegan desde el frontend, se usan los valores guardados en la tabla.
$temperature = isset($_POST['temperature'])
    ? (float)$_POST['temperature']
    : (float)aiAgentValue('chat_main', 'temperature', 0.7);
$max_tokens = isset($_POST['max_tokens'])
    ? max(1, (int)$_POST['max_tokens'])
    : max(1, (int)aiAgentExtra('chat_main', 'default_max_tokens_fallback', 1200));
$top_p = isset($_POST['top_p'])
    ? (float)$_POST['top_p']
    : (float)aiAgentValue('chat_main', 'top_p', 0.9);
$use_rag = isset($_POST['use_rag']) && $_POST['use_rag'] === '1';

// Memoria selectiva: solo controla recuperación. Guardar/vectorizar Q&A ocurre
// independientemente para que la memoria esté disponible cuando se active después.
$use_question_memory = (!isset($_POST['use_question_memory']) || $_POST['use_question_memory'] === '1')
    && !empty($pipelineEffective['question_memory_read']);
$question_memory_scope = isset($_POST['question_memory_scope']) && $_POST['question_memory_scope'] === 'session'
    ? 'session'
    : 'project';
$question_memory_max_candidates = isset($_POST['question_memory_max_candidates'])
    ? max(5, min(50, (int)$_POST['question_memory_max_candidates']))
    : 20;
$question_memory_window_lines = isset($_POST['question_memory_window_lines'])
    ? max(2, min(15, (int)$_POST['question_memory_window_lines']))
    : 5;

// Parámetros del compilador: modelo/estado/instrucciones desde la tabla.
$compile_temperature = isset($_POST['compile_temperature'])
    ? (float)$_POST['compile_temperature']
    : (float)aiAgentValue('prompt_compiler', 'temperature', 0.0);
$compile_max_tokens = isset($_POST['compile_max_tokens'])
    ? max(100, (int)$_POST['compile_max_tokens'])
    : max(100, (int)aiAgentValue('prompt_compiler', 'max_tokens_prompt', 200));
$compile_top_p = isset($_POST['compile_top_p'])
    ? (float)$_POST['compile_top_p']
    : (float)aiAgentValue('prompt_compiler', 'top_p', 0.1);
$resp_max_tokens = isset($_POST['resp_max_tokens'])
    ? max(100, (int)$_POST['resp_max_tokens'])
    : max(100, (int)aiAgentValue('chat_main', 'max_tokens_output', 1000));
$seed = isset($_POST['seed'])
    ? max(0, (int)$_POST['seed'])
    : max(0, (int)aiAgentValue('chat_main', 'seed', 0));

// NUEVO: Parámetros para Human-in-the-loop (Fase 4)
$compile_only_requested = isset($_POST['compile_only']) && $_POST['compile_only'] === '1';
// Si el compilador está apagado, la primera petición se convierte en respuesta
// directa. chat.js ya soporta este contrato cuando no recibe phase=compile_only.
$compile_only = $compile_only_requested
    && !$compiler_fallback_requested
    && !empty($pipelineEffective['prompt_compiler']);
$compiled_prompt_input = isset($_POST['compiled_prompt']) ? trim($_POST['compiled_prompt']) : '';
$compilation_id = isset($_POST['compilation_id']) ? (int)$_POST['compilation_id'] : 0;

// ===== Verificar sesión =====
$stmtS = $db_connection->prepare("SELECT id_, project_id_, meta, context_summary FROM ChatSessions WHERE id_=? AND user_id_=?");
if(!$stmtS) jexit(['ok'=>false,'error'=>'Error preparando SELECT sesión: '.$db_connection->error],500);
$stmtS->bind_param('ii', $session_id, $user_id);
if(!$stmtS->execute()){ $e=$stmtS->error; $stmtS->close(); jexit(['ok'=>false,'error'=>'Error ejecutando SELECT sesión: '.$e],500); }
$resS = $stmtS->get_result();
if(!$resS || !$resS->num_rows){ $stmtS->close(); jexit(['ok'=>false,'error'=>'Sesión no encontrada'],404); }
$sessionData = $resS->fetch_assoc();
$stmtS->close();
$memoryScope = (new ConversationScopeResolver($db_connection))->resolve($user_id, $session_id);
$projectId = $memoryScope->projectId();
$requested_question_memory_scope = $question_memory_scope;
// Fase 4.1: chats libres jamás pueden pedir scope=project; los proyectos sí comparten por project_id_.
$question_memory_scope = $memoryScope->semanticScope();
$attachmentMode = getSessionAttachmentMode($sessionData['meta'] ?? '');

// =====================================================================
// MEMORY CONTEXT ROUTER · FASE 1
// Decide qué memoria consultar usando la pregunta ORIGINAL del usuario.
// No llama a ningún modelo ni genera embeddings.
// =====================================================================
$memoryRouteText = $text !== '' ? $text : $compiled_prompt_input;
if (!empty($pipelineEffective['memory_router'])) {
    $memoryRoute = $memoryContextRouter->route($memoryRouteText, [
        'project_id' => $projectId,
        'has_project' => $memoryScope->isProject(),
        'scope_kind' => $memoryScope->kind(),
        'has_lineage' => $memoryScope->hasLineage(),
    ]);
} else {
    // Ruta neutra: ninguna recuperación implícita ni herramientas.
    $memoryRoute = [
        'version' => 5,
        'context_contract' => 'pipeline_router_disabled_v5',
        'query' => $memoryRouteText,
        'intent' => 'general',
        'mode' => 'none',
        'confidence' => 1.0,
        'signals' => ['memory_router_disabled'],
        'scores' => ['general' => 1],
        'has_project' => $memoryScope->isProject(),
        'scope_kind' => $memoryScope->kind(),
        'has_lineage' => $memoryScope->hasLineage(),
        'execution_lane' => 'chat',
        'decision_summary' => 'Memory Context Router desactivado por Feature Flag.',
        'code_operation' => false,
        'code_policy_only' => false,
        'use_project_tools' => false,
        'use_policy_procedural_memory' => false,
        'use_answer_procedural_memory' => false,
        'use_project_context' => false,
        'project_context_types' => [],
        'use_session_context' => false,
        'use_question_memory' => false,
        'question_memory_fallback' => false,
        'use_project_rag' => false,
        'use_attachment_context' => false,
    ];
}
$memoryRoute['memory_scope'] = $memoryScope->toArray();


// Identificador estable del turno. Existe aunque la telemetría visual esté apagada y
// permite que el fallback del compilador reutilice el mensaje original sin duplicarlo.
$requestFlowId = trim((string)($_POST['request_id'] ?? ''));
if ($requestFlowId !== '' && !preg_match('/^[A-Za-z0-9_-]{16,36}$/', $requestFlowId)) {
    $requestFlowId = '';
}

// Trace compartido por las fases compile + respond. Lo genera chat.js cuando la actividad está visible.
$activityTraceId = trim((string)($_POST['trace_id'] ?? ''));
if ($activityTraceId !== '' && !preg_match('/^[A-Za-z0-9_-]{16,36}$/', $activityTraceId)) {
    $activityTraceId = '';
}
$activityPhase = $compile_only ? 'compile' : 'respond';
if ($activityTraceId !== '') {
    activityEmit(
        $db_connection, $activityTraceId, $session_id, $user_id, $activityPhase,
        'request_started', 'started',
        $compile_only ? 'Iniciando compilación del prompt' : 'Iniciando generación de respuesta',
        $compile_only ? 'Preparando el contexto que recibirá el compilador.' : 'Preparando RAG, memoria y prompt final.',
        [
            'query' => $text,
            'session_id' => $session_id,
            'project_id' => $projectId ?: null,
            'compile_only' => $compile_only,
            'compilation_id' => $compilation_id ?: null,
            'request_id' => $requestFlowId !== '' ? $requestFlowId : null,
            'compiler_fallback_requested' => $compiler_fallback_requested,
            'use_project_rag' => !empty($memoryRoute['use_project_rag']) && aiAgentActive('embedding_main', false),
            'attachment_mode' => $attachmentMode,
            'use_question_memory' => $use_question_memory && (!empty($memoryRoute['use_question_memory']) || !empty($memoryRoute['question_memory_fallback'])),
            'question_memory_scope' => $question_memory_scope,
            'question_memory_scope_requested' => $requested_question_memory_scope,
            'memory_scope' => $memoryScope->toArray(),
            'question_memory_max_candidates' => $question_memory_max_candidates,
            'question_memory_window_lines' => $question_memory_window_lines,
            'chat_model' => $model_id,
            'compiler_model' => aiAgentModel('prompt_compiler', ''),
            'embedding_model' => aiAgentModel('embedding_main', ''),
            'pipeline_features_configured' => $pipelineConfigured,
            'pipeline_features_effective' => $pipelineEffective,
        ],
        $compile_only ? aiAgentModel('prompt_compiler', '') : $model_id
    );

    if ($compiler_fallback_requested) {
        activityEmit(
            $db_connection, $activityTraceId, $session_id, $user_id, 'respond',
            'compiler_fallback_continue', 'info', 'Continuando con prompt original',
            'Fase 6 omitió el compilador y reanudó la respuesta con el texto original.',
            ['request_id' => $requestFlowId !== '' ? $requestFlowId : null, 'fallback' => 'original_prompt']
        );
    }

    activityEmit(
        $db_connection, $activityTraceId, $session_id, $user_id, $activityPhase,
        'memory_router_decision', 'completed', 'Memory Context Router',
        !empty($pipelineEffective['memory_router'])
            ? ('Intención: ' . (string)($memoryRoute['intent'] ?? 'general') . ' · modo: ' . (string)($memoryRoute['mode'] ?? 'none'))
            : 'Router desactivado por Feature Flag; se usa una ruta neutra.',
        $memoryRoute
    );

    activityEmit(
        $db_connection, $activityTraceId, $session_id, $user_id, $activityPhase,
        'pipeline_features_resolved', 'completed', 'Feature Flags · Fase 5',
        'Switches configurados y estado efectivo resueltos para esta petición.',
        [
            'configured' => $pipelineConfigured,
            'effective' => $pipelineEffective,
            'dependencies' => [
                'embedding_main_available' => $embeddingAvailable,
                'prompt_compiler_agent_active' => aiAgentActive('prompt_compiler', false),
            ],
        ]
    );
}
// ✅ NUEVO: Si el frontend envió use_rag, sobreescribir el modo de la sesión
if (isset($_POST['use_rag'])) {
    $attachmentMode = $use_rag ? 'rag' : 'always';
}

// Fase 2: la memoria procedural se recupera dentro de ContextBuilder.

// ========================================================================
// ✅ CORRECCIÓN CRÍTICA: Recuperar user_msg_id si estamos en Fase 2
// Si el usuario aprobó un prompt compilado (Fase 2), el mensaje de usuario
// ya se guardó en la Fase 1. Necesitamos ese ID para SessionContextBlocks.
// ========================================================================
$saved_user_text_id = null;
if ($compilation_id > 0) {
    $stmtCompMsg = $db_connection->prepare("SELECT user_msg_id FROM PromptCompilations WHERE id_ = ? LIMIT 1");
    if ($stmtCompMsg) {
        $stmtCompMsg->bind_param('i', $compilation_id);
        $stmtCompMsg->execute();
        $resCompMsg = $stmtCompMsg->get_result();
        if ($rowCompMsg = $resCompMsg->fetch_assoc()) {
            $saved_user_text_id = (int)$rowCompMsg['user_msg_id'];
        }
        $stmtCompMsg->close();
    }
}

// Fase 6: si el navegador agotó los 5 s del compilador, la segunda petición
// reutiliza el mensaje original mediante request_id. Así no duplica ChatMessages.
if (!$saved_user_text_id && $compiler_fallback_requested && $requestFlowId !== '') {
    $stmtFallbackMsg = $db_connection->prepare("
        SELECT id_
        FROM ChatMessages
        WHERE session_id_ = ?
          AND user_id_ = ?
          AND role = 'user'
          AND content = ?
          AND meta IS NOT NULL
          AND JSON_VALID(meta)
          AND JSON_UNQUOTE(JSON_EXTRACT(meta, '$.request_id')) = ?
        ORDER BY id_ DESC
        LIMIT 1
    ");
    if ($stmtFallbackMsg) {
        $stmtFallbackMsg->bind_param('iiss', $session_id, $user_id, $text, $requestFlowId);
        $stmtFallbackMsg->execute();
        $resFallbackMsg = $stmtFallbackMsg->get_result();
        if ($rowFallbackMsg = $resFallbackMsg->fetch_assoc()) {
            $saved_user_text_id = (int)$rowFallbackMsg['id_'];
        }
        $stmtFallbackMsg->close();
    }
}

// ===== Guardar mensaje de usuario (texto) =====
// $saved_user_text_id = null; // Ya lo inicializamos arriba
$file_ids = [];


$contextTexts = [];
$ocrItems     = [];

// ✅ CORRECCIÓN: Solo guardar el mensaje de usuario en la Fase 1 (compile_only) 
// o si es un chat normal sin compilación ($compilation_id === 0).
// Esto evita que se guarde DUPLICADO en la Fase 2 (respuesta final).
if ($text !== '' && !$saved_user_text_id && ($compile_only || $compilation_id === 0)) {
    $idM = next_id($db_connection, 'ChatMessages', 'id_');
    $role_user   = 'user';
    $ctype       = 'text';
    $content     = $text;
    $s3_key      = null; $mime = null; $size_bytes = null; $thumb_key=null; $duration_ms=null;
    $model_msg   = null; $stop_reason=null; $prompt_tok=null; $compl_tok=null; $latency_ms=null;
    $messageMeta = [];
    if ($requestFlowId !== '') $messageMeta['request_id'] = $requestFlowId;
    if ($activityTraceId !== '') $messageMeta['trace_id'] = $activityTraceId;
    $meta = $messageMeta ? json_encode($messageMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    // NUEVO: Campos de metacognición
    $is_primordial = 0;
    $phase = 'respond';
    $parent_msg_id = null;
    
    $sqlI = "INSERT INTO ChatMessages (
        id_, session_id_, user_id_, role, content_type, content,
        s3_key, mime_type, size_bytes, thumb_s3_key, duration_ms,
        model_id, stop_reason, prompt_tokens, completion_tokens, latency_ms, meta,
        is_primordial, phase, parent_msg_id
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    
    $stmtI = $db_connection->prepare($sqlI);
    if(!$stmtI) jexit(['ok'=>false,'error'=>'Error preparando INSERT texto: '.$db_connection->error],500);
    
    $types = "iiisssssisissiiisisi";
    $stmtI->bind_param($types,
        $idM, $session_id, $user_id, $role_user, $ctype, $content,
        $s3_key, $mime, $size_bytes, $thumb_key, $duration_ms, $model_msg, $stop_reason, $prompt_tok, $compl_tok, $latency_ms, $meta,
        $is_primordial, $phase, $parent_msg_id
    );
    
    if(!$stmtI->execute()){ $e=$stmtI->error; $stmtI->close(); jexit(['ok'=>false,'error'=>'Error insertando texto: '.$e],500); }
    $stmtI->close();
    $saved_user_text_id = $idM;
}


// ===== Adjuntos ===== 
if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
  $s3 = null; $bucket = null;
  if ($have_s3 && class_exists('S3Manager') && class_exists('Config')) {
    try {
      $manager = new S3Manager();
      $bucket = $manager->getBucket();
      $s3 = Config::getS3();
    } catch(Throwable $e){
      $errors[]='S3 init: '.$e->getMessage();
    }
  }

  $count = count($_FILES['files']['name']);
  for ($i=0; $i<$count; $i++) {
    if (!isset($_FILES['files']['error'][$i]) || $_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
      $errors[]='Archivo '.$i.' no recibido'; continue;
    }

    $tmp  = $_FILES['files']['tmp_name'][$i];
    $name = safe_filename($_FILES['files']['name'][$i]);
    $mime = (string)($_FILES['files']['type'][$i] ?? '');
    $size = (int)($_FILES['files']['size'][$i] ?? 0);
    $ctype = detect_content_type_from_mime($mime);
    $s3_key = null; $thumb_key = null;

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ((strpos($mime,'text/')===0) || in_array($ext, ['txt','md','csv','json','xml','log'])) {
      $raw = @file_get_contents($tmp);
      if ($raw !== false) {
        $txt = @mb_convert_encoding($raw, 'UTF-8', 'auto');
        $txt = trim(mb_substr($txt ?? '', 0, 50000));
        if ($txt !== '') $contextTexts[] = "Archivo $name:\n".$txt;
      }
    }

    if ($s3 && $bucket) {
      $prefix = 'Chat/Uploads/'.$session_id.'/';
      if (defined('Config::RUTA_RAIZ') && Config::RUTA_RAIZ) $prefix = rtrim(Config::RUTA_RAIZ,'/').'/'.$prefix;

      $key = $prefix . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $name;
      try {
        $s3->putObject([
          'Bucket'      => $bucket,
          'Key'         => $key,
          'SourceFile'  => $tmp,
          'ContentType' => ($mime ?: 'application/octet-stream'),
          'ACL'         => 'private'
        ]);
        $s3_key = $key;
      } catch (Throwable $e) {
        $errors[] = 'S3 putObject: '.$e->getMessage();
      }

      if ($s3_key && strpos($mime,'image/') === 0) $ocrItems[] = ['name'=>$name,'s3_key'=>$s3_key];
    }

    $idF = next_id($db_connection, 'ChatMessages', 'id_');
    $role_user   = 'user';
    $content     = $name;
    $duration_ms = null; $model_msg = null; $stop_reason=null;
    $prompt_tok = null; $compl_tok=null; $latency_ms=null; $meta=null;
    
    // NUEVO: Campos de metacognición para archivos
    $is_primordial = 0;
    $phase = 'respond';
    $parent_msg_id = null;

    $sqlF = "INSERT INTO ChatMessages (
      id_, session_id_, user_id_, role, content_type, content,
      s3_key, mime_type, size_bytes, thumb_s3_key, duration_ms,
      model_id, stop_reason, prompt_tokens, completion_tokens, latency_ms, meta,
      is_primordial, phase, parent_msg_id
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $stmtF = $db_connection->prepare($sqlF);
    if(!$stmtF) jexit(['ok'=>false,'error'=>'Error preparando INSERT file: '.$db_connection->error],500);
    
    $typesF = "iiisssssisissiiisisi";
    $stmtF->bind_param($typesF, 
      $idF, $session_id, $user_id, $role_user, $ctype, $content,
      $s3_key, $mime, $size, $thumb_key, $duration_ms, $model_msg, $stop_reason, $prompt_tok, $compl_tok, $latency_ms, $meta,
      $is_primordial, $phase, $parent_msg_id
    );
      
    if(!$stmtF->execute()){ $e=$stmtF->error; $stmtF->close(); jexit(['ok'=>false,'error'=>'Error insertando file: '.$e],500); }
    $stmtF->close();
    $file_ids[] = $idF;
  }
}

// ===== OCR Textract =====
if (!empty($ocrItems) && $have_s3) {
  try {
    if (!$aws_sdk_loaded || !class_exists('Aws\\Textract\\TextractClient')) {
      throw new RuntimeException('AWS Textract no disponible (vendor/autoload.php).');
    }

    $textract = Config::getTextract([
      'http'        => ['connect_timeout' => 15, 'timeout' => 120],
    ]);

    $bucket = null;
    if (class_exists('S3Manager')) {
      try { $bucket = (new S3Manager())->getBucket(); }
      catch(Throwable $e){ $errors[]='S3 bucket: '.$e->getMessage(); }
    }

    foreach ($ocrItems as $it) {
      try {
        if (empty($bucket)) continue;
        $res = $textract->detectDocumentText([
          'Document' => [ 'S3Object' => ['Bucket' => $bucket, 'Name' => $it['s3_key']] ]
        ]);
        $lines = [];
        foreach (($res['Blocks'] ?? []) as $b) {
          if (($b['BlockType'] ?? '') === 'LINE' && !empty($b['Text'])) $lines[] = $b['Text'];
        }
        $ocr = trim(implode("\n", $lines));
        if ($ocr !== '') {
          $ocr = mb_substr($ocr, 0, 50000);
          $contextTexts[] = "Texto OCR de {$it['name']}:\n".$ocr;
        }
      } catch (Throwable $e) {
        $errors[] = 'Textract '.$it['name'].': '.$e->getMessage();
      }
    }
  } catch (Throwable $e) {
    $errors[] = 'Textract init: '.$e->getMessage();
  }
}

if ($activityTraceId !== '') {
    activityEmit(
        $db_connection, $activityTraceId, $session_id, $user_id, $activityPhase,
        'input_ready', 'completed', 'Entrada preparada',
        count($file_ids) > 0
            ? count($file_ids) . ' archivo(s) recibido(s) y contexto extraído cuando correspondía.'
            : 'Mensaje de usuario disponible para el pipeline.',
        [
            'user_message_id' => $saved_user_text_id,
            'user_text' => $text,
            'file_message_ids' => $file_ids,
            'ocr_items' => array_map(static function(array $item): array {
                return [
                    'name' => $item['name'] ?? null,
                    's3_key' => $item['s3_key'] ?? null,
                ];
            }, $ocrItems),
            'extracted_contexts' => $contextTexts,
        ]
    );
}


// ===== El contexto se recupera de forma dirigida en la sección final =====
// MemoryContextRouter decide qué fuentes consultar; evitamos cargar aquí todo
// ProjectContext y luego sobrescribir $systemPrompt.
$systemPrompt = '';


// ===== Auto-router mínimo =====
$action = null;
$router = ['improved_prompt'=>$text, 'decided'=>'text'];
if ($auto && $text !== '') {
    $t = mb_strtolower($text,'UTF-8');
    if (preg_match('/\b(img|image|imagen|dibuja|ilustra|pintar)\b/u',$t)) { $action='gen_image'; $router['decided']='image'; }
    elseif (preg_match('/\b(video|clip|animaci[oó]n|reel)\b/u',$t)) { $action='gen_video'; $router['decided']='video'; }
}

// ===== Llamada a Bedrock con RAG, Instrucciones y TOOL USE ===== 
$reply_text = null; 
$assistant_id = null; 
$memoryBackfillPublic = null;
$usage = ['prompt_tokens'=>0, 'completion_tokens'=>0, 'total_tokens'=>0];
$questionMemory = [
  'context' => '',
  'used' => false,
  'question_ids' => [],
  'block_ids' => [],
  'fragments' => 0,
  'candidates' => 0,
  'reindex_queued' => 0,
  'scope' => $question_memory_scope,
];

if ( ($text !== '' || !empty($contextTexts)) && $action === null) {
  try {
    if (!$aws_sdk_loaded || !class_exists('Aws\\BedrockRuntime\\BedrockRuntimeClient')) {
      throw new RuntimeException('AWS SDK no cargado (vendor/autoload.php).');
    }

    $bedrock = Config::getBedrockRuntime([
      'http'        => ['connect_timeout' => 20, 'timeout' => 240],
    ]);

    // Fase 2: un único Builder consume la decisión del Router.
    $memoryRouteObject = new MemoryRoute($memoryRoute);
    $contextBuilder = new ContextBuilder($db_connection, $bedrock);
    $contextBundle = null;


    // ---------------------------------------------------------
    // 1. OBTENER INSTRUCCIONES Y CONTEXTO RAG DEL PROYECTO
    // ---------------------------------------------------------
    $projectInstructions = '';
    $primordialRules = ''; // ✅ Variable para reglas primordiales cross-session
    
    if ($projectId) {
        // A. Instrucciones generales del proyecto
        $stmtProj = $db_connection->prepare("SELECT meta FROM Projects WHERE id_ = ? AND user_id_ = ? AND status <> 'deleted'");
        $stmtProj->bind_param('ii', $projectId, $user_id);
        $stmtProj->execute();
        $resProj = $stmtProj->get_result();
        if ($rowProj = $resProj->fetch_assoc()) {
            if (!empty($rowProj['meta'])) {
                $meta = json_decode($rowProj['meta'], true);
                if (isset($meta['instructions']) && !empty(trim($meta['instructions']))) {
                    $projectInstructions = trim((string)$meta['instructions']);
                }
            }
        }
        $stmtProj->close();

        // ✅ B. OBTENER REGLAS PRIMORDIALES DE CUALQUIER SESIÓN DEL PROYECTO (Metacognición Cross-Session)
        $sqlPrimordial = "SELECT cm.content, cm.created_at 
                  FROM ChatMessages cm 
                  JOIN ChatSessions cs ON cm.session_id_ = cs.id_ 
                  WHERE cs.project_id_ = ? 
                    AND cs.user_id_ = ?
                    AND cm.user_id_ = ?
                    AND cm.is_primordial = 1 
                    AND cm.role = 'assistant'
                    AND cs.status != 'archived'
                  ORDER BY cm.created_at ASC";
        $stmtPrimordial = $db_connection->prepare($sqlPrimordial);
        $stmtPrimordial->bind_param('iii', $projectId, $user_id, $user_id);
        $stmtPrimordial->execute();
        $resPrimordial = $stmtPrimordial->get_result();
        $rules = [];
        $primordialItemTemplate = aiAgentInstruction(
            'chat_main_primordial_rule_item_template',
            '- [{{date}}] {{content}}'
        );
        while ($row = $resPrimordial->fetch_assoc()) {
            $rules[] = aiRenderTemplate($primordialItemTemplate, [
                'date' => date('Y-m-d H:i', strtotime((string)$row['created_at'])),
                'content' => (string)$row['content'],
            ]);
        }
        $stmtPrimordial->close();
        
        if (!empty($rules)) {
            $primordialRules = aiRenderTemplate(
                aiAgentInstruction(
                    'chat_main_primordial_rules_template',
                    "[REGLAS PRIMORDIALES DEL PROYECTO (VERDAD ABSOLUTA)]\n{{primordial_rules}}"
                ),
                ['primordial_rules' => implode("\n", $rules)]
            );
        }

        if ($activityTraceId !== '') {
            activityEmit(
                $db_connection, $activityTraceId, $session_id, $user_id, $activityPhase,
                'project_context_loaded', 'completed', 'Contexto del proyecto cargado',
                ($projectInstructions !== '' ? 'Instrucciones del proyecto disponibles. ' : 'Sin instrucciones de proyecto. ') . count($rules) . ' regla(s) primordial(es).',
                [
                    'project_id' => $projectId,
                    'project_instructions' => $projectInstructions,
                    'primordial_rule_count' => count($rules),
                    'primordial_rules_block' => $primordialRules,
                ]
            );
        }

        // C. El RAG del proyecto ya no se construye aquí.
        // Fase 2: ContextBuilder comparte un único embedding entre Project RAG,
        // adjuntos y memoria semántica, y recupera cada fuente según MemoryRoute.
    }

    


// ---------------------------------------------------------
// 1.5. COMPILACIÓN DEL PROMPT - configuración dinámica
// ---------------------------------------------------------
$compiled_prompt = $text;
$compiler_model = aiAgentModel('prompt_compiler', '');
$compilerEnabled = !$compiler_fallback_requested
    && !empty($pipelineEffective['prompt_compiler'])
    && aiAgentActive('prompt_compiler', false)
    && $compiler_model !== '';
$compilerFallback = false;
$compilerFallbackReason = '';
$compilerFallbackError = '';
// El backend corta antes de los 5 s del navegador para dejar margen de red/JSON.
$compilerTimeoutSeconds = (float)aiAgentExtra('prompt_compiler', 'timeout_seconds', 4.25);
$compilerTimeoutSeconds = max(1.0, min(4.5, $compilerTimeoutSeconds));
$compilerConnectTimeoutSeconds = (float)aiAgentExtra('prompt_compiler', 'connect_timeout_seconds', 1.5);
$compilerConnectTimeoutSeconds = max(0.5, min($compilerTimeoutSeconds, $compilerConnectTimeoutSeconds));

// Si el frontend ya envió un prompt aprobado (Fase 2), no recompilar.
if ($compilation_id > 0 && isset($_POST['compiled_prompt']) && trim($_POST['compiled_prompt']) !== '') {
    $compiled_prompt = trim($_POST['compiled_prompt']);
} elseif (trim($text) !== '' && $compilerEnabled) {
    try {
        $compilerSystemPrompt = aiAgentInstruction(
            'prompt_compiler',
            'Eres un Ingeniero de Prompts experto. Transforma la entrada del usuario en una instrucción clara y útil.'
        );

        // Contexto del proyecto desde text_block.
        $compilerContext = '';
        if ($projectInstructions !== '') {
            $compilerContext .= aiRenderTemplate(
                aiAgentInstruction('prompt_compiler_context_project_template', "Contexto del proyecto: {{project_instructions}}"),
                ['project_instructions' => $projectInstructions]
            ) . "\n";
        } else {
            $compilerContext .= "Contexto del proyecto: " . aiAgentInstruction('prompt_compiler_context_project_none', 'Ninguno') . "\n";
        }

        if ($primordialRules !== '') {
            $compilerContext .= "\n" . $primordialRules . "\n";
        }

        // Fase 2: el mismo ContextBuilder prepara el contexto del compilador.
        // En stage=compile omite deliberadamente Project RAG, adjuntos y Q&A
        // semántico: no se generan embeddings para mejorar el prompt.
        $compilerBuildStartedAt = microtime(true);
        $compilerContextBundle = $contextBuilder->build($memoryRouteObject, [
            'stage' => 'compile',
            'user_id' => $user_id,
            'session_id' => $session_id,
            'project_id' => $projectId,
            'memory_scope' => $memoryScope,
            'query_text' => $text,
            'attachment_mode' => $attachmentMode,
            'question_memory_enabled' => $use_question_memory,
            'question_memory_scope' => $question_memory_scope,
            'question_memory_max_candidates' => $question_memory_max_candidates,
            'question_memory_window_lines' => $question_memory_window_lines,
            'log_message_id' => getValidMessageId($db_connection, $saved_user_text_id, $session_id),
            'pipeline_features' => $pipelineEffective,
        ]);
        $compilerBuilderContext = $compilerContextBundle->block('compiler_context');
        if ($compilerBuilderContext !== '') {
            $compilerContext .= "\n" . $compilerBuilderContext . "\n";
        }

        if ($activityTraceId !== '') {
            activityEmit(
                $db_connection, $activityTraceId, $session_id, $user_id, 'compile',
                'context_builder_compile', 'completed', 'Context Builder + Ranking · compilador',
                'Se recuperó únicamente contexto tipado barato; las fuentes semánticas se reservaron para respond.',
                [
                    'query' => $text,
                    'memory_route' => $memoryRoute,
                    'context_builder' => $compilerContextBundle->toActivityArray(),
                    'compiler_context' => trim($compilerContext),
                ],
                $compiler_model,
                activityDurationMs($compilerBuildStartedAt)
            );
        }

        $compilerUserPrompt = aiRenderTemplate(
            aiAgentUserTemplate(
                'prompt_compiler',
                "{{compiler_context}}\n\nEntrada del usuario: \"{{user_text}}\"\nTarea: Transforma esta entrada en una instrucción experta."
            ),
            [
                'compiler_context' => trim($compilerContext),
                'user_text' => $text,
            ]
        );

        $compilerInferConfig = [
            'maxTokens'   => $compile_max_tokens,
            'temperature' => $compile_temperature,
            'topP'        => $compile_top_p,
        ];
        $compilerSeed = (int)aiAgentValue('prompt_compiler', 'seed', $seed);
        if ($compilerSeed > 0) {
            $compilerInferConfig['seed'] = $compilerSeed;
        }

        $compilerParams = [
            'modelId' => $compiler_model,
            'messages' => [['role' => 'user', 'content' => [['text' => $compilerUserPrompt]]]],
            'system' => [['text' => $compilerSystemPrompt]],
            'inferenceConfig' => $compilerInferConfig,
        ];

        $compilerStartedAt = microtime(true);
        if ($activityTraceId !== '') {
            activityEmit(
                $db_connection, $activityTraceId, $session_id, $user_id, 'compile',
                'compiler_started', 'started', 'Compilador de prompt',
                'Enviando el prompt real al modelo configurado.',
                [
                    'model' => $compiler_model,
                    'system_prompt' => $compilerSystemPrompt,
                    'user_prompt' => $compilerUserPrompt,
                    'inference_config' => $compilerInferConfig,
                ],
                $compiler_model
            );
        }
        // Fase 6: el compilador tiene su propio cliente, sin reintentos y con
        // timeout corto. El cliente principal conserva sus timeouts largos.
        $compilerBedrock = Config::getBedrockRuntime([
            'retries' => 0,
            'http' => [
                'connect_timeout' => $compilerConnectTimeoutSeconds,
                'timeout' => $compilerTimeoutSeconds,
            ],
        ]);
        $compilerRes = $compilerBedrock->converse($compilerParams);
        unset($compilerBedrock);
        $compilerBlocks = $compilerRes['output']['message']['content'] ?? [];
        $compilerUsage = $compilerRes['usage'] ?? [];
        
        $compilerInput = (int)($compilerUsage['inputTokens'] ?? 0);
        $compilerOutput = (int)($compilerUsage['outputTokens'] ?? 0);

        $compilerCandidate = '';
        foreach ($compilerBlocks as $block) {
            if (!isset($block['text'])) continue;
            $candidate = trim((string)$block['text']);
            $candidate = preg_replace('/^```(?:json|text)?\s*/i', '', $candidate);
            $candidate = preg_replace('/\s*```$/i', '', $candidate);
            $candidate = trim((string)$candidate, "\"' \n\r");
            if ($candidate === '') continue;
            $compilerCandidate = $candidate;
            break;
        }

        if ($compilerCandidate === '') {
            throw new RuntimeException('PROMPT_COMPILER_EMPTY_OUTPUT');
        }

        similar_text($text, $compilerCandidate, $percent);
        if ($percent > 90 || trim($compilerCandidate) === trim($text)) {
            // Una salida prácticamente idéntica no justifica retrasar al usuario.
            $compilerFallback = true;
            $compilerFallbackReason = 'no_improvement';
            $compiled_prompt = $text;
        } else {
            $compiled_prompt = $compilerCandidate;
        }

        $compilerDurationMs = isset($compilerStartedAt) ? activityDurationMs($compilerStartedAt) : 0;
        if ($activityTraceId !== '' && !$compilerFallback) {
            activityEmit(
                $db_connection, $activityTraceId, $session_id, $user_id, 'compile',
                'compiler_completed', 'completed', 'Prompt compilado',
                'El compilador devolvió la instrucción optimizada.',
                [
                    'model' => $compiler_model,
                    'compiled_prompt' => $compiled_prompt,
                    'input_tokens' => $compilerInput,
                    'output_tokens' => $compilerOutput,
                    'stop_reason' => $compilerRes['stopReason'] ?? null,
                ],
                $compiler_model,
                $compilerDurationMs
            );
        }

        // ========================================================================
        // PASO 2: REGISTRAR EN BASE DE DATOS (Solo en Fase 1)
        // ========================================================================
        if (($compilerInput > 0 || $compilerOutput > 0) && !$compilerFallback) {
            try {
                $compiler_msg_id = next_id($db_connection, 'ChatMessages', 'id_');
                $session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
                $role_compiler = 'system'; 
                $ctypeA = 'text'; 
                $contentA = $compiled_prompt; 
                $s3_key = null; $mime = null; $size_bytes = null; $thumb_key = null; $duration_ms = null;
                $model_msg = $compiler_model; $stop_reason = null; 
                $prompt_tok = $compilerInput; $compl_tok = $compilerOutput; 
                $latency_ms = null; $meta = $activityTraceId !== '' ? json_encode(['trace_id'=>$activityTraceId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null; $is_primordial = 0; $phase = 'compile'; 
                $parent_msg_id = $saved_user_text_id ?: (isset($file_ids[0]) ? $file_ids[0] : null);

                $sqlA = "INSERT INTO ChatMessages (
                    id_, session_id_, user_id_, role, content_type, content,
                    s3_key, mime_type, size_bytes, thumb_s3_key, duration_ms,
                    model_id, stop_reason, prompt_tokens, completion_tokens, latency_ms, meta,
                    is_primordial, phase, parent_msg_id
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
                
                $stmtA = $db_connection->prepare($sqlA);
                if ($stmtA) {
                    $stmtA->bind_param("iiisssssisissiiisisi", 
                        $compiler_msg_id, $session_id, $user_id, $role_compiler, $ctypeA, $contentA,
                        $s3_key, $mime, $size_bytes, $thumb_key, $duration_ms, $model_msg, $stop_reason, 
                        $prompt_tok, $compl_tok, $latency_ms, $meta, $is_primordial, $phase, $parent_msg_id
                    );
                    $stmtA->execute();
                    $stmtA->close();
                }
                
// ✅ CORREGIDO: Validar message_id_ antes de insertar
$tcId = next_id($db_connection, 'TokenUsage', 'id_');
$tcPhase = (string)aiAgentValue('prompt_compiler', 'token_usage_phase', 'compile'); $tcModel = $compiler_model;
$tcCost = calculateCost($compiler_model, $compilerInput, $compilerOutput);
$tcDuration = isset($compilerDurationMs) ? $compilerDurationMs : 0;
// ✅ VALIDAR: ¿El mensaje del compilador realmente existe en ChatMessages?
$tcMsgId = getValidMessageId($db_connection, $compiler_msg_id, $session_id);
$sqlTC = "INSERT INTO TokenUsage (id_, session_id_, message_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
$stmtTC = $db_connection->prepare($sqlTC);
if ($stmtTC) {
    // ✅ CORREGIDO: Si $tcMsgId es null, bind_param lo maneja como SQL NULL
    $stmtTC->bind_param("iiissiddi", $tcId, $session_id, $tcMsgId, $tcPhase, $tcModel, $compilerInput, $compilerOutput, $tcCost, $tcDuration);
    $stmtTC->execute();
    $stmtTC->close();
}
            } catch (Throwable $e) { 
                $errors[] = 'TokenUsage (compile): ' . $e->getMessage(); 
            }
        } elseif (($compilerInput > 0 || $compilerOutput > 0) && $compilerFallback) {
            // El modelo sí consumió tokens, pero su salida no fue útil. Registrar coste
            // sin crear un falso mensaje de sistema "optimizado".
            logTokenUsage(
                $db_connection,
                $session_id,
                getValidMessageId($db_connection, $saved_user_text_id, $session_id),
                (string)aiAgentValue('prompt_compiler', 'token_usage_phase', 'compile'),
                $compiler_model,
                $compilerInput,
                $compilerOutput,
                $compilerDurationMs
            );
        }
    } catch (Throwable $e) {
        $compilerFallback = true;
        $compiled_prompt = $text;
        $compilerFallbackError = $e->getMessage();
        $durationForFallback = isset($compilerStartedAt) ? activityDurationMs($compilerStartedAt) : 0;
        $looksLikeTimeout = preg_match('/timeout|timed out|curl error 28|operation timed/i', $compilerFallbackError) === 1
            || $durationForFallback >= (int)floor($compilerTimeoutSeconds * 900);
        if ($looksLikeTimeout) {
            $compilerFallbackReason = 'timeout';
        } elseif (strpos($compilerFallbackError, 'PROMPT_COMPILER_EMPTY_OUTPUT') !== false) {
            $compilerFallbackReason = 'empty';
        } else {
            $compilerFallbackReason = 'error';
        }
        $errors[] = 'Compilador omitido (' . $compilerFallbackReason . '): ' . $compilerFallbackError;
    }
}

// Fase 6: si el compilador falla, se agota o no mejora realmente el texto,
// NO fabricamos un prompt alternativo. Devolvemos inmediatamente control al
// navegador para que continúe con la pregunta ORIGINAL en una petición respond.
if ($compile_only && $compilerFallback) {
    if ($activityTraceId !== '') {
        $fallbackTitle = $compilerFallbackReason === 'timeout'
            ? 'Prompt Compiler · TIMEOUT'
            : ($compilerFallbackReason === 'empty' ? 'Prompt Compiler · EMPTY' : 'Prompt Compiler · FALLBACK');
        activityEmit(
            $db_connection, $activityTraceId, $session_id, $user_id, 'compile',
            'compiler_fallback_original', 'info', $fallbackTitle,
            'Se omite la mejora y se continuará con la pregunta original del usuario.',
            [
                'reason' => $compilerFallbackReason,
                'error' => $compilerFallbackError !== '' ? $compilerFallbackError : null,
                'fallback' => 'original_prompt',
                'timeout_seconds' => $compilerTimeoutSeconds,
                'request_id' => $requestFlowId !== '' ? $requestFlowId : null,
            ],
            $compiler_model,
            isset($compilerStartedAt) ? activityDurationMs($compilerStartedAt) : null
        );
    }

    jexit([
        'ok' => true,
        'phase' => 'compile_fallback',
        'fallback_to_original' => true,
        'fallback_reason' => $compilerFallbackReason,
        'original_prompt' => $text,
        'user_message_id' => $saved_user_text_id,
        'request_id' => $requestFlowId,
        'memory_router' => $memoryRoute,
        'context_builder' => isset($compilerContextBundle) ? $compilerContextBundle->toPublicArray() : null,
        'pipeline_features' => ['configured'=>$pipelineConfigured, 'effective'=>$pipelineEffective],
        'ai_runtime' => aiRuntimeSnapshot(),
    ]);
}

// ---------------------------------------------------------
// 1.6. MODO COMPILE_ONLY: Devolver solo el prompt compilado
// ---------------------------------------------------------
if ($compile_only) {
    $compilationId = next_id($db_connection, 'PromptCompilations', 'id_');
    $usedContextIds = json_encode([]);
    $usedCodeRefs = json_encode([]);
    $notesForUser = null;
    $status = 'pending';
    $userMsgId = $saved_user_text_id ?: 0;
    
    $sqlComp = "INSERT INTO PromptCompilations (
        id_, session_id_, user_msg_id, compiled_prompt, used_context_ids, 
        used_code_refs, notes_for_user, status
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmtComp = $db_connection->prepare($sqlComp);
    
    if ($stmtComp) {
        $stmtComp->bind_param("iiisssss", $compilationId, $session_id, $userMsgId, $compiled_prompt, $usedContextIds, $usedCodeRefs, $notesForUser, $status);
        if (!$stmtComp->execute()) error_log('Error insertando PromptCompilation: ' . $stmtComp->error);
        $stmtComp->close();
    }
    
    if ($activityTraceId !== '') {
        activityEmit(
            $db_connection, $activityTraceId, $session_id, $user_id, 'compile',
            'approval_waiting', 'waiting', 'Prompt mejorado listo · ventana de 5 segundos',
            'El frontend permitirá cancelar la mejora durante 5 segundos; si no se cancela, continuará automáticamente con el prompt mejorado.',
            ['compilation_id'=>$compilationId, 'compiled_prompt'=>$compiled_prompt],
            $compiler_model
        );
    }

    jexit([
        'ok' => true,
        'phase' => 'compile_only',
        'compilation_id' => $compilationId,
        'compiled_prompt' => $compiled_prompt,
        'usage' => $usage,
        'memory_router' => $memoryRoute,
        'context_builder' => isset($compilerContextBundle) ? $compilerContextBundle->toPublicArray() : null,
        'pipeline_features' => ['configured'=>$pipelineConfigured, 'effective'=>$pipelineEffective],
        'ai_runtime' => aiRuntimeSnapshot(),
    ]);
}

// Fase 8.3: adaptación pasiva del turno síncrono al dominio Tasks. El bridge
// encapsula toda la persistencia; si el subsistema nuevo falla, el chat continúa.
$chatTaskBridge = null;
$chatTaskContext = null;
$chatPipelineFailure = null;
if (!empty($pipelineEffective['task_orchestrator']) && $saved_user_text_id) {
    try {
        require_once __DIR__ . '/includes/Tasks/bootstrap.php';
        if ($activityTraceId === '') {
            // Este pasa a ser el trace real del pipeline, no un trace paralelo.
            $activityTraceId = TaskPublicId::generate();
        }
        $taskRequestId = $requestFlowId !== '' ? $requestFlowId : ('message-' . (int)$saved_user_text_id);
        $taskRepository = new TaskRepository($db_connection);
        $chatTaskBridge = new ChatTaskBridge(new TaskOrchestrator(
            $db_connection,
            $taskRepository,
            new TaskEventRepository($db_connection)
        ));
        $chatTaskContext = $chatTaskBridge->beginTurn(
            $user_id,
            $session_id,
            $projectId > 0 ? $projectId : null,
            (int)$saved_user_text_id,
            $taskRequestId,
            $text,
            $activityTraceId,
            $model_id
        );
    } catch (Throwable $taskError) {
        error_log('CHAT_TASK_BRIDGE_BEGIN: ' . ChatTaskBridge::sanitizeError($taskError));
        $chatTaskBridge = null;
        $chatTaskContext = null;
    }
}

// ---------------------------------------------------------
// 1.7. MODO RESPUESTA FINAL: Usar prompt compilado aprobado
// ---------------------------------------------------------
if ($compilation_id > 0 && isset($_POST['compiled_prompt']) && trim($_POST['compiled_prompt']) !== '') {
    $compiled_prompt_input = trim($_POST['compiled_prompt']);
    $originalCompiledPrompt = '';
    $stmtOriginal = $db_connection->prepare("SELECT compiled_prompt FROM PromptCompilations WHERE id_=? AND session_id_=? LIMIT 1");
    if ($stmtOriginal) {
        $stmtOriginal->bind_param('ii', $compilation_id, $session_id);
        $stmtOriginal->execute();
        $rowOriginal = $stmtOriginal->get_result()->fetch_assoc();
        $stmtOriginal->close();
        $originalCompiledPrompt = (string)($rowOriginal['compiled_prompt'] ?? '');
    }
    $promptWasEdited = ($originalCompiledPrompt !== '' && trim($originalCompiledPrompt) !== trim($compiled_prompt_input)) ? 1 : 0;
    
    // 1. Actualizar el estado de la compilación con el dato real de edición.
    $stmtUpd = $db_connection->prepare("UPDATE PromptCompilations SET status = 'approved', was_edited_by_user = ? WHERE id_ = ? AND session_id_ = ?");
    if ($stmtUpd) {
        $stmtUpd->bind_param("iii", $promptWasEdited, $compilation_id, $session_id);
        $stmtUpd->execute();
        $stmtUpd->close();
    }
    
    $compiled_prompt = $compiled_prompt_input;
    if ($activityTraceId !== '') {
        activityEmit(
            $db_connection, $activityTraceId, $session_id, $user_id, 'respond',
            'prompt_approved', 'completed', 'Prompt aprobado',
            $promptWasEdited ? 'El usuario modificó el prompt compilado antes de continuar.' : 'El usuario aprobó el prompt sin cambios.',
            [
                'compilation_id' => $compilation_id,
                'original_compiled_prompt' => $originalCompiledPrompt,
                'approved_prompt' => $compiled_prompt_input,
                'was_edited_by_user' => (bool)$promptWasEdited,
            ],
            aiAgentModel('prompt_compiler','')
        );
    }

    // 2. ✅ BLINDAJE TOTAL: Verificar si YA existe un mensaje de sistema vinculado a este mensaje de usuario
    $parent_msg_id = $saved_user_text_id ?: null;
    
    // Buscamos CUALQUIER mensaje de sistema (compile o respond) que sea hijo de este mensaje de usuario
    $checkSys = $db_connection->prepare("SELECT id_, content FROM ChatMessages WHERE session_id_ = ? AND role = 'system' AND parent_msg_id = ? LIMIT 1");
    
    if ($checkSys) {
        $checkSys->bind_param("ii", $session_id, $parent_msg_id);
        $checkSys->execute();
        $resCheck = $checkSys->get_result();
        
        if ($resCheck && $resCheck->num_rows > 0) {
            // YA EXISTE un mensaje de sistema (el de la fase 'compile'). 
            // Si el usuario lo editó en el modal, actualizamos el contenido del registro existente.
            $existing = $resCheck->fetch_assoc();
            $existing_sys_id = (int)$existing['id_'];
            $existing_content = (string)$existing['content'];
            
            if (trim($existing_content) !== trim($compiled_prompt)) {
                $updSys = $db_connection->prepare("UPDATE ChatMessages SET content = ? WHERE id_ = ?");
                if ($updSys) {
                    $updSys->bind_param("si", $compiled_prompt, $existing_sys_id);
                    $updSys->execute();
                    $updSys->close();
                }
            }
            // ✅ NO HACEMOS INSERT. El registro ya existe y conserva sus datos de tokens/modelo.
        } else {
            // CASO DE FALLBACK: No existe ningún mensaje de sistema para este usuario (por seguridad)
            $id_system = next_id($db_connection, 'ChatMessages', 'id_');
            $role_system = 'system';
            $ctype = 'text';
            $content = $compiled_prompt;
            $is_primordial = 0;
            $phase = 'respond'; 
            if (!empty($parent_msg_id)) {
            $sqlSys = "INSERT INTO ChatMessages (
                id_, session_id_, user_id_, role, content_type, content,
                is_primordial, phase, parent_msg_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmtSys = $db_connection->prepare($sqlSys);
            if ($stmtSys) {
                $stmtSys->bind_param("iiisssisi",
                    $id_system, $session_id, $user_id, $role_system, $ctype, $content,
                    $is_primordial, $phase, $parent_msg_id
                );
                $stmtSys->execute();
                $stmtSys->close();
            }
            }
        }
        $checkSys->close();
    }
}

    // ---------------------------------------------------------
    // 2. CONSTRUIR EL PROMPT FINAL (Versión Definitiva) 
    // ---------------------------------------------------------
    // El system prompt final se arma desde UserAIAgentConfigs.
    // Primero obtenemos contextos dinámicos; después rellenamos la plantilla chat_main.

    // ---------------------------------------------------------
    // 2.1. BACKFILL DIRIGIDO + CONTEXT BUILDER
    // ---------------------------------------------------------
    // Si el Router pide memoria estructurada del proyecto pero todavía no existe,
    // Fase 4 puede rescatar hasta dos Q&A históricas relevantes del mismo proyecto,
    // consolidarlas y dejar que ContextBuilder las recupere EN ESTE MISMO TURNO.
    $memoryBackfillPublic = !empty($pipelineEffective['memory_backfill'])
        ? null
        : ['version'=>5, 'attempted'=>false, 'status'=>'skipped', 'reason'=>'feature_disabled'];
    if (!empty($pipelineEffective['memory_backfill']) && $projectId > 0 && !empty($memoryRoute['use_project_context'])) {
        try {
            $backfillStartedAt = microtime(true);
            $backfill = new MemoryBackfillService($db_connection, $bedrock);
            $memoryBackfillPublic = $backfill->backfill($user_id, $projectId, $memoryRouteText !== '' ? $memoryRouteText : $text, $memoryRoute);

            if ($activityTraceId !== '' && !empty($memoryBackfillPublic['attempted'])) {
                activityEmit(
                    $db_connection, $activityTraceId, $session_id, $user_id, 'respond',
                    'memory_backfill_completed', 'completed', 'Backfill de memoria · Fase 4',
                    (int)($memoryBackfillPublic['writes'] ?? 0) . ' memoria(s) estructurada(s) recuperada(s) desde Q&A históricas.',
                    $memoryBackfillPublic,
                    (string)($memoryBackfillPublic['model_id'] ?? ''),
                    activityDurationMs($backfillStartedAt)
                );
            }

            $bfUsage = (array)($memoryBackfillPublic['usage'] ?? []);
            if (!empty($bfUsage['input_tokens']) || !empty($bfUsage['output_tokens'])) {
                $bfModel = (string)($memoryBackfillPublic['model_id'] ?? 'unknown_model');
                $bfTuId = next_id($db_connection, 'TokenUsage', 'id_');
                $bfMsgId = getValidMessageId($db_connection, $saved_user_text_id, $session_id);
                $bfPhase = 'memory_backfill';
                $bfInput = (int)($bfUsage['input_tokens'] ?? 0);
                $bfOutput = (int)($bfUsage['output_tokens'] ?? 0);
                $bfCost = calculateCost($bfModel, $bfInput, $bfOutput);
                $bfDuration = activityDurationMs($backfillStartedAt);
                $stmtBfTu = $db_connection->prepare("INSERT INTO TokenUsage (id_, session_id_, message_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmtBfTu) {
                    $stmtBfTu->bind_param('iiissiddi', $bfTuId, $session_id, $bfMsgId, $bfPhase, $bfModel, $bfInput, $bfOutput, $bfCost, $bfDuration);
                    $stmtBfTu->execute();
                    $stmtBfTu->close();
                }
            }
        } catch (Throwable $e) {
            $memoryBackfillPublic = ['version'=>4,'attempted'=>true,'reason'=>'backfill_error','error'=>$e->getMessage()];
            $errors[] = 'Memory Backfill: ' . $e->getMessage();
        }
    }

    // MemoryContextRouter ya decidió QUÉ necesita la pregunta.
    // ContextBuilder recupera por tipo, deduplica la memoria procedural,
    // comparte un solo embedding y devuelve bloques compatibles con chat_main.
    $contextBuildStartedAt = microtime(true);
    $contextBundle = $contextBuilder->build($memoryRouteObject, [
        'stage' => 'respond',
        'user_id' => $user_id,
        'session_id' => $session_id,
        'project_id' => $projectId,
        'memory_scope' => $memoryScope,
        'query_text' => $memoryRouteText !== '' ? $memoryRouteText : $text,
        'attachment_mode' => $attachmentMode,
        'question_memory_enabled' => $use_question_memory,
        'question_memory_scope' => $question_memory_scope,
        'question_memory_max_candidates' => $question_memory_max_candidates,
        'question_memory_window_lines' => $question_memory_window_lines,
        'log_message_id' => getValidMessageId($db_connection, $saved_user_text_id, $session_id),
        'pipeline_features' => $pipelineEffective,
    ]);

    $proceduralMemoryBlock = $contextBundle->block('procedural_memory_block');
    $sessionMemoryBlock = $contextBundle->block('session_memory_block');
    $attachmentContextBlock = $contextBundle->block('attachment_context_block');
    $questionMemoryBlock = $contextBundle->block('question_memory_block');
    $projectMemoryBlock = $contextBundle->block('project_memory_block');
    $ragContextBlock = $contextBundle->block('project_rag_context_block');

    // Mantener contrato JSON histórico del frontend mientras Fase 2 introduce
    // ContextItem/ContextBundle como estructura interna normalizada.
    $questionMemory = (array)$contextBundle->legacy('question_memory', $questionMemory);
    $projectMemoryItems = [];
    foreach ($contextBundle->selectedItems('project_context') as $item) {
        $projectMemoryItems[] = [
            'id_' => $item->sourceId,
            'type' => $item->type,
            'title' => (string)($item->metadata['title'] ?? ''),
            'content' => (string)($item->metadata['raw_content'] ?? $item->content),
            'source_chunk_id' => $item->metadata['source_chunk_id'] ?? null,
            'created_at' => $item->metadata['created_at'] ?? null,
            'updated_at' => $item->metadata['updated_at'] ?? null,
            'ranking_score' => $item->rankingScore,
            'rank' => $item->rank,
            'ranking_signals' => $item->rankingSignals,
        ];
    }
    $contextBuilderPublic = $contextBundle->toPublicArray();

    // Las instrucciones del proyecto siguen siendo configuración obligatoria,
    // no memoria recuperada. ProjectContext se anexa después como memoria tipada.
    $projectInstructionsBlock = $projectInstructions !== ''
        ? aiRenderTemplate(
            aiAgentInstruction('chat_main_project_instructions_template', "[INSTRUCCIONES OBLIGATORIAS DEL PROYECTO]\n{{project_instructions}}"),
            ['project_instructions' => $projectInstructions]
        )
        : '';

    if ($projectMemoryBlock !== '') {
        $projectInstructionsBlock = trim(
            ($projectInstructionsBlock !== '' ? $projectInstructionsBlock . "\n\n" : '') . $projectMemoryBlock
        );
    }

    if ($activityTraceId !== '') {
        $builderTelemetry = $contextBundle->telemetry();
        $builderSummary = $builderTelemetry['builder'] ?? [];
        $rankingSummary = $builderTelemetry['ranking'] ?? [];

        $rankingEnabledForTrace = !empty($rankingSummary['enabled']);
        activityEmit(
            $db_connection, $activityTraceId, $session_id, $user_id, 'respond',
            'context_ranking_completed', 'completed',
            $rankingEnabledForTrace ? 'Context Ranker · Fase 3' : 'Context Ranker desactivado · Fase 5',
            ($rankingEnabledForTrace ? 'Ranking multi-señal completado: ' : 'Selección determinista sin ranking: ') .
            (int)($rankingSummary['retrieved'] ?? 0) . ' candidato(s), ' .
            (int)($rankingSummary['selected'] ?? 0) . ' seleccionado(s), ' .
            (int)($rankingSummary['duplicates_removed'] ?? 0) . ' duplicado(s) descartado(s).',
            [
                'memory_route' => $memoryRoute,
                'ranking' => $rankingSummary,
                'ranked_items' => $contextBundle->toPublicArray()['items'] ?? [],
            ],
            aiAgentModel('embedding_main','')
        );

        activityEmit(
            $db_connection, $activityTraceId, $session_id, $user_id, 'respond',
            'context_builder_completed', 'completed', 'Context Builder · Fase 3',
            ($rankingEnabledForTrace ? 'Contexto recuperado, rankeado, deduplicado y ensamblado. ' : 'Contexto recuperado, seleccionado de forma determinista, deduplicado y ensamblado. ') .
            'ProjectContext=' . count($contextBundle->selectedItems('project_context')) .
            ', sesión=' . count($contextBundle->selectedItems('session_memory')) .
            ', Q&A=' . count($contextBundle->selectedItems('question_memory')) .
            ', RAG=' . count($contextBundle->selectedItems('project_rag')) .
            ', adjuntos=' . count($contextBundle->selectedItems('attachments')) . '.',
            [
                'memory_route' => $memoryRoute,
                'context_builder' => $contextBundle->toActivityArray(),
                'system_blocks' => [
                    'procedural_memory_block' => $proceduralMemoryBlock,
                    'session_memory_block' => $sessionMemoryBlock,
                    'attachment_context_block' => $attachmentContextBlock,
                    'question_memory_block' => $questionMemoryBlock,
                    'project_instructions_block' => $projectInstructionsBlock,
                    'project_memory_block' => $projectMemoryBlock,
                    'primordial_rules_block' => $primordialRules,
                    'project_rag_context_block' => $ragContextBlock,
                ],
                'builder_summary' => $builderSummary,
                'ranking_summary' => $rankingSummary,
            ],
            aiAgentModel('embedding_main',''),
            activityDurationMs($contextBuildStartedAt)
        );
    }

    $baseInstruction = aiAgentInstruction(
        'chat_main_base',
        'Eres un asistente de IA experto en programación y conocimiento general. Responde de manera directa, útil y precisa en español.'
    );
    // Fase 4.1: las reglas internas de herramientas sólo entran cuando el Router
    // autorizó una operación concreta de código/proyecto. Así no contaminan consultas
    // como "¿qué reglas tengo guardadas?".
    $toolRulesBlock = !empty($pipelineEffective['project_tools']) && !empty($memoryRoute['use_project_tools'])
        ? aiAgentInstruction('chat_main_tool_rules', '')
        : '';
    $behaviorRulesBlock = aiAgentInstruction('chat_main_behavior_rules', '');

    // Fase 4 · Grounding de código/proyecto.
    // Una petición que diga "revisa X.php" no autoriza al modelo a inventar el
    // contenido de X.php. Si hay proyecto, debe obtener evidencia real mediante
    // herramientas; sin proyecto, debe reconocer que no puede inspeccionarlo.
    if (($memoryRoute['intent'] ?? '') === 'code' || !empty($memoryRoute['use_project_tools'])) {
        if ($projectId > 0 && !empty($pipelineEffective['project_tools'])) {
            $groundingRule = "[GROUNDING OBLIGATORIO PARA CÓDIGO DEL PROYECTO]
Si el usuario pide revisar, explicar, diagnosticar o modificar un archivo EXISTENTE del proyecto, DEBES usar grep, view o search antes de afirmar cómo está implementado. Basa cualquier afirmación sobre ese archivo únicamente en resultados reales de herramientas/RAG. Si el archivo no aparece o una herramienta falla, dilo explícitamente. PROHIBIDO inventar clases, métodos, regex, contenido o estructura del archivo. Las preguntas conceptuales de programación que no dependan de un archivo existente sí pueden responderse con conocimiento general.";
        } elseif ($projectId > 0) {
            $groundingRule = "[HERRAMIENTAS DEL PROYECTO DESACTIVADAS]
Las herramientas del proyecto están desactivadas por preferencia del usuario. No afirmes haber inspeccionado ni modificado archivos reales y no inventes su contenido. Puedes responder preguntas conceptuales; para información exacta del proyecto utiliza únicamente contexto que ya haya sido recuperado por fuentes habilitadas.";
        } else {
            $groundingRule = "[GROUNDING OBLIGATORIO PARA CÓDIGO]
No hay proyecto activo accesible en esta petición. Si el usuario pide revisar, explicar o modificar un archivo existente, NO afirmes haberlo inspeccionado y NO inventes su contenido. Indica que necesitas que el archivo esté disponible en un proyecto/adjunto para revisarlo. Las preguntas conceptuales de programación que no dependan de un archivo concreto sí pueden responderse normalmente.";
        }
        $behaviorRulesBlock = trim($behaviorRulesBlock . "

" . $groundingRule);
    }

    if (in_array((string)($memoryRoute['intent'] ?? ''), ['decision','rule','preference','fact','todo'], true)) {
        $behaviorRulesBlock = trim($behaviorRulesBlock . "

[CONSULTA DE MEMORIA ESTRUCTURADA]
Cuando el usuario pregunte qué decisiones, reglas, preferencias, hechos o pendientes tiene guardados, responde únicamente con información presente en los bloques de memoria recuperados para ESTA petición. Las instrucciones internas del sistema, reglas de herramientas y descripciones de Tool Use NO son memorias del usuario y no deben enumerarse como tales. Si no se recuperó una memoria pertinente, dilo claramente y no reemplaces la respuesta con consejos genéricos inventados.");
    }


    if (!empty($memoryRoute['code_policy_only'])) {
        $behaviorRulesBlock = trim($behaviorRulesBlock . "

[DECLARACIÓN DE POLÍTICA SOBRE CÓDIGO — NO ES UNA OPERACIÓN]
El usuario está estableciendo, recordando o consultando una regla/preferencia sobre cómo trabajar con código; NO está pidiendo ejecutar una operación sobre un archivo ahora. No solicites nombres de archivo, no anuncies ni menciones herramientas internas (code_edit, grep, view, search, str_replace), no intentes abrir archivos y no inventes un archivo genérico como archivo.php. Limítate a reconocer o responder la regla/preferencia y deja que Memory Writer la consolide cuando corresponda.");
    }

    $mainSystemTemplate = aiAgentInstruction(
        'chat_main',
        "{{base_instruction}}\n\n{{procedural_memory_block}}\n\n{{session_memory_block}}\n\n{{attachment_context_block}}\n\n{{question_memory_block}}\n\n{{project_instructions_block}}\n\n{{tool_rules_block}}\n\n{{primordial_rules_block}}\n\n{{rag_context_block}}\n\n{{behavior_rules_block}}"
    );

    $systemPrompt = trim(aiRenderTemplate($mainSystemTemplate, [
        'base_instruction' => $baseInstruction,
        'procedural_memory_block' => $proceduralMemoryBlock,
        'session_memory_block' => $sessionMemoryBlock,
        'attachment_context_block' => $attachmentContextBlock,
        'question_memory_block' => $questionMemoryBlock,
        'project_instructions_block' => $projectInstructionsBlock,
        'tool_rules_block' => $toolRulesBlock,
        'primordial_rules_block' => $primordialRules,
        'rag_context_block' => $ragContextBlock,
        'behavior_rules_block' => $behaviorRulesBlock,
    ]));

    $userParts = [];
    // Si el compilador está activo usamos el prompt compilado; si no, el texto original/router.
    $finalUserText = $compiled_prompt ?: ($router['improved_prompt'] ?: $text);
    
    if ($finalUserText !== '') {
        $userParts[] = ['text' => $finalUserText];
    } elseif (!empty($contextTexts)) {
        $userParts[] = ['text' => 'Analiza los archivos adjuntos y respóndeme en español.'];
    }
    foreach ($contextTexts as $ctx) $userParts[] = ['text' => $ctx];

    $messages = [ [ 'role' => 'user', 'content' => $userParts ] ];

$use_max_tokens = $resp_max_tokens > 0
    ? $resp_max_tokens
    : max(1, (int)aiAgentValue('chat_main', 'max_tokens_output', $max_tokens));
$inferBase = [
    'maxTokens' => $use_max_tokens,
    'temperature' => $temperature,
    'topP' => $top_p,
];
$chatSeed = isset($_POST['seed']) ? $seed : (int)aiAgentValue('chat_main', 'seed', $seed);
if ($chatSeed > 0) {
    $inferBase['seed'] = $chatSeed;
}
    // ===== DEFINICIÓN DE HERRAMIENTAS PARA BEDROCK (Mejorada para edición) =====
    $tools = [
        ['toolSpec' => [
            'name' => 'grep', 
            'description' => 'Busca un patrón de texto o NOMBRE DE ARCHIVO en los archivos indexados. USA ESTA HERRAMIENTA PRIMERO si el usuario menciona un archivo específico (ej. "EjemploClase.php") para obtener su source_id antes de usar "str_replace" o "view".', 
            'inputSchema' => ['json' => ['type' => 'object', 'properties' => ['pattern' => ['type' => 'string', 'description' => 'Texto o nombre de archivo exacto a buscar (ej. EjemploClase.php)']], 'required' => ['pattern']]]
        ]],
        ['toolSpec' => [
            'name' => 'view', 
            'description' => 'Muestra el contenido completo de un chunk de código dado su ID. Requiere que primero hayas obtenido el chunk_id mediante "grep" o "search".', 
            'inputSchema' => ['json' => ['type' => 'object', 'properties' => ['chunk_id' => ['type' => 'integer', 'description' => 'ID numérico del chunk']], 'required' => ['chunk_id']]]
        ]],
        ['toolSpec' => [
            'name' => 'search', 
            'description' => 'Búsqueda semántica o por concepto en los archivos del proyecto.', 
            'inputSchema' => ['json' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => 'Concepto o texto a buscar']], 'required' => ['query']]]
        ]],
        ['toolSpec' => [
            'name' => 'str_replace', 
            'description' => 'Reemplaza un bloque de texto en un archivo. REQUIERE que primero uses "grep" para obtener el "source_id" del archivo. El "old_text" debe coincidir EXACTAMENTE con el contenido original.', 
            'inputSchema' => ['json' => ['type' => 'object', 'properties' => [
                'source_id' => ['type' => 'integer', 'description' => 'ID de la fuente obtenido de grep'], 
                'old_text' => ['type' => 'string', 'description' => 'Texto exacto a reemplazar'], 
                'new_text' => ['type' => 'string', 'description' => 'Nuevo texto']
            ], 'required' => ['source_id', 'old_text', 'new_text']]]
        ]],
        // 🚀 Herramienta CRUD completa de archivos del proyecto (crear/editar/leer/eliminar)
        ['toolSpec' => [
            'name' => 'code_edit',
            'description' => "Administra archivos del proyecto en S3 + base de datos. Úsala SIEMPRE que el usuario pida crear, modificar, guardar, leer/ver el contenido real de, o eliminar un archivo.\n- action='write' (default): crea el archivo si no existe, o lo edita si ya existe. Requiere 'instruction'.\n- action='read': devuelve el contenido REAL actual del archivo tal como está en S3.\n- action='delete': elimina el archivo de S3 y de la base de datos de forma permanente.",
            'inputSchema' => [
                'json' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'ID del proyecto'],
                        'session_id' => ['type' => 'integer', 'description' => 'ID de la sesión'],
                        'target_filename' => ['type' => 'string', 'description' => 'Nombre del archivo (ej: claseejemplo1.php)'],
                        'action' => ['type' => 'string', 'enum' => ['write', 'read', 'delete'], 'description' => "'write' para crear/editar (default), 'read' para leer el contenido real, 'delete' para eliminarlo"],
                        'instruction' => ['type' => 'string', 'description' => "Qué hacer en el archivo. OBLIGATORIO solo cuando action='write'"]
                    ],
                    'required' => ['project_id', 'session_id', 'target_filename']
                ]
            ]
        ]]
    ];

$converseParams = [
    'modelId'         => $model_id,
    'messages'        => $messages,
    'inferenceConfig' => $inferBase,
    'system'          => [['text' => $systemPrompt]] // ✅ CORREGIDO: El System Prompt SIEMPRE debe enviarse
];

// ✅ CORREGIDO: SOLO ofrecer herramientas si hay un proyecto activo. 
// Esto evita que la IA intente usar 'grep' o 'view' en preguntas de cultura general y diga "no encontré el archivo".
if ($projectId > 0
    && !empty($pipelineEffective['project_tools'])
    && !empty($memoryRoute['use_project_tools'])
    && (bool)aiAgentExtra('chat_main', 'tools_enabled_only_with_project', true)) {
    $converseParams['toolConfig'] = ['tools' => $tools];
}

if ($activityTraceId !== '') {
    $toolNamesForTrace = [];
    foreach (($converseParams['toolConfig']['tools'] ?? []) as $toolDef) {
        if (!empty($toolDef['toolSpec']['name'])) $toolNamesForTrace[] = (string)$toolDef['toolSpec']['name'];
    }
    activityEmit(
        $db_connection, $activityTraceId, $session_id, $user_id, 'respond',
        'final_prompt_prepared', 'completed', 'Prompt final preparado',
        'System prompt + mensaje de usuario listos para chat_main.',
        [
            'model' => $model_id,
            'system_prompt' => $systemPrompt,
            'messages' => $messages,
            'inference_config' => $inferBase,
            'tools_enabled' => !empty($toolNamesForTrace),
            'tool_names' => $toolNamesForTrace,
            'memory_router_execution_lane' => $memoryRoute['execution_lane'] ?? 'chat',
            'memory_router_use_project_tools' => (bool)($memoryRoute['use_project_tools'] ?? false),
            'project_tools_feature_enabled' => (bool)($pipelineEffective['project_tools'] ?? false),
        ],
        $model_id
    );
}

    // ===== BUCLE DE TOOL USE (Máximo 3 iteraciones) =====
    $maxRounds = max(1, (int)aiAgentExtra('chat_main', 'max_rounds', 5));
    $round = 0;
    $stopReason = null;
    $anyToolFailed = false;
    $lastToolError = '';
    $successfulToolNames = [];

    // ✅ Liberamos el lock del archivo de sesión ANTES de entrar al bucle: el tool
    // 'code_edit' hace una petición HTTP interna hacia code_edit.php que también
    // necesita abrir esta misma sesión (para leer $_SESSION['user_id']). Si no la
    // cerramos aquí, esa petición interna queda bloqueada esperando el lock hasta
    // agotar su propio timeout, y el archivo nunca llega a crearse/editarse.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    while ($round < $maxRounds) {
        $roundNumber = $round + 1;
        $roundStartedAt = microtime(true);
        if ($activityTraceId !== '') {
            activityEmit(
                $db_connection, $activityTraceId, $session_id, $user_id, 'respond',
                'model_round_started', 'started', 'Generando con chat_main · ronda ' . $roundNumber,
                'Llamando a Bedrock Converse con el estado actual del diálogo y herramientas.',
                [
                    'round' => $roundNumber,
                    'model' => $model_id,
                    'message_count' => count($converseParams['messages'] ?? []),
                    'inference_config' => $converseParams['inferenceConfig'] ?? [],
                ],
                $model_id
            );
        }
        $res = $bedrock->converse($converseParams);
        
        $usage['prompt_tokens']     += (int)($res['usage']['inputTokens'] ?? 0);
        $usage['completion_tokens'] += (int)($res['usage']['outputTokens'] ?? 0);
        $usage['total_tokens']      += (int)($res['usage']['totalTokens'] ?? 0);

        $stopReason = $res['stopReason'] ?? null;
        $contentBlocks = $res['output']['message']['content'] ?? [];

        $textResponse = '';
        $toolRequests = [];

        foreach ($contentBlocks as $block) {
            if (isset($block['text'])) {
                $textResponse .= $block['text'];
            } elseif (isset($block['toolUse'])) {
                $toolRequests[] = $block['toolUse'];
            }
        }

        if ($activityTraceId !== '') {
            $toolPreview = [];
            foreach ($toolRequests as $toolReq) {
                $toolPreview[] = [
                    'name' => $toolReq['name'] ?? null,
                    'tool_use_id' => $toolReq['toolUseId'] ?? null,
                    'input' => $toolReq['input'] ?? [],
                ];
            }
            activityEmit(
                $db_connection, $activityTraceId, $session_id, $user_id, 'respond',
                'model_round_completed', 'completed', 'Ronda ' . $roundNumber . ' completada',
                'stopReason=' . (string)($stopReason ?? 'desconocido') . ' · ' . count($toolRequests) . ' herramienta(s) solicitada(s).',
                [
                    'round' => $roundNumber,
                    'stop_reason' => $stopReason,
                    'input_tokens' => (int)($res['usage']['inputTokens'] ?? 0),
                    'output_tokens' => (int)($res['usage']['outputTokens'] ?? 0),
                    'total_tokens' => (int)($res['usage']['totalTokens'] ?? 0),
                    'visible_text_chars' => mb_strlen($textResponse),
                    'note' => $textResponse !== '' ? 'La salida textual intermedia no se expone en telemetría; la respuesta final se muestra en el chat.' : null,
                    'tool_requests' => $toolPreview,
                ],
                $model_id,
                activityDurationMs($roundStartedAt)
            );
        }

        if ($stopReason === 'tool_use' && !empty($toolRequests)) {
            $assistantMessage = $res['output']['message'];
            $converseParams['messages'][] = $assistantMessage;
            
            $toolResults = [];
            foreach ($toolRequests as $tool) {
                $toolName = $tool['name'];
                $toolUseId = $tool['toolUseId'];
                $args = $tool['input'] ?? [];
                $toolStartedAt = microtime(true);
                if ($activityTraceId !== '') {
                    activityEmit(
                        $db_connection, $activityTraceId, $session_id, $user_id, 'respond',
                        'tool_started', 'started', 'Herramienta: ' . $toolName,
                        'Ejecutando una herramienta solicitada por chat_main.',
                        ['tool'=>$toolName, 'tool_use_id'=>$toolUseId, 'params'=>$args],
                        $model_id
                    );
                }

                $resultText = "Error: Herramienta desconocida";
                try {
                    if ($toolName === 'grep') {
                        $resultText = execute_tool_grep($args, $projectId, $db_connection);
                    } elseif ($toolName === 'view') {
                        $resultText = execute_tool_view($args, $projectId, $db_connection);
                    } elseif ($toolName === 'search') {
                        $resultText = execute_tool_search($args, $projectId, $db_connection, $bedrock);
                    } elseif ($toolName === 'str_replace') {
                        $resultText = execute_tool_str_replace($args, $projectId, $db_connection);
                    } elseif ($toolName === 'code_edit') {
                        // ✅ CORREGIDO: antes usaba $sessionId (nunca definida) y siempre
                        // caía a 0, lo que hacía que code_edit.php rechazara la petición
                        // con "Faltan parámetros" y JAMÁS llegara a crear el archivo.
                        $editResult = execute_tool_code_edit($args, $projectId, $session_id, $db_connection);
                        $resultText = $editResult;
                    }

                } catch (Throwable $e) {
                    $resultText = json_encode(['error' => $e->getMessage()]);
                }

                // ✅ Rastreamos si la herramienta realmente falló para no fingir éxito después
                $decodedResult = json_decode($resultText, true);
                $toolFailed = is_array($decodedResult) && !empty($decodedResult['error']);
                if ($toolFailed) {
                    $anyToolFailed = true;
                    $lastToolError = (string)$decodedResult['error'];
                } else {
                    $successfulToolNames[] = (string)$toolName;
                    $successfulToolNames = array_values(array_unique($successfulToolNames));
                }
                if ($activityTraceId !== '') {
                    activityEmit(
                        $db_connection, $activityTraceId, $session_id, $user_id, 'respond',
                        'tool_completed', $toolFailed ? 'error' : 'completed', 'Herramienta: ' . $toolName,
                        $toolFailed ? (string)$decodedResult['error'] : 'Herramienta ejecutada correctamente.',
                        [
                            'tool' => $toolName,
                            'tool_use_id' => $toolUseId,
                            'params' => $args,
                            'result' => $decodedResult !== null ? $decodedResult : $resultText,
                        ],
                        $model_id,
                        activityDurationMs($toolStartedAt)
                    );
                }

                $toolResults[] = [
                    'toolResult' => [
                        'toolUseId' => $toolUseId,
                        'content' => [['text' => $resultText]]
                    ]
                ];
            }
            
            $converseParams['messages'][] = [
                'role' => 'user',
                'content' => $toolResults
            ];
            $round++;
        } else {
            $reply_text = $textResponse;
            break;
        }
    }

    if ($reply_text === null || trim($reply_text) === '') {
        if ($round > 0 && $anyToolFailed) {
            // ✅ CORREGIDO: antes se devolvía un mensaje de éxito genérico sin importar
            // si la herramienta había fallado, por lo que el usuario creía que el
            // archivo se había creado cuando en realidad code_edit.php nunca llegó a subirlo.
            $reply_text = "⚠️ No se pudo completar la operación sobre el archivo. Detalle técnico: " . $lastToolError;
        } elseif ($round > 0) {
            $reply_text = "✅ La operación se ejecutó correctamente en el proyecto. Revisa los archivos para confirmar los cambios. Si necesitas que te muestre el código o te genere un enlace de descarga, por favor pídemelo explícitamente.";
        } else {
            $reply_text = "Procesé tu solicitud, pero no pude generar una respuesta de texto. ¿Podrías reformular la pregunta?";
        }
    }

  } catch (Throwable $e) {
    $chatPipelineFailure = $e;
    $reply_text = '✔️ Recibido. (No pude contactar Bedrock: '.$e->getMessage().')';
    if ($activityTraceId !== '') {
        activityEmit(
            $db_connection, $activityTraceId, $session_id, $user_id, 'respond',
            'model_error', 'error', 'Error generando la respuesta',
            $e->getMessage(),
            ['model'=>$model_id],
            $model_id
        );
    }
  }

}

// ====================================================================
// FASE 4 · GUARDARRAÍL DETERMINISTA DE GROUNDING PARA ARCHIVOS
// ====================================================================
$groundingGuardApplied = false;
$groundingRequestedFile = '';
if (!empty($memoryRoute['code_operation']) && trim($text) !== '') {
    $filePattern = '/\b(?:revisa|analiza|inspecciona|explica|mejora|modifica|edita|cambia|diagnostica)\b[^\n]{0,220}?\b([a-zA-Z0-9_.-]+\.(?:php|phtml|inc|js|mjs|cjs|ts|tsx|css|html|json|sql|py|java|go|rs))\b/iu';
    if (preg_match($filePattern, $text, $groundMatch)) {
        $groundingRequestedFile = (string)($groundMatch[1] ?? '');
        $groundedContextCount = isset($contextBundle)
            ? count($contextBundle->selectedItems('project_rag')) + count($contextBundle->selectedItems('attachments'))
            : 0;
        $groundedToolCount = isset($successfulToolNames) ? count($successfulToolNames) : 0;

        if ($groundingRequestedFile !== '' && $groundedContextCount === 0 && $groundedToolCount === 0) {
            $groundingGuardApplied = true;
            $reply_text = $projectId > 0
                ? "No pude verificar el archivo `{$groundingRequestedFile}` en el proyecto actual. Para evitar inventar su contenido, no voy a afirmar cómo está implementado. Agrégalo/indexa el archivo o asegúrate de que exista en el proyecto y vuelve a pedirme que lo revise."
                : "No tengo acceso real al archivo `{$groundingRequestedFile}` en esta conversación. Para revisarlo sin inventar contenido, adjúntalo o trabaja dentro de un proyecto donde el archivo esté disponible.";

            if ($activityTraceId !== '') {
                activityEmit(
                    $db_connection, $activityTraceId, $session_id, $user_id, 'respond',
                    'grounding_guard_applied', 'completed', 'Grounding de archivo aplicado',
                    'Se evitó responder sobre un archivo existente sin evidencia recuperada ni herramientas.',
                    [
                        'filename' => $groundingRequestedFile,
                        'project_id' => $projectId,
                        'selected_grounding_contexts' => $groundedContextCount,
                        'successful_tools' => isset($successfulToolNames) ? $successfulToolNames : [],
                    ]
                );
            }
        }
    }
}

// ===== Guardar respuesta del asistente =====
$assistant_id = next_id($db_connection,'ChatMessages','id_');
$role_assistant = 'assistant'; $ctypeA='text'; $contentA=$reply_text;
$s3_key=null; $mime=null; $size_bytes=null; $thumb_key=null; $duration_ms=null;
$model_msg=$model_id; $stop_reason=null; $prompt_tok=$usage['prompt_tokens']; $compl_tok=$usage['completion_tokens']; $latency_ms=null; $meta=$activityTraceId !== '' ? json_encode(['trace_id'=>$activityTraceId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

// Campos de metacognición para la respuesta
$is_primordial = 0;
$phase = 'respond';
$parent_msg_id = null;

$sqlA = "INSERT INTO ChatMessages (
  id_, session_id_, user_id_, role, content_type, content,
  s3_key, mime_type, size_bytes, thumb_s3_key, duration_ms,
  model_id, stop_reason, prompt_tokens, completion_tokens, latency_ms, meta,
  is_primordial, phase, parent_msg_id
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
$stmtA=$db_connection->prepare($sqlA);
if($stmtA){
  $typesA = "iiisssssisissiiisisi";
  $stmtA->bind_param($typesA, 
    $assistant_id, $session_id, $user_id, $role_assistant, $ctypeA, $contentA,
    $s3_key, $mime, $size_bytes, $thumb_key, $duration_ms, $model_msg, $stop_reason, $prompt_tok, $compl_tok, $latency_ms, $meta,
    $is_primordial, $phase, $parent_msg_id
  );
  $stmtA->execute();
  $stmtA->close();
}
if ($activityTraceId !== '') {
    activityEmit(
        $db_connection, $activityTraceId, $session_id, $user_id, 'respond',
        'response_saved', 'completed', 'Respuesta guardada',
        'La respuesta final se persistió en ChatMessages.',
        [
            'assistant_message_id' => $assistant_id,
            'model' => $model_id,
            'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
            'completion_tokens' => $usage['completion_tokens'] ?? 0,
            'reply' => $reply_text,
        ],
        $model_id
    );
}

// ====================================================================
// ✅ MEMORIA SELECTIVA: guardar Q&A crudo y encolar SOLO embedding
// ====================================================================
$memoryBlockIdForTrace = null;
$memoryJobIdForTrace = null;
$memoryEmbeddingModelForTrace = null;
try {
    $question_msg_for_block = $saved_user_text_id ?: null;

    // Un level_0 conversacional requiere pregunta y respuesta reales.
    if ($question_msg_for_block && $assistant_id) {
        $block_id = next_id($db_connection, 'SessionContextBlocks', 'id_');
        $memoryBlockIdForTrace = $block_id;

        // content_preview es solo una vista rápida determinista. La fuente íntegra
        // permanece en ChatMessages mediante question_msg_id/answer_msg_id.
        $rawPreview = "Pregunta: " . $text . "\nRespuesta: " . $reply_text;
        $preview = mb_substr($rawPreview, 0, 8000);
        $token_count = (int)ceil(mb_strlen($rawPreview) / 4);

        $sqlBlock = "INSERT INTO SessionContextBlocks (
            id_, session_id_, block_type, question_msg_id, answer_msg_id,
            content_preview, is_locked, token_count, is_memory_summary
        ) VALUES (?, ?, 'level_0', ?, ?, ?, 0, ?, 0)";

        $stmtBlock = $db_connection->prepare($sqlBlock);
        if ($stmtBlock) {
            $stmtBlock->bind_param(
                "iiissi",
                $block_id,
                $session_id,
                $question_msg_for_block,
                $assistant_id,
                $preview,
                $token_count
            );

            if ($stmtBlock->execute()) {
                $affectedRows = $stmtBlock->affected_rows;
                $stmtBlock->close();

                if ($affectedRows > 0) {
                    // Solo embedding_main. No Smart Memory/LLM por cada Q&A.
                    if (aiAgentActive('embedding_main', false) && aiAgentModel('embedding_main', '') !== '') {
                        $embeddingModel = aiAgentModel('embedding_main', '');
                        $memoryEmbeddingModelForTrace = $embeddingModel;
                        $job_id = next_id($db_connection, 'EmbeddingJobs', 'id_');
                        $memoryJobIdForTrace = $job_id;
                        $sqlJob = "INSERT INTO EmbeddingJobs (
                            id_, target_type, target_id, model_id, status, attempts
                        ) VALUES (?, 'session_block', ?, ?, 'pending', 0)";
                        $stmtJob = $db_connection->prepare($sqlJob);
                        if ($stmtJob) {
                            $stmtJob->bind_param("iis", $job_id, $block_id, $embeddingModel);
                            $stmtJob->execute();
                            $stmtJob->close();
                        }
                    }

                    // process_embedding_queue.php marcará pending_summary cuando
                    // existan 10 level_0 desbloqueados y YA vectorizados.
                }
            } else {
                $err = $stmtBlock->error;
                $stmtBlock->close();
                error_log("❌ Error insertando SessionContextBlocks: " . $err);
            }
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Memoria selectiva (guardado Q&A): ' . $e->getMessage();
}

if ($activityTraceId !== '') {
    activityEmit(
        $db_connection, $activityTraceId, $session_id, $user_id, 'respond',
        'memory_queued', 'completed', 'Memoria Q&A preparada',
        $memoryJobIdForTrace !== null
            ? 'Q&A crudo guardado y embedding encolado.'
            : ($memoryBlockIdForTrace !== null ? 'Q&A crudo guardado; embedding_main no generó un job.' : 'No se creó bloque de memoria.'),
        [
            'session_block_id' => $memoryBlockIdForTrace,
            'embedding_job_id' => $memoryJobIdForTrace,
            'embedding_model' => $memoryEmbeddingModelForTrace,
            'question_message_id' => $saved_user_text_id,
            'answer_message_id' => $assistant_id,
        ],
        $memoryEmbeddingModelForTrace
    );
}


// ====================================================================
// FASE 4 · MEMORY WRITER / CONSOLIDACIÓN INTELIGENTE
// ====================================================================
$memoryWriterPublic = !empty($pipelineEffective['memory_writer'])
    ? null
    : ['version'=>5, 'status'=>'skipped', 'reason'=>'feature_disabled', 'candidate_count'=>0, 'write_count'=>0];
try {
    if (!empty($pipelineEffective['memory_writer']) && isset($bedrock) && $bedrock && $saved_user_text_id && $assistant_id && trim($text) !== '') {
        $memoryWriteStartedAt = microtime(true);
        $memoryWriter = new MemoryWriter($db_connection, $bedrock);
        $memoryWriteResult = $memoryWriter->write(
            $user_id,
            $session_id,
            $projectId,
            (int)$saved_user_text_id,
            (int)$assistant_id,
            $text,
            (string)$reply_text,
            $memoryRoute,
            isset($successfulToolNames) ? $successfulToolNames : []
        );
        $memoryWriterPublic = $memoryWriteResult->toArray(false);

        if ($activityTraceId !== '') {
            $mwData = $memoryWriteResult->toArray(true);
            $writeCount = (int)($mwData['write_count'] ?? 0);
            $candidateCount = (int)($mwData['candidate_count'] ?? 0);
            $mwStatus = (string)($mwData['status'] ?? 'skipped');
            activityEmit(
                $db_connection, $activityTraceId, $session_id, $user_id, 'respond',
                'memory_writer_completed', $mwStatus === 'error' ? 'error' : 'completed', 'Memory Writer · Fase 4',
                $mwStatus === 'completed'
                    ? "{$candidateCount} candidato(s) detectado(s), {$writeCount} escritura(s)/refuerzo(s)."
                    : 'Writer: ' . (string)($mwData['reason'] ?? $mwStatus),
                $mwData + [
                    'successful_tools' => isset($successfulToolNames) ? $successfulToolNames : [],
                    'duration_ms' => activityDurationMs($memoryWriteStartedAt),
                ],
                (string)($mwData['model_id'] ?? ''),
                activityDurationMs($memoryWriteStartedAt)
            );
        }

        // Registrar el costo adicional del extractor sólo cuando realmente llamó a un modelo.
        $mwUsage = $memoryWriteResult->usage;
        if (!empty($mwUsage['input_tokens']) || !empty($mwUsage['output_tokens'])) {
            $mwModel = $memoryWriteResult->modelId ?: 'unknown_model';
            $mwTuId = next_id($db_connection, 'TokenUsage', 'id_');
            $mwMsgId = getValidMessageId($db_connection, $assistant_id, $session_id);
            $mwPhase = 'memory_write';
            $mwInput = (int)($mwUsage['input_tokens'] ?? 0);
            $mwOutput = (int)($mwUsage['output_tokens'] ?? 0);
            $mwCost = calculateCost($mwModel, $mwInput, $mwOutput);
            $mwDuration = activityDurationMs($memoryWriteStartedAt);
            $stmtMwTu = $db_connection->prepare("INSERT INTO TokenUsage (id_, session_id_, message_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmtMwTu) {
                $stmtMwTu->bind_param('iiissiddi', $mwTuId, $session_id, $mwMsgId, $mwPhase, $mwModel, $mwInput, $mwOutput, $mwCost, $mwDuration);
                $stmtMwTu->execute();
                $stmtMwTu->close();
            }
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Memory Writer: ' . $e->getMessage();
    $memoryWriterPublic = [
        'version' => 4,
        'status' => 'error',
        'reason' => 'memory_writer_exception',
        'errors' => [$e->getMessage()],
    ];
    if ($activityTraceId !== '') {
        activityEmit(
            $db_connection, $activityTraceId, $session_id, $user_id, 'respond',
            'memory_writer_completed', 'error', 'Memory Writer · Fase 4',
            $e->getMessage(),
            $memoryWriterPublic
        );
    }
}

// ====================================================================
// ✅ REGISTRAR USO DE TOKENS DE LA RESPUESTA PRINCIPAL EN TokenUsage
// ====================================================================
try {
    $tuInput = (int)($usage['prompt_tokens'] ?? 0);
    $tuOutput = (int)($usage['completion_tokens'] ?? 0);
    
    $tuId = next_id($db_connection, 'TokenUsage', 'id_');
    $tuPhase = (string)aiAgentValue('chat_main', 'token_usage_phase', 'respond');
    $tuModel = $model_id ?: 'unknown_model';
    
    // ✅ CORREGIDO: Validar que el message_id exista en ChatMessages
    // Si no existe, busca el último de la sesión. Si tampoco, usa NULL.
    // NUNCA usa 0 porque viola la FK fk_tu_message.
    $tuMsgId = getValidMessageId($db_connection, $assistant_id, $session_id);
    $tuDuration = activityDurationMs($requestStartedAt);
    $estimatedCost = calculateCost($tuModel, $tuInput, $tuOutput);
    
    $sqlTU = "INSERT INTO TokenUsage (id_, session_id_, message_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
              
    $stmtTU = $db_connection->prepare($sqlTU);
    if (!$stmtTU) {
        throw new Exception("Error preparando: " . $db_connection->error);
    }
    
    $stmtTU->bind_param("iiissiddi", $tuId, $session_id, $tuMsgId, $tuPhase, $tuModel, $tuInput, $tuOutput, $estimatedCost, $tuDuration);
    
    if (!$stmtTU->execute()) {
        throw new Exception("Error ejecutando: " . $stmtTU->error);
    }
    $stmtTU->close();
    
} catch (Throwable $e) {
    $errors[] = 'TokenUsage tracking FALLÓ: ' . $e->getMessage();
}


if (empty($pipelineEffective['memory_writer']) && $activityTraceId !== '') {
    activityEmit(
        $db_connection, $activityTraceId, $session_id, $user_id, 'respond',
        'memory_writer_completed', 'completed', 'Memory Writer desactivado · Fase 5',
        'Memory Writer fue omitido por Feature Flag; no se consolidó memoria estructurada en este turno.',
        $memoryWriterPublic
    );
}

// La tarea termina únicamente después de persistir la respuesta y completar las
// operaciones esenciales existentes. Los fallos del bridge son siempre fail-open.
if ($chatTaskBridge instanceof ChatTaskBridge && $chatTaskContext instanceof ChatTaskContext) {
    try {
        if ($chatPipelineFailure instanceof Throwable) {
            $chatTaskBridge->failTurn($chatTaskContext, $user_id, $chatPipelineFailure);
        } else {
            $chatTaskBridge->completeTurn($chatTaskContext, $user_id, (int)$assistant_id, (string)$reply_text, $model_id);
        }
    } catch (Throwable $taskError) {
        error_log('CHAT_TASK_BRIDGE_FINISH: ' . ChatTaskBridge::sanitizeError($taskError));
    }
}

if ($activityTraceId !== '') {
    activityEmit(
        $db_connection, $activityTraceId, $session_id, $user_id, 'respond',
        'trace_completed', 'completed', 'Respuesta terminada',
        'Pipeline completo: contexto, modelo, herramientas y guardado finalizaron.',
        [
            'assistant_message_id' => $assistant_id,
            'usage' => $usage,
            'memory_used' => (bool)($questionMemory['used'] ?? false),
            'memory_fragments' => (int)($questionMemory['fragments'] ?? 0),
            'memory_router' => $memoryRoute,
            'context_builder' => isset($contextBuilderPublic) ? $contextBuilderPublic : null,
            'memory_backfill' => $memoryBackfillPublic,
            'memory_writer' => $memoryWriterPublic,
            'pipeline_features' => ['configured'=>$pipelineConfigured, 'effective'=>$pipelineEffective],
            'project_memory_items' => isset($projectMemoryItems) ? count($projectMemoryItems) : 0,
            'notes' => $errors,
        ],
        $model_id,
        activityDurationMs($requestStartedAt)
    );
}

// ===== Salida =====
$out = [
  'ok'               => true,
  'trace_id'         => $activityTraceId !== '' ? $activityTraceId : null,
  'saved'            => ['user_text_id'=>$saved_user_text_id, 'file_ids'=>$file_ids, 'assistant_id'=>$assistant_id],
  'reply'            => $reply_text,
  'compiled_prompt'  => $compiled_prompt,
  'usage'            => $usage,
  'action'           => $action,
  'router'           => $router,
  'memory_router'    => $memoryRoute,
  'context_builder'  => isset($contextBuilderPublic) ? $contextBuilderPublic : null,
  'memory_backfill' => $memoryBackfillPublic,
  'memory_writer'   => $memoryWriterPublic,
  'pipeline_features' => ['configured'=>$pipelineConfigured, 'effective'=>$pipelineEffective],
  'project_memory_items' => isset($projectMemoryItems) ? $projectMemoryItems : [],
  'memory_used'      => (bool)($questionMemory['used'] ?? false),
  'memory_question_ids' => array_values($questionMemory['question_ids'] ?? []),
  'memory_block_ids' => array_values($questionMemory['block_ids'] ?? []),
  'memory_fragments' => (int)($questionMemory['fragments'] ?? 0),
  'memory_candidates'=> (int)($questionMemory['candidates'] ?? 0),
  'memory_reindex_queued' => (int)($questionMemory['reindex_queued'] ?? 0),
  'memory_scope'     => (string)($questionMemory['scope'] ?? $question_memory_scope),
  'memory_scope_kind'=> $memoryScope->kind(),
  'memory_scope_guard'=> $memoryScope->toArray(),
  'ai_runtime'       => aiRuntimeSnapshot()
];
if (!empty($errors)) $out['notes'] = $errors;
if ($chatTaskContext instanceof ChatTaskContext) {
    $out['task'] = [
        'public_id' => $chatTaskContext->publicId,
        'status' => $chatPipelineFailure instanceof Throwable ? 'failed' : 'completed',
    ];
}
jexit($out);
