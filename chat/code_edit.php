<?php
/**
 * code_edit.php
 * 
 * Maneja la edición quirúrgica de archivos con:
 *  1. Patrón SCOUT → EXTRACT → EDIT → REASSEMBLE (Ahorro del 80% en tokens y precisión del 99%).
 *  2. Clasificador de complejidad (Nova Micro) para elegir el modelo adecuado.
 *  3. Escalera dinámica de modelos (Nova Micro → Haiku → Nova Pro → Sonnet → Opus).
 *  4. Linting automático (php -l, node --check) sobre el archivo reensamblado.
 *  5. Reintentos inteligentes (le pasa el error a la IA para que lo corrija).
 *  6. Versionado en S3 y trazabilidad en BD (FileVersions + LintAttempts).
 */

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

@ini_set('max_execution_time', '300');
@set_time_limit(300);

// ===== 0. Exigir sesión autenticada (antes: se usaba user_id=1 como fallback silencioso) =====
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado. Inicia sesión de nuevo.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$userId = (int) $_SESSION['user_id'];

function jexit($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

// ===== FUNCIÓN AUXILIAR PARA OBTENER EL SIGUIENTE ID (Faltaba en este archivo) =====
// @deprecated La Fase 2 la elimina: todas las tablas ya tienen AUTO_INCREMENT.
function next_id(mysqli $db, string $table, string $col): int {
    $table = preg_replace('/[^A-Za-z0-9_]+/', '', $table);
    $col   = preg_replace('/[^A-Za-z0-9_]+/', '', $col);
    $rs = $db->query("SELECT COALESCE(MAX($col), 0) + 1 AS nxt FROM $table");
    if (!$rs) return 1;
    $row = $rs->fetch_assoc();
    return (int)($row['nxt'] ?? 1);
}

// ===== 1. Validar parámetros =====
// action: 'write' (crear/editar, comportamiento histórico de este archivo),
// 'read' (leer el contenido actual) o 'delete' (eliminar el archivo de S3 + BD).
$action = isset($_POST['action']) ? trim(strtolower($_POST['action'])) : 'write';
if (!in_array($action, ['write', 'read', 'delete'], true)) {
    jexit(['ok' => false, 'error' => "action inválida: {$action}. Usa 'write', 'read' o 'delete'."], 400);
}

$sessionId = isset($_POST['session_id']) ? (int) $_POST['session_id'] : 0;
$projectId = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
$targetFilename = isset($_POST['target_filename']) ? trim($_POST['target_filename']) : '';
$instruction = isset($_POST['instruction']) ? trim($_POST['instruction']) : '';
// Opcional: mensaje de ChatMessages que originó este cambio (FileVersions.message_id_).
$messageId = isset($_POST['message_id']) && (int) $_POST['message_id'] > 0 ? (int) $_POST['message_id'] : null;

if ($sessionId <= 0 || $projectId <= 0 || $targetFilename === '') {
    jexit(['ok' => false, 'error' => 'Faltan parámetros: session_id, project_id, target_filename'], 400);
}
if ($action === 'write' && $instruction === '') {
    jexit(['ok' => false, 'error' => 'Falta parámetro: instruction'], 400);
}

// ===== 2. Cargar dependencias =====
try {
    $vendorPath = __DIR__ . '/vendor/autoload.php';
    if (file_exists($vendorPath)) {
        require_once $vendorPath;
    } elseif (file_exists(__DIR__ . '/../vendor/autoload.php')) {
        require_once __DIR__ . '/../vendor/autoload.php';
    }

    $bootstrapPath = __DIR__ . '/app_bootstrap.php';
    if (!is_file($bootstrapPath)) {
        throw new RuntimeException('app_bootstrap.php no encontrado en ' . __DIR__);
    }
    require_once $bootstrapPath;

    $s3ManagerPath = __DIR__ . '/S3Manager.php';
    if (!is_file($s3ManagerPath)) {
        throw new RuntimeException('S3Manager.php no encontrado en ' . __DIR__);
    }
    require_once $s3ManagerPath;

    require_once __DIR__ . '/includes/FileToolkit.php';
    require_once __DIR__ . '/includes/EditEngine.php';
    require_once __DIR__ . '/includes/ProjectIndexer.php';
} catch (Throwable $e) {
    jexit(['ok' => false, 'error' => 'Error cargando dependencias: ' . $e->getMessage()], 500);
}

// ===== 2.1. Sanitizar la ruta ANTES de tocar la BD o S3 =====
// $targetFilename llega del usuario (y del modelo vía bedrock_chat2.php) y se
// concatenaba directo contra root_prefix para formar la key de S3, así que un
// "../../" permitía escribir fuera del prefijo del proyecto — y como el prefijo
// es lo que aísla a un usuario de otro, eso era una fuga entre cuentas.
try {
    $targetFilename = sanitizeRelativePath($targetFilename);
    assertAllowedFileType($targetFilename);
} catch (InvalidArgumentException $e) {
    jexit(['ok' => false, 'error' => $e->getMessage(), 'code' => 'ruta_invalida'], 400);
}

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    jexit(['ok' => false, 'error' => 'DB no disponible. Revisa tu app_bootstrap.php'], 500);
}

if (!class_exists('Aws\\BedrockRuntime\\BedrockRuntimeClient') || !class_exists('S3Manager')) {
    jexit(['ok' => false, 'error' => 'AWS SDK o S3Manager no se cargaron correctamente.'], 500);
}

// ===== 2.5. Verificar que el proyecto pertenece al usuario autenticado (antes: sin control) =====
$stmtOwner = $db_connection->prepare("SELECT id_ FROM Projects WHERE id_ = ? AND user_id_ = ? LIMIT 1");
$stmtOwner->bind_param('ii', $projectId, $userId);
$stmtOwner->execute();
$ownerRow = $stmtOwner->get_result()->fetch_assoc();
$stmtOwner->close();
if (!$ownerRow) {
    jexit(['ok' => false, 'error' => 'Proyecto no encontrado o no pertenece al usuario.'], 403);
}

// ===== 3. Buscar el archivo en ProjectSources (Soporta Creación y Edición) =====
$isCreation = false;
$stmt = $db_connection->prepare("
    SELECT ps.id_, ps.s3_key, ps.filename, p.root_prefix, ps.mime_type
    FROM ProjectSources ps
    JOIN Projects p ON p.id_ = ps.project_id_
    WHERE ps.project_id_ = ? AND ps.filename = ?
    LIMIT 1
");
$stmt->bind_param('is', $projectId, $targetFilename);
$stmt->execute();
$res = $stmt->get_result();
$source = $res->fetch_assoc();
$stmt->close();

if (!$source) {
    // ✅ MODO CREACIÓN: El archivo no existe, lo crearemos desde cero
    $isCreation = true;
    
    // Necesitamos el root_prefix del proyecto para saber dónde guardarlo en S3
    $stmtProj = $db_connection->prepare("SELECT root_prefix FROM Projects WHERE id_ = ? LIMIT 1");
    $stmtProj->bind_param('i', $projectId);
    $stmtProj->execute();
    $projRes = $stmtProj->get_result()->fetch_assoc();
    $stmtProj->close();
    
    if (!$projRes) {
        jexit(['ok' => false, 'error' => 'Proyecto no encontrado.'], 404);
    }

    // buildProjectS3Key garantiza que la key queda contenida en root_prefix.
    try {
        $newKey = buildProjectS3Key($projRes['root_prefix'], $targetFilename);
    } catch (InvalidArgumentException $e) {
        jexit(['ok' => false, 'error' => $e->getMessage(), 'code' => 'ruta_invalida'], 400);
    }

    // Simulamos la estructura de $source para que el resto del script no rompa
    $source = [
        'id_' => 0,
        's3_key' => $newKey,
        'filename' => $targetFilename,
        'root_prefix' => $projRes['root_prefix'],
        'mime_type' => 'text/plain' // Se ajustará luego
    ];
    $currentContent = ''; // No hay contenido previo
}

// ===== 3.1. Contención de la key (defensa en profundidad) =====
// Para archivos ya existentes la key viene de la BD, no del usuario, pero pudo
// haberse insertado antes de que existiera la validación de rutas. Si una fila
// apunta fuera del prefijo del proyecto se rechaza en vez de operar sobre ella.
$expectedPrefix = rtrim((string) $source['root_prefix'], '/') . '/';
if (strncmp((string) $source['s3_key'], $expectedPrefix, strlen($expectedPrefix)) !== 0) {
    error_log("SEGURIDAD: ProjectSources#{$source['id_']} apunta fuera del prefijo del proyecto {$projectId}: {$source['s3_key']}");
    jexit([
        'ok' => false,
        'error' => 'La ruta registrada del archivo está fuera del proyecto. Revisa la fuente en la base de datos.',
        'code' => 'key_fuera_de_prefijo'
    ], 409);
}

// ===== 3.5. Inicializar Cliente S3 (Necesario tanto para leer como para escribir) =====
try {
    $manager = new S3Manager();
    $s3 = Config::getS3();
    $bucket = $manager->getBucket();
} catch (Throwable $e) {
    jexit(['ok' => false, 'error' => 'No se pudo inicializar el cliente S3: ' . $e->getMessage()], 500);
}

// ===== 4. Obtener contenido actual desde S3 (SOLO si NO es creación) =====
$currentContent = '';
if (!$isCreation) {
    try {
        $result = $s3->getObject(['Bucket' => $bucket, 'Key' => $source['s3_key']]);
        $currentContent = (string) $result['Body'];
    } catch (Throwable $e) {
        // 🛡️ AUTO-REPARACIÓN: Si el archivo no existe en S3 (404 / NoSuchKey), 
        // lo tratamos como una CREACIÓN desde cero en lugar de fallar.
        if (strpos($e->getMessage(), 'NoSuchKey') !== false || strpos($e->getMessage(), '404 Not Found') !== false) {
            error_log("ADVERTENCIA: El archivo '{$source['s3_key']}' está en la BD pero no en S3. Se tratará como nueva creación (resucitando registro zombie).");
            $isCreation = true;
            $currentContent = '';
        } else {
            // Si es otro error real de S3 (permisos, red, bucket incorrecto), sí fallamos.
            jexit(['ok' => false, 'error' => 'No se pudo leer el archivo desde S3: ' . $e->getMessage()], 500);
        }
    }
}

// ===== 4.5. ACCIONES DE SOLO LECTURA / ELIMINACIÓN (no pasan por la escalera de modelos) =====
// La propiedad del proyecto (y por lo tanto del s3_key, que ya está namespaced con el
// user_id vía root_prefix) ya se validó en el paso 2.5, así que estas acciones son
// seguras: un usuario nunca puede leer/eliminar archivos de otro usuario.
if ($action === 'read') {
    if ($isCreation) {
        jexit(['ok' => false, 'error' => "El archivo '{$targetFilename}' no existe en el proyecto."], 404);
    }
    jexit([
        'ok'       => true,
        'action'   => 'read',
        'filename' => $targetFilename,
        's3_key'   => $source['s3_key'],
        'mime_type'=> $source['mime_type'] ?: 'text/plain',
        'size_bytes' => strlen($currentContent),
        'content'  => $currentContent
    ]);
}

if ($action === 'delete') {
    if ($isCreation) {
        jexit(['ok' => false, 'error' => "El archivo '{$targetFilename}' no existe en el proyecto."], 404);
    }

    $db_connection->begin_transaction();
    try {
        // 1. Borrar el objeto real en S3
        $s3->deleteObject(['Bucket' => $bucket, 'Key' => $source['s3_key']]);

        // 2. Limpiar el registro legacy FileS3 (code_edit.php lo sincroniza por
        // Ruta+Nombre en el paso 14c; ProjectSources.files3_id_ nunca se llena
        // en esta ruta, así que hay que buscarlo por user_id_+Ruta+Nombre)
        $legacyFilename = basename($source['s3_key']);
        $legacyFolder = dirname($source['s3_key']);
        if ($legacyFolder === '.') $legacyFolder = '';
        $stmtDelLegacy = $db_connection->prepare("DELETE FROM FileS3 WHERE user_id_ = ? AND Ruta = ? AND Nombre = ?");
        $stmtDelLegacy->bind_param('iss', $userId, $legacyFolder, $legacyFilename);
        $stmtDelLegacy->execute();
        $stmtDelLegacy->close();

        // 3. Borrar la fuente (cascada elimina SourceChunks + ChunkEmbeddings, ver FKs)
        $stmtDelSrc = $db_connection->prepare("DELETE FROM ProjectSources WHERE id_ = ? AND project_id_ = ?");
        $stmtDelSrc->bind_param('ii', $source['id_'], $projectId);
        $stmtDelSrc->execute();
        $stmtDelSrc->close();

        // 4. Borrar el historial de versiones de ESTE archivo (no tiene FK a ProjectSources)
        $stmtDelVer = $db_connection->prepare("DELETE FROM FileVersions WHERE project_id_ = ? AND original_filename = ?");
        $stmtDelVer->bind_param('is', $projectId, $targetFilename);
        $stmtDelVer->execute();
        $stmtDelVer->close();

        $db_connection->commit();
    } catch (Throwable $e) {
        $db_connection->rollback();
        jexit(['ok' => false, 'error' => 'No se pudo eliminar el archivo: ' . $e->getMessage()], 500);
    }

    jexit([
        'ok'       => true,
        'action'   => 'delete',
        'filename' => $targetFilename,
        'message'  => "🗑️ Archivo '{$targetFilename}' eliminado de S3 y de la base de datos."
    ]);
}

// ===== 5. FUNCIÓN DE LINTING AVANZADO + MULTI-LENGUAJE + SEGURIDAD =====
function lintCode(string $code, string $filename): array {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $tmpFile = tempnam(sys_get_temp_dir(), 'lint_') . '.' . $ext;
    file_put_contents($tmpFile, $code);

    $output = [];
    $returnCode = 0;
    $advancedErrors = [];

    // =====================================================================
    // NIVEL 1: VERIFICACIÓN BÁSICA DE SINTAXIS (Gatekeeper)
    // =====================================================================
    if ($ext === 'php') {
        exec("php -l " . escapeshellarg($tmpFile) . " 2>&1", $output, $returnCode);
    } elseif (in_array($ext, ['js', 'ts', 'tsx', 'jsx'])) {
        exec("node --check " . escapeshellarg($tmpFile) . " 2>&1", $output, $returnCode);
    } elseif ($ext === 'py') {
        exec("python3 -m py_compile " . escapeshellarg($tmpFile) . " 2>&1", $output, $returnCode);
    } elseif ($ext === 'sql') {
        // Validación básica de SQL (puedes integrar sqlflint si lo tienes)
        $returnCode = (stripos($code, 'SELECT') === false && stripos($code, 'INSERT') === false && stripos($code, 'UPDATE') === false) ? 1 : 0;
        if ($returnCode !== 0) $output[] = "Posible sintaxis SQL inválida o incompleta.";
    }

    if ($returnCode !== 0) {
        unlink($tmpFile);
        $errorMsg = implode("\n", $output);
        return ['success' => false, 'error' => trim($errorMsg), 'type' => 'syntax'];
    }

    // =====================================================================
    // NIVEL 2: LINTING ESPECÍFICO POR LENGUAJE
    // =====================================================================
    if ($ext === 'php') {
        $phpstanPath = file_exists(__DIR__ . '/vendor/bin/phpstan') ? __DIR__ . '/vendor/bin/phpstan' : 'phpstan';
        $autoloadArg = file_exists(__DIR__ . '/vendor/autoload.php') ? ' --autoload-file=' . escapeshellarg(__DIR__ . '/vendor/autoload.php') : '';
        exec($phpstanPath . " analyse --no-progress --level=5 --error-format=raw" . $autoloadArg . " " . escapeshellarg($tmpFile) . " 2>&1", $phpstanOut, $phpstanRet);
        if ($phpstanRet !== 0 && $phpstanRet !== 127) {
               $filtered = array_filter($phpstanOut, function($l) {
    return !preg_match('/(Project config|Autoloader|bootstrap)/i', $l);
});
            if (!empty($filtered)) $advancedErrors[] = "PHPStan (Nivel 5):\n" . implode("\n", $filtered);
        }
    } elseif (in_array($ext, ['js', 'jsx'])) {
        $eslintPath = file_exists(__DIR__ . '/node_modules/.bin/eslint') ? __DIR__ . '/node_modules/.bin/eslint' : 'npx eslint';
        exec($eslintPath . " --no-eslintrc --parser-options=ecmaVersion:2020 --env browser,node " . escapeshellarg($tmpFile) . " 2>&1", $eslintOut, $eslintRet);
        if ($eslintRet !== 0 && $eslintRet !== 127 && $eslintRet !== 2) {
            $advancedErrors[] = "ESLint:\n" . implode("\n", $eslintOut);
        }
    } elseif ($ext === 'ts' || $ext === 'tsx') {
        exec("npx tsc --noEmit " . escapeshellarg($tmpFile) . " 2>&1", $tsOut, $tsRet);
        if ($tsRet !== 0 && $tsRet !== 127) $advancedErrors[] = "TypeScript:\n" . implode("\n", $tsOut);
    } elseif ($ext === 'py') {
        exec("ruff check " . escapeshellarg($tmpFile) . " 2>&1", $ruffOut, $ruffRet);
        if ($ruffRet !== 0 && $ruffRet !== 127) $advancedErrors[] = "Ruff (Python):\n" . implode("\n", $ruffOut);
    }

    // =====================================================================
    // NIVEL 3: DETECCIÓN DE PROBLEMAS DE SEGURIDAD (Semgrep)
    // =====================================================================
    if (in_array($ext, ['php', 'js', 'py'])) {
        // Semgrep detecta SQLi, XSS, Hardcoded Secrets, eval(), etc.
        $semgrepCmd = "semgrep scan --config auto --quiet --disable-version-check " . escapeshellarg($tmpFile) . " 2>&1";
        exec($semgrepCmd, $secOut, $secRet);
        
        // 127 = comando no encontrado. 1 = encontró vulnerabilidades. 0 = limpio.
        if ($secRet === 1) { 
            // Filtramos el ruido de "configuring" para mostrar solo los hallazgos reales
               $findings = array_filter($secOut, function($l) {
    return preg_match('/(rule-id|severity|line|found)/i', $l) || strpos($l, '===') !== false;
});
            if (!empty($findings)) {
                $advancedErrors[] = "⚠️ PROBLEMAS DE SEGURIDAD DETECTADOS (Semgrep):\n" . implode("\n", array_slice($findings, 0, 15)); // Máx 15 líneas para no saturar
            }
        }
    }

    unlink($tmpFile);

    if (!empty($advancedErrors)) {
        return ['success' => false, 'error' => implode("\n\n---\n\n", $advancedErrors), 'type' => 'advanced_lint'];
    }

    return ['success' => true, 'error' => '', 'type' => 'ok'];
}

// ===== 6. LIMPIEZA DE MARKDOWN =====
// cleanMarkdown() vive ahora en includes/FileToolkit.php. La versión que estaba
// aquí usaba preg_replace('/^`+|`+$/m', ...): el flag /m aplicaba la limpieza al
// inicio y fin de CADA LÍNEA, destruyendo los template literals de JavaScript y
// los identificadores entrecomillados de MySQL que hubiera en el código.

// ===== 6.5. FUNCIÓN DE CONTEXTO MULTI-ARCHIVO (RAG DE CÓDIGO) ===== 
function fetchRelatedContext(mysqli $db, int $projectId, string $instruction, $bedrock, int $sessionId, int $newVersionId): string {
    // 1. Extraer entidades (clases, funciones, métodos) de la instrucción usando Nova Micro (barato y rápido)
    $extractPrompt = "Extrae ÚNICAMENTE los nombres de clases, funciones, métodos, interfaces o variables clave mencionados en esta instrucción de desarrollo. Devuelve un array JSON plano. Ejemplo: [\"UserRepository\", \"AuthService\", \"login\"].\nInstrucción: " . $instruction;
    
    $entities = [];
    try {
        $res = $bedrock->converse([
            'modelId' => 'amazon.nova-micro-v1:0',
            'messages' => [['role' => 'user', 'content' => [['text' => $extractPrompt]]]],
            'inferenceConfig' => ['maxTokens' => 100, 'temperature' => 0.1]
        ]);
        
        // Registro de costos para trazabilidad total
        $inputTokens = (int)($res['usage']['inputTokens'] ?? 0);
        $outputTokens = (int)($res['usage']['outputTokens'] ?? 0);
        try {
            $tcId = next_id($db, 'TokenUsage', 'id_');
            $tcPhase = 'compile'; // 'compile' es válido en el ENUM de tu tabla TokenUsage
            $tcModel = 'amazon.nova-micro-v1:0';
            $tcCost = ($inputTokens / 1000 * 0.000035) + ($outputTokens / 1000 * 0.00014);
            $sqlTC = "INSERT INTO TokenUsage (id_, session_id_, message_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?)";
            $stmtTC = $db->prepare($sqlTC);
            if ($stmtTC) {
                $durationMs = 0;
                $stmtTC->bind_param("iissiddi", $tcId, $sessionId, $tcPhase, $tcModel, $inputTokens, $outputTokens, $tcCost, $durationMs);
                $stmtTC->execute();
                $stmtTC->close();
            }
        } catch (Throwable $e) {
            @file_put_contents(__DIR__ . '/token_usage_debug.log', "[" . date('Y-m-d H:i:s') . "] Context RAG TokenUsage: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        }

        $text = '';
        foreach (($res['output']['message']['content'] ?? []) as $block) {
            if (isset($block['text'])) $text .= $block['text'];
        }
        $text = preg_replace('/^```json\s*/i', '', trim($text));
        $text = preg_replace('/\s*```$/i', '', trim($text));
        $parsed = json_decode($text, true);
        if (is_array($parsed)) {
            // Filtrar valores vacíos o no string
            $entities = array_filter($parsed, function($val) { return is_string($val) && strlen(trim($val)) > 1; });
        }
    } catch (Throwable $e) {
        return ""; // Fallback silencioso si falla la extracción
    }

    if (empty($entities)) {
        return ""; 
    }

    // 2. Buscar en SourceChunks por nombre o firma (Híbrido: Keyword Search)
    $conditions = [];
    $params = [$projectId];
    $paramTypes = 'i';
    
    foreach ($entities as $entity) {
        $conditions[] = "(name LIKE ? OR signature LIKE ?)";
        $params[] = "%$entity%";
        $params[] = "%$entity%";
        $paramTypes .= 'ss';
    }
    
    // Limitamos a 5 resultados y usamos LEFT(content, 800) para no saturar tokens con código gigante
    $sql = "SELECT name, chunk_type, signature, LEFT(content, 800) as content_preview 
            FROM SourceChunks 
            WHERE project_id_ = ? AND (" . implode(' OR ', $conditions) . ") 
            LIMIT 5";
    
    $stmt = $db->prepare($sql);
    if (!$stmt) return "";
    
    $stmt->bind_param($paramTypes, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $contextParts = [];
    while ($row = $result->fetch_assoc()) {
        $preview = trim($row['content_preview']);
        $contextParts[] = "### {$row['chunk_type']} '{$row['name']}'\nSignature: " . trim($row['signature'] ?? 'N/A') . "\nPreview:\n" . ($preview ?: 'Sin contenido disponible') . "\n";
    }
    $stmt->close();
    
    if (empty($contextParts)) {
        return "";
    }
    
    return "\n\n--- CONTEXTO RELACIONADO DEL PROYECTO (Referencia) ---\n" . implode("\n", $contextParts) . "--- FIN DEL CONTEXTO ---\n";
}

// ===== 6.6. DETECCIÓN DE IMPACTO MULTI-ARCHIVO (Refactor en Cascada) =====
function findReferences(mysqli $db, int $projectId, string $symbolName, string $excludeFile): array {
    // Busca en SourceChunks dónde más se menciona esta clase/método
    $sql = "SELECT DISTINCT ps.filename, sc.start_line, sc.end_line, LEFT(sc.content, 150) as preview
            FROM SourceChunks sc
            JOIN ProjectSources ps ON ps.id_ = sc.source_id_
            WHERE sc.project_id_ = ? 
              AND ps.filename != ?
              AND (sc.content LIKE ? OR sc.signature LIKE ?)
            LIMIT 10";
    $stmt = $db->prepare($sql);
    if (!$stmt) return [];
    $searchTerm = "%$symbolName%";
    $stmt->bind_param('isss', $projectId, $excludeFile, $searchTerm, $searchTerm);
    $stmt->execute();
    $res = $stmt->get_result();
    $refs = [];
    while($row = $res->fetch_assoc()) { $refs[] = $row; }
    $stmt->close();
    return $refs;
}

// ===== 6.7. GENERADOR DE PLAN (Modo PLAN -> EXECUTE) =====
function generateExecutionPlan(string $instruction, string $currentFile, $bedrock, mysqli $db, int $sessionId, int $newVersionId): array {
    $prompt = "Eres un Arquitecto de Software. Analiza la instrucción y genera un PLAN DE EJECUCIÓN en JSON puro.
Identifica qué símbolos (clases, métodos, variables globales) se verán afectados para buscar referencias en otros archivos, y sugiere si hay tests que correr.
Formato JSON exacto (sin markdown):
{
  \"affected_symbols\": [\"NombreClase\", \"metodo\"],
  \"test_command\": \"vendor/bin/phpunit tests/X.php\" o \"npm test\" o null,
  \"risk_level\": \"low|medium|high\",
  \"plan_summary\": \"Breve descripción de los pasos lógicos\"
}
Instrucción: $instruction
Archivo actual: $currentFile";

    try {
        $res = $bedrock->converse([
            'modelId' => 'amazon.nova-micro-v1:0',
            'messages' => [['role' => 'user', 'content' => [['text' => $prompt]]]],
            'inferenceConfig' => ['maxTokens' => 200, 'temperature' => 0.1]
        ]);
        
        $text = '';
        foreach (($res['output']['message']['content'] ?? []) as $block) {
            if (isset($block['text'])) $text .= $block['text'];
        }
        $text = preg_replace('/^```json\s*/i', '', trim($text));
        $text = preg_replace('/\s*```$/i', '', trim($text));
        return json_decode($text, true) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}
// ===== 7. CLASIFICADOR DE COMPLEJIDAD (usa Nova Micro) =====
function classifyInstruction(string $instruction, $bedrock, mysqli $db, int $sessionId, int $newVersionId): string {
    $classifyPrompt = "Clasifica esta tarea de edición de código en una de estas categorías y responde SÓLO con la palabra clave:
- 'simple': cambios menores, corrección de errores, ajustes de una línea.
- 'medium': añadir funciones, modificar lógica moderada.
- 'complex': reescribir clases enteras, cambiar arquitectura, refactorización pesada.
Instrucción: " . $instruction;

    try {
        $res = $bedrock->converse([
            'modelId' => 'amazon.nova-micro-v1:0',
            'messages' => [['role' => 'user', 'content' => [['text' => $classifyPrompt]]]],
            'inferenceConfig' => ['maxTokens' => 50, 'temperature' => 0.1]
        ]);

        $inputTokens = (int)($res['usage']['inputTokens'] ?? 0);
        $outputTokens = (int)($res['usage']['outputTokens'] ?? 0);
        try {
            $tcId = next_id($db, 'TokenUsage', 'id_');
            $tcPhase = 'lint_fix';
            $tcModel = 'amazon.nova-micro-v1:0';
            $costIn = 0.000035; 
            $costOut = 0.00014;
            
            $tcCost = ($inputTokens / 1000 * $costIn) + ($outputTokens / 1000 * $costOut);
            
            $sqlTC = "INSERT INTO TokenUsage (id_, session_id_, message_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms) 
                      VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?)";
            $stmtTC = $db->prepare($sqlTC);
            if ($stmtTC) {
                $durationMs = 0; // ✅ CORRECCIÓN: Variable en lugar de 0
                $stmtTC->bind_param("iissiddi", $tcId, $sessionId, $tcPhase, $tcModel, $inputTokens, $outputTokens, $tcCost, $durationMs);
                $stmtTC->execute();
                $stmtTC->close();
            }
        } catch (Throwable $e) {
            $logMsg = "[" . date('Y-m-d H:i:s') . "] " . basename(__FILE__) . " | " . $e->getMessage() . "\n";
            @file_put_contents(__DIR__ . '/token_usage_debug.log', $logMsg, FILE_APPEND | LOCK_EX);
        }

        $text = '';
        foreach (($res['output']['message']['content'] ?? []) as $block) {
            if (isset($block['text'])) {
                $text .= $block['text'];
            }
        }
        $category = trim(strtolower($text));
        if (!in_array($category, ['simple', 'medium', 'complex'])) {
            return 'medium';
        }
        return $category;
    } catch (Throwable $e) {
        return 'medium';
    }
}

// ===== 7.5. RESUMEN PROFESIONAL DEL RESULTADO (usa Haiku: "modelo que revisa código") =====
// Genera, a partir del código REALMENTE producido (no de la instrucción cruda), un
// resumen técnico conciso que NUNCA debe omitir nombres de variables, funciones,
// clases o rutas de archivo — es lo que luego alimenta la memoria de la sesión y
// la respuesta final del asistente, así que debe ser preciso y profesional.
function summarizeCodeChange(
    $bedrock, mysqli $db, int $sessionId, int $newVersionId,
    string $instruction, string $filename, string $newContent, bool $isCreation
): array {
    $reviewerModel = 'anthropic.claude-3-5-haiku-20241022-v1:0';

    $systemPrompt = "Eres un revisor de código senior. Tu única tarea es describir, en un párrafo breve y profesional (máx. 80 palabras), qué se implementó en el archivo dado.
REGLAS OBLIGATORIAS:
1. NUNCA omitas nombres exactos de funciones, métodos, clases, variables o constantes relevantes que aparezcan en el código.
2. Menciona el nombre del archivo y si fue creado o editado.
3. No repitas el código completo, solo referencia los identificadores clave.
4. No uses markdown ni comillas, solo texto plano en español.
5. Sé técnicamente preciso: si detectas el lenguaje de programación, indícalo.";

    $action = $isCreation ? 'CREACIÓN' : 'EDICIÓN';
    $userPrompt = "Acción: {$action}\nArchivo: {$filename}\nInstrucción original: " . mb_substr($instruction, 0, 500) . "\n\nCódigo resultante:\n```\n" . mb_substr($newContent, 0, 6000) . "\n```\n\nDescribe qué se implementó, preservando los nombres exactos de funciones/variables/clases usados.";

    try {
        $res = $bedrock->converse([
            'modelId' => $reviewerModel,
            'messages' => [['role' => 'user', 'content' => [['text' => $userPrompt]]]],
            'system' => [['text' => $systemPrompt]],
            'inferenceConfig' => ['maxTokens' => 300, 'temperature' => 0.2]
        ]);

        $inputTokens = (int)($res['usage']['inputTokens'] ?? 0);
        $outputTokens = (int)($res['usage']['outputTokens'] ?? 0);
        try {
            $tcId = next_id($db, 'TokenUsage', 'id_');
            $tcPhase = 'compile';
            $tcCost = ($inputTokens / 1000 * 0.0008) + ($outputTokens / 1000 * 0.004);
            $sqlTC = "INSERT INTO TokenUsage (id_, session_id_, message_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms)
                      VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?)";
            $stmtTC = $db->prepare($sqlTC);
            if ($stmtTC) {
                $durationMs = 0;
                $stmtTC->bind_param("iissiddi", $tcId, $sessionId, $tcPhase, $reviewerModel, $inputTokens, $outputTokens, $tcCost, $durationMs);
                $stmtTC->execute();
                $stmtTC->close();
            }
        } catch (Throwable $e) {
            @file_put_contents(__DIR__ . '/token_usage_debug.log', "[" . date('Y-m-d H:i:s') . "] summarizeCodeChange TokenUsage: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        }

        $text = '';
        foreach (($res['output']['message']['content'] ?? []) as $block) {
            if (isset($block['text'])) $text .= $block['text'];
        }
        $text = trim($text);
        if ($text === '') {
            $text = ($isCreation ? "Archivo {$filename} creado." : "Archivo {$filename} editado.") . " " . mb_substr($instruction, 0, 150);
        }
        return ['text' => $text, 'model' => $reviewerModel];
    } catch (Throwable $e) {
        // Fallback: nunca bloquear la respuesta final por un fallo del resumen
        return [
            'text' => ($isCreation ? "Archivo {$filename} creado." : "Archivo {$filename} editado.") . " " . mb_substr($instruction, 0, 150),
            'model' => 'fallback'
        ];
    }
}

// ===== 8. Preparar la escalera base =====
const OPUS_MODEL_ID = 'anthropic.claude-opus-4-8-v1:0';

$ladderBase = [
    ['model' => 'amazon.nova-pro-v1:0',                       'max_attempts' => 1],
    ['model' => 'anthropic.claude-3-5-haiku-20241022-v1:0',   'max_attempts' => 1],
    ['model' => 'anthropic.claude-sonnet-4-5-20250929-v1:0',  'max_attempts' => 1],
    ['model' => OPUS_MODEL_ID,                                'max_attempts' => 1],
];

// ===== 9. Crear registro "Borrador" en FileVersions =====
$stmtVer = $db_connection->prepare("
    SELECT version FROM FileVersions 
    WHERE project_id_ = ? AND original_filename = ? 
    ORDER BY id_ DESC LIMIT 1
");
$stmtVer->bind_param('is', $projectId, $targetFilename);
$stmtVer->execute();
$resVer = $stmtVer->get_result();
$lastRow = $resVer->fetch_assoc();
$stmtVer->close();

if (!$lastRow) {
    $nextVersion = '1';
} else {
    $parts = explode('.', $lastRow['version']);
    $parts[count($parts) - 1] = (int) $parts[count($parts) - 1] + 1;
    $nextVersion = implode('.', $parts);
}

$newVersionId = 0;
$rs = $db_connection->query("SELECT IFNULL(MAX(id_),0)+1 AS nxt FROM FileVersions");
if ($rs) {
    $newVersionId = (int) ($rs->fetch_assoc()['nxt'] ?? 1);
    $rs->free();
}

$diffSummary = mb_substr($instruction, 0, 100) . (mb_strlen($instruction) > 100 ? '...' : '');
$newS3Key = rtrim($source['root_prefix'], '/') . '/' . $targetFilename . '.v' . $nextVersion;

// message_id_ tiene FK a ChatMessages y siempre se insertaba NULL, así que no
// se podía responder "qué mensaje del chat produjo este cambio". Ahora se
// guarda si el llamador lo envía.
$stmtInsert = $db_connection->prepare("
    INSERT INTO FileVersions
    (id_, project_id_, session_id_, message_id_, original_filename, version, s3_path, diff_summary, is_stable)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)
");
$stmtInsert->bind_param('iiiissss', $newVersionId, $projectId, $sessionId, $messageId, $targetFilename, $nextVersion, $newS3Key, $diffSummary);
$stmtInsert->execute();
$stmtInsert->close();

// ===== 10. Crear cliente Bedrock =====
try {
    // ✅ CORRECCIÓN: defined('Config::REGION') nunca detecta constantes de clase,
    // solo constantes globales. Se usa class_exists() + comprobación directa.
    $region = getenv('AWS_REGION') ?: ((class_exists('Config') && Config::REGION) ? Config::REGION : 'us-east-1');
    $ak = getenv('AWS_ACCESS_KEY_ID') ?: ((class_exists('Config') && Config::ACCESS_KEY) ? Config::ACCESS_KEY : '');
    $sk = getenv('AWS_SECRET_ACCESS_KEY') ?: ((class_exists('Config') && Config::SECRET_KEY) ? Config::SECRET_KEY : '');

    $bedrock = new Aws\BedrockRuntime\BedrockRuntimeClient([
        'region'      => $region,
        'version'     => 'latest',
        'credentials' => ['key' => $ak, 'secret' => $sk],
        'http'        => ['connect_timeout' => 20, 'timeout' => 120],
    ]);
} catch (Throwable $e) {
    jexit(['ok' => false, 'error' => 'No se pudo inicializar el cliente Bedrock: ' . $e->getMessage()], 500);
}

// ===== 11. Clasificar la instrucción y reordenar la escalera dinámicamente =====
try {
    $category = classifyInstruction($instruction, $bedrock, $db_connection, $sessionId, $newVersionId);

    $priorityMap = [
        'simple'  => [
            'amazon.nova-micro-v1:0',
            'anthropic.claude-3-5-haiku-20241022-v1:0',
            'amazon.nova-pro-v1:0',
            'anthropic.claude-sonnet-4-5-20250929-v1:0',
            OPUS_MODEL_ID
        ],
        'medium'  => [
            'amazon.nova-pro-v1:0',
            'anthropic.claude-sonnet-4-5-20250929-v1:0',
            'anthropic.claude-3-5-haiku-20241022-v1:0',
            'amazon.nova-micro-v1:0',
            OPUS_MODEL_ID
        ],
        'complex' => [
            OPUS_MODEL_ID,
            'anthropic.claude-sonnet-4-5-20250929-v1:0',
            'amazon.nova-pro-v1:0',
            'anthropic.claude-3-5-haiku-20241022-v1:0',
            'amazon.nova-micro-v1:0'
        ]
    ];

    $orderedModels = $priorityMap[$category] ?? $priorityMap['medium'];

    $ladder = [];
    foreach ($orderedModels as $idx => $modelId) {
        if ($modelId === OPUS_MODEL_ID) {
            $maxAttempts = 1;
        } else {
            $maxAttempts = ($idx === 0) ? 2 : 1;
        }
        $ladder[] = [
            'model'        => $modelId,
            'max_attempts' => $maxAttempts
        ];
    }
} catch (Throwable $e) {
    $ladder = $ladderBase;
    $category = 'unknown (fallback)';
}

// ===== 11.5. ANÁLISIS DE IMPACTO Y PLANIFICACIÓN (Modo PLAN) =====
$impactAnalysis = [
    'is_multi_file' => false,
    'affected_files' => [],
    'test_command' => null,
    'plan_summary' => '',
    'risk_level' => 'low'
];

if (in_array($category, ['medium', 'complex'])) {
    // 1. La IA genera el plan y detecta símbolos afectados
    $planData = generateExecutionPlan($instruction, $targetFilename, $bedrock, $db_connection, $sessionId, $newVersionId);
    $impactAnalysis['plan_summary'] = $planData['plan_summary'] ?? '';
    $impactAnalysis['risk_level'] = $planData['risk_level'] ?? 'medium';
    $impactAnalysis['test_command'] = $planData['test_command'] ?? null;
    
    // 2. Si la IA detectó símbolos, cruzamos con la BD para encontrar archivos reales (Refactor en Cascada)
    if (!empty($planData['affected_symbols']) && is_array($planData['affected_symbols'])) {
        foreach ($planData['affected_symbols'] as $symbol) {
            $refs = findReferences($db_connection, $projectId, $symbol, $targetFilename);
            foreach ($refs as $ref) {
                $impactAnalysis['affected_files'][$ref['filename']] = [
                    'filename' => $ref['filename'],
                    'preview' => $ref['preview'],
                    'lines' => $ref['start_line'] . '-' . $ref['end_line']
                ];
            }
        }
        if (!empty($impactAnalysis['affected_files'])) {
            $impactAnalysis['is_multi_file'] = true;
        }
    }
}

// ===== 12. Ejecutar Flujo: CREACIÓN o EDICIÓN POR ANCLA =====
$newContent   = '';
$lastError    = '';
$attemptLog   = [];
$success      = false;
$failureCode  = 'lint_fallido';
$strategyUsed = null;

// Cascada de la edición (ver Fase 1 del refactor):
//   Nivel 1  apply_edit con ancla única.
//   Nivel 2  hasta 3 intentos de ancla, devolviéndole al modelo el conteo real.
//   Nivel 3  reescritura completa, SOLO si el archivo es pequeño y con guards.
//   Nivel 4  fallar explícitamente. Reescribir 800 líneas a ciegas no es una opción.
$maxAnchorAttempts   = 3;
$fullRewriteMaxLines = 300;
$anchorFailures      = 0;

/**
 * Registra el consumo de un modelo. Local a este flujo para no repetir el
 * bloque de INSERT + tabla de precios en cada intento (la Fase 5 lo unifica en
 * un único mapa MODEL_PRICING junto con el resto del archivo).
 */
$logModelUsage = function (string $phase, string $model, int $inTok, int $outTok, int $durationMs) use ($db_connection, $sessionId): void {
    try {
        $costIn = 0.000035; $costOut = 0.00014;
        if (strpos($model, 'sonnet') !== false)        { $costIn = 0.003;   $costOut = 0.015; }
        elseif (strpos($model, 'opus') !== false)      { $costIn = 0.015;   $costOut = 0.075; }
        elseif (strpos($model, 'haiku') !== false)     { $costIn = 0.00025; $costOut = 0.00125; }
        elseif (strpos($model, 'nova-pro') !== false)  { $costIn = 0.0008;  $costOut = 0.0032; }
        $cost = ($inTok / 1000 * $costIn) + ($outTok / 1000 * $costOut);

        $tcId = next_id($db_connection, 'TokenUsage', 'id_');
        $stmt = $db_connection->prepare(
            "INSERT INTO TokenUsage (id_, session_id_, message_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms)
             VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            $stmt->bind_param("iissiddi", $tcId, $sessionId, $phase, $model, $inTok, $outTok, $cost, $durationMs);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
        @file_put_contents(__DIR__ . '/token_usage_debug.log',
            "[" . date('Y-m-d H:i:s') . "] logModelUsage: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    }
};

/** Registra un intento en LintAttempts. */
$logLintAttempt = function (int $attemptNum, string $model, string $error, bool $ok, int $durationMs) use ($db_connection, $newVersionId): void {
    $stmt = $db_connection->prepare(
        "INSERT INTO LintAttempts (file_version_id_, attempt_number, model_used, error_message, is_success, duration_ms) VALUES (?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) { // prepare() puede devolver false; antes se llamaba bind_param sobre false.
        error_log('No se pudo preparar el INSERT de LintAttempts: ' . $db_connection->error);
        return;
    }
    $isSuccess = $ok ? 1 : 0;
    $stmt->bind_param("iissii", $newVersionId, $attemptNum, $model, $error, $isSuccess, $durationMs);
    $stmt->execute();
    $stmt->close();
};

try {
    if ($isCreation) {
        // =====================================================================
        // MODO CREACIÓN: la IA genera el archivo completo desde cero
        // =====================================================================
        $systemPromptCreator = "You are an expert software engineer. Your task is to create a NEW file from scratch based on the user's instruction.
RULES:
1. Return ONLY the raw code. Do NOT wrap it in markdown backticks.
2. Do not add explanations, just the code.
3. NEVER abbreviate with comments like '// ... rest of the file'. Write every line.
4. Ensure the code is syntactically perfect and ready to run.";

        $attemptNum = 0;
        foreach ($ladder as $tier) {
            for ($i = 0; $i < $tier['max_attempts']; $i++) {
                $userPrompt = "FILE TO CREATE: {$targetFilename}\nUSER INSTRUCTION:\n{$instruction}";
                if ($lastError !== '') {
                    $userPrompt .= "\n⚠️ CRITICAL: Your previous code failed validation with this error:\n```\n{$lastError}\n```\nFix it and return the complete corrected file.";
                }

                // Presupuesto de salida acotado por el techo real del modelo. Si
                // se agota se reintenta UNA vez con el doble antes de escalar:
                // truncar por presupuesto no es culpa del modelo y escalar a
                // Opus por ello es puro gasto.
                $budget = min(8000, maxOutputTokensFor($tier['model']));
                $truncationRetried = false;

                do {
                    $t0 = hrtime(true);
                    $res = $bedrock->converse([
                        'modelId' => $tier['model'],
                        'messages' => [['role' => 'user', 'content' => [['text' => $userPrompt]]]],
                        'system' => [['text' => $systemPromptCreator]],
                        'inferenceConfig' => ['maxTokens' => $budget, 'temperature' => 0.2, 'topP' => 0.9]
                    ]);
                    $durationMs = (int) round((hrtime(true) - $t0) / 1e6);

                    $logModelUsage('respond', $tier['model'],
                        (int) ($res['usage']['inputTokens'] ?? 0),
                        (int) ($res['usage']['outputTokens'] ?? 0),
                        $durationMs);

                    $truncated = (($res['stopReason'] ?? '') === 'max_tokens');
                    if ($truncated && !$truncationRetried) {
                        $truncationRetried = true;
                        $budget = min($budget * 2, maxOutputTokensFor($tier['model']));
                        continue;
                    }
                    break;
                } while (true);

                $attemptNum++;

                $rawResponse = '';
                foreach (($res['output']['message']['content'] ?? []) as $block) {
                    if (isset($block['text'])) $rawResponse .= $block['text'];
                }

                // Un archivo cortado a la mitad nunca se escribe, aunque por
                // casualidad pasara el lint.
                if (($res['stopReason'] ?? '') === 'max_tokens') {
                    $lastError = 'La respuesta se cortó por límite de tokens: el archivo estaría incompleto.';
                    $attemptLog[] = ['model' => $tier['model'], 'attempt' => $attemptNum, 'success' => false, 'error' => $lastError, 'strategy' => 'create'];
                    $logLintAttempt($attemptNum, $tier['model'], $lastError, false, $durationMs);
                    continue;
                }

                $candidate = cleanMarkdown($rawResponse);

                if (containsElisionMarker($candidate)) {
                    $lastError = 'El archivo generado está abreviado con un marcador tipo "... resto sin cambios".';
                    $attemptLog[] = ['model' => $tier['model'], 'attempt' => $attemptNum, 'success' => false, 'error' => $lastError, 'strategy' => 'create'];
                    $logLintAttempt($attemptNum, $tier['model'], $lastError, false, $durationMs);
                    continue;
                }

                $lintResult = lintCode($candidate, $targetFilename);
                $attemptLog[] = ['model' => $tier['model'], 'attempt' => $attemptNum, 'success' => $lintResult['success'], 'error' => $lintResult['error'], 'strategy' => 'create'];
                $logLintAttempt($attemptNum, $tier['model'], $lintResult['error'], $lintResult['success'], $durationMs);

                if ($lintResult['success']) {
                    $newContent   = $candidate;
                    $success      = true;
                    $strategyUsed = 'create';
                    break 2;
                }
                $lastError = $lintResult['error'];
                if (preg_match('/undefined method|type mismatch|cannot resolve|fatal error/i', $lastError)) break;
            }
        }
    } else {
        // =====================================================================
        // MODO EDICIÓN: cascada apply_edit → reescritura → fallo explícito
        // =====================================================================
        $relatedContextRaw = fetchRelatedContext($db_connection, $projectId, $instruction, $bedrock, $sessionId, $newVersionId);
        $relatedContext = $relatedContextRaw !== '' ? "\n\n📚 RELATED PROJECT CONTEXT:\n{$relatedContextRaw}" : '';

        // ---------- NIVELES 1 y 2: edición por ancla única ----------
        $feedback   = null;
        $attemptNum = 0;

        foreach ($ladder as $tier) {
            for ($i = 0; $i < $tier['max_attempts']; $i++) {
                if ($anchorFailures >= $maxAnchorAttempts) break 2;

                $attemptNum++;
                $edit = requestAnchoredEdit(
                    $bedrock, $tier['model'], $source['filename'], $currentContent,
                    $instruction, $relatedContext, $feedback
                );
                $logModelUsage('lint_fix', $tier['model'], $edit['input_tokens'], $edit['output_tokens'], $edit['duration_ms']);

                // El modelo no produjo un JSON usable.
                if (!$edit['ok']) {
                    $anchorFailures++;
                    $feedback  = $edit['error'];
                    $lastError = $edit['error'];
                    $attemptLog[] = ['model' => $tier['model'], 'attempt' => $attemptNum, 'success' => false, 'error' => $edit['error'], 'strategy' => 'apply_edit'];
                    $logLintAttempt($attemptNum, $tier['model'], $edit['error'], false, $edit['duration_ms']);
                    continue;
                }

                // El ancla debe identificar exactamente un punto del archivo.
                $applied = applyUniqueEdit($currentContent, $edit['old_string'], $edit['new_string']);
                if (!$applied['ok']) {
                    $anchorFailures++;
                    $feedback  = $applied['error'];
                    $lastError = $applied['error'];
                    $attemptLog[] = [
                        'model' => $tier['model'], 'attempt' => $attemptNum, 'success' => false,
                        'error' => $applied['error'], 'strategy' => 'apply_edit',
                        'anchor_matches' => $applied['count'],
                    ];
                    $logLintAttempt($attemptNum, $tier['model'], $applied['error'], false, $edit['duration_ms']);
                    continue;
                }

                $candidate = (string) $applied['content'];

                if (containsElisionMarker($edit['new_string'])) {
                    $feedback  = 'new_string contiene un marcador de elisión ("... resto sin cambios"). Escribe el código completo del fragmento.';
                    $lastError = $feedback;
                    $attemptLog[] = ['model' => $tier['model'], 'attempt' => $attemptNum, 'success' => false, 'error' => $feedback, 'strategy' => 'apply_edit'];
                    $logLintAttempt($attemptNum, $tier['model'], $feedback, false, $edit['duration_ms']);
                    continue;
                }

                if (detectSuspiciousShrink($currentContent, $candidate, $instruction)) {
                    $feedback  = 'La edición eliminaría más del 40% del archivo y la instrucción no pedía borrar nada. Ancla en una región más pequeña.';
                    $lastError = $feedback;
                    $attemptLog[] = ['model' => $tier['model'], 'attempt' => $attemptNum, 'success' => false, 'error' => $feedback, 'strategy' => 'apply_edit'];
                    $logLintAttempt($attemptNum, $tier['model'], $feedback, false, $edit['duration_ms']);
                    continue;
                }

                $lintResult = lintCode($candidate, $targetFilename);
                $attemptLog[] = ['model' => $tier['model'], 'attempt' => $attemptNum, 'success' => $lintResult['success'], 'error' => $lintResult['error'], 'strategy' => 'apply_edit'];
                $logLintAttempt($attemptNum, $tier['model'], $lintResult['error'], $lintResult['success'], $edit['duration_ms']);

                if ($lintResult['success']) {
                    $newContent   = $candidate;
                    $success      = true;
                    $strategyUsed = 'apply_edit';
                    break 2;
                }
                $feedback  = "El código resultante no pasó la validación de sintaxis:\n" . $lintResult['error'];
                $lastError = $lintResult['error'];
            }
        }

        // ---------- NIVEL 3: reescritura completa (solo archivos pequeños) ----------
        if (!$success) {
            $totalLines = countLines($currentContent);

            if ($totalLines >= $fullRewriteMaxLines) {
                // ---------- NIVEL 4 ----------
                // Fallar aquí es lo correcto: reescribir un archivo grande a
                // ciegas arriesga perder código que nadie pidió tocar.
                $failureCode = 'ancla_no_resoluble';
                $lastError = "No se pudo localizar un ancla única tras {$anchorFailures} intentos, y el archivo tiene {$totalLines} líneas "
                           . "(el límite para reescritura completa es {$fullRewriteMaxLines}). Acota la instrucción indicando la función o clase concreta a modificar.";
            } else {
                foreach ($ladder as $tier) {
                    $attemptNum++;
                    $rewrite = requestFullRewrite(
                        $bedrock, $tier['model'], $source['filename'], $currentContent,
                        $instruction, $relatedContext
                    );
                    $logModelUsage('respond', $tier['model'], $rewrite['input_tokens'], $rewrite['output_tokens'], $rewrite['duration_ms']);

                    if (!$rewrite['ok']) {
                        $lastError = $rewrite['error'];
                        $attemptLog[] = ['model' => $tier['model'], 'attempt' => $attemptNum, 'success' => false, 'error' => $rewrite['error'], 'strategy' => 'full_rewrite'];
                        $logLintAttempt($attemptNum, $tier['model'], $rewrite['error'], false, $rewrite['duration_ms']);
                        continue;
                    }

                    // El guard de reducción es obligatorio en este nivel: es el
                    // que impide que una reescritura abreviada borre el archivo.
                    if (detectSuspiciousShrink($currentContent, $rewrite['content'], $instruction)) {
                        $failureCode = 'reduccion_sospechosa';
                        $lastError = sprintf(
                            'La reescritura pasó de %d a %d bytes (%.0f%%) sin que la instrucción pidiera borrar nada. Se descarta.',
                            strlen($currentContent), strlen($rewrite['content']),
                            strlen($rewrite['content']) / max(1, strlen($currentContent)) * 100
                        );
                        $attemptLog[] = ['model' => $tier['model'], 'attempt' => $attemptNum, 'success' => false, 'error' => $lastError, 'strategy' => 'full_rewrite'];
                        $logLintAttempt($attemptNum, $tier['model'], $lastError, false, $rewrite['duration_ms']);
                        continue;
                    }

                    $lintResult = lintCode($rewrite['content'], $targetFilename);
                    $attemptLog[] = ['model' => $tier['model'], 'attempt' => $attemptNum, 'success' => $lintResult['success'], 'error' => $lintResult['error'], 'strategy' => 'full_rewrite'];
                    $logLintAttempt($attemptNum, $tier['model'], $lintResult['error'], $lintResult['success'], $rewrite['duration_ms']);

                    if ($lintResult['success']) {
                        $newContent   = $rewrite['content'];
                        $success      = true;
                        $strategyUsed = 'full_rewrite';
                        break;
                    }
                    $lastError = $lintResult['error'];
                }
            }
        }
    }
} catch (Throwable $e) {
    jexit(['ok' => false, 'error' => 'Error crítico en la escalera de modelos: ' . $e->getMessage()], 500);
}

// ===== 13. Evaluar resultado final =====
if (!$success) {
    // 'ancla_no_resoluble' y 'reduccion_sospechosa' no son errores del servidor:
    // son rechazos deliberados para no corromper el archivo. Van con 422 para
    // que el cliente distinga "no pude" de "me rompí".
    $isRejection = in_array($failureCode, ['ancla_no_resoluble', 'reduccion_sospechosa'], true);

    $mensaje = $failureCode === 'ancla_no_resoluble'
        ? 'No se pudo localizar de forma inequívoca la parte del archivo a modificar.'
        : ($failureCode === 'reduccion_sospechosa'
            ? 'El resultado se descartó porque habría eliminado una parte importante del archivo.'
            : 'No se pudo generar código válido después de múltiples intentos.');

    jexit([
        'ok'          => false,
        'error'       => $mensaje,
        'code'        => $failureCode,
        'last_error'  => $lastError,
        'anchor_failures' => $anchorFailures,
        'attempt_log' => $attemptLog
    ], $isRejection ? 422 : 500);
}

// ===== 14. Éxito: Subir a S3 y Registrar en BD (Incluye sync con FileS3 y S3Folders) =====
// ✅ CORRECCIÓN: se envuelven todas las escrituras de este bloque en una transacción
// para que, si algo falla a mitad de camino, no queden registros huérfanos
// (p. ej. ProjectSources actualizado pero FileS3 sin sincronizar).
$db_connection->begin_transaction();
try {
    $originalS3Key = $source['s3_key'];
    
    // Detectar MIME type y lenguaje básico
    $ext = strtolower(pathinfo($targetFilename, PATHINFO_EXTENSION));
    $mimeMap = ['php' => 'text/x-php', 'js' => 'application/javascript', 'html' => 'text/html', 'css' => 'text/css', 'py' => 'text/x-python'];
    $mimeType = $mimeMap[$ext] ?? 'text/plain';
    $lang = $ext === 'php' ? 'php' : ($ext === 'js' ? 'javascript' : $ext);

// 14a. Actualizar o Crear en ProjectSources
if ($isCreation) {
    // 1. Verificamos si ya existe un registro "zombie" en la BD para este proyecto y ruta
    $stmtCheck = $db_connection->prepare("SELECT id_ FROM ProjectSources WHERE project_id_ = ? AND s3_key = ? LIMIT 1");
    $stmtCheck->bind_param("is", $projectId, $originalS3Key);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();
    $existingSource = $resCheck->fetch_assoc();
    $stmtCheck->close();

    $sizeBytes = strlen($newContent);

    if ($existingSource) {
        // ✅ Es un archivo zombie: Actualizamos el registro existente en lugar de insertar uno nuevo
        $sourceId = $existingSource['id_'];
        $stmtUpdateSource = $db_connection->prepare("
            UPDATE ProjectSources 
            SET filename = ?, mime_type = ?, size_bytes = ?, language = ?, status = 'indexed', indexed_at = NOW()
            WHERE id_ = ?
        ");
        $stmtUpdateSource->bind_param("ssisi", $targetFilename, $mimeType, $sizeBytes, $lang, $sourceId);
        $stmtUpdateSource->execute();
        $stmtUpdateSource->close();
        $source['id_'] = $sourceId;
    } else {
        // ✅ Realmente es una creación desde cero: Insertamos normal
        $newSourceId = next_id($db_connection, 'ProjectSources', 'id_');
        $stmtInsertSource = $db_connection->prepare("
            INSERT INTO ProjectSources (id_, project_id_, files3_id_, s3_key, filename, mime_type, size_bytes, language, sha256, status, indexed_at)
            VALUES (?, ?, NULL, ?, ?, ?, ?, ?, NULL, 'indexed', NOW())
        ");
        $stmtInsertSource->bind_param("iisssis", $newSourceId, $projectId, $originalS3Key, $targetFilename, $mimeType, $sizeBytes, $lang);
        $stmtInsertSource->execute();
        $stmtInsertSource->close();
        $source['id_'] = $newSourceId;
    }
} else {
        $backupS3Key = preg_replace('/(\.[a-zA-Z0-9]+)$/i', '.ver0$1', $originalS3Key);
        // s3CopySource() codifica cada segmento pero preserva las barras.
        // urlencode() sobre la ruta entera convertía cada '/' en %2F, así que
        // CopySource no era una ruta válida y el respaldo nunca se creaba.
        $s3->copyObject(['Bucket' => $bucket, 'CopySource' => s3CopySource($bucket, $originalS3Key), 'Key' => $backupS3Key]);
        
        $updSource = $db_connection->prepare("UPDATE ProjectSources SET status = 'stale' WHERE id_ = ?");
        $updSource->bind_param('i', $source['id_']);
        $updSource->execute();
        $updSource->close();
    }

    // 14b. Subir el archivo oficial a S3
    $s3->putObject([
        'Bucket'      => $bucket,
        'Key'         => $originalS3Key,
        'Body'        => $newContent,
        'ContentType' => $mimeType,
        'ACL'         => 'private'
    ]);

// =====================================================================
// 14c. 🚀 SINCRONIZACIÓN CON TABLAS LEGACY (FileS3 y S3Folders)
// =====================================================================
// $userId ya viene validado y autorizado desde el paso 0/2.5
$filename = basename($originalS3Key);
$folderPrefix = dirname($originalS3Key);
if ($folderPrefix === '.') $folderPrefix = '';
$fileSize = strlen($newContent);

// ✅ IMPORTANTE: Calcular el hash ANTES de cualquier INSERT
// ✅ CORRECCIÓN: se incluye el user_id_ en el hash porque `Encriptado` tiene
// una UNIQUE KEY global en la BD; sin esto, dos usuarios con el mismo s3_key
// (root_prefix repetido entre proyectos de distintos dueños) chocarían entre sí.
$encriptadoVal = hash('sha256', $userId . '|' . $originalS3Key);

// A) Sincronizar Carpeta (S3Folders)
if ($folderPrefix !== '') {
    $stmtFolder = $db_connection->prepare("SELECT id_ FROM S3Folders WHERE user_id_ = ? AND Prefix = ? LIMIT 1");
    $stmtFolder->bind_param("is", $userId, $folderPrefix);
    $stmtFolder->execute();
    $resFolder = $stmtFolder->get_result();
    
    if ($resFolder->num_rows === 0) {
        $newFolderId = next_id($db_connection, 'S3Folders', 'id_');
        $folderName = basename($folderPrefix);
        $parentPrefix = dirname($folderPrefix);
        if ($parentPrefix === '.') $parentPrefix = '';

        // ✅ PrefixHash se calcula automáticamente (GENERATED ALWAYS AS)
        $stmtInsFolder = $db_connection->prepare("
            INSERT INTO S3Folders (id_, user_id_, Prefix, Nombre, ParentPrefix, Found, AccessType, CreatedAt, UpdatedAt)
            VALUES (?, ?, ?, ?, ?, 1, 'normal', NOW(), NOW())
        ");
        $stmtInsFolder->bind_param("iisss", $newFolderId, $userId, $folderPrefix, $folderName, $parentPrefix);
        $stmtInsFolder->execute();
        $stmtInsFolder->close();
    }
    $stmtFolder->close();
}

// B) Sincronizar Archivo (FileS3)
$stmtFile = $db_connection->prepare("SELECT id_, Tamano FROM FileS3 WHERE user_id_ = ? AND Ruta = ? AND Nombre = ? LIMIT 1");
$stmtFile->bind_param("iss", $userId, $folderPrefix, $filename);
$stmtFile->execute();
$resFile = $stmtFile->get_result();
$fileRow = $resFile->fetch_assoc();
$stmtFile->close();

if ($fileRow) {
    $stmtUpdFile = $db_connection->prepare("UPDATE FileS3 SET Tamano = ?, Fecha = NOW(), Found = 1 WHERE id_ = ?");
    $stmtUpdFile->bind_param("ii", $fileSize, $fileRow['id_']);
    $stmtUpdFile->execute();
    $stmtUpdFile->close();
} else {
    $newFileId = next_id($db_connection, 'FileS3', 'id_');
    $metadata = json_encode(['source' => 'ai_editor', 'project_id' => $projectId, 's3_key' => $originalS3Key]);
    
    $stmtInsFile = $db_connection->prepare("
        INSERT INTO FileS3 (id_, Nombre, Encriptado, Tamano, Metadatos, Ruta, Found, AccessType, Fecha, user_id_)
        VALUES (?, ?, ?, ?, ?, ?, 1, 'normal', NOW(), ?)
    ");
    $stmtInsFile->bind_param("ississi", $newFileId, $filename, $encriptadoVal, $fileSize, $metadata, $folderPrefix, $userId);
    $stmtInsFile->execute();
    $stmtInsFile->close();
}

    // ✅ Todo salió bien: confirmamos la transacción
    $db_connection->commit();
} catch (Throwable $e) {
    // ✅ CORRECCIÓN: si algo falla a mitad de camino, revertimos los cambios en BD
    // (el archivo ya subido a S3 en el paso 14b queda huérfano, pero la BD queda consistente)
    $db_connection->rollback();
    error_log("Error en Paso 14 (S3/BD Sync): " . $e->getMessage());
    jexit(['ok' => false, 'error' => 'No se pudo guardar el archivo en S3/BD: ' . $e->getMessage()], 500);
}

// ===== 14d. RESUMEN PROFESIONAL DEL TRABAJO (modelo revisor de código) =====
// Se genera a partir del código YA subido a S3, no de la instrucción cruda,
// para no perder nombres de variables/funciones/clases en la memoria de la sesión.
$summaryResult = summarizeCodeChange($bedrock, $db_connection, $sessionId, $newVersionId, $instruction, $targetFilename, $newContent, $isCreation);
$diffSummary = $summaryResult['text'];
try {
    $stmtUpdSummary = $db_connection->prepare("UPDATE FileVersions SET diff_summary = ? WHERE id_ = ?");
    $stmtUpdSummary->bind_param('si', $diffSummary, $newVersionId);
    $stmtUpdSummary->execute();
    $stmtUpdSummary->close();
} catch (Throwable $e) {
    error_log("No se pudo actualizar diff_summary: " . $e->getMessage());
}

// ===== 14e. INDEXACIÓN REAL (chunks + embeddings) DEL ARCHIVO GENERADO =====
// Antes esto dependía de que el frontend llamara después a index_project_sources.php;
// si esa llamada fallaba o el usuario no esperaba, el archivo quedaba marcado como
// 'indexed' en la BD sin tener chunks/embeddings reales, y la IA no podía "verlo"
// en búsquedas posteriores. Ahora se indexa aquí mismo, con el contenido en memoria.
$indexResult = ['ok' => false, 'error' => 'no ejecutado'];
try {
    $indexResult = indexProjectSourceContent($db_connection, $bedrock, $projectId, (int)$source['id_'], $targetFilename, $newContent);
} catch (Throwable $e) {
    $indexResult = ['ok' => false, 'error' => $e->getMessage()];
    error_log("Error indexando {$targetFilename} tras code_edit: " . $e->getMessage());
}

// ===== 15. Respuesta exitosa con Análisis de Impacto =====
$downloadUrl = 'descargar.php?archivo=' . urlencode($source['s3_key']) . '&nombre=' . urlencode($targetFilename);

// El análisis de impacto es SOLO informativo: detecta qué otros archivos
// referencian los símbolos tocados, pero el sistema no aplica refactor en
// cascada. Antes se anunciaba "se recomienda aplicar refactor en cascada",
// prometiendo una capacidad inexistente; ahora sale como advertencia para que
// el humano revise esos archivos.
$warnings = [];
if (!empty($impactAnalysis['is_multi_file'])) {
    $warnings[] = [
        'code'    => 'referencias_en_otros_archivos',
        'message' => 'Otros ' . count($impactAnalysis['affected_files']) . ' archivo(s) referencian los símbolos modificados. Revísalos manualmente: este cambio no se propagó a ellos.',
        'files'   => array_keys($impactAnalysis['affected_files']),
    ];
}
if (!($indexResult['ok'] ?? false)) {
    $warnings[] = [
        'code'    => 'indexacion_fallida',
        'message' => 'El archivo se guardó pero no se pudo indexar, así que aún no aparecerá en las búsquedas semánticas.',
        'detail'  => $indexResult['error'] ?? null,
    ];
}

jexit([
    'ok'             => true,
    'message'        => $isCreation
        ? "✅ Archivo '{$targetFilename}' creado en el proyecto."
        : "✅ Archivo '{$targetFilename}' actualizado (respaldo .ver0 creado).",
    'filename'       => $targetFilename,
    'new_version'    => $nextVersion,
    'download_url'   => $downloadUrl,
    'diff_summary'   => $diffSummary,
    'summary_model'  => $summaryResult['model'],
    'model_used'     => $attemptLog ? $attemptLog[count($attemptLog) - 1]['model'] : 'unknown',
    'strategy'       => $strategyUsed,
    'anchor_failures' => $anchorFailures,
    'complexity'     => $category ?? 'unknown',
    'indexed'        => (bool)($indexResult['ok'] ?? false),
    'index_error'    => $indexResult['ok'] ? null : ($indexResult['error'] ?? null),
    'needs_indexing' => false,
    'attempt_log'    => $attemptLog,
    'impact_analysis' => $impactAnalysis,
    'warnings'       => $warnings,
]);