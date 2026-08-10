<?php
// session_upload.php
// Sube archivos adjuntos de una sesión de chat.
// Los archivos se almacenan en S3 y se registran mediante FileS3.
// NO utiliza SessionAttachments.

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
 *
 * IMPORTANTE:
 * Ya NO utilizamos:
 *
 *     if (!$user_id) $user_id = 1;
 *
 * porque eso permitiría que una petición sin autenticación
 * terminara trabajando como usuario 1.
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
 * SESSION ID
 * ============================================================
 *
 * Para un archivo adjunto de una sesión necesitamos saber
 * exactamente a qué ChatSessions pertenece.
 */

$session_id = isset($_POST['session_id'])
    ? (int)$_POST['session_id']
    : 0;

if ($session_id <= 0) {

    jexit(
        [
            'ok' => false,
            'error' => 'session_id es requerido'
        ],
        400
    );
}


/*
 * ============================================================
 * VALIDAR PROPIEDAD DE LA SESIÓN
 * ============================================================
 *
 * Evita que un usuario pueda subir archivos dentro de la
 * carpeta de una sesión que pertenece a otro usuario.
 */

$stmt = $db_connection->prepare(
    "SELECT id_
       FROM ChatSessions
      WHERE id_ = ?
        AND user_id_ = ?
      LIMIT 1"
);

if (!$stmt) {

    jexit(
        [
            'ok' => false,
            'error' => 'Error preparando validación de sesión: ' .
                       $db_connection->error
        ],
        500
    );
}

$stmt->bind_param(
    'ii',
    $session_id,
    $user_id
);

if (!$stmt->execute()) {

    $error = $stmt->error;
    $stmt->close();

    jexit(
        [
            'ok' => false,
            'error' => 'Error validando sesión: ' . $error
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
            'error' => 'Sesión no encontrada o acceso denegado'
        ],
        403
    );
}

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
 * FECHA
 * ============================================================
 */

$now = new DateTime();

$year  = $now->format('Y');
$month = $now->format('m');
$day   = $now->format('d');


/*
 * ============================================================
 * RUTA DEL CHAT
 * ============================================================
 *
 * Ejemplo real:
 *
 * Data/Chat/Uploads/1/2026/08/10/1/
 *
 * El archivo terminará dentro de esta ruta.
 */

$rutaDestino =
    "Data/Chat/Uploads/" .
    "{$user_id}/" .
    "{$year}/" .
    "{$month}/" .
    "{$day}/" .
    "{$session_id}/";


/*
 * ============================================================
 * S3 MANAGER
 * ============================================================
 */

$manager = new S3Manager();

$uploaded = [];
$errors   = [];


/*
 * ============================================================
 * CREAR / VERIFICAR S3FOLDERS
 * ============================================================
 *
 * S3Folders representa la estructura lógica de carpetas.
 *
 * No se utiliza SessionAttachments.
 */

try {

    $prefixes = [

        "Data/Chat/Uploads/",

        "Data/Chat/Uploads/{$user_id}/",

        "Data/Chat/Uploads/{$user_id}/{$year}/",

        "Data/Chat/Uploads/{$user_id}/{$year}/{$month}/",

        "Data/Chat/Uploads/{$user_id}/{$year}/{$month}/{$day}/",

        "Data/Chat/Uploads/{$user_id}/{$year}/{$month}/{$day}/{$session_id}/"

    ];

    foreach ($prefixes as $prefix) {

        if (!$manager->folderExistsDb($user_id, $prefix)) {

            $manager->upsertFolderDbPublic(
                $user_id,
                $prefix
            );
        }
    }

} catch (Throwable $e) {

    /*
     * No detenemos el upload.
     *
     * S3Manager puede crear/registrar la información
     * durante uploadFile().
     */

    error_log(
        'Warning: Error creando S3Folders: ' .
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
     * Archivo temporal
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
     * Nombre original
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
     * Tamaño
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
     * MIME
     * --------------------------------------------------------
     */

    $mimeType = mime_content_type($tmpPath);

    if (!$mimeType) {
        $mimeType = 'application/octet-stream';
    }


    /*
     * --------------------------------------------------------
     * UPLOAD
     * --------------------------------------------------------
     *
     * S3Manager se encarga del almacenamiento y del
     * registro correspondiente en FileS3.
     *
     * NO hacemos INSERT en SessionAttachments.
     */

    try {

        $result = $manager->uploadFile(
            $tmpPath,
            $originalName,
            $rutaDestino,
            $user_id,
            $mimeType,
            $fileSize
        );


        /*
         * Verificar que S3Manager realmente devolvió
         * información del archivo.
         */

        if (
            !is_array($result) ||
            !isset($result['id'])
        ) {

            throw new RuntimeException(
                'S3Manager::uploadFile() no devolvió un ID de FileS3 válido.'
            );
        }


        /*
         * ----------------------------------------------------
         * Respuesta para JavaScript
         * ----------------------------------------------------
         */

        $uploaded[] = [

            'id' =>
                (int)$result['id'],

            'files3_id' =>
                (int)$result['id'],

            'filename' =>
                $result['nombre_original']
                ?? $originalName,

            's3_key' =>
                $result['key_s3']
                ?? null,

            'size' =>
                (int)$fileSize,

            'size_bytes' =>
                (int)$fileSize,

            'mime_type' =>
                $mimeType,

            'ruta' =>
                $result['ruta']
                ?? $rutaDestino,

            'created_at' =>
                date('Y-m-d H:i:s')
        ];


    } catch (Throwable $e) {

        $errors[] =
            'Error subiendo ' .
            $originalName .
            ': ' .
            $e->getMessage();

        error_log(
            'session_upload.php: ' .
            $e->getMessage()
        );
    }
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

        'message' =>
            count($uploaded) .
            ' archivos subidos correctamente'
    ]
);