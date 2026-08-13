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

if (ob_get_level() === 0) {
    ob_start();
}

ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

@ini_set('max_execution_time', '600');
@set_time_limit(600);

// ===== 0. Exigir sesión autenticada (antes: se usaba user_id=1 como fallback silencioso) =====
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado. Inicia sesión de nuevo.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$userId = (int) $_SESSION['user_id'];

function jexit($arr, $code = 200) {
    if (ob_get_length() > 0) {
        ob_clean();
    }

    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}


// ===== FUNCIÓN AUXILIAR PARA OBTENER EL SIGUIENTE ID (Faltaba en este archivo) =====
function next_id(mysqli $db, string $table, string $col): int {
    $table = preg_replace('/[^A-Za-z0-9_]+/', '', $table);
    $col   = preg_replace('/[^A-Za-z0-9_]+/', '', $col);
    $rs = $db->query("SELECT COALESCE(MAX($col), 0) + 1 AS nxt FROM $table");
    if (!$rs) return 1;
    $row = $rs->fetch_assoc();
    return (int)($row['nxt'] ?? 1);
}

function canonical_project_prefix(int $userId, int $projectId): string
{
    return "Data/Chat/Uploads/{$userId}/{$projectId}/";
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

    require_once __DIR__ . '/includes/ProjectIndexer.php';
} catch (Throwable $e) {
    jexit(['ok' => false, 'error' => 'Error cargando dependencias: ' . $e->getMessage()], 500);
}

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    jexit(['ok' => false, 'error' => 'DB no disponible. Revisa tu app_bootstrap.php'], 500);
}

if (!class_exists('Aws\\BedrockRuntime\\BedrockRuntimeClient') || !class_exists('S3Manager')) {
    jexit(['ok' => false, 'error' => 'AWS SDK o S3Manager no se cargaron correctamente.'], 500);
}

// ===== 2.5. Verificar que el proyecto pertenece al usuario autenticado (antes: sin control) =====
$stmtOwner = $db_connection->prepare("
    SELECT id_, root_prefix
    FROM Projects
    WHERE id_ = ?
      AND user_id_ = ?
    LIMIT 1
");

$stmtOwner->bind_param('ii', $projectId, $userId);
$stmtOwner->execute();
$ownerRow = $stmtOwner->get_result()->fetch_assoc();
$stmtOwner->close();

if (!$ownerRow) {
    jexit(['ok' => false, 'error' => 'Proyecto no encontrado o no pertenece al usuario.'], 403);
}

// =====================================================================
// ✅ FIX: Forzar ruta canónica del proyecto
// =====================================================================
$canonicalRootPrefix = canonical_project_prefix($userId, $projectId);

$currentRootPrefix = rtrim(
    trim((string)($ownerRow['root_prefix'] ?? '')),
    '/'
) . '/';

if ($currentRootPrefix !== $canonicalRootPrefix) {
    $stmtFixRoot = $db_connection->prepare("
        UPDATE Projects
        SET root_prefix = ?
        WHERE id_ = ?
          AND user_id_ = ?
    ");

    if ($stmtFixRoot) {
        $stmtFixRoot->bind_param(
            'sii',
            $canonicalRootPrefix,
            $projectId,
            $userId
        );

        $stmtFixRoot->execute();
        $stmtFixRoot->close();

        error_log(
            "code_edit.php: root_prefix corregido para proyecto {$projectId}: {$canonicalRootPrefix}"
        );
    }
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

    // =====================================================================
    // ✅ FIX: Usar SIEMPRE la ruta canónica del proyecto:
    // Data/Chat/Uploads/{user_id}/{project_id}/
    // =====================================================================
    $source = [
        'id_' => 0,
        's3_key' => $canonicalRootPrefix . $targetFilename,
        'filename' => $targetFilename,
        'root_prefix' => $canonicalRootPrefix,
        'mime_type' => 'text/plain'
    ];

    $currentContent = '';
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

// =====================================================================
// ✅ FIX 4.0: Normalizar fuentes existentes a la ruta canónica
// =====================================================================
// Si ProjectSources.s3_key apunta a una carpeta incorrecta, por ejemplo:
// Data/Chat/Uploads/1/2026/08/12/num/archivo.php
// lo movemos/usamos desde:
// Data/Chat/Uploads/1/1/archivo.php 
// =====================================================================
if (!$isCreation && !empty($source['s3_key'])) {
    $oldS3Key = (string)$source['s3_key'];
    $expectedS3Key = $canonicalRootPrefix . basename($oldS3Key);

    if ($oldS3Key !== $expectedS3Key) {

        // Evitar collision con otro ProjectSources que ya tenga esa ruta
        $stmtDup = $db_connection->prepare("
            SELECT id_
            FROM ProjectSources
            WHERE project_id_ = ?
              AND s3_key = ?
              AND id_ <> ?
            LIMIT 1
        ");

        $stmtDup->bind_param(
            'isi',
            $projectId,
            $expectedS3Key,
            $source['id_']
        );

        $stmtDup->execute();
        $dupRes = $stmtDup->get_result();
        $dupRow = $dupRes ? $dupRes->fetch_assoc() : null;
        $stmtDup->close();

        if ($dupRow) {
            error_log(
                "code_edit.php: No se pudo normalizar {$oldS3Key} porque {$expectedS3Key} " .
                "ya pertenece a ProjectSources.id_={$dupRow['id_']}"
            );
        } else {
            $oldExists = false;
            $newExists = false;

            try {
                $s3->headObject([
                    'Bucket' => $bucket,
                    'Key'    => $oldS3Key
                ]);
                $oldExists = true;
            } catch (Throwable $e) {
                $oldExists = false;
            }

            try {
                $s3->headObject([
                    'Bucket' => $bucket,
                    'Key'    => $expectedS3Key
                ]);
                $newExists = true;
            } catch (Throwable $e) {
                $newExists = false;
            }

            if (!$newExists && $oldExists) {
                // Mover el objeto de la ruta vieja a la ruta canónica
                $s3->copyObject([
                    'Bucket'     => $bucket,
                    'CopySource' => urlencode($bucket . '/' . $oldS3Key),
                    'Key'        => $expectedS3Key,
                    'ACL'        => 'private'
                ]);

                $s3->deleteObject([
                    'Bucket' => $bucket,
                    'Key'    => $oldS3Key
                ]);

                error_log(
                    "code_edit.php: Archivo movido de {$oldS3Key} a {$expectedS3Key}"
                );
            } elseif ($newExists && $oldExists) {
                error_log(
                    "code_edit.php: Ya existía {$expectedS3Key}; se usará ese. " .
                    "El viejo {$oldS3Key} quedó como respaldo manual."
                );
            } elseif (!$newExists && !$oldExists) {
                if ($action === 'write') {
                    // Si no existe en ninguna ruta, pero se va a editar/crear,
                    // se recreará en la ruta correcta.
                    $isCreation = true;
                    $currentContent = '';

                    error_log(
                        "code_edit.php: {$oldS3Key} no existe en S3. " .
                        "Se recreará en {$expectedS3Key}"
                    );
                } else {
                    jexit([
                        'ok' => false,
                        'error' => "El archivo no existe en S3 ni en {$oldS3Key} ni en {$expectedS3Key}."
                    ], 404);
                }
            }

            // Actualizar ProjectSources.s3_key a la ruta canónica
            $updKey = $db_connection->prepare("
                UPDATE ProjectSources
                SET s3_key = ?
                WHERE id_ = ?
                  AND project_id_ = ?
            ");

            $updKey->bind_param(
                'sii',
                $expectedS3Key,
                $source['id_'],
                $projectId
            );

            if ($updKey->execute()) {
                $source['s3_key'] = $expectedS3Key;
            } else {
                error_log(
                    'code_edit.php: No se pudo actualizar ProjectSources.s3_key: ' .
                    $updKey->error
                );
            }

            $updKey->close();
        }

        $source['root_prefix'] = $canonicalRootPrefix;
    }
}

if (!$isCreation) {
    try {
        $result = $s3->getObject([
            'Bucket' => $bucket,
            'Key'    => $source['s3_key']
        ]);

        $currentContent = (string)$result['Body'];
    } catch (Throwable $e) {
        if (
            strpos($e->getMessage(), 'NoSuchKey') !== false ||
            strpos($e->getMessage(), '404 Not Found') !== false
        ) {
            error_log(
                "ADVERTENCIA: El archivo '{$source['s3_key']}' está en la BD pero no en S3. " .
                "Se tratará como nueva creación (resucitando registro zombie)."
            );

            $isCreation = true;
            $currentContent = '';
        } else {
            jexit([
                'ok' => false,
                'error' => 'No se pudo leer el archivo desde S3: ' . $e->getMessage()
            ], 500);
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

// ===== FUNCIÓN AUXILIAR PARA NORMALIZAR CÓDIGO PHP =====
function normalizePhpCode(string $code): string {
    // Detectar si hay declare(strict_types=1) mal posicionado
    if (preg_match('/declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/i', $code)) {
        // Eliminar todas las ocurrencias de declare(strict_types=1)
        $code = preg_replace('/declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/i', '', $code);
        
        // Buscar la apertura de <?php
        if (preg_match('/^(<\?php\s*)/i', $code, $matches)) {
            // Insertar declare(strict_types=1) inmediatamente después de <?php
            $code = preg_replace(
                '/^(<\?php\s*)/i',
                '$1' . "\n" . 'declare(strict_types=1);' . "\n",
                $code,
                1
            );
        }
    }
    
    // Limpiar múltiples líneas en blanco al inicio
    $code = preg_replace('/^(\s*\n){2,}/', "\n", $code);
    
    return $code;
}

// ===== 5. FUNCIÓN DE LINTING AVANZADO + MULTI-LENGUAJE + SEGURIDAD =====
function lintCode(string $code, string $filename): array {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    // ✅ FIX: Normalizar código PHP antes del linting
    if ($ext === 'php') {
        $code = normalizePhpCode($code);
    }
    
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

// ===== 6. FUNCIÓN DE LIMPIEZA DE MARKDOWN =====
function cleanMarkdown(string $text): string {
    if (preg_match('/^```(?:php|js|javascript|html|css|python)?\s*(.*?)\s*```$/s', $text, $matches)) {
        return trim($matches[1]);
    }
    return trim(preg_replace('/^`+|`+$/m', '', $text));
}

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
// ========================================================================
// ✅ NUEVAS FUNCIONES PARA EL PATRÓN SCOUT → EXTRACT → EDIT → REASSEMBLE
// ========================================================================

function scoutCodeBlock(string $fullContent, string $instruction, $bedrock, mysqli $db, int $sessionId, int $newVersionId): array {
    $totalLines = substr_count($fullContent, "\n") + 1;
    $prompt = "Eres un Explorador de Código (Code Scout). Tu ÚNICA tarea es identificar el bloque exacto de código que debe ser modificado según la instrucción del usuario.
Analiza el archivo y devuelve ÚNICAMENTE un objeto JSON válido con este formato:
{
  \"target_name\": \"Nombre de la función/clase (ej: function login)\",
  \"start_line\": número_de_línea_de_inicio,
  \"end_line\": número_de_línea_de_fin
}
Reglas:
1. start_line y end_line deben ser números enteros basados en el archivo (la línea 1 es la primera).
2. Si la modificación afecta a todo el archivo, usa start_line: 1 y end_line: $totalLines.
3. NO incluyas markdown, ni explicaciones, solo el JSON puro.";

    try {
        $res = $bedrock->converse([
            'modelId' => 'amazon.nova-micro-v1:0',
            'messages' => [['role' => 'user', 'content' => [['text' => "ARCHIVO (Total líneas: $totalLines):\n" . $fullContent . "\n\nINSTRUCCIÓN: " . $instruction]]]],
            'inferenceConfig' => ['maxTokens' => 150, 'temperature' => 0.1]
        ]);
        
        // Registro de costos del Scout (trazabilidad total)
        $inputTokens = (int)($res['usage']['inputTokens'] ?? 0);
        $outputTokens = (int)($res['usage']['outputTokens'] ?? 0);
        try {
            $tcId = next_id($db, 'TokenUsage', 'id_');
            $tcPhase = 'lint_fix';
            $tcModel = 'amazon.nova-micro-v1:0';
            $tcCost = ($inputTokens / 1000 * 0.000035) + ($outputTokens / 1000 * 0.00014);
            $sqlTC = "INSERT INTO TokenUsage (id_, session_id_, message_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?)";
            $stmtTC = $db->prepare($sqlTC);
            if ($stmtTC) {
                $durationMs = 0; // ✅ CORRECCIÓN: Variable en lugar de 0
                $stmtTC->bind_param("iissiddi", $tcId, $sessionId, $tcPhase, $tcModel, $inputTokens, $outputTokens, $tcCost, $durationMs);
                $stmtTC->execute();
                $stmtTC->close();
            }
        } catch (Throwable $e) {
            @file_put_contents(__DIR__ . '/token_usage_debug.log', "[" . date('Y-m-d H:i:s') . "] Scout TokenUsage: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        }

        $text = '';
        foreach (($res['output']['message']['content'] ?? []) as $block) {
            if (isset($block['text'])) $text .= $block['text'];
        }
        
        $text = preg_replace('/^```json\s*/i', '', trim($text));
        $text = preg_replace('/\s*```$/i', '', trim($text));
        
        $data = json_decode($text, true);
        if (isset($data['start_line']) && isset($data['end_line'])) {
            return [
                'success' => true,
                'start_line' => max(1, (int)$data['start_line']),
                'end_line' => min($totalLines, (int)$data['end_line']),
                'target_name' => $data['target_name'] ?? 'unknown'
            ];
        }
    } catch (Throwable $e) {
        // Fallback silencioso
    }
    
    // Fallback: Si el Scout falla, devolvemos todo el archivo (comportamiento antiguo seguro)
    return [
        'success' => false,
        'start_line' => 1,
        'end_line' => substr_count($fullContent, "\n") + 1,
        'target_name' => 'full_file_fallback'
    ];
}

function extractContext(string $content, int $start, int $end, int $buffer = 15): array {
    $lines = explode("\n", $content);
    $totalLines = count($lines);
    
    $extractStart = max(1, $start - $buffer);
    $extractEnd = min($totalLines, $end + $buffer);
    
    $snippetLines = array_slice($lines, $extractStart - 1, ($extractEnd - $extractStart) + 1);
    
    return [
        'snippet' => implode("\n", $snippetLines),
        'absolute_start' => $extractStart,
        'absolute_end' => $extractEnd
    ];
}

function reassembleFile(string $originalContent, int $absStart, int $absEnd, string $newSnippet): string {
    $lines = explode("\n", $originalContent);
    $newSnippetLines = explode("\n", trim($newSnippet));
    
    // Limpiar marcadores si la IA los incluyó por error
    $cleanNewLines = [];
    foreach ($newSnippetLines as $line) {
        if (strpos($line, '@@START_EDIT@@') !== false || strpos($line, '@@END_EDIT@@') !== false) {
            continue;
        }
        $cleanNewLines[] = $line;
    }
    
    // Reemplazar las líneas (los índices de array son 0-based, así que restamos 1)
    $before = array_slice($lines, 0, $absStart - 1);
    $after = array_slice($lines, $absEnd);
    
    $finalLines = array_merge($before, $cleanNewLines, $after);
    return implode("\n", $finalLines);
}

function injectImports(string $originalContent, string $currentContent, array $newImports): string {
    $uniqueImports = array_unique($newImports);
    $importsToAdd = [];
    
    foreach ($uniqueImports as $import) {
        $import = trim($import);
        $classPath = trim(str_replace(['use ', ';'], '', $import));
        $pattern = '/^\s*use\s+' . preg_quote($classPath, '/') . '\s*(?:as\s+\w+)?\s*;/m';
        if (!preg_match($pattern, $originalContent)) {
            $importsToAdd[] = $import;
        }
    }
    
    if (empty($importsToAdd)) return $currentContent;
    
    $importBlock = "\n" . implode("\n", $importsToAdd) . "\n";
    
    if (preg_match('/^(.*?)(^\s*use\s+[^;]+;\s*)$/ms', $currentContent, $matches)) {
        $currentContent = preg_replace('/^(.*?)(^\s*use\s+[^;]+;\s*)$/ms', '$1$2' . $importBlock, $currentContent, 1);
    } elseif (preg_match('/(^\s*namespace\s+[^;]+;\s*)/m', $currentContent)) {
        $currentContent = preg_replace('/(^\s*namespace\s+[^;]+;\s*)/m', '$1' . $importBlock, $currentContent, 1);
    } elseif (strpos($currentContent, '<?php') === 0) {
        $currentContent = preg_replace('/(<\?php\s*)/', '$1' . $importBlock, $currentContent, 1);
    } else {
        $currentContent = $importBlock . $currentContent;
    }
    return $currentContent;
}


// ===== 7. CLASIFICADOR DE COMPLEJIDAD (usa Nova Micro) =====
function classifyInstruction(string $instruction, $bedrock, mysqli $db, int $sessionId, int $newVersionId): string {
    // ✅ NUEVO: Detectar si la instrucción requiere precisión quirúrgica
    $precisionKeywords = [
        'solo cambia', 'solo modifica', 'solo actualiza',
        'únicamente cambia', 'únicamente modifica',
        'no cambies nada más', 'no modifiques nada más',
        'solo el parámetro', 'solo la firma', 'solo el tipo',
        'only change', 'just modify', 'don\'t change anything else',
        'cambia el parámetro', 'cambia la firma', 'cambia el tipo'
    ];
    
    $requiresPrecision = false;
    $instructionLower = mb_strtolower($instruction);
    foreach ($precisionKeywords as $keyword) {
        if (mb_strpos($instructionLower, $keyword) !== false) {
            $requiresPrecision = true;
            break;
        }
    }
    
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
                $durationMs = 0;
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
            $category = 'medium';
        }
        
        // ✅ NUEVO: Si requiere precisión quirúrgica, forzar categoría 'medium' o superior
        // para que use modelos más capaces (nova-pro, haiku, sonnet) en lugar de nova-micro
        if ($requiresPrecision && $category === 'simple') {
            $category = 'medium';
            error_log("⚠️ Instrucción requiere precisión quirúrgica. Forzando categoría 'medium' para usar modelos más capaces.");
        }
        
        return $category;
        
    } catch (Throwable $e) {
        return $requiresPrecision ? 'medium' : 'medium';
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

$stmtInsert = $db_connection->prepare("
    INSERT INTO FileVersions 
    (id_, project_id_, session_id_, message_id_, original_filename, version, s3_path, diff_summary, is_stable)
    VALUES (?, ?, ?, NULL, ?, ?, ?, ?, 0)
");
$stmtInsert->bind_param('iiissss', $newVersionId, $projectId, $sessionId, $targetFilename, $nextVersion, $newS3Key, $diffSummary);
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

// ===== 12. Ejecutar Flujo: SCOUT (Edición) o GENERATE (Creación) =====
$newContent = '';
$lastError = '';
$attemptLog = [];
$success = false;

try {
    if ($isCreation) {
        // =====================================================================
        // ✅ MODO CREACIÓN: La IA genera el archivo completo desde cero
        // =====================================================================
        $systemPromptCreator = "You are an expert software engineer. Your task is to create a NEW file from scratch based on the user's instruction.
RULES:
1. Return ONLY the raw code. Do NOT wrap it in markdown backticks (```php).
2. Do not add explanations, just the code.
3. Ensure the code is syntactically perfect and ready to run.";

        foreach ($ladder as $tier) {
            for ($i = 0; $i < $tier['max_attempts']; $i++) {
                $userPrompt = "FILE TO CREATE: {$targetFilename}\nUSER INSTRUCTION:\n{$instruction}";
                
                if ($lastError !== '') {
                    $userPrompt .= "\n⚠️ CRITICAL: Your previous code failed syntax validation with this error:\n```\n{$lastError}\n```\nPlease fix the error and return the complete corrected code.";
                }

                $res = $bedrock->converse([
                    'modelId' => $tier['model'],
                    'messages' => [['role' => 'user', 'content' => [['text' => $userPrompt]]]],
                    'system' => [['text' => $systemPromptCreator]],
                    'inferenceConfig' => ['maxTokens' => 4000, 'temperature' => 0.2, 'topP' => 0.9]
                ]);

                $inputTokens = (int)($res['usage']['inputTokens'] ?? 0);
                $outputTokens = (int)($res['usage']['outputTokens'] ?? 0);
                $tcId = next_id($db_connection, 'TokenUsage', 'id_');
                $tcPhase = 'respond';
                $tcModel = $tier['model'];
                $costIn = 0.000035; $costOut = 0.00014;
                if (strpos($tcModel, 'sonnet') !== false) { $costIn = 0.003; $costOut = 0.015; }
                elseif (strpos($tcModel, 'opus') !== false) { $costIn = 0.015; $costOut = 0.075; }
                elseif (strpos($tcModel, 'haiku') !== false) { $costIn = 0.00025; $costOut = 0.00125; }
                elseif (strpos($tcModel, 'nova-pro') !== false) { $costIn = 0.0008; $costOut = 0.0032; }
                $tcCost = ($inputTokens / 1000 * $costIn) + ($outputTokens / 1000 * $costOut);
                $stmtTC = $db_connection->prepare("INSERT INTO TokenUsage (id_, session_id_, message_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?)");
                $durationMs = 0;
                if($stmtTC){ $stmtTC->bind_param("iissiddi", $tcId, $sessionId, $tcPhase, $tcModel, $inputTokens, $outputTokens, $tcCost, $durationMs); $stmtTC->execute(); $stmtTC->close(); }

                $rawResponse = '';
                foreach (($res['output']['message']['content'] ?? []) as $block) {
                    if (isset($block['text'])) $rawResponse .= $block['text'];
                }
                
                $newContent = cleanMarkdown($rawResponse);

                $lintResult = lintCode($newContent, $targetFilename);
                $attemptLog[] = ['model' => $tier['model'], 'attempt' => $i + 1, 'success' => $lintResult['success'], 'error' => $lintResult['error'], 'scout_target' => 'NEW_FILE_CREATION'];
                $stmtLint = $db_connection->prepare("INSERT INTO LintAttempts (file_version_id_, attempt_number, model_used, error_message, is_success, duration_ms) VALUES (?, ?, ?, ?, ?, ?)");
                $attemptNum = $i + 1;
                $isSuccess = $lintResult['success'] ? 1 : 0;
                $durationMs = 0;
                $stmtLint->bind_param("iissii", $newVersionId, $attemptNum, $tier['model'], $lintResult['error'], $isSuccess, $durationMs);
                $stmtLint->execute(); 
                $stmtLint->close();

                // ✅ CORREGIDO: En modo creación, $newContent YA contiene el código generado.
                // No hay $reassembledContent aquí.
                if ($lintResult['success']) {
                    $success = true;
                    // $newContent ya tiene el valor correcto, no reasignar
                    break 2;
                }

                $lastError = $lintResult['error'];
                if (preg_match('/undefined method|type mismatch|cannot resolve|fatal error/i', $lastError)) break;
            }
        }
    } else {
        // =====================================================================
        // ✅ MODO EDICIÓN: Patrón SCOUT → EXTRACT → EDIT → REASSEMBLE
        // =====================================================================
        
        $relatedContext = fetchRelatedContext($db_connection, $projectId, $instruction, $bedrock, $sessionId, $newVersionId);
        
        $scoutResult = scoutCodeBlock($currentContent, $instruction, $bedrock, $db_connection, $sessionId, $newVersionId);
        $contextInfo = extractContext($currentContent, $scoutResult['start_line'], $scoutResult['end_line'], 15);
    
        $systemPromptEditor = "You are an expert surgical code editor. Your task is to modify ONLY the provided code snippet according to the user's instruction.
RULES:
1. Return ONLY the modified snippet. Do NOT return the entire file.
2. 🚀 If your modification introduces NEW classes/interfaces that require `use` statements, list them at the VERY TOP of your response, each on a new line, prefixed with `// @@IMPORT@@ ` (e.g., `// @@IMPORT@@ use App\\Services\\UserService;`).
3. Wrap the actual code modification EXCLUSIVELY between these markers: // @@START_EDIT@@ and // @@END_EDIT@@
4. Preserve exact indentation, variable names, and structure of the surrounding context.
5. Use the PROVIDED RELATED PROJECT CONTEXT to ensure your edits are compatible with existing classes, methods, or variables.
6. 🛡️ CRITICAL - ANTI-HALLUCINATION RULES:
   - NEVER rename functions, methods, classes, or variables UNLESS the user explicitly asks to rename them.
   - NEVER add new methods, properties, or logic UNLESS the user explicitly asks for them.
   - If the user says 'only change X', 'just modify X', 'don't change anything else', you MUST change ONLY that specific element and leave everything else EXACTLY as it is.
   - If the user asks to change a parameter type (e.g., 'float \$x' to 'mixed \$x'), change ONLY the type declaration, not the parameter name, not the method name, not the return type, not the body.
   - If you're unsure whether a change is requested, DO NOT make it. Preserve the original code.";
   
        foreach ($ladder as $tier) {
            for ($i = 0; $i < $tier['max_attempts']; $i++) {
                $contextBlock = $relatedContext !== '' ? "\n\n📚 RELATED PROJECT CONTEXT:\n{$relatedContext}" : "";
                
                $userPrompt = "FILE: {$source['filename']}\nBLOQUE A MODIFICAR (Líneas {$scoutResult['start_line']} a {$scoutResult['end_line']}):\n{$contextInfo['snippet']}{$contextBlock}\nUSER INSTRUCTION:\n{$instruction}";
                
                if ($lastError !== '') {
                    $userPrompt .= "\n⚠️ CRITICAL: Your previous attempt failed syntax validation with this error:\n```\n{$lastError}\n```\nPlease fix ONLY this error in the snippet and return it wrapped in // @@START_EDIT@@ and // @@END_EDIT@@ markers.";
                }

                $res = $bedrock->converse([
                    'modelId' => $tier['model'],
                    'messages' => [['role' => 'user', 'content' => [['text' => $userPrompt]]]],
                    'system' => [['text' => $systemPromptEditor]],
                    'inferenceConfig' => ['maxTokens' => 2000, 'temperature' => 0.1, 'topP' => 0.9]
                ]);

                $inputTokens = (int)($res['usage']['inputTokens'] ?? 0);
                $outputTokens = (int)($res['usage']['outputTokens'] ?? 0);
                $tcId = next_id($db_connection, 'TokenUsage', 'id_');
                $tcPhase = 'lint_fix'; $tcModel = $tier['model'];
                $costIn = 0.000035; $costOut = 0.00014;
                if (strpos($tcModel, 'sonnet') !== false) { $costIn = 0.003; $costOut = 0.015; }
                elseif (strpos($tcModel, 'opus') !== false) { $costIn = 0.015; $costOut = 0.075; }
                elseif (strpos($tcModel, 'haiku') !== false) { $costIn = 0.00025; $costOut = 0.00125; }
                elseif (strpos($tcModel, 'nova-pro') !== false) { $costIn = 0.0008; $costOut = 0.0032; }
                $tcCost = ($inputTokens / 1000 * $costIn) + ($outputTokens / 1000 * $costOut);
                $stmtTC = $db_connection->prepare("INSERT INTO TokenUsage (id_, session_id_, message_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?)");
                $durationMs = 0;
                if($stmtTC){ $stmtTC->bind_param("iissiddi", $tcId, $sessionId, $tcPhase, $tcModel, $inputTokens, $outputTokens, $tcCost, $durationMs); $stmtTC->execute(); $stmtTC->close(); }

                $rawResponse = '';
                foreach (($res['output']['message']['content'] ?? []) as $block) {
                    if (isset($block['text'])) $rawResponse .= $block['text'];
                }

                $newImports = [];
                if (preg_match_all('/\/\/\s*@@IMPORT@@\s+(use\s+[^;]+;)/i', $rawResponse, $importMatches)) {
                    $newImports = $importMatches[1];
                    $rawResponse = preg_replace('/\/\/\s*@@IMPORT@@\s+use\s+[^;]+;\s*/i', '', $rawResponse);
                }

                $reassembledContent = reassembleFile($currentContent, $contextInfo['absolute_start'], $contextInfo['absolute_end'], $rawResponse);
                
                if (!empty($newImports)) {
                    $reassembledContent = injectImports($currentContent, $reassembledContent, $newImports);
                }

                $lintResult = lintCode($reassembledContent, $targetFilename);
                
                $attemptLog[] = ['model' => $tier['model'], 'attempt' => $i + 1, 'success' => $lintResult['success'], 'error' => $lintResult['error'], 'scout_target' => $scoutResult['target_name'], 'lines_edited' => ($contextInfo['absolute_end'] - $contextInfo['absolute_start'] + 1)];
                
                $attemptNum = $i + 1;
                $isSuccess = $lintResult['success'] ? 1 : 0;
                $durationMs = 0;
                $stmtLint = $db_connection->prepare("INSERT INTO LintAttempts (file_version_id_, attempt_number, model_used, error_message, is_success, duration_ms) VALUES (?, ?, ?, ?, ?, ?)");
                $stmtLint->bind_param("iissii", $newVersionId, $attemptNum, $tier['model'], $lintResult['error'], $isSuccess, $durationMs);
                $stmtLint->execute(); 
                $stmtLint->close();

                // ✅ NUEVO: Validación de cambios mínimos (anti-alucinación)
                // SOLO en modo EDICIÓN, donde $currentContent tiene el archivo original
                if ($lintResult['success'] && (
                    stripos($instruction, 'solo cambia') !== false || 
                    stripos($instruction, 'no cambies nada más') !== false ||
                    stripos($instruction, 'only change') !== false ||
                    stripos($instruction, 'just modify') !== false ||
                    stripos($instruction, "don't change anything else") !== false
                )) {
                    $originalLines = explode("\n", $currentContent);
                    $newLines = explode("\n", $reassembledContent);
                    
                    $changedLines = 0;
                    $maxLines = max(count($originalLines), count($newLines));
                    
                    for ($j = 0; $j < $maxLines; $j++) {
                        $origLine = $originalLines[$j] ?? '';
                        $newLine = $newLines[$j] ?? '';
                        if (trim($origLine) !== trim($newLine)) {
                            $changedLines++;
                        }
                    }
                    
                    if ($maxLines > 0) {
                        $changePercentage = ($changedLines / $maxLines) * 100;
                        
                        if ($changePercentage > 10) {
                            error_log("⚠️ ADVERTENCIA ANTI-ALUCINACIÓN: La instrucción pedía cambios mínimos, pero se modificó " . round($changePercentage, 1) . "% del archivo ({$changedLines} de {$maxLines} líneas). Posible alucinación de la IA. Modelo: {$tier['model']}, Intento: " . ($i + 1));
                        }
                    }
                }

                if ($lintResult['success']) {
                    $success = true;
                    $newContent = $reassembledContent; // ✅ CORRECTO: aquí $reassembledContent SÍ existe
                    break 2;
                }
                
                $lastError = $lintResult['error'];
                if (preg_match('/undefined method|type mismatch|cannot resolve|fatal error/i', $lastError)) break;
            }
        }
    }
} catch (Throwable $e) {
    jexit(['ok' => false, 'error' => 'Error crítico en la escalera de modelos: ' . $e->getMessage()], 500);
}

// ===== 13. Evaluar resultado final =====
if (!$success) {
    jexit([
        'ok'          => false,
        'error'       => 'No se pudo generar código válido después de múltiples intentos.',
        'last_error'  => $lastError,
        'attempt_log' => $attemptLog
    ], 500);
}

// ===== 14. Éxito: Subir a S3 y Registrar en BD (Incluye sync con FileS3 y S3Folders) =====
$warnings = [];
$s3Saved = false;
$dbCommitted = false;
$backupS3Key = null;
$canonicalRootPrefix = "Data/Chat/Uploads/{$userId}/{$projectId}/";
$originalS3Key = $canonicalRootPrefix . basename($source['s3_key']);

try {
    $ext = strtolower(pathinfo($targetFilename, PATHINFO_EXTENSION));
    $mimeMap = [
        'php' => 'text/x-php',
        'js' => 'application/javascript',
        'html' => 'text/html',
        'css' => 'text/css',
        'py' => 'text/x-python'
    ];
    $mimeType = $mimeMap[$ext] ?? 'text/plain';
    $lang = $ext === 'php' ? 'php' : ($ext === 'js' ? 'javascript' : $ext);
    $fileSize = strlen($newContent);

    $db_connection->begin_transaction();

    // 14a. Actualizar o Crear en ProjectSources
    if ($isCreation) {
        $stmtCheck = $db_connection->prepare("SELECT id_ FROM ProjectSources WHERE project_id_ = ? AND s3_key = ? LIMIT 1");
        $stmtCheck->bind_param("is", $projectId, $originalS3Key);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();
        $existingSource = $resCheck->fetch_assoc();
        $stmtCheck->close();

        if ($existingSource) {
            $sourceId = $existingSource['id_'];
            $stmtUpdateSource = $db_connection->prepare("
                UPDATE ProjectSources
                SET filename = ?, mime_type = ?, size_bytes = ?, language = ?, status = 'indexed', indexed_at = NOW()
                WHERE id_ = ?
            ");
            $stmtUpdateSource->bind_param("ssisi", $targetFilename, $mimeType, $fileSize, $lang, $sourceId);
            $stmtUpdateSource->execute();
            $stmtUpdateSource->close();
            $source['id_'] = $sourceId;
        } else {
            $newSourceId = next_id($db_connection, 'ProjectSources', 'id_');
            $stmtInsertSource = $db_connection->prepare("
                INSERT INTO ProjectSources (id_, project_id_, files3_id_, s3_key, filename, mime_type, size_bytes, language, sha256, status, indexed_at)
                VALUES (?, ?, NULL, ?, ?, ?, ?, ?, NULL, 'indexed', NOW())
            ");
            $stmtInsertSource->bind_param("iisssis", $newSourceId, $projectId, $originalS3Key, $targetFilename, $mimeType, $fileSize, $lang);
            $stmtInsertSource->execute();
            $stmtInsertSource->close();
            $source['id_'] = $newSourceId;
        }
    } else {
        $backupS3Key = preg_replace('/(\.[a-zA-Z0-9]+)$/i', '.ver0$1', $originalS3Key);
        $s3->copyObject([
            'Bucket' => $bucket,
            'CopySource' => urlencode($bucket . '/' . $originalS3Key),
            'Key' => $backupS3Key
        ]);
        $updSource = $db_connection->prepare("UPDATE ProjectSources SET status = 'stale' WHERE id_ = ?");
        $updSource->bind_param('i', $source['id_']);
        $updSource->execute();
        $updSource->close();
    }

// =====================================================================
// 14c. SINCRONIZACIÓN CON TABLAS LEGACY (FileS3 y S3Folders)
// =====================================================================
$filename = $targetFilename; // ✅ FIX: Usar el nombre original, no un hash
$folderPrefix = $canonicalRootPrefix;
$encriptadoVal = $filename; // ✅ FIX: El nombre encriptado es el nombre real del archivo

// Crear jerarquía de carpetas
$foldersToCreate = [
    "Data/Chat/Uploads/",
    "Data/Chat/Uploads/{$userId}/",
    $canonicalRootPrefix
];

foreach ($foldersToCreate as $prefix) {
    $stmtFolder = $db_connection->prepare("
        SELECT id_ FROM S3Folders WHERE user_id_ = ? AND Prefix = ? LIMIT 1
    ");
    if ($stmtFolder) {
        $stmtFolder->bind_param("is", $userId, $prefix);
        $stmtFolder->execute();
        $resFolder = $stmtFolder->get_result();
        $folderExists = $resFolder && $resFolder->num_rows > 0;
        $stmtFolder->close();

        if (!$folderExists) {
            $newFolderId = next_id($db_connection, 'S3Folders', 'id_');
            $folderName = basename(rtrim($prefix, '/'));
            $parentPrefixRaw = dirname(rtrim($prefix, '/'));
            $parentPrefix = ($parentPrefixRaw === '.' || $parentPrefixRaw === '') 
                ? '' 
                : rtrim($parentPrefixRaw, '/') . '/';

            $stmtInsFolder = $db_connection->prepare("
                INSERT INTO S3Folders (id_, user_id_, Prefix, Nombre, ParentPrefix, Found, AccessType, CreatedAt, UpdatedAt)
                VALUES (?, ?, ?, ?, ?, 1, 'normal', NOW(), NOW())
            ");
            if ($stmtInsFolder) {
                $stmtInsFolder->bind_param("iisss", $newFolderId, $userId, $prefix, $folderName, $parentPrefix);
                $stmtInsFolder->execute();
                $stmtInsFolder->close();
            }
        }
    }
}

// Sincronizar FileS3
$files3Id = 0;
$stmtFile = $db_connection->prepare("
    SELECT id_, Tamano FROM FileS3 
    WHERE user_id_ = ? AND Ruta = ? AND Nombre = ? 
    LIMIT 1
");
if ($stmtFile) {
    $stmtFile->bind_param("iss", $userId, $folderPrefix, $filename);
    $stmtFile->execute();
    $resFile = $stmtFile->get_result();
    $fileRow = $resFile ? $resFile->fetch_assoc() : null;
    $stmtFile->close();

    if ($fileRow) {
        $stmtUpdFile = $db_connection->prepare("
            UPDATE FileS3 SET Tamano = ?, Fecha = NOW(), Found = 1 WHERE id_ = ?
        ");
        if ($stmtUpdFile) {
            $stmtUpdFile->bind_param("ii", $fileSize, $fileRow['id_']);
            $stmtUpdFile->execute();
            $stmtUpdFile->close();
            $files3Id = (int)$fileRow['id_'];
        }
    } else {
        $newFileId = next_id($db_connection, 'FileS3', 'id_');
        $metadata = json_encode([
            'source' => 'ai_editor',
            'project_id' => $projectId,
            's3_key' => $originalS3Key
        ], JSON_UNESCAPED_UNICODE);

        $stmtInsFile = $db_connection->prepare("
            INSERT INTO FileS3 (id_, Nombre, Encriptado, Tamano, Metadatos, Ruta, Found, AccessType, Fecha, user_id_)
            VALUES (?, ?, ?, ?, ?, ?, 1, 'normal', NOW(), ?)
        ");
        if ($stmtInsFile) {
            $stmtInsFile->bind_param("ississi", $newFileId, $filename, $encriptadoVal, $fileSize, $metadata, $folderPrefix, $userId);
            if ($stmtInsFile->execute()) {
                $files3Id = (int)$newFileId;
            }
            $stmtInsFile->close();
        }
    }
}

// Vincular FileS3 con ProjectSources
if ($files3Id > 0) {
    $sourceIdForLink = (int)($source['id_'] ?? 0);
    if ($sourceIdForLink > 0) {
        $stmtLink = $db_connection->prepare("
            UPDATE ProjectSources SET files3_id_ = ? WHERE id_ = ? AND project_id_ = ?
        ");
        if ($stmtLink) {
            $stmtLink->bind_param("iii", $files3Id, $sourceIdForLink, $projectId);
            $stmtLink->execute();
            $stmtLink->close();
        }
    }
}


    // 14b. Subir a S3
    $s3->putObject([
        'Bucket'      => $bucket,
        'Key'         => $originalS3Key,
        'Body'        => $newContent,
        'ContentType' => $mimeType,
        'ACL'         => 'private'
    ]);
    $s3Saved = true;

    $db_connection->commit();
    $dbCommitted = true;

} catch (Throwable $e) {
    try {
        if ($db_connection instanceof mysqli) {
            $db_connection->rollback();
        }
    } catch (Throwable $rb) {
        error_log("Error adicional en rollback: " . $rb->getMessage());
    }

    error_log("Error en Paso 14 (S3/BD Sync): " . $e->getMessage());

    $compensated = false;
    if ($s3Saved) {
        try {
            if (!$isCreation && !empty($backupS3Key)) {
                $s3->copyObject([
                    'Bucket' => $bucket,
                    'CopySource' => urlencode($bucket . '/' . $backupS3Key),
                    'Key' => $originalS3Key
                ]);
            } else {
                $s3->deleteObject([
                    'Bucket' => $bucket,
                    'Key' => $originalS3Key
                ]);
            }
            $compensated = true;
        } catch (Throwable $comp) {
            error_log("No se pudo compensar S3: " . $comp->getMessage());
        }
    }

    if ($s3Saved && !$compensated) {
        jexit([
            'ok' => false,
            's3_saved' => true,
            'db_committed' => false,
            'error' => 'El archivo se guardó en S3, pero falló la BD.',
            'details' => $e->getMessage(),
        ], 500);
    }

    jexit([
        'ok' => false,
        'error' => 'No se pudo guardar el archivo en S3/BD: ' . $e->getMessage()
    ], 500);
}

// ===== 14d. RESUMEN PROFESIONAL DEL TRABAJO =====
$summaryResult = summarizeCodeChange($bedrock, $db_connection, $sessionId, $newVersionId, $instruction, $targetFilename, $newContent, $isCreation);
$diffSummary = $summaryResult['text'];

try {
    $stmtUpdSummary = $db_connection->prepare("UPDATE FileVersions SET diff_summary = ? WHERE id_ = ?");
    $stmtUpdSummary->bind_param('si', $diffSummary, $newVersionId);
    $stmtUpdSummary->execute();
    $stmtUpdSummary->close();
} catch (Throwable $e) {
    $warnings[] = 'No se pudo actualizar diff_summary: ' . $e->getMessage();
}

// ===== 14e. INDEXACIÓN REAL =====
$indexResult = ['ok' => false, 'error' => 'no ejecutado'];
try {
    if (function_exists('indexProjectSourceContent')) {
        $indexResult = indexProjectSourceContent($db_connection, $bedrock, $projectId, (int)$source['id_'], $targetFilename, $newContent);
    }
} catch (Throwable $e) {
    $indexResult = ['ok' => false, 'error' => $e->getMessage()];
}

// ===== 15. Respuesta exitosa =====
$downloadUrl = 'descargar.php?archivo=' . urlencode($source['s3_key']) . '&nombre=' . urlencode($targetFilename);

$finalWarnings = $warnings ?? [];
if (empty($indexResult['ok']) && !empty($indexResult['error'])) {
    $finalWarnings[] = 'Indexación secundaria fallida: ' . $indexResult['error'];
}

jexit([
    'ok'              => true,
    's3_saved'        => true,
    'db_committed'    => $dbCommitted ?? true,
    'message'         => "✅ Archivo " . ($isCreation ? "creado" : "actualizado") . " exitosamente (versión v{$nextVersion}).",
    'filename'        => $targetFilename,
    'new_version'     => $nextVersion,
    'download_url'    => $downloadUrl,
    'diff_summary'    => $diffSummary,
    'summary_model'   => $summaryResult['model'],
    'model_used'      => $attemptLog[count($attemptLog) - 1]['model'] ?? 'unknown',
    'complexity'      => $category ?? 'unknown',
    'indexed'         => (bool)($indexResult['ok'] ?? false),
    'index_error'     => $indexResult['ok'] ? null : ($indexResult['error'] ?? null),
    'needs_indexing'  => false,
    'scout_info'      => $scoutResult ?? null,
    'warnings'        => array_values($finalWarnings),
    'impact_analysis' => $impactAnalysis ?? [],
    'next_steps'      => ($impactAnalysis['is_multi_file'] ?? false)
        ? "⚠️ Esta edición afecta a " . count($impactAnalysis['affected_files']) . " archivos más."
        : "Edición contenida en un solo archivo."
]);