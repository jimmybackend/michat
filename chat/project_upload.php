<?php
// project_upload.php
// Sube archivos fuente de un proyecto.
// Los archivos se almacenan en S3 bajo Data/Chat/Uploads/{user_id}/{project_id}/ 
// y se registran en S3Folders + FileS3 + ProjectSources.

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Respuesta JSON y finalización.
 */
function jexit(array $arr, int $code = 200): void
{
    http_response_code($code);
    echo json_encode(
        $arr,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/**
 * Resolver posibles raíces del proyecto para localizar app_bootstrap.php.
 */
function resolve_root_candidates(): array
{
    $docRoot = isset($_SERVER['DOCUMENT_ROOT'])
        ? (string)$_SERVER['DOCUMENT_ROOT']
        : '';
    $rootFromDoc = $docRoot !== ''
        ? realpath($docRoot . '/..')
        : false;
    
    $candidates = [];
    foreach ([
        $rootFromDoc,
        realpath(__DIR__ . '/../../'),
        realpath(__DIR__ . '/../..'),
        realpath(__DIR__ . '/../../../'),
        realpath(__DIR__ . '/../'),
        realpath(__DIR__),
    ] as $path) {
        if ($path && is_dir($path)) {
            $candidates[$path] = true;
        }
    }
    return array_keys($candidates);
}

/**
 * Buscar un archivo dentro de las posibles raíces.
 */
function find_file_in_candidates(
    string $filename,
    array $bases,
    array $subfolders
): ?string {
    $filename = ltrim($filename, '/');
    foreach ($bases as $base) {
        foreach ($subfolders as $sub) {
            $sub = ($sub === '')
                ? ''
                : '/' . trim($sub, '/');
            $try = rtrim($base, '/') . $sub . '/' . $filename;
            if (is_file($try)) {
                return $try;
            }
        }
    }
    return null;
}

/**
 * Detectar lenguaje de programación basado en extensión.
 */
function detectLanguageFromExtension(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $map = [
        'php' => 'php',
        'js' => 'javascript',
        'jsx' => 'javascript',
        'ts' => 'typescript',
        'tsx' => 'typescript',
        'py' => 'python',
        'java' => 'java',
        'kt' => 'kotlin',
        'go' => 'go',
        'rs' => 'rust',
        'rb' => 'ruby',
        'c' => 'c',
        'cpp' => 'cpp',
        'h' => 'c',
        'hpp' => 'cpp',
        'cs' => 'csharp',
        'swift' => 'swift',
        'sql' => 'sql',
        'sh' => 'bash',
        'bash' => 'bash',
        'zsh' => 'bash',
        'bat' => 'batch',
        'cmd' => 'batch',
        'ps1' => 'powershell',
        'html' => 'html',
        'htm' => 'html',
        'css' => 'css',
        'scss' => 'scss',
        'less' => 'less',
        'json' => 'json',
        'xml' => 'xml',
        'yaml' => 'yaml',
        'yml' => 'yaml',
        'md' => 'markdown',
        'txt' => 'text',
    ];
    return $map[$ext] ?? 'unknown';
}

/**
 * Verificar que un registro existe en una tabla por su ID.
 * Retorna true si existe, false si no.
 */
function verifyRecordExists(mysqli $db, string $table, int $id): bool
{
    $table = preg_replace('/[^A-Za-z0-9_]+/', '', $table);
    $stmt = $db->prepare("SELECT 1 FROM `{$table}` WHERE id_ = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    $stmt->close();
    return $exists;
}

/**
 * Verificar que un registro existe en S3Folders por prefix y user_id.
 */
function verifyFolderExists(mysqli $db, int $userId, string $prefix): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM S3Folders WHERE user_id_ = ? AND Prefix = ? LIMIT 1"
    );
    if (!$stmt) return false;
    $stmt->bind_param('is', $userId, $prefix);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    $stmt->close();
    return $exists;
}

try {
    /*
    * ============================================================
    * BOOTSTRAP
    * ============================================================
    */
    $bootstrap = __DIR__ . '/app_bootstrap.php';
    if (!is_file($bootstrap)) {
        $bootstrap = __DIR__ . '/../app_bootstrap.php';
    }
    if (!is_file($bootstrap)) {
        $bases = resolve_root_candidates();
        $bootstrap = find_file_in_candidates(
            'app_bootstrap.php',
            $bases,
            [
                '',
                'public_html',
                'api',
                'app',
                'www'
            ]
        );
    }
    if (!$bootstrap || !is_file($bootstrap)) {
        throw new RuntimeException(
            'app_bootstrap.php no encontrado.'
        );
    }
    require_once $bootstrap;
} catch (Throwable $e) {
    jexit(
        [
            'ok' => false,
            'error' => 'bootstrap: ' . $e->getMessage()
        ],
        500
    );
}

/*
* ============================================================
* BASE DE DATOS
* ============================================================
*/
if (
    !isset($db_connection) ||
    !($db_connection instanceof mysqli)
) {
    jexit(
        [
            'ok' => false,
            'error' => 'DB no disponible'
        ],
        500
    );
}

/*
* ============================================================
* S3 MANAGER
* ============================================================
*/
require_once __DIR__ . '/S3Manager.php';

/*
* ============================================================
* VALIDAR MÉTODO
* ============================================================
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jexit(
        [
            'ok' => false,
            'error' => 'Método no permitido'
        ],
        405
    );
}

/*
* ============================================================
* USUARIO AUTENTICADO
* ============================================================
*/
if (
    !isset($_SESSION['user_id']) ||
    !is_numeric($_SESSION['user_id']) ||
    (int)$_SESSION['user_id'] <= 0
) {
    jexit(
        [
            'ok' => false,
            'error' => 'No autenticado'
        ],
        401
    );
}
$user_id = (int)$_SESSION['user_id'];

/*
* ============================================================
* PROJECT ID
* ============================================================
*/
$project_id = isset($_POST['project_id'])
    ? (int)$_POST['project_id']
    : 0;

if ($project_id <= 0) {
    jexit(
        [
            'ok' => false,
            'error' => 'project_id es requerido'
        ],
        400
    );
}

/*
* ============================================================
* VALIDAR PROPIEDAD DEL PROYECTO
* ============================================================
*/
$stmt = $db_connection->prepare(
    "SELECT id_, name, slug, root_prefix
    FROM Projects
    WHERE id_ = ?
    AND user_id_ = ?
    AND status = 'active'
    LIMIT 1"
);
if (!$stmt) {
    jexit(
        [
            'ok' => false,
            'error' => 'Error preparando validación de proyecto: ' .
                $db_connection->error
        ],
        500
    );
}
$stmt->bind_param(
    'ii',
    $project_id,
    $user_id
);
if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();
    jexit(
        [
            'ok' => false,
            'error' => 'Error validando proyecto: ' . $error
        ],
        500
    );
}
$result = $stmt->get_result();
if (!$result || $result->num_rows === 0) {
    $stmt->close();
    jexit(
        [
            'ok' => false,
            'error' => 'Proyecto no encontrado o acceso denegado'
        ],
        403
    );
}
$projectData = $result->fetch_assoc();
$stmt->close();

/*
* ============================================================
* VALIDAR ARCHIVOS
* ============================================================
*/
if (
    empty($_FILES['files']) ||
    !isset($_FILES['files']['name']) ||
    !is_array($_FILES['files']['name'])
) {
    jexit(
        [
            'ok' => false,
            'error' => 'No se recibieron archivos'
        ],
        400
    );
}

/*
* ============================================================
* RUTA DEL PROYECTO
* ============================================================
*
* Estructura: Data/Chat/Uploads/{user_id}/{project_id}/
*
* Todos los archivos del proyecto van aquí, sin importar
* cuántas sesiones/chats tenga el proyecto.
*
* NO choca con session_upload.php que usa:
* Data/Chat/Uploads/{user_id}/{year}/{month}/{day}/{session_id}/
*/
$rutaDestino =
    "Data/Chat/Uploads/" .
    "{$user_id}/" .
    "{$project_id}/";

/*
* ============================================================
* S3 MANAGER - INSTANCIAR ANTES DE USAR
* ============================================================
*/
$manager = new S3Manager();
$uploaded = [];
$errors = [];

/*
* ============================================================
* TABLA 1: S3Folders — CREAR / VERIFICAR CARPETAS LÓGICAS
* ============================================================
*
* Se crean 3 niveles de carpeta:
*   1. Data/Chat/Uploads/
*   2. Data/Chat/Uploads/{user_id}/
*   3. Data/Chat/Uploads/{user_id}/{project_id}/
*
* upsertFolderDbPublic() hace INSERT ... ON DUPLICATE KEY UPDATE
* por lo que es seguro llamarlo múltiples veces.
*/
$foldersCreated = 0;
$foldersVerified = 0;
try {
    $prefixes = [
        "Data/Chat/Uploads/",
        "Data/Chat/Uploads/{$user_id}/",
        "Data/Chat/Uploads/{$user_id}/{$project_id}/"
    ];
    foreach ($prefixes as $prefix) {
        if (!$manager->folderExistsDb($user_id, $prefix)) {
            $manager->upsertFolderDbPublic(
                $user_id,
                $prefix
            );
            $foldersCreated++;
        }
        
        // ✅ VERIFICACIÓN POST-INSERT: confirmar que la carpeta quedó en BD
        if (verifyFolderExists($db_connection, $user_id, $prefix)) {
            $foldersVerified++;
        } else {
            error_log(
                "project_upload.php: ⚠️ Carpeta NO verificada en S3Folders: {$prefix}"
            );
        }
    }
} catch (Throwable $e) {
    error_log(
        'Warning: Error creando S3Folders para proyecto: ' .
        $e->getMessage()
    );
}

/*
* ============================================================
* SUBIR ARCHIVOS
* ============================================================
*/
$count = count($_FILES['files']['name']);
for ($i = 0; $i < $count; $i++) {
    /*
    * --------------------------------------------------------
    * Validar error PHP del upload
    * --------------------------------------------------------
    */
    if (
        !isset($_FILES['files']['error'][$i]) ||
        $_FILES['files']['error'][$i] !== UPLOAD_ERR_OK
    ) {
        $errors[] =
            'Archivo ' . $i .
            ' no recibido o con error';
        continue;
    }

    /*
    * --------------------------------------------------------
    * Archivo temporal - Validación de seguridad
    * --------------------------------------------------------
    */
    $tmpPath = $_FILES['files']['tmp_name'][$i];
    if (!is_uploaded_file($tmpPath)) {
        $errors[] =
            'El archivo ' . $i .
            ' no corresponde a un upload válido';
        continue;
    }

    /*
    * --------------------------------------------------------
    * Nombre original - Validación
    * --------------------------------------------------------
    */
    $originalName =
        basename(
            (string)$_FILES['files']['name'][$i]
        );
    if ($originalName === '') {
        $errors[] =
            'El archivo ' . $i .
            ' no tiene nombre válido';
        continue;
    }

    /*
    * --------------------------------------------------------
    * Tamaño - Validación
    * --------------------------------------------------------
    */
    $fileSize = filesize($tmpPath);
    if ($fileSize === false) {
        $errors[] =
            'No se pudo determinar el tamaño de ' .
            $originalName;
        continue;
    }

    /*
    * --------------------------------------------------------
    * MIME - Con fallback
    * --------------------------------------------------------
    */
    $mimeType = mime_content_type($tmpPath);
    if (!$mimeType) {
        $mimeType = 'application/octet-stream';
    }

    /*
    * --------------------------------------------------------
    * INICIAR TRANSACCIÓN para atomicidad FileS3 + ProjectSources
    * --------------------------------------------------------
    */
    $db_connection->begin_transaction();
    
    try {
        /*
        * --------------------------------------------------------
        * TABLA 2: FileS3 — UPLOAD A S3 + REGISTRO AUTOMÁTICO
        * --------------------------------------------------------
        *
        * S3Manager::uploadFile() internamente:
        *   1. Sube el archivo físico a S3
        *   2. Genera un nombre encriptado único
        *   3. INSERT en FileS3 con todos los metadatos
        *   4. Retorna ['id' => int, 'key_s3' => string, ...]
        */
        $result = $manager->uploadFile(
            $tmpPath,
            $originalName,
            $rutaDestino,
            $user_id,
            $mimeType,
            $fileSize
        );

        if (
            !is_array($result) ||
            !isset($result['id'])
        ) {
            throw new RuntimeException(
                'S3Manager::uploadFile() no devolvió un ID de FileS3 válido.'
            );
        }

        $files3_id = (int)$result['id'];
        $s3Key = $result['key_s3'] ?? null;
        $filename = $result['nombre_original'] ?? $originalName;

        /*
        * --------------------------------------------------------
        * ✅ VERIFICACIÓN POST-INSERT: confirmar FileS3 en BD
        * --------------------------------------------------------
        */
        if (!verifyRecordExists($db_connection, 'FileS3', $files3_id)) {
            throw new RuntimeException(
                "FileS3 id_={$files3_id} NO existe en la base de datos después del upload."
            );
        }

        /*
        * --------------------------------------------------------
        * TABLA 3: ProjectSources — REGISTRO DE FUENTE DEL PROYECTO
        * --------------------------------------------------------
        *
        * Vincula el archivo (files3_id_) con el proyecto (project_id_).
        * Usa INSERT IGNORE para evitar duplicados si el mismo archivo
        * se sube dos veces al mismo proyecto.
        */
        $language = detectLanguageFromExtension($filename);
        
        // Verificar si ya existe esta fuente (mismo archivo en mismo proyecto)
        $stmtCheck = $db_connection->prepare(
            "SELECT id_, status
            FROM ProjectSources
            WHERE project_id_ = ?
            AND files3_id_ = ?
            LIMIT 1"
        );
        
        $existingSourceId = null;
        $existingStatus = null;
        
        if ($stmtCheck) {
            $stmtCheck->bind_param('ii', $project_id, $files3_id);
            $stmtCheck->execute();
            $checkResult = $stmtCheck->get_result();
            if ($checkResult && $checkResult->num_rows > 0) {
                $existingRow = $checkResult->fetch_assoc();
                $existingSourceId = (int)$existingRow['id_'];
                $existingStatus = $existingRow['status'];
            }
            $stmtCheck->close();
        }

        if ($existingSourceId) {
            /*
            * La fuente ya existe. Reactivarla si estaba en error,
            * o dejarla como está si ya está indexada/pendiente.
            */
            if ($existingStatus === 'error') {
                $stmtReset = $db_connection->prepare(
                    "UPDATE ProjectSources
                    SET status = 'pending', created_at = NOW()
                    WHERE id_ = ?"
                );
                if ($stmtReset) {
                    $stmtReset->bind_param('i', $existingSourceId);
                    $stmtReset->execute();
                    $stmtReset->close();
                }
            }
            $sourceId = $existingSourceId;
        } else {
            /*
            * Nueva fuente: INSERT en ProjectSources
            *
            * Columnas según schema:
            *   id_            → AUTO_INCREMENT
            *   project_id_    → FK a Projects.id_
            *   files3_id_     → FK a FileS3.id_
            *   s3_key         → Ruta completa en S3
            *   s3_key_hash    → GENERATED ALWAYS (sha256 automático)
            *   filename       → Nombre legible
            *   mime_type      → Tipo MIME
            *   size_bytes     → Tamaño en bytes
            *   language       → Lenguaje detectado
            *   sha256         → NULL (se calcula en indexación)
            *   status         → 'pending'
            *   indexed_at     → NULL (se llena al indexar)
            *   created_at     → CURRENT_TIMESTAMP automático
            */
            $stmtSource = $db_connection->prepare(
                "INSERT INTO ProjectSources (
                    project_id_,
                    files3_id_,
                    s3_key,
                    filename,
                    mime_type,
                    size_bytes,
                    language,
                    status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')"
            );

            if (!$stmtSource) {
                throw new RuntimeException(
                    'Error preparando INSERT en ProjectSources: ' .
                    $db_connection->error
                );
            }

            // ✅ Tipos corregidos: i-i-s-s-s-i-s
            // i=project_id, i=files3_id, s=s3Key, s=filename,
            // s=mimeType, i=fileSize, s=language
            $stmtSource->bind_param(
                'iisssis',
                $project_id,
                $files3_id,
                $s3Key,
                $filename,
                $mimeType,
                $fileSize,
                $language
            );

            if (!$stmtSource->execute()) {
                $error = $stmtSource->error;
                $stmtSource->close();
                throw new RuntimeException(
                    'Error insertando en ProjectSources: ' . $error
                );
            }

            // Verificar que realmente se insertó al menos 1 fila
            if ($stmtSource->affected_rows < 1) {
                $stmtSource->close();
                throw new RuntimeException(
                    'INSERT en ProjectSources afectó 0 filas (posible duplicado por UNIQUE KEY).'
                );
            }

            $sourceId = $db_connection->insert_id;
            $stmtSource->close();
        }

        /*
        * --------------------------------------------------------
        * ✅ VERIFICACIÓN POST-INSERT: confirmar ProjectSources en BD
        * --------------------------------------------------------
        */
        if (!verifyRecordExists($db_connection, 'ProjectSources', $sourceId)) {
            throw new RuntimeException(
                "ProjectSources id_={$sourceId} NO existe en la base de datos después del INSERT."
            );
        }

        /*
        * --------------------------------------------------------
        * COMMIT: Todo salió bien, confirmar transacción
        * --------------------------------------------------------
        */
        $db_connection->commit();

        /*
        * --------------------------------------------------------
        * ✅ LOGGING DE ÉXITO
        * --------------------------------------------------------
        */
        error_log(
            "project_upload.php: ✅ Archivo subido correctamente | " .
            "FileS3.id_={$files3_id} | " .
            "ProjectSources.id_={$sourceId} | " .
            "S3Key={$s3Key} | " .
            "Proyecto={$project_id}"
        );

        /*
        * --------------------------------------------------------
        * Respuesta para JavaScript
        * --------------------------------------------------------
        */
        $uploaded[] = [
            'id' => $sourceId,
            'files3_id' => $files3_id,
            'filename' => $filename,
            's3_key' => $s3Key,
            'size' => (int)$fileSize,
            'size_bytes' => (int)$fileSize,
            'mime_type' => $mimeType,
            'language' => $language,
            'status' => 'pending',
            'ruta' => $result['ruta'] ?? $rutaDestino,
            'created_at' => date('Y-m-d H:i:s')
        ];
    } catch (Throwable $e) {
        /*
        * --------------------------------------------------------
        * ROLLBACK: Algo falló, deshacer la transacción
        * --------------------------------------------------------
        */
        $db_connection->rollback();
        
        $errors[] =
            'Error subiendo ' .
            $originalName .
            ': ' .
            $e->getMessage();
        error_log(
            'project_upload.php ❌ ROLLBACK: ' .
            $e->getMessage() .
            ' | Archivo: ' . $originalName
        );
    }
}

/*
* ============================================================
* VALIDACIÓN FINAL
* ============================================================
*/
if (empty($uploaded) && !empty($errors)) {
    jexit(
        [
            'ok' => false,
            'errors' => $errors,
            'ruta_destino' => $rutaDestino,
            'folders_created' => $foldersCreated,
            'folders_verified' => $foldersVerified,
            'message' => 'No se pudo subir ningún archivo'
        ],
        500
    );
}

/*
* ============================================================
* RESPUESTA FINAL
* ============================================================
*/
jexit(
    [
        'ok' => true,
        'success' => count($uploaded) > 0,
        'uploaded' => $uploaded,
        'errors' => $errors,
        'ruta_destino' => $rutaDestino,
        'folders_created' => $foldersCreated,
        'folders_verified' => $foldersVerified,
        'message' =>
            count($uploaded) .
            ' archivos subidos correctamente al proyecto'
    ]
);