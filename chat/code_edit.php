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

// ===== LOCK DE EDICIÓN =====
// Nombre del lock que tiene este request, o null. Global porque lo necesitan
// tanto jexit() como el shutdown handler.
$GLOBALS['edit_lock_name'] = null;

/**
 * Suelta el lock de edición si lo tenemos. Idempotente.
 *
 * Se llama desde jexit() y desde register_shutdown_function(). NO se usa
 * try/finally: jexit() termina en exit, y exit NO ejecuta los bloques finally.
 * Con ~15 jexit() repartidos por el flujo de escritura, un finally habría
 * dejado el lock tomado en casi todas las salidas de error.
 */
function releaseEditLock(): void {
    if (empty($GLOBALS['edit_lock_name'])) {
        return;
    }
    $name = $GLOBALS['edit_lock_name'];
    $GLOBALS['edit_lock_name'] = null; // antes de soltar: si algo lanza, no reentramos

    $db = $GLOBALS['db_connection'] ?? null;
    if (!($db instanceof mysqli)) {
        return;
    }
    try {
        $stmt = $db->prepare("SELECT RELEASE_LOCK(?)");
        if ($stmt) {
            $stmt->bind_param('s', $name);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log("No se pudo soltar el lock de edición '{$name}': " . $e->getMessage());
    }
}

function jexit($arr, $code = 200) {
    releaseEditLock();
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Suelta cualquier instancia del lock heredada por esta conexión.
 *
 * Hace falta por el escenario de conexión persistente ('p:' en el host de
 * db-s3.php, pendiente de confirmar en el servidor). Si la conexión sobrevive
 * al request, puede llegarnos con el lock ya tomado por un request anterior que
 * murió sin soltarlo. Y GET_LOCK en MySQL es REENTRANTE: la misma conexión
 * puede volver a tomarlo, incrementando un contador interno, y tendría éxito
 * aunque el lock siga lógicamente ocupado. Es decir: sin esto, la exclusión
 * mutua se rompe justo entre requests del mismo worker de PHP-FPM, que es donde
 * más probable es que coincidan.
 *
 * Con conexión NO persistente esta función no encuentra nada y no hace nada, así
 * que el código sirve para los dos escenarios sin saber cuál está activo.
 */
function releaseInheritedLocks(mysqli $db, string $name): void {
    for ($i = 0; $i < 16; $i++) {
        $stmt = $db->prepare("SELECT IS_USED_LOCK(?) = CONNECTION_ID() AS mine");
        if (!$stmt) return;
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (empty($row['mine'])) {
            return; // o está libre, o lo tiene otra conexión: no es nuestro
        }

        $rel = $db->prepare("SELECT RELEASE_LOCK(?)");
        if (!$rel) return;
        $rel->bind_param('s', $name);
        $rel->execute();
        $rel->close();
    }
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

// ===== 3.6. Tomar el lock de edición (solo el flujo de escritura) =====
// Va ANTES de leer el contenido de S3, no después: si se tomara después, otro
// request podría escribir entre nuestra lectura y nuestro lock, y estaríamos
// editando contra una versión que ya no existe.
if ($action === 'write') {
    $lockName = "edit:{$projectId}:{$targetFilename}";

    // Escenario de conexión persistente: soltar lo heredado antes de pedirlo.
    releaseInheritedLocks($db_connection, $lockName);

    $stmtLock = $db_connection->prepare("SELECT GET_LOCK(?, 5) AS got");
    if (!$stmtLock) {
        jexit(['ok' => false, 'error' => 'No se pudo solicitar el lock de edición.'], 500);
    }
    $stmtLock->bind_param('s', $lockName);
    $stmtLock->execute();
    $lockRow = $stmtLock->get_result()->fetch_assoc();
    $stmtLock->close();

    // GET_LOCK devuelve 1 si lo obtuvo, 0 si expiró el timeout y NULL si hubo error.
    if (($lockRow['got'] ?? null) !== 1 && ($lockRow['got'] ?? null) !== '1') {
        jexit([
            'ok'    => false,
            'error' => "Otra edición de '{$targetFilename}' está en curso. Inténtalo de nuevo en unos segundos.",
            'code'  => 'edicion_en_curso',
        ], 409);
    }

    $GLOBALS['edit_lock_name'] = $lockName;
    // Red de seguridad: cubre fatal errors y timeouts, donde jexit() no llega a
    // ejecutarse. register_shutdown_function SÍ corre tras exit().
    register_shutdown_function('releaseEditLock');
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

        // 2. Limpiar el registro legacy FileS3 a través de la FK.
        // El paso 14 ahora puebla ProjectSources.files3_id_, así que se puede
        // borrar por relación en vez de reconstruir Ruta+Nombre con
        // basename/dirname y confiar en que coincidan.
        // El WHERE sobre user_id_ se conserva: la FK dice qué fila es, pero la
        // propiedad la sigue mandando el usuario autenticado.
        $stmtDelLegacy = $db_connection->prepare(
            "DELETE f FROM FileS3 f
             JOIN ProjectSources ps ON ps.files3_id_ = f.id_
             WHERE ps.id_ = ? AND f.user_id_ = ?"
        );
        $stmtDelLegacy->bind_param('ii', $source['id_'], $userId);
        $stmtDelLegacy->execute();
        $borradasPorFk = $stmtDelLegacy->affected_rows;
        $stmtDelLegacy->close();

        // Fallback para las filas anteriores a la Fase 2, que tienen
        // files3_id_ = NULL y por tanto no las alcanza el JOIN.
        if ($borradasPorFk === 0) {
            $legacyFilename = basename($source['s3_key']);
            $legacyFolder = dirname($source['s3_key']);
            if ($legacyFolder === '.') $legacyFolder = '';
            $stmtDelOld = $db_connection->prepare("DELETE FROM FileS3 WHERE user_id_ = ? AND Ruta = ? AND Nombre = ?");
            $stmtDelOld->bind_param('iss', $userId, $legacyFolder, $legacyFilename);
            $stmtDelOld->execute();
            $stmtDelOld->close();
        }

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
            $tcPhase = 'compile'; // 'compile' es válido en el ENUM de tu tabla TokenUsage
            $tcModel = 'amazon.nova-micro-v1:0';
            $tcCost = ($inputTokens / 1000 * 0.000035) + ($outputTokens / 1000 * 0.00014);
            $sqlTC = "INSERT INTO TokenUsage (session_id_, message_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms) VALUES (?, NULL, ?, ?, ?, ?, ?, ?)";
            $stmtTC = $db->prepare($sqlTC);
            if ($stmtTC) {
                $durationMs = 0;
                $stmtTC->bind_param("issiddi", $sessionId, $tcPhase, $tcModel, $inputTokens, $outputTokens, $tcCost, $durationMs);
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
            $tcPhase = 'lint_fix';
            $tcModel = 'amazon.nova-micro-v1:0';
            $costIn = 0.000035; 
            $costOut = 0.00014;
            
            $tcCost = ($inputTokens / 1000 * $costIn) + ($outputTokens / 1000 * $costOut);
            
            $sqlTC = "INSERT INTO TokenUsage (session_id_, message_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms)
                      VALUES (?, NULL, ?, ?, ?, ?, ?, ?)";
            $stmtTC = $db->prepare($sqlTC);
            if ($stmtTC) {
                $durationMs = 0; // ✅ CORRECCIÓN: Variable en lugar de 0
                $stmtTC->bind_param("issiddi", $sessionId, $tcPhase, $tcModel, $inputTokens, $outputTokens, $tcCost, $durationMs);
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
            $tcPhase = 'compile';
            $tcCost = ($inputTokens / 1000 * 0.0008) + ($outputTokens / 1000 * 0.004);
            $sqlTC = "INSERT INTO TokenUsage (session_id_, message_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms)
                      VALUES (?, NULL, ?, ?, ?, ?, ?, ?)";
            $stmtTC = $db->prepare($sqlTC);
            if ($stmtTC) {
                $durationMs = 0;
                $stmtTC->bind_param("issiddi", $sessionId, $tcPhase, $reviewerModel, $inputTokens, $outputTokens, $tcCost, $durationMs);
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

$diffSummary = mb_substr($instruction, 0, 100) . (mb_strlen($instruction) > 100 ? '...' : '');

// La key versionada cuelga de la canónica: {key}.v{n}. Antes s3_path apuntaba
// aquí pero NADIE subía este objeto — la columna señalaba a una key inexistente
// y el respaldo real iba a un .ver0 que se sobrescribía en cada edición, así que
// solo se podía volver a la penúltima versión. Ahora este objeto sí se sube.
$versionedS3Key = $source['s3_key'] . '.v' . $nextVersion;

// Estado ANTES de tocar nada. Sin esto no hay forma de comprobar después que se
// sobrescribió el archivo que creíamos.
$sha256Before = $isCreation ? null : hash('sha256', $currentContent);
$bytesBefore  = $isCreation ? null : strlen($currentContent);

// La versión nace en 'draft'. `status` es el ciclo de vida de la ESCRITURA y lo
// pone el sistema; `is_stable` es "un humano marcó esta versión como la buena" y
// se queda en 0. committed != stable.
// id_ es AUTO_INCREMENT: se omite y se lee de insert_id.
$draftStatus = Schema::FV_DRAFT;
$stmtInsert = $db_connection->prepare("
    INSERT INTO FileVersions
    (project_id_, session_id_, message_id_, original_filename, version, s3_path, diff_summary, is_stable,
     status, sha256_before, bytes_before)
    VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)
");
$stmtInsert->bind_param('iiissssssi', $projectId, $sessionId, $messageId, $targetFilename, $nextVersion,
                        $versionedS3Key, $diffSummary, $draftStatus, $sha256Before, $bytesBefore);
$stmtInsert->execute();
$newVersionId = (int) $db_connection->insert_id;
$stmtInsert->close();

/**
 * Cierra la versión como 'failed' y responde.
 *
 * Sin esto, cada fallo dejaba la fila en 'draft' para siempre y no había manera
 * de distinguir "se está escribiendo ahora mismo" de "se murió a mitad hace tres
 * semanas".
 */
function failVersion(mysqli $db, int $versionId, string $errorMessage, ?string $modelUsed, array $payload, int $httpCode = 500) {
    try {
        $failed = Schema::FV_FAILED;
        $stmt = $db->prepare("UPDATE FileVersions SET status = ?, error_message = ?, model_used = ? WHERE id_ = ?");
        if ($stmt) {
            $stmt->bind_param('sssi', $failed, $errorMessage, $modelUsed, $versionId);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log('No se pudo marcar FileVersions#' . $versionId . ' como failed: ' . $e->getMessage());
    }
    jexit($payload, $httpCode);
}

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
$modelUsed    = null;   // modelo que produjo el resultado bueno (FileVersions.model_used)

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

        $stmt = $db_connection->prepare(
            "INSERT INTO TokenUsage (session_id_, message_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            $stmt->bind_param("issiddi", $sessionId, $phase, $model, $inTok, $outTok, $cost, $durationMs);
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
                    $modelUsed    = $tier['model'];
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
                    $modelUsed    = $tier['model'];
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
                    $modelUsed    = $tier['model'];
                        break;
                    }
                    $lastError = $lintResult['error'];
                }
            }
        }
    }
} catch (Throwable $e) {
    failVersion($db_connection, $newVersionId, 'Error crítico en la escalera: ' . $e->getMessage(), $modelUsed,
        ['ok' => false, 'error' => 'Error crítico en la escalera de modelos: ' . $e->getMessage()], 500);
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

    failVersion($db_connection, $newVersionId, $lastError !== '' ? $lastError : $mensaje, $modelUsed, [
        'ok'          => false,
        'error'       => $mensaje,
        'code'        => $failureCode,
        'last_error'  => $lastError,
        'anchor_failures' => $anchorFailures,
        'attempt_log' => $attemptLog
    ], $isRejection ? 422 : 500);
}

// ===== 14. Éxito: persistir el resultado =====
//
// ORDEN, Y POR QUÉ ESTE Y NO OTRO
//
// Antes el putObject de la key canónica vivía DENTRO de begin_transaction(), así
// que un rollback dejaba S3 adelantado respecto a la base: el archivo nuevo ya
// estaba publicado y la BD seguía describiendo el viejo. S3 no participa en la
// transacción y no tiene rollback, así que el orden tiene que estar pensado para
// que el paso irreversible sea el ÚLTIMO:
//
//   1. Subir a la key VERSIONADA ({key}.v{n}). Objeto nuevo, nadie lo lee aún:
//      si algo falla después, sobra pero no corrompe nada.
//   2. COMMIT de la base.
//   3. Actualizar la key CANÓNICA. Este es el paso que publica el cambio.
//   4. Si el commit falló: rollback + borrar el objeto versionado (compensación).
//
// La ventana que queda es entre 2 y 3: si el proceso muere ahí, la BD dice
// 'committed' y la canónica sigue teniendo el contenido viejo. Es recuperable
// —el objeto versionado existe y FileVersions.s3_path apunta a él—, al revés no
// lo sería.
$originalS3Key = $source['s3_key'];

// Detectar MIME type y lenguaje básico
$ext = strtolower(pathinfo($targetFilename, PATHINFO_EXTENSION));
$mimeMap = ['php' => 'text/x-php', 'js' => 'application/javascript', 'html' => 'text/html', 'css' => 'text/css', 'py' => 'text/x-python'];
$mimeType = $mimeMap[$ext] ?? 'text/plain';
$lang = $ext === 'php' ? 'php' : ($ext === 'js' ? 'javascript' : $ext);

$sizeBytes    = strlen($newContent);
$sha256After  = hash('sha256', $newContent);

// ---------- 14a. Subir la versión (paso 1) ----------
// Reemplaza al respaldo .ver0, que se sobrescribía en cada edición y por tanto
// solo permitía volver una versión atrás.
try {
    $s3->putObject([
        'Bucket'      => $bucket,
        'Key'         => $versionedS3Key,
        'Body'        => $newContent,
        'ContentType' => $mimeType,
        'ACL'         => 'private',
    ]);
} catch (Throwable $e) {
    failVersion($db_connection, $newVersionId, 'No se pudo subir la versión a S3: ' . $e->getMessage(), $modelUsed,
        ['ok' => false, 'error' => 'No se pudo guardar la versión en S3: ' . $e->getMessage()], 500);
}

// ---------- 14b. Escrituras en base (paso 2) ----------
$db_connection->begin_transaction();
try {
    $filename     = basename($originalS3Key);
    $folderPrefix = dirname($originalS3Key);
    if ($folderPrefix === '.') $folderPrefix = '';

    // El hash incluye el user_id_ porque `Encriptado` forma parte de
    // uq_files3_user_key (user_id_, Encriptado): sin él, dos usuarios con el
    // mismo s3_key relativo colisionarían.
    $encriptadoVal = hash('sha256', $userId . '|' . $originalS3Key);

    // --- A) Carpeta en S3Folders ---
    // PrefixHash es GENERATED: no se envía nunca.
    if ($folderPrefix !== '') {
        $stmtFolder = $db_connection->prepare("SELECT id_ FROM S3Folders WHERE user_id_ = ? AND Prefix = ? LIMIT 1");
        $stmtFolder->bind_param("is", $userId, $folderPrefix);
        $stmtFolder->execute();
        $existeCarpeta = $stmtFolder->get_result()->num_rows > 0;
        $stmtFolder->close();

        if (!$existeCarpeta) {
            $folderName = basename($folderPrefix);
            $parentPrefix = dirname($folderPrefix);
            if ($parentPrefix === '.') $parentPrefix = '';

            $stmtInsFolder = $db_connection->prepare("
                INSERT INTO S3Folders (user_id_, Prefix, Nombre, ParentPrefix, Found, AccessType, CreatedAt, UpdatedAt)
                VALUES (?, ?, ?, ?, 1, 'normal', NOW(), NOW())
            ");
            $stmtInsFolder->bind_param("isss", $userId, $folderPrefix, $folderName, $parentPrefix);
            $stmtInsFolder->execute();
            $stmtInsFolder->close();
        }
    }

    // --- B) Archivo en FileS3, PRIMERO ---
    // Se sincroniza antes que ProjectSources para poder guardar su id_ en
    // ProjectSources.files3_id_. Esa FK existía desde siempre y siempre se
    // insertaba NULL, lo que obligaba a buscar la fila legacy por
    // basename/dirname en el borrado. Con la FK poblada, el delete es un JOIN.
    $stmtFile = $db_connection->prepare("SELECT id_ FROM FileS3 WHERE user_id_ = ? AND Ruta = ? AND Nombre = ? LIMIT 1");
    $stmtFile->bind_param("iss", $userId, $folderPrefix, $filename);
    $stmtFile->execute();
    $fileRow = $stmtFile->get_result()->fetch_assoc();
    $stmtFile->close();

    if ($fileRow) {
        $files3Id = (int) $fileRow['id_'];
        $stmtUpdFile = $db_connection->prepare("UPDATE FileS3 SET Tamano = ?, Fecha = NOW(), Found = 1 WHERE id_ = ?");
        $stmtUpdFile->bind_param("ii", $sizeBytes, $files3Id);
        $stmtUpdFile->execute();
        $stmtUpdFile->close();
    } else {
        $metadata = json_encode(['source' => 'ai_editor', 'project_id' => $projectId, 's3_key' => $originalS3Key]);
        $stmtInsFile = $db_connection->prepare("
            INSERT INTO FileS3 (Nombre, Encriptado, Tamano, Metadatos, Ruta, Found, AccessType, Fecha, user_id_)
            VALUES (?, ?, ?, ?, ?, 1, 'normal', NOW(), ?)
        ");
        $stmtInsFile->bind_param("ssissi", $filename, $encriptadoVal, $sizeBytes, $metadata, $folderPrefix, $userId);
        $stmtInsFile->execute();
        $files3Id = (int) $db_connection->insert_id;
        $stmtInsFile->close();
    }

    // --- C) ProjectSources ---
    // INSERT ... ON DUPLICATE KEY UPDATE apoyado en uq_source_project_key
    // (project_id_, s3_key_hash). Esto sustituye a la rama de "archivo zombie":
    // aquel SELECT-y-luego-decide era una comprobación de existencia hecha a
    // mano que el índice único ya resuelve, y además era carrera pura entre dos
    // requests concurrentes. s3_key_hash es GENERATED: no se envía.
    $indexedStatus = Schema::SOURCE_INDEXED;
    $stmtSource = $db_connection->prepare("
        INSERT INTO ProjectSources
            (project_id_, files3_id_, s3_key, filename, mime_type, size_bytes, language, sha256, status, indexed_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            files3_id_  = VALUES(files3_id_),
            filename    = VALUES(filename),
            mime_type   = VALUES(mime_type),
            size_bytes  = VALUES(size_bytes),
            language    = VALUES(language),
            sha256      = VALUES(sha256),
            status      = VALUES(status),
            indexed_at  = NOW(),
            id_         = LAST_INSERT_ID(id_)
    ");
    $stmtSource->bind_param(
        "iisssisss",
        $projectId, $files3Id, $originalS3Key, $targetFilename, $mimeType, $sizeBytes, $lang, $sha256After, $indexedStatus
    );
    $stmtSource->execute();
    // LAST_INSERT_ID(id_) en la rama UPDATE hace que insert_id devuelva el id_
    // de la fila existente en vez de 0.
    $source['id_'] = (int) $db_connection->insert_id;
    $stmtSource->close();

    // --- D) Cerrar la versión como 'committed' ---
    $committedStatus = Schema::FV_COMMITTED;
    $stmtCommitVer = $db_connection->prepare("
        UPDATE FileVersions
        SET status = ?, sha256_after = ?, bytes_after = ?, model_used = ?
        WHERE id_ = ?
    ");
    $stmtCommitVer->bind_param('ssisi', $committedStatus, $sha256After, $sizeBytes, $modelUsed, $newVersionId);
    $stmtCommitVer->execute();
    $stmtCommitVer->close();

    $db_connection->commit();
} catch (Throwable $e) {
    // ---------- Paso 4: compensación ----------
    $db_connection->rollback();

    // El objeto versionado ya está en S3 y la base no lo respalda: se borra.
    // Si este borrado también falla, queda un objeto huérfano —desperdicia
    // espacio, no corrompe nada— y se registra para poder limpiarlo.
    try {
        $s3->deleteObject(['Bucket' => $bucket, 'Key' => $versionedS3Key]);
    } catch (Throwable $e2) {
        error_log("Compensación fallida: quedó huérfano el objeto S3 '{$versionedS3Key}': " . $e2->getMessage());
    }

    error_log("Error en Paso 14 (BD): " . $e->getMessage());
    failVersion($db_connection, $newVersionId, $e->getMessage(), $modelUsed,
        ['ok' => false, 'error' => 'No se pudo guardar el archivo en la base de datos: ' . $e->getMessage()], 500);
}

// ---------- 14c. Publicar en la key canónica (paso 3) ----------
// Único paso irreversible, y va después del commit a propósito.
try {
    $s3->putObject([
        'Bucket'      => $bucket,
        'Key'         => $originalS3Key,
        'Body'        => $newContent,
        'ContentType' => $mimeType,
        'ACL'         => 'private',
    ]);
} catch (Throwable $e) {
    // La base ya está confirmada, así que esto no se puede deshacer con un
    // rollback. La versión existe en S3 y s3_path apunta a ella: el cambio es
    // recuperable a mano. Se marca 'failed' para que no parezca publicado.
    error_log("La versión {$nextVersion} de {$targetFilename} quedó sin publicar en la canónica: " . $e->getMessage());
    failVersion($db_connection, $newVersionId,
        'Versión guardada pero no publicada en la key canónica: ' . $e->getMessage(), $modelUsed,
        [
            'ok'            => false,
            'error'         => 'El cambio se guardó como versión pero no se pudo publicar el archivo.',
            'code'          => 'publicacion_fallida',
            'version'       => $nextVersion,
            'versioned_key' => $versionedS3Key,
        ], 500);
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
        ? "✅ Archivo '{$targetFilename}' creado en el proyecto (versión {$nextVersion})."
        : "✅ Archivo '{$targetFilename}' actualizado (versión {$nextVersion} guardada).",
    'filename'       => $targetFilename,
    'new_version'    => $nextVersion,
    // Key del objeto versionado, que ahora existe de verdad en S3.
    'versioned_key'  => $versionedS3Key,
    'download_url'   => $downloadUrl,
    'diff_summary'   => $diffSummary,
    'summary_model'  => $summaryResult['model'],
    // El modelo que produjo el resultado bueno, no el del último intento
    // registrado (que en una escalera con fallos es otro).
    'model_used'     => $modelUsed ?? 'unknown',
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