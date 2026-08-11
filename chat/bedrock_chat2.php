<?php
putenv('AWS_EC2_METADATA_DISABLED=true'); // <- evita IMDS (169.254.169.254)

// bedrock_chat2.php — Chat directo a Amazon Bedrock (Converse)
// con soporte de adjuntos, OCR (Textract), RAG, Tool Use (Function Calling) y Metacognición (Fase 1)

// ===== Salida JSON y sesión =====
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

// ✅ ANTI-CICLO: debe coincidir con RECENT_WINDOW de compress_session_context.php.
// Es el umbral de bloques level_0 acumulados a partir del cual se marca la sesión
// como "pendiente de resumen" para que el cron la recoja.
define('SESSION_RECENT_WINDOW', 5);

// ===== Timeouts PHP =====
@ini_set('max_execution_time', '600');
@set_time_limit(600);
@ini_set('default_socket_timeout', '240');
@ignore_user_abort(true);

// ===== Acumulador de notas/errores =====
$errors = [];

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

function aws_credentials_or_throw(): array {
  $ak = getenv('AWS_ACCESS_KEY_ID') ?: (defined('Config::ACCESS_KEY') ? Config::ACCESS_KEY : '');
  $sk = getenv('AWS_SECRET_ACCESS_KEY') ?: (defined('Config::SECRET_KEY') ? Config::SECRET_KEY : '');
  $ak = is_string($ak) ? trim($ak) : '';
  $sk = is_string($sk) ? trim($sk) : '';
  if ($ak === '' || $sk === '') {
    throw new RuntimeException('Faltan credenciales AWS. Define AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY o Config::ACCESS_KEY/Config::SECRET_KEY.');
  }
  return ['key'=>$ak,'secret'=>$sk];
}

// ===== Función para calcular similitud coseno entre dos vectores (MySQL fallback) =====
function cosineSimilarity(array $vecA, array $vecB): float {
    $dotProduct = 0.0; $normA = 0.0; $normB = 0.0;
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
// ✅ RAG DE ARCHIVOS ADJUNTOS DE SESIÓN (helpers)
// =====================================================================
if (!defined('ATTACHMENT_EMBEDDING_MODEL')) define('ATTACHMENT_EMBEDDING_MODEL', 'amazon.titan-embed-text-v2:0');
if (!defined('ATTACHMENT_EMBEDDING_DIMENSIONS')) define('ATTACHMENT_EMBEDDING_DIMENSIONS', 1024);
if (!defined('ATTACHMENT_RAG_THRESHOLD')) define('ATTACHMENT_RAG_THRESHOLD', 0.25);
if (!defined('ATTACHMENT_RAG_RELATED_FILE_THRESHOLD')) define('ATTACHMENT_RAG_RELATED_FILE_THRESHOLD', 0.20);
if (!defined('ATTACHMENT_RAG_TOP')) define('ATTACHMENT_RAG_TOP', 4);
if (!defined('ATTACHMENT_RAG_MAX_CHARS')) define('ATTACHMENT_RAG_MAX_CHARS', 12000);

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
    function generateTitanEmbedding($bedrock, string $text, string $modelId): array {
        $embedRes = $bedrock->invokeModel([
            'modelId' => $modelId,
            'contentType' => 'application/json',
            'accept' => 'application/json',
            'body' => json_encode([
                'inputText' => mb_substr($text, 0, 8000),
                'dimensions' => ATTACHMENT_EMBEDDING_DIMENSIONS,
                'normalize' => true,
            ]),
        ]);

        $embedData = json_decode((string)$embedRes['body'], true);

        return [
            'embedding' => $embedData['embedding'] ?? [],
            'inputTokens' => (int)($embedData['inputTextTokenCount'] ?? 0),
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

if (!function_exists('buildSessionBaseContext')) {
    function buildSessionBaseContext(mysqli $db, int $sessionId, array $sessionData): string {
        if (!empty($sessionData['context_summary'])) {
            return (string)$sessionData['context_summary'];
        }

        $blocks = [];

        $stmt = $db->prepare("
            SELECT block_type, content_preview
            FROM SessionContextBlocks
            WHERE session_id_ = ?
              AND block_type NOT IN ('file', 'file_chunk')
            ORDER BY created_at ASC
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

        if (empty($blocks)) return '';

        $out = "=== CONTEXTO DE LA CONVERSACIÓN DE ESTA SESIÓN ===\n";

        foreach ($blocks as $idx => $block) {
            $out .= ($idx + 1) . ". " . mb_substr((string)($block['content_preview'] ?? ''), 0, 300) . "\n";
        }

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
        ?int $logMsgId = null
    ): string {
        $queryText = trim((string)$queryText);
        $blocks = [];

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

                if ($chars + mb_strlen($part) > ATTACHMENT_RAG_MAX_CHARS) {
                    break;
                }

                $out .= $part;
                $chars += mb_strlen($part);
            }

            return trim($out);
        }

        // =========================================================
        // MODO "rag": solo adjuntos relevantes
        // =========================================================
        if ($queryText === '') {
            return '';
        }

        $stmt = $db->prepare("
            SELECT id_, block_type, content_preview, s3_path, source_ids, embedding_json
            FROM SessionContextBlocks
            WHERE session_id_ = ?
              AND block_type IN ('file', 'file_chunk')
              AND embedding_json IS NOT NULL
            ORDER BY created_at ASC
            LIMIT 200
        ");

        if (!$stmt) return '';

        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $blocks[] = $row;
        }

        $stmt->close();

        if (empty($blocks)) return '';

        $queryVector = is_array($precomputedQueryVector) ? $precomputedQueryVector : [];

        if (empty($queryVector)) {
            try {
                $emb = generateTitanEmbedding($bedrock, $queryText, ATTACHMENT_EMBEDDING_MODEL);
                $queryVector = $emb['embedding'] ?? [];

                if (!empty($emb['inputTokens']) && $emb['inputTokens'] > 0) {
                    logTokenUsage(
                        $db,
                        $sessionId,
                        $logMsgId,
                        'rag',
                        ATTACHMENT_EMBEDDING_MODEL,
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

            if (!is_array($vec) || empty($vec)) {
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

        $chunks = [];
        $files = [];

        foreach ($scored as $row) {
            if ($row['block_type'] === 'file_chunk') {
                if ($row['score'] >= ATTACHMENT_RAG_THRESHOLD) {
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
                if (count($selected) >= ATTACHMENT_RAG_TOP) break;

                if (empty($file['s3_path']) || !isset($selectedPaths[(string)$file['s3_path']])) {
                    continue;
                }

                if ($file['score'] >= ATTACHMENT_RAG_RELATED_FILE_THRESHOLD) {
                    $selected[$file['id_']] = $file;
                }
            }
        }

        // 3) Completar con resúmenes relevantes si todavía hay espacio.
        foreach ($files as $file) {
            if (count($selected) >= ATTACHMENT_RAG_TOP) break;

            if (isset($selected[$file['id_']])) {
                continue;
            }

            if ($file['score'] >= ATTACHMENT_RAG_THRESHOLD) {
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

            if ($chars + mb_strlen($part) > ATTACHMENT_RAG_MAX_CHARS) {
                break;
            }

            $out .= $part;
            $chars += mb_strlen($part);
        }

        return trim($out);
    }
}

// =====================================================================
// ✅ DETECTOR DE CÓDIGO MULTI-LENGUAJE (agnóstico al lenguaje)
// Se usa tanto para elegir el modelo de resumen de memoria como para
// decidir si un texto debe tratarse como contenido técnico/código.
// Cubre palabras clave de PHP, JS/TS, Python, Java/Kotlin, C/C++/C#,
// Go, Rust, Ruby, Swift, SQL y shell, además de sintaxis genérica
// (llaves, punto y coma, indentación de bloques, extensiones de archivo).
// =====================================================================
function detectIsCode(string $text): bool {
    $patterns = [
        // Genérico / control de flujo común a casi todos los lenguajes
        '/\b(function|def|func|fn|class|interface|struct|enum|const|let|var|import|export|package|namespace|return|yield|if\s*\(|else\s*if|elif|for\s*\(|foreach|while\s*\(|switch\s*\(|match\s*\(|try\s*\{|catch\s*\(|except|throw|raise|async|await|public|private|protected|static|void|null|nil|None|true|false)\b/i',
        // Marcadores de sintaxis de apertura de bloque de código
        '/<\?php|=>|->|::|<div|<script|<style|#include|#!\/usr\/bin|using\s+System|SELECT\s+.+\s+FROM|INSERT\s+INTO|UPDATE\s+.+\s+SET|console\.log|System\.out\.println|print\s*\(/i',
        // Palabras relacionadas con desarrollo/depuración
        '/\b(c[oó]digo|archivo|script|variable|array|json|xml|yaml|endpoint|api|query|database|bd|bug|error|excepci[oó]n|stack\s*trace|compilar|compile|refactor|debug|framework|librer[ií]a|library|repositorio|repository|commit|merge|deploy)\b/i',
        // Extensiones de archivo típicas de código
        '/\.(php|js|jsx|ts|tsx|py|java|kt|go|rs|rb|c|cpp|cs|swift|sql|sh|html|css|json|yml|yaml)\b/i',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $text)) return true;
    }
    return false;
}

// =====================================================================
// ✅ SMART MEMORY: Resumir Q&A con IA ANTES de guardar en BD
// Detecta automáticamente si es código (de cualquier lenguaje) para usar
// Haiku (mayor precisión técnica), o Nova Micro para conversaciones
// normales (máximo ahorro).
// Retorna: ['text' => '...', 'inputTokens' => int, 'outputTokens' => int, 'model' => string]
// =====================================================================
function summarizeQAWithAI($bedrock, string $question, string $answer): array {
    // 1. Detección multi-lenguaje de si el Q&A es sobre código/programación
    $isCode = detectIsCode($question . ' ' . $answer);

    // 2. Seleccionar el modelo dinámicamente
    $modelId = $isCode ? 'anthropic.claude-3-5-haiku-20241022-v1:0' : 'amazon.nova-micro-v1:0';
    
    $systemPrompt = "Eres un motor de memoria inteligente. Resume la siguiente pregunta y respuesta en un bloque de conocimiento conciso (máximo 250 palabras).

REGLAS:
1. Detecta el TIPO de contenido y adapta el formato:
   - Si es PROGRAMACIÓN: incluye objetivo, solución técnica, archivos/funciones clave, decisiones y fragmentos de código relevantes.
   - Si es HISTORIA/CULTURA/CIENCIA: incluye tema, datos clave, personajes, fechas, lugares.
   - Si es TRIVIAL o SALUDO: resume en 1 línea.
2. REGLA CRÍTICA: NUNCA omitas valores de variables, rutas de archivos, puertos, IPs, nombres de funciones o credenciales mencionadas. Preserva los datos técnicos exactos (strings, números, rutas) intactos.
3. NO uses campos de programación para temas de cultura general.
4. No uses markdown, solo texto plano.
5. Responde en el mismo idioma que el contenido original.
6. Sé conciso pero técnicamente preciso.";

    $userPrompt = "PREGUNTA:\n" . mb_substr($question, 0, 4000) . "\n\nRESPUESTA:\n" . mb_substr($answer, 0, 6000) . "\n\nGenera el resumen:";

    try {
        $res = $bedrock->converse([
            'modelId' => $modelId,
            'messages' => [['role' => 'user', 'content' => [['text' => $userPrompt]]]],
            'system' => [['text' => $systemPrompt]],
            // Temperature 0.2 para forzar a la IA a ser factual y no "alucinar" omitiendo datos técnicos
            'inferenceConfig' => ['maxTokens' => 600, 'temperature' => 0.2, 'topP' => 0.9]
        ]);

        $text = '';
        foreach (($res['output']['message']['content'] ?? []) as $block) {
            if (isset($block['text'])) $text .= $block['text'];
        }

        return [
            'text' => trim($text) ?: mb_substr($answer, 0, 300),
            'inputTokens' => (int)($res['usage']['inputTokens'] ?? 0),
            'outputTokens' => (int)($res['usage']['outputTokens'] ?? 0),
            'model' => $modelId // Devolvemos el modelo usado para trazabilidad en costos
        ];
    } catch (Throwable $e) {
        return [
            'text' => mb_substr($answer, 0, 300),
            'inputTokens' => 0,
            'outputTokens' => 0,
            'model' => 'fallback'
        ];
    }
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
    
    $embedRes = $bedrockClient->invokeModel([
        'modelId' => 'amazon.titan-embed-text-v2:0',
        'contentType' => 'application/json', 'accept' => 'application/json',
        'body' => json_encode(['inputText' => mb_substr($query, 0, 8000)])
    ]);
    $queryVector = json_decode((string)$embedRes['body'], true)['embedding'] ?? [];
    if (empty($queryVector)) return json_encode(['error' => 'No se pudo generar el embedding']);

    $sql = "SELECT sc.id_ as chunk_id, sc.source_id_, sc.name, sc.content, ps.filename, ce.embedding_json 
            FROM SourceChunks sc JOIN ProjectSources ps ON ps.id_ = sc.source_id_ 
            JOIN ChunkEmbeddings ce ON ce.chunk_id_ = sc.id_ WHERE sc.project_id_ = ? LIMIT 100";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $scored = [];
    while ($row = $res->fetch_assoc()) {
        $dbVector = json_decode($row['embedding_json'], true);
        if (is_array($dbVector) && count($dbVector) > 0) {
            $score = cosineSimilarity($queryVector, $dbVector);
            if ($score > 0.35) {
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

        // 5. Marcar la fuente como desactualizada (stale)
        $upd = $db->prepare("UPDATE ProjectSources SET status = 'stale' WHERE id_ = ?");
        $upd->bind_param('i', $source_id);
        $upd->execute();
        $upd->close();

        // 6. ✅ NUEVO: Eliminar los chunks antiguos de este archivo para que la IA NO lea datos viejos
        $delChunks = $db->prepare("DELETE FROM SourceChunks WHERE source_id_ = ?");
        $delChunks->bind_param('i', $source_id);
        $delChunks->execute();
        $delChunks->close();

        // 7. ✅ NUEVO: Crear un job de embedding urgente para este archivo modificado
        $job_id = next_id($db, 'EmbeddingJobs', 'id_');
        $stmtJob = $db->prepare("INSERT INTO EmbeddingJobs (id_, target_type, target_id, model_id, status, attempts) VALUES (?, 'source_chunk', ?, 'amazon.titan-embed-text-v2:0', 'pending', 0)");
        $stmtJob->bind_param("ii", $job_id, $source_id);
        $stmtJob->execute();
        $stmtJob->close();

        // 8. Respuesta al frontend con señal de indexación
        return json_encode([
            'replacements' => $count, 
            'file' => $source['filename'], 
            'status' => 'marked_for_reindex',
            'needs_indexing' => true // <-- Señal clave para que el frontend dispare la indexación
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
            'indexed' => $data['indexed'] ?? false
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

// ===== AWS SDK cargado? =====
$aws_sdk_loaded = class_exists('Aws\\BedrockRuntime\\BedrockRuntimeClient') || class_exists('Aws\\Textract\\TextractClient');
if (!$aws_sdk_loaded) $errors[] = 'AWS SDK no está cargado (vendor/autoload.php). Revisa app_bootstrap.php';

// ===== Cargar SessionRetriever para Metacognición (Fase 4) =====
$retriever = null;
if (file_exists(__DIR__ . '/includes/SessionRetriever.php')) {
    require_once __DIR__ . '/includes/SessionRetriever.php';
    // Se inicializa con null en bedrock, la clase lo creará bajo demanda si se necesita
    $retriever = new SessionRetriever($db_connection, null); 
}

// ===== Parámetros =====
$session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
if ($session_id <= 0) jexit(['ok'=>false,'error'=>'session_id inválido'], 400);

$user_id = 0;
if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) $user_id = (int)$_SESSION['user_id'];
if (!$user_id && isset($_POST['user_id']) && is_numeric($_POST['user_id'])) $user_id = (int)$_POST['user_id'];
if (!$user_id && class_exists('Config') && defined('Config::DEFAULT_USER_ID')) $user_id = (int)Config::DEFAULT_USER_ID;
if (!$user_id) $user_id = 1;

$text = isset($_POST['text']) ? trim((string)$_POST['text']) : '';
// ✅ INICIALIZAR $compilation_id ANTES del debounce para evitar "Undefined variable"  
$compilation_id = isset($_POST['compilation_id']) ? (int)$_POST['compilation_id'] : 0;
// ========================================================================
// ✅ BANDERA ANTI-DOBLE ENVÍO (DEBOUNCE) PARA EVITAR REGISTROS DUPLICADOS
// CORREGIDO: NO se aplica en la Fase 2 (compilation_id > 0) porque es
// una continuación legítima del mismo flujo, no un doble envío.
// ========================================================================
if ($text !== '' && $compilation_id === 0) {
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
$auto = (isset($_POST['auto']) && (string)$_POST['auto'] === '1');

$model_id = isset($_POST['model']) ? trim((string)$_POST['model']) : '';
if ($model_id === '') jexit(['ok'=>false,'error'=>'Falta parámetro model'], 400);

$temperature = isset($_POST['temperature']) ? (float)$_POST['temperature'] : 0.7;
$max_tokens  = isset($_POST['max_tokens']) ? max(1,(int)$_POST['max_tokens']) : 1200;
$top_p       = isset($_POST['top_p']) ? (float)$_POST['top_p'] : 0.9;

// NUEVO: Parámetros para Human-in-the-loop (Fase 4)
$compile_only = isset($_POST['compile_only']) && $_POST['compile_only'] === '1';
$compiled_prompt_input = isset($_POST['compiled_prompt']) ? trim($_POST['compiled_prompt']) : '';
$compilation_id = isset($_POST['compilation_id']) ? (int)$_POST['compilation_id'] : 0;

// ===== Verificar sesión =====
$stmtS = $db_connection->prepare("SELECT id_, project_id_, meta FROM ChatSessions WHERE id_=?");
if(!$stmtS) jexit(['ok'=>false,'error'=>'Error preparando SELECT sesión: '.$db_connection->error],500);
$stmtS->bind_param('i', $session_id);
if(!$stmtS->execute()){ $e=$stmtS->error; $stmtS->close(); jexit(['ok'=>false,'error'=>'Error ejecutando SELECT sesión: '.$e],500); }
$resS = $stmtS->get_result();
if(!$resS || !$resS->num_rows){ $stmtS->close(); jexit(['ok'=>false,'error'=>'Sesión no encontrada'],404); }
$sessionData = $resS->fetch_assoc();
$projectId = (int)($sessionData['project_id_'] ?? 0);
$stmtS->close();
$attachmentMode = getSessionAttachmentMode($sessionData['meta'] ?? '');

// ✅ MEMORIA PROCEDURAL: Cargar patrones globales del usuario
$proceduralMemory = [];
$stmtPM = $db_connection->prepare("
    SELECT memory_type, content, confidence
    FROM UserProceduralMemory
    WHERE user_id_ = ? AND is_active = 1
    ORDER BY confidence DESC, updated_at DESC
    LIMIT 10
");
if ($stmtPM) {
    $stmtPM->bind_param('i', $user_id);
    $stmtPM->execute();
    $resPM = $stmtPM->get_result();
    while ($pmRow = $resPM->fetch_assoc()) {
        $proceduralMemory[] = $pmRow;
    }
    $stmtPM->close();
}

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

// ===== Guardar mensaje de usuario (texto) =====
// $saved_user_text_id = null; // Ya lo inicializamos arriba
$file_ids = [];


$contextTexts = [];
$ocrItems     = [];

// ✅ CORRECCIÓN: Solo guardar el mensaje de usuario en la Fase 1 (compile_only) 
// o si es un chat normal sin compilación ($compilation_id === 0).
// Esto evita que se guarde DUPLICADO en la Fase 2 (respuesta final).
if ($text !== '' && ($compile_only || $compilation_id === 0)) {
    $idM = next_id($db_connection, 'ChatMessages', 'id_');
    $role_user   = 'user';
    $ctype       = 'text';
    $content     = $text;
    $s3_key      = null; $mime = null; $size_bytes = null; $thumb_key=null; $duration_ms=null;
    $model_msg   = null; $stop_reason=null; $prompt_tok=null; $compl_tok=null; $latency_ms=null; $meta=null;
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

    $region = (class_exists('Config') && defined('Config::REGION') && Config::REGION) ? Config::REGION : 'us-east-1';
    $creds  = aws_credentials_or_throw();

    $textract = new Aws\Textract\TextractClient([
      'region'      => $region,
      'version'     => 'latest',
      'credentials' => $creds,
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


// ===== Contexto del proyecto y sesión (RAG Pre-fetch) =====
$systemPrompt = '';
$projectContext = [];
$sessionContext = '';

// ✅ A. CONTEXTO DEL PROYECTO (solo si la sesión tiene proyecto)
if (!empty($sessionData['project_id_'])) {
    $sqlProjectCtx = "SELECT type, title, content FROM ProjectContext WHERE project_id_ = ? ORDER BY created_at ASC";
    $stmtProjectCtx = $db_connection->prepare($sqlProjectCtx);
    if ($stmtProjectCtx) {
        $stmtProjectCtx->bind_param('i', $projectId);
        $stmtProjectCtx->execute();
        $resProjectCtx = $stmtProjectCtx->get_result();
        while ($ctx = $resProjectCtx->fetch_assoc()) {
            $projectContext[] = $ctx;
        }
        $stmtProjectCtx->close();
    }
}


// ✅ B eliminado: la memoria de sesión y adjuntos se construye una sola vez en la sección final.
$sessionContext = '';

// ✅ C. CONSTRUIR SYSTEM PROMPT BASE
$systemParts = [];
if (!empty($projectContext)) {
    $systemParts[] = "=== CONTEXTO DEL PROYECTO ===";
    foreach ($projectContext as $ctx) {
        $systemParts[] = "[{$ctx['type']}] " . ($ctx['title'] ? "{$ctx['title']}: " : "") . $ctx['content'];
    }
}
if (!empty($sessionContext)) {
    $systemParts[] = "=== MEMORIA DE ESTA SESIÓN (SOLO ESTA, NO OTRAS) ===";
    $systemParts[] = $sessionContext;
}
if (!empty($systemParts)) {
    $systemPrompt = implode("\n\n", $systemParts);
}


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
$usage = ['prompt_tokens'=>0, 'completion_tokens'=>0, 'total_tokens'=>0];

if ( ($text !== '' || !empty($contextTexts)) && $action === null) {
  try {
    if (!$aws_sdk_loaded || !class_exists('Aws\\BedrockRuntime\\BedrockRuntimeClient')) {
      throw new RuntimeException('AWS SDK no cargado (vendor/autoload.php).');
    }

    $region = (class_exists('Config') && defined('Config::REGION') && Config::REGION) ? Config::REGION : 'us-east-1';
    $creds  = aws_credentials_or_throw();

    $bedrock = new Aws\BedrockRuntime\BedrockRuntimeClient([
      'region'      => $region,
      'version'     => 'latest',
      'credentials' => $creds,
      'http'        => ['connect_timeout' => 20, 'timeout' => 240],
    ]);


    // ---------------------------------------------------------
    // 1. OBTENER INSTRUCCIONES Y CONTEXTO RAG DEL PROYECTO
    // ---------------------------------------------------------
    $projectInstructions = '';
    $ragContext = '';
    $primordialRules = ''; // ✅ Variable para reglas primordiales cross-session
    
    if ($projectId) {
        // A. Instrucciones generales del proyecto
        $stmtProj = $db_connection->prepare("SELECT meta FROM Projects WHERE id_ = ?");
        $stmtProj->bind_param('i', $projectId);
        $stmtProj->execute();
        $resProj = $stmtProj->get_result();
        if ($rowProj = $resProj->fetch_assoc()) {
            if (!empty($rowProj['meta'])) {
                $meta = json_decode($rowProj['meta'], true);
                if (isset($meta['instructions']) && !empty(trim($meta['instructions']))) {
                    $projectInstructions = "\n\n[INSTRUCCIONES OBLIGATORIAS DEL PROYECTO]\n" . trim($meta['instructions']) . "\n\nDebes seguir estas reglas estrictamente en tu respuesta. Si el usuario pide código, usa el lenguaje y versiones especificadas aquí.";
                }
            }
        }
        $stmtProj->close();

        // ✅ B. OBTENER REGLAS PRIMORDIALES DE CUALQUIER SESIÓN DEL PROYECTO (Metacognición Cross-Session)
        $sqlPrimordial = "SELECT cm.content, cm.created_at 
                  FROM ChatMessages cm 
                  JOIN ChatSessions cs ON cm.session_id_ = cs.id_ 
                  WHERE cs.project_id_ = ? 
                    AND cm.is_primordial = 1 
                    AND cm.role = 'assistant'
                    AND cs.status != 'archived'
                  ORDER BY cm.created_at ASC";
        $stmtPrimordial = $db_connection->prepare($sqlPrimordial);
        $stmtPrimordial->bind_param('i', $projectId);
        $stmtPrimordial->execute();
        $resPrimordial = $stmtPrimordial->get_result();
        $rules = [];
        while ($row = $resPrimordial->fetch_assoc()) {
            $rules[] = "- [" . date('Y-m-d H:i', strtotime($row['created_at'])) . "] " . $row['content'];
        }
        $stmtPrimordial->close();
        
        if (!empty($rules)) {
            $primordialRules = "\n\n[REGLAS PRIMORDIALES DEL PROYECTO (VERDAD ABSOLUTA)]\nEl usuario ha establecido estas reglas en sesiones anteriores. DEBES obedecerlas estrictamente por encima de cualquier otra lógica o conocimiento general:\n" . implode("\n", $rules);
            
            // Inyectamos las reglas primordiales directamente en las instrucciones del proyecto
            $projectInstructions .= $primordialRules;
        }

        // ✅ C. Contexto RAG (Embeddings) - TU CÓDIGO ORIGINAL INTACTO
        if ($text !== '') {
            $embedModel = 'amazon.titan-embed-text-v2:0';
            $embedBody = json_encode(['inputText' => mb_substr($text, 0, 8000)]);
            $embedRes = $bedrock->invokeModel([
                'modelId' => $embedModel,
                'contentType' => 'application/json',
                'accept' => 'application/json',
                'body' => $embedBody
            ]);
            
            $embedData = json_decode((string)$embedRes['body'], true);
            $queryVector = $embedData['embedding'] ?? [];

            if (!empty($queryVector)) {
                $sqlChunks = "SELECT sc.id_, sc.content, sc.name, ce.embedding_json 
                              FROM SourceChunks sc
                              JOIN ChunkEmbeddings ce ON ce.chunk_id_ = sc.id_
                              WHERE sc.project_id_ = ?
                              LIMIT 150";
                $stmtChunks = $db_connection->prepare($sqlChunks);
                $stmtChunks->bind_param('i', $projectId);
                $stmtChunks->execute();
                $resChunks = $stmtChunks->get_result();
                
                $scoredChunks = [];
                while ($chunk = $resChunks->fetch_assoc()) {
                    $dbVector = json_decode($chunk['embedding_json'], true);
                    if (is_array($dbVector) && count($dbVector) > 0) {
                        $dotProduct = 0.0; $normA = 0.0; $normB = 0.0;
                        $count = min(count($queryVector), count($dbVector));
                        for ($i = 0; $i < $count; $i++) {
                            $dotProduct += $queryVector[$i] * $dbVector[$i];
                            $normA += $queryVector[$i] * $queryVector[$i];
                            $normB += $dbVector[$i] * $dbVector[$i];
                        }
                        $score = ($normA == 0 || $normB == 0) ? 0.0 : $dotProduct / (sqrt($normA) * sqrt($normB));
                        $scoredChunks[] = ['score' => $score, 'name' => $chunk['name'], 'content' => $chunk['content']];
                    }
                }
                $stmtChunks->close();

                usort($scoredChunks, function($a, $b) { return $b['score'] <=> $a['score']; });
                $topChunks = array_slice($scoredChunks, 0, 4);

                if (!empty($topChunks)) {
                    $ragContext = "\n\n[CONTEXTO DE TUS ARCHIVOS INDEXADOS]\n";
                    $foundCount = 0;
                    foreach ($topChunks as $idx => $tc) {
                        if ($tc['score'] > 0.3) {
                            $ragContext .= "--- Fragmento " . ($idx + 1) . " (Archivo: " . $tc['name'] . ", Similitud: " . round($tc['score'], 2) . ") ---\n";
                            $ragContext .= $tc['content'] . "\n\n";
                            $foundCount++;
                        }
                    }
                    if ($foundCount > 0) {
                        $ragContext .= "\n[DIAGNÓSTICO: Se encontraron $foundCount fragmentos relevantes en tus archivos. Úsalos para responder.]\n";
                    } else {
                        $ragContext .= "\n[DIAGNÓSTICO: No se encontraron fragmentos con similitud > 0.3. Responde con tu conocimiento general siguiendo las instrucciones.]\n";
                    }
                }
            }
        }
    }

    


/*
    // Opción 2: Nova Micro (ultra-rápido y barato, de Amazon)
    $compiler_model = 'amazon.nova-micro-v1:0';

    $compiler_model = 'meta.llama3-1-70b-instruct-v1:0';
    $compiler_model = 'amazon.nova-pro-v1:0'; //  amazon.nova-lite-v1:0   amazon.nova-micro-v1:0
    $compiler_model = 'anthropic.claude-sonnet-4-5-20250929-v1:0';
    // ✅ Compilador experto en seguir instrucciones (rápido y preciso)
    $compiler_model = 'anthropic.claude-3-5-haiku-20241022-v1:0';
*/
 
// ---------------------------------------------------------
// 1.5. COMPILACIÓN DEL PROMPT (Sanitizador y Optimizador con Haiku)
// ---------------------------------------------------------
$compiled_prompt = $text; 
$compiler_model = 'amazon.nova-micro-v1:0';

// ✅ CORRECCIÓN CRÍTICA: Si el frontend ya envió un prompt aprobado (Fase 2), 
// NO volvemos a llamar a Haiku ni a guardar en la BD. Usamos el que aprobó el usuario.
if ($compilation_id > 0 && isset($_POST['compiled_prompt']) && trim($_POST['compiled_prompt']) !== '') {
    $compiled_prompt = trim($_POST['compiled_prompt']);
} elseif (trim($text) !== '') {
    // Solo compilamos si es la Fase 1 (compile_only) o un chat normal sin compilación previa.
    try {
$compilerSystemPrompt = "Eres un Ingeniero de Prompts experto. Tu ÚNICA tarea es transformar la entrada del usuario en una instrucción perfecta, clara y enriquecida para un modelo de IA avanzado.

REGLAS OBLIGATORIAS:
1. NUNCA repitas la pregunta del usuario tal cual. Debes REESCRIBIRLA como una instrucción directa a la IA.
2. Corrige automáticamente CUALQUIER error ortográfico, gramatical o de tipeo en nombres o conceptos.
3. Añade contexto profesional para garantizar la mejor respuesta posible.
4. Devuelve ÚNICAMENTE el texto de la instrucción optimizada. Sin markdown, sin comillas.
5. PROHIBIDO mencionar 'la sesión', 'el contexto de la sesión', 'esta conversación' o 'lo que hemos hablado' en el prompt generado. NUNCA agregues frases como 'en el contexto de la sesión actual'. El prompt debe ser una instrucción limpia y directa.
6. PREGUNTAS META-COGNITIVAS: SOLO si el usuario pregunta EXPLÍCITAMENTE sobre la conversación misma (ej: '¿qué te he preguntado?', '¿de qué hemos hablado?', 'resume la sesión'), genera un prompt que pida un resumen de los temas tratados. Para CUALQUIER otra pregunta (historia, ciencia, programación, seguimiento de un tema), genera una instrucción de conocimiento general normal.
7. INTENCIÓN ORIGINAL: Respeta SIEMPRE la intención del usuario. Si pregunta sobre Colón, genera un prompt sobre Colón. Si pregunta sobre código, genera un prompt sobre código. NUNCA cambies el tipo de respuesta que el usuario espera.";

$compilerUserPrompt = "Entrada del usuario: \"{$text}\"

Tarea: Transforma esta entrada en una instrucción experta, corregida y enriquecida para una IA, siguiendo estrictamente las reglas. Si la entrada es una pregunta sobre la conversación misma (meta-cognitiva), genera una instrucción que pida resumir los temas tratados, NO una pregunta enciclopédica.";

// ✅ CONSTRUIR CONTEXTO PARA EL COMPILADOR
$compilerContext = '';

// A. Contexto del proyecto (instrucciones)
$compilerContext .= "Contexto del proyecto: " . ($projectInstructions ?: "Ninguno") . "\n";

// B. Últimos mensajes de la conversación (para entender preguntas de seguimiento)
$recentMsgs = [];
$stmtRecent = $db_connection->prepare("
    SELECT role, content 
    FROM ChatMessages 
    WHERE session_id_ = ? AND role IN ('user', 'assistant') AND content IS NOT NULL AND content != ''
    ORDER BY id_ DESC 
    LIMIT 6
");
if ($stmtRecent) {
    $stmtRecent->bind_param('i', $session_id);
    $stmtRecent->execute();
    $resRecent = $stmtRecent->get_result();
    while ($rowRecent = $resRecent->fetch_assoc()) {
        $recentMsgs[] = $rowRecent;
    }
    $stmtRecent->close();
}

if (!empty($recentMsgs)) {
    $recentMsgs = array_reverse($recentMsgs); // Orden cronológico
    $compilerContext .= "\nÚLTIMOS MENSAJES DE LA CONVERSACIÓN (para entender el contexto):\n";
    foreach ($recentMsgs as $rm) {
        $roleLabel = ($rm['role'] === 'user') ? 'USUARIO' : 'ASISTENTE';
        $compilerContext .= "[$roleLabel]: " . mb_substr($rm['content'], 0, 200) . "\n";
    }
}

// C. Contexto de sesión (SessionRetriever - memoria a largo plazo)
if (isset($retriever)) {
    $sessionRetrieval = $retriever->retrieve($session_id, $text, $projectId);
    $formattedSessionContext = $retriever->formatContextForCompiler($sessionRetrieval);
    if (!empty($formattedSessionContext)) {
        $compilerContext .= "\nMemoria de sesión: " . $formattedSessionContext . "\n";
    }
}

$compilerUserPrompt = $compilerContext . "\n" . $compilerUserPrompt;

        $compilerParams = [
            'modelId' => $compiler_model,
            'messages' => [['role' => 'user', 'content' => [['text' => $compilerUserPrompt]]]],
            'system' => [['text' => $compilerSystemPrompt]],
            'inferenceConfig' => ['maxTokens' => 1000, 'temperature' => 0.7, 'topP' => 0.9]
        ];
        
        $compilerRes = $bedrock->converse($compilerParams);
        $compilerBlocks = $compilerRes['output']['message']['content'] ?? [];
        $compilerUsage = $compilerRes['usage'] ?? [];
        
        $compilerInput = (int)($compilerUsage['inputTokens'] ?? 0);
        $compilerOutput = (int)($compilerUsage['outputTokens'] ?? 0);

        foreach ($compilerBlocks as $block) {
            if (isset($block['text'])) {
                $compiled_prompt = trim($block['text']);
                $compiled_prompt = preg_replace('/^```(?:json|text)?\s*/i', '', $compiled_prompt);
                $compiled_prompt = preg_replace('/\s*```$/i', '', $compiled_prompt);
                $compiled_prompt = trim($compiled_prompt, "\"' \n\r");
                
                similar_text($text, $compiled_prompt, $percent);
                if ($percent > 90 || $compiled_prompt === $text) {
                    error_log("⚠️ WARNING: Haiku output too similar to input ({$percent}%). Applying direct enrichment fallback.");
                    $compiled_prompt = "Actúa como un experto en la materia. Proporciona una respuesta muy detallada, estructurada y completa sobre: \"{$text}\". Asegúrate de corregir cualquier error ortográfico o de tipeo en la consulta original y añade todo el contexto necesario.";
                }
                break;
            }
        }

        // ========================================================================
        // PASO 2: REGISTRAR EN BASE DE DATOS (Solo en Fase 1)
        // ========================================================================
        if ($compilerInput > 0 || $compilerOutput > 0) {
            try {
                $compiler_msg_id = next_id($db_connection, 'ChatMessages', 'id_');
                $session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
                $role_compiler = 'system'; 
                $ctypeA = 'text'; 
                $contentA = $compiled_prompt; 
                $s3_key = null; $mime = null; $size_bytes = null; $thumb_key = null; $duration_ms = null;
                $model_msg = $compiler_model; $stop_reason = null; 
                $prompt_tok = $compilerInput; $compl_tok = $compilerOutput; 
                $latency_ms = null; $meta = null; $is_primordial = 0; $phase = 'compile'; 
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
$tcPhase = 'compile'; $tcModel = $compiler_model;
$tcCost = ($compilerInput / 1000 * 0.000035) + ($compilerOutput / 1000 * 0.00014);
$tcDuration = 117;
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
        }
    } catch (Throwable $e) {
        $errors[] = 'Compilación de prompt fallida: ' . $e->getMessage();
        $compiled_prompt = "Actúa como un experto en la materia. Proporciona una respuesta muy detallada, estructurada y completa sobre: \"{$text}\". Asegúrate de corregir cualquier error ortográfico o de tipeo en la consulta original y añade todo el contexto necesario.";
    }
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
    
    jexit([
        'ok' => true,
        'phase' => 'compile_only',
        'compilation_id' => $compilationId,
        'compiled_prompt' => $compiled_prompt,
        'usage' => $usage,
    ]);
}

// ---------------------------------------------------------
// 1.7. MODO RESPUESTA FINAL: Usar prompt compilado aprobado
// ---------------------------------------------------------
if ($compilation_id > 0 && isset($_POST['compiled_prompt']) && trim($_POST['compiled_prompt']) !== '') {
    $compiled_prompt_input = trim($_POST['compiled_prompt']);
    
    // 1. Actualizar el estado de la compilación
    $stmtUpd = $db_connection->prepare("UPDATE PromptCompilations SET status = 'approved', was_edited_by_user = 1 WHERE id_ = ? AND session_id_ = ?");
    if ($stmtUpd) {
        $stmtUpd->bind_param("ii", $compilation_id, $session_id);
        $stmtUpd->execute();
        $stmtUpd->close();
    }
    
    $compiled_prompt = $compiled_prompt_input;

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
    $systemPrompt = "Eres un asistente de IA experto en programación y conocimiento general. Responde de manera directa, útil y precisa en español.";

// ✅ INYECTAR MEMORIA PROCEDURAL (patrones del usuario)
if (!empty($proceduralMemory)) {
    $systemPrompt .= "\n\n[PATRONES Y PREFERENCIAS DEL USUARIO - MEMORIA PROCEDURAL]\n";
    $systemPrompt .= "El usuario ha establecido estos patrones a lo largo de sus conversaciones. DEBES seguirlos en TODAS tus respuestas:\n";
    foreach ($proceduralMemory as $idx => $pm) {
        $typeLabel = [
            'rule' => 'REGLA',
            'preference' => 'PREFERENCIA',
            'correction' => 'CORRECCIÓN',
            'workflow' => 'FLUJO DE TRABAJO',
            'pattern' => 'PATRÓN'
        ][$pm['memory_type']] ?? 'REGLA';

        $systemPrompt .= ($idx + 1) . ". [{$typeLabel}] " . $pm['content'] . "\n";
    }
    $systemPrompt .= "Estos patrones tienen prioridad sobre tu comportamiento por defecto.\n";
}

// ====================================================================
// ✅ MEMORIA DE SESIÓN + RAG DE ADJUNTOS (UNA SOLA LECTURA)
// ====================================================================

// 1) Memoria conversacional normal (sin file/file_chunk)
$sessionMemory = buildSessionBaseContext($db_connection, $session_id, $sessionData);

// 2) Texto de consulta para RAG de adjuntos
$attachmentQueryText = trim((string)$text);
if ($attachmentQueryText === '' && isset($compiled_prompt) && trim((string)$compiled_prompt) !== '') {
    $attachmentQueryText = trim((string)$compiled_prompt);
}

// 3) Vector de pregunta: reutilizar si ya existe (RAG de proyecto), o generarlo aquí
$precomputedQueryVector = (isset($queryVector) && is_array($queryVector) && !empty($queryVector))
    ? $queryVector
    : null;

if (empty($precomputedQueryVector) && $attachmentQueryText !== '') {
    try {
        $embData = generateTitanEmbedding($bedrock, $attachmentQueryText, ATTACHMENT_EMBEDDING_MODEL);
        if (!empty($embData['embedding'])) {
            $precomputedQueryVector = $embData['embedding'];
            
            if (!empty($embData['inputTokens']) && $embData['inputTokens'] > 0) {
                $attachmentLogMsgId = getValidMessageId($db_connection, $saved_user_text_id, $session_id);
                logTokenUsage(
                    $db_connection,
                    $session_id,
                    $attachmentLogMsgId,
                    'rag',
                    ATTACHMENT_EMBEDDING_MODEL,
                    (int)$embData['inputTokens'],
                    0
                );
            }
        }
    } catch (Throwable $e) {
        error_log('Error generando embedding para RAG de adjuntos: ' . $e->getMessage());
    }
}

// 4) Message ID válido para TokenUsage
$attachmentLogMsgId = getValidMessageId($db_connection, $saved_user_text_id, $session_id);

// 5) Construir contexto de adjuntos (modo rag o always)
$attachmentContext = buildSessionAttachmentContext(
    $db_connection,
    $bedrock,
    $session_id,
    $attachmentQueryText,
    $attachmentMode,
    $precomputedQueryVector,
    $attachmentLogMsgId
);

// 6) Inyectar memoria conversacional
if (!empty($sessionMemory)) {
    $systemPrompt .= "\n[MEMORIA DE ESTA SESIÓN - SOLO ESTA, NO OTRAS]\n";
    $systemPrompt .= "Si pregunta '¿qué he preguntado?' o '¿de qué hemos hablado?', responde EXCLUSIVAMENTE basándote en esta memoria. No inventes temas:\n";
    $systemPrompt .= $sessionMemory;
}

// 7) Inyectar adjuntos solo si hubo contexto relevante
if (!empty($attachmentContext)) {
    $systemPrompt .= "\n[ARCHIVOS ADJUNTOS DE ESTA SESIÓN]\n";
    $systemPrompt .= "El siguiente contenido proviene de archivos adjuntos reales de esta conversación. Úsalo solo si es relevante para la pregunta actual.\n";
    $systemPrompt .= $attachmentContext;
}

    // ✅ INSTRUCCIONES DEL PROYECTO (solo si la sesión tiene proyecto)
    if (!empty($projectInstructions)) {
        $systemPrompt .= "\n\n[INSTRUCCIONES DEL PROYECTO]\n" . $projectInstructions;
    }
    
$systemPrompt .= "

[REGLA CRÍTICA DE HERRAMIENTAS - OBLIGATORIA]
Cuando el usuario solicite CREAR, MODIFICAR, EDITAR o GUARDAR un archivo de código en el proyecto, DEBES usar OBLIGATORIAMENTE la herramienta 'code_edit' con action='write' (o sin 'action', es el valor por defecto).
Cuando el usuario pida VER, LEER o mostrar el contenido REAL/actual de un archivo del proyecto (no lo que tú recuerdes), usa 'code_edit' con action='read'.
Cuando el usuario pida ELIMINAR, BORRAR o quitar un archivo del proyecto, usa 'code_edit' con action='delete'.
NUNCA respondas con el código directamente en el chat si la instrucción implica crear, modificar, leer o eliminar un archivo real del proyecto: siempre usa la herramienta.
Parámetros requeridos: project_id, session_id, target_filename (y 'instruction' solo cuando action='write').
";

    // ✅ REGLAS PRIMORDIALES (Cross-Session, solo sesiones activas)
    if (!empty($primordialRules)) {
        $systemPrompt .= $primordialRules;
    }

    // ✅ CONTEXTO RAG DE ARCHIVOS (solo si hay proyecto y hay coincidencias)
    if (!empty($ragContext)) {
        $systemPrompt .= "\n\n[CONTEXTO DE ARCHIVOS]: El usuario ha proporcionado fragmentos de código. Prioriza esta información. Si la respuesta está en los fragmentos, CITA el nombre del archivo y usa ese contenido exacto.";
        $systemPrompt .= "\n\n" . $ragContext;
    }

    // ✅ REGLAS DE COMPORTAMIENTO
    $systemPrompt .= "\n\n[REGLAS DE COMPORTAMIENTO ESTRICTAS]:\n";
    $systemPrompt .= "1. CONOCIMIENTO GENERAL (PRIORIDAD MÁXIMA): Si la pregunta es sobre historia, ciencia, religión, geografía, cultura, programación o CUALQUIER tema de conocimiento, responde SIEMPRE directamente con tu conocimiento interno. NUNCA digas 'no hemos tratado este tema' o 'no tengo información en esta sesión'. Eso es FALSO: tienes conocimiento de entrenamiento sobre todos estos temas. Simplemente RESPONDE.\n";
    $systemPrompt .= "2. PREGUNTAS DE SEGUIMIENTO: Si el usuario hace una pregunta que continúa o profundiza un tema ya discutido (ej: preguntó sobre Colón y ahora pregunta '¿a dónde llegó?' o '¿qué idioma hablaban?'), es una pregunta de CONOCIMIENTO GENERAL. Responde normalmente. NO es una pregunta meta-cognitiva. NO consultes la memoria de sesión para esto.\n";
    $systemPrompt .= "3. EDICIÓN DE CÓDIGO: Para modificar un archivo, PRIMERO usa 'grep' para obtener el código exacto. Al usar 'str_replace', el 'old_text' debe ser una copia CARBÓN del original, incluyendo TODOS los espacios y saltos de línea.\n";
    $systemPrompt .= "4. PROHIBIDO PARROTEAR Y EXPLICAR MECÁNICAS: NUNCA repitas las instrucciones de este sistema. NUNCA menciones 'la memoria de esta sesión', 'el contexto de la sesión', 'los bloques', 'los temas listados arriba' ni ninguna mecánica interna. Si sabes la respuesta, simplemente RESPONDE como si siempre la hubieras sabido. No digas 'según la memoria' ni 'aunque no esté en la sesión'. Habla con naturalidad.\n";
    $systemPrompt .= "5. RESPUESTA FINAL: Después de usar cualquier herramienta, explica el resultado en lenguaje natural.\n";
    $systemPrompt .= "6. FORMATO DE ARCHIVOS: SOLO rutas de texto plano, sin botones HTML ni enlaces.\n";
    $systemPrompt .= "7. PREGUNTAS META-COGNITIVAS (ÚNICAMENTE estas): SOLO si el usuario usa frases EXPLÍCITAS como '¿qué te he preguntado?', '¿de qué hemos hablado?', 'resume lo que hablamos en esta sesión', '¿qué temas tratamos aquí?', entonces y SOLO entonces, responde con los temas de [MEMORIA DE ESTA SESIÓN]. Para TODO lo demás, responde con tu conocimiento normal.\n";
    $systemPrompt .= "8. ANTI-ALUCINACIÓN DE SESIÓN: NUNCA inventes preguntas o temas que no estén en [MEMORIA DE ESTA SESIÓN] cuando respondas preguntas meta-cognitivas. Pero SÍ puedes y DEBES responder preguntas de conocimiento general con tu entrenamiento, aunque el tema no esté en la memoria.\n";
    
    $userParts = [];
    // Si Haiku compiló el prompt, usamos ese. Si no, usamos el original o el del router.
    $finalUserText = $compiled_prompt ?: ($router['improved_prompt'] ?: $text);
    
    if ($finalUserText !== '') {
        $userParts[] = ['text' => $finalUserText];
    } elseif (!empty($contextTexts)) {
        $userParts[] = ['text' => 'Analiza los archivos adjuntos y respóndeme en español.'];
    }
    foreach ($contextTexts as $ctx) $userParts[] = ['text' => $ctx];

    $messages = [ [ 'role' => 'user', 'content' => $userParts ] ];
    $inferBase = ['maxTokens' => $max_tokens, 'temperature' => $temperature, 'topP' => $top_p];

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
if ($projectId > 0) {
    $converseParams['toolConfig'] = ['tools' => $tools];
}

    // ===== BUCLE DE TOOL USE (Máximo 3 iteraciones) =====
    $maxRounds = 5;
    $round = 0;
    $stopReason = null;
    $anyToolFailed = false;
    $lastToolError = '';

    // ✅ Liberamos el lock del archivo de sesión ANTES de entrar al bucle: el tool
    // 'code_edit' hace una petición HTTP interna hacia code_edit.php que también
    // necesita abrir esta misma sesión (para leer $_SESSION['user_id']). Si no la
    // cerramos aquí, esa petición interna queda bloqueada esperando el lock hasta
    // agotar su propio timeout, y el archivo nunca llega a crearse/editarse.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    while ($round < $maxRounds) {
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

        if ($stopReason === 'tool_use' && !empty($toolRequests)) {
            $assistantMessage = $res['output']['message'];
            $converseParams['messages'][] = $assistantMessage;
            
            $toolResults = [];
            foreach ($toolRequests as $tool) {
                $toolName = $tool['name'];
                $toolUseId = $tool['toolUseId'];
                $args = $tool['input'] ?? [];

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
                if (is_array($decodedResult) && !empty($decodedResult['error'])) {
                    $anyToolFailed = true;
                    $lastToolError = (string)$decodedResult['error'];
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
    $reply_text = '✔️ Recibido. (No pude contactar Bedrock: '.$e->getMessage().')';
  }

}

// ===== Guardar respuesta del asistente =====
$assistant_id = next_id($db_connection,'ChatMessages','id_');
$role_assistant = 'assistant'; $ctypeA='text'; $contentA=$reply_text;
$s3_key=null; $mime=null; $size_bytes=null; $thumb_key=null; $duration_ms=null;
$model_msg=$model_id; $stop_reason=null; $prompt_tok=$usage['prompt_tokens']; $compl_tok=$usage['completion_tokens']; $latency_ms=null; $meta=null;

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

// ====================================================================
// ✅ METACOGNICIÓN FASE 1 - SMART MEMORY (Única versión, sin duplicados)
// Nova Micro resume el Q&A ANTES de guardar en SessionContextBlocks.
// ====================================================================
try {
    $question_msg_for_block = $saved_user_text_id ?: (isset($file_ids[0]) ? $file_ids[0] : null);
    $block_id = next_id($db_connection, 'SessionContextBlocks', 'id_');
    
    // ✅ SMART MEMORY: Nova Micro resume el Q&A de forma inteligente
    $summaryData = summarizeQAWithAI($bedrock, $text, $reply_text);
    $preview = $summaryData['text'];
    $token_count = (int)ceil(mb_strlen($preview) / 4);
    
    $sqlBlock = "INSERT INTO SessionContextBlocks (
        id_, session_id_, block_type, question_msg_id, answer_msg_id,
        content_preview, is_locked, token_count
    ) VALUES (?, ?, 'level_0', ?, ?, ?, 0, ?)";
    
    $stmtBlock = $db_connection->prepare($sqlBlock);
    if ($stmtBlock) {
        $stmtBlock->bind_param("iiissi", $block_id, $session_id, $question_msg_for_block, $assistant_id, $preview, $token_count);
        
        // ✅ CORRECCIÓN CRÍTICA: Verificar que el INSERT fue exitoso
        if ($stmtBlock->execute()) {
            $affectedRows = $stmtBlock->affected_rows;
            $stmtBlock->close();
            
            // ✅ SOLO crear el job si el bloque se insertó correctamente
            if ($affectedRows > 0) {
                $job_id = next_id($db_connection, 'EmbeddingJobs', 'id_');
                $sqlJob = "INSERT INTO EmbeddingJobs (
                    id_, target_type, target_id, model_id, status, attempts
                ) VALUES (?, 'session_block', ?, 'amazon.titan-embed-text-v2:0', 'pending', 0)";
                $stmtJob = $db_connection->prepare($sqlJob);
                if ($stmtJob) {
                    $stmtJob->bind_param("ii", $job_id, $block_id);
                    $stmtJob->execute();
                    $stmtJob->close();
                }

                // ✅ ANTI-CICLO: marcar la sesión como "pendiente de resumen" solo
                // cuando ya se acumularon más de SESSION_RECENT_WINDOW bloques level_0.
                // El cron (compress_session_context.php) lee este flag en vez de hacer
                // JOIN+GROUP BY+COUNT sobre todas las sesiones abiertas, y lo vuelve a
                // poner en 0 después de procesarla.
                try {
                    $stmtCountL0 = $db_connection->prepare(
                        "SELECT COUNT(*) AS c FROM SessionContextBlocks WHERE session_id_ = ? AND block_type = 'level_0'"
                    );
                    if ($stmtCountL0) {
                        $stmtCountL0->bind_param('i', $session_id);
                        $stmtCountL0->execute();
                        $rowL0 = $stmtCountL0->get_result()->fetch_assoc();
                        $stmtCountL0->close();
                        $level0Count = (int)($rowL0['c'] ?? 0);

                        if ($level0Count > SESSION_RECENT_WINDOW) {
                            $stmtFlag = $db_connection->prepare(
                                "UPDATE ChatSessions SET pending_summary = 1 WHERE id_ = ?"
                            );
                            if ($stmtFlag) {
                                $stmtFlag->bind_param('i', $session_id);
                                $stmtFlag->execute();
                                $stmtFlag->close();
                            }
                        }
                    }
                } catch (Throwable $e) {
                    error_log('⚠️ No se pudo marcar pending_summary: ' . $e->getMessage());
                }
            } else {
                error_log("⚠️ SessionContextBlocks INSERT afectó 0 filas para block_id=$block_id");
            }
        } else {
            $stmtBlock->close();
            error_log("❌ Error insertando SessionContextBlocks: " . $stmtBlock->error);
        }
    }
    
// ✅ REGISTRAR COSTO DEL RESUMEN EN TokenUsage
if (($summaryData['inputTokens'] ?? 0) > 0 || ($summaryData['outputTokens'] ?? 0) > 0) {
    $sumMsgId = getValidMessageId($db_connection, $assistant_id, $session_id);
    $sumTcId = next_id($db_connection, 'TokenUsage', 'id_');
    $sumPhase = 'compile';
    
    // ✅ USAR EL MODELO REAL DETECTADO POR LA FUNCIÓN
    $sumModel = $summaryData['model'] ?? 'amazon.nova-micro-v1:0'; 
    
    // Calcular costo según el modelo real usado
    $isHaiku = stripos($sumModel, 'haiku') !== false;
    if ($isHaiku) {
        // Precios Claude 3.5 Haiku (aprox)
        $sumCost = (($summaryData['inputTokens'] ?? 0) / 1000 * 0.00025) + (($summaryData['outputTokens'] ?? 0) / 1000 * 0.00125);
    } else {
        // Precios Nova Micro (aprox)
        $sumCost = (($summaryData['inputTokens'] ?? 0) / 1000 * 0.000035) + (($summaryData['outputTokens'] ?? 0) / 1000 * 0.00014);
    }
    
    $sumDuration = 0;
    $sqlSumTC = "INSERT INTO TokenUsage (id_, session_id_, message_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmtSumTC = $db_connection->prepare($sqlSumTC);
    if ($stmtSumTC) {
        $stmtSumTC->bind_param("iiissiddi", $sumTcId, $session_id, $sumMsgId, $sumPhase, $sumModel, $summaryData['inputTokens'], $summaryData['outputTokens'], $sumCost, $sumDuration);
        $stmtSumTC->execute();
        $stmtSumTC->close();
    }
}

} catch (Throwable $e) {
    $errors[] = 'Metacognición (Smart Memory): ' . $e->getMessage();
}


// ====================================================================
// ✅ REGISTRAR USO DE TOKENS DE LA RESPUESTA PRINCIPAL EN TokenUsage
// ====================================================================
try {
    $tuInput = (int)($usage['prompt_tokens'] ?? 0);
    $tuOutput = (int)($usage['completion_tokens'] ?? 0);
    
    $tuId = next_id($db_connection, 'TokenUsage', 'id_');
    $tuPhase = 'respond';
    $tuModel = $model_id ?: 'unknown_model';
    
    // ✅ CORREGIDO: Validar que el message_id exista en ChatMessages
    // Si no existe, busca el último de la sesión. Si tampoco, usa NULL.
    // NUNCA usa 0 porque viola la FK fk_tu_message.
    $tuMsgId = getValidMessageId($db_connection, $assistant_id, $session_id);
    $tuDuration = 0;
    
    $costPer1kInput = 0.000035;
    $costPer1kOutput = 0.00014;
    if (strpos($model_id, 'sonnet') !== false) {
        $costPer1kInput = 0.003; $costPer1kOutput = 0.015;
    } elseif (strpos($model_id, 'opus') !== false) {
        $costPer1kInput = 0.015; $costPer1kOutput = 0.075;
    } elseif (strpos($model_id, 'haiku') !== false) {
        $costPer1kInput = 0.00025; $costPer1kOutput = 0.00125;
    }
    $estimatedCost = ($tuInput / 1000 * $costPer1kInput) + ($tuOutput / 1000 * $costPer1kOutput);
    
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


// ===== Salida =====
$out = [
  'ok'               => true,
  'saved'            => ['user_text_id'=>$saved_user_text_id, 'file_ids'=>$file_ids, 'assistant_id'=>$assistant_id],
  'reply'            => $reply_text,
  'compiled_prompt'  => $compiled_prompt,
  'usage'            => $usage,
  'action'           => $action,
  'router'           => $router
];
if (!empty($errors)) $out['notes'] = $errors;
jexit($out);