<?php
/**
 * session_attachments.php
 *
 * Gestión de archivos adjuntos de una sesión de Chat.
 *
 * Arquitectura actual:
 *   ChatSessions
 *        ↓
 *   Data/Chat/Uploads/{user_id}/{YYYY}/{MM}/{DD}/{session_id}/
 *        ↓
 *   FileS3
 *
 * IMPORTANTE:
 *   Este archivo NO utiliza SessionAttachments.
 *
 * Acciones:
 *   GET  ?action=list&session_id=X
 *   POST ?action=remove
 *   POST ?action=reindex
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/* ============================================================
 * RESPUESTA JSON
 * ============================================================ */

function jexit(array $data, int $code = 200): void
{
    http_response_code($code);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* ============================================================
 * BOOTSTRAP
 * ============================================================ */

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


function find_file_in_candidates(
    string $filename,
    array $bases,
    array $subfolders
): ?string {

    $filename = ltrim($filename, '/');

    foreach ($bases as $base) {

        foreach ($subfolders as $subfolder) {

            $subfolder = ($subfolder === '')
                ? ''
                : '/' . trim($subfolder, '/');

            $file = rtrim($base, '/')
                  . $subfolder
                  . '/'
                  . $filename;

            if (is_file($file)) {
                return $file;
            }
        }
    }

    return null;
}


try {

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

    jexit([
        'ok' => false,
        'error' => 'bootstrap: ' . $e->getMessage()
    ], 500);
}


/* ============================================================
 * BASE DE DATOS
 * ============================================================ */

if (
    !isset($db_connection) ||
    !($db_connection instanceof mysqli)
) {
    jexit([
        'ok' => false,
        'error' => 'DB no disponible'
    ], 500);
}


/* ============================================================
 * USUARIO AUTENTICADO
 * ============================================================ */

$user_id = 0;

if (
    isset($_SESSION['user_id']) &&
    is_numeric($_SESSION['user_id'])
) {
    $user_id = (int)$_SESSION['user_id'];
}

if (!$user_id) {

    jexit([
        'ok' => false,
        'error' => 'Usuario no autenticado'
    ], 401);
}


/* ============================================================
 * ACCIÓN
 * ============================================================ */

$action = isset($_POST['action'])
    ? trim((string)$_POST['action'])
    : (
        isset($_GET['action'])
            ? trim((string)$_GET['action'])
            : 'list'
    );


/* ============================================================
 * VALIDAR SESIÓN
 * ============================================================ */

function validate_chat_session(
    mysqli $db,
    int $session_id,
    int $user_id
): bool {

    if ($session_id <= 0 || $user_id <= 0) {
        return false;
    }

    $sql = "
        SELECT id_
        FROM ChatSessions
        WHERE id_ = ?
          AND user_id_ = ?
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);

    if (!$stmt) {
        jexit([
            'ok' => false,
            'error' => 'Error preparando validación de sesión: '
                     . $db->error
        ], 500);
    }

    $stmt->bind_param(
        'ii',
        $session_id,
        $user_id
    );

    if (!$stmt->execute()) {

        $error = $stmt->error;

        $stmt->close();

        jexit([
            'ok' => false,
            'error' => 'Error validando sesión: ' . $error
        ], 500);
    }

    $result = $stmt->get_result();

    $exists = $result && $result->num_rows > 0;

    $stmt->close();

    return $exists;
}


/* ============================================================
 * GENERAR KEY S3
 * ============================================================ */

function build_file_s3_key(
    string $ruta,
    string $encriptado
): string {

    $ruta = rtrim(
        str_replace('\\', '/', trim($ruta)),
        '/'
    ) . '/';

    $encriptado = ltrim(
        str_replace('\\', '/', trim($encriptado)),
        '/'
    );

    if ($encriptado === '') {
        return '';
    }

    /*
     * Si Encriptado ya contiene la ruta completa,
     * no volver a agregar Ruta.
     */
    if (strpos($encriptado, $ruta) === 0) {
        return $encriptado;
    }

    return $ruta . $encriptado;
}


/* ============================================================
 * EXTENSIÓN
 * ============================================================ */

function file_extension(string $filename): string
{
    return strtolower(
        pathinfo($filename, PATHINFO_EXTENSION)
    );
}


/* ============================================================
 * ACCIONES DEL ARCHIVO
 * ============================================================ */

function get_file_actions(
    string $ruta,
    string $encriptado,
    string $nombre,
    string $accessType = 'normal'
): array {

    /*
     * Si el archivo está marcado como secure,
     * no generamos URLs directas.
     */
    if ($accessType === 'secure') {

        return [
            'edit'     => null,
            'view'     => null,
            'download' => null
        ];
    }

    $s3key = build_file_s3_key(
        $ruta,
        $encriptado
    );

    if ($s3key === '') {

        return [
            'edit'     => null,
            'view'     => null,
            'download' => null
        ];
    }

    $keyEncoded = urlencode($s3key);

    $extension = file_extension($nombre);

    $editableExtensions = [
        'txt',
        'srt',
        'vtt',
        'md',
        'html',
        'css',
        'js',
        'php',
        'py',
        'json',
        'csv',
        'sql',
        'jas'
    ];

    return [

        'edit' => in_array(
            $extension,
            $editableExtensions,
            true
        )
            ? "editor.php?archivo={$keyEncoded}"
            : null,

        'view' =>
            "ver_archivo.php?archivo={$keyEncoded}",

        'download' =>
            "descargar.php?archivo={$keyEncoded}"
    ];
}


/* ============================================================
 * LISTAR ARCHIVOS DE UNA SESIÓN
 * ============================================================ */

if ($action === 'list') {

    $session_id = isset($_GET['session_id'])
        ? (int)$_GET['session_id']
        : 0;

    if ($session_id <= 0) {

        jexit([
            'ok' => false,
            'error' => 'session_id requerido'
        ], 400);
    }


    /*
     * IMPORTANTE:
     *
     * Validamos que la sesión pertenezca al usuario.
     */
    if (!validate_chat_session(
        $db_connection,
        $session_id,
        $user_id
    )) {

        jexit([
            'ok' => false,
            'error' => 'Sesión no encontrada o acceso denegado'
        ], 403);
    }


    /*
     * La relación sesión → archivo está determinada
     * por la estructura de Ruta:
     *
     * Data/Chat/Uploads/{user_id}/{YYYY}/{MM}/{DD}/{session_id}/
     *
     * No usamos SessionAttachments.
     *
     * Ruta contiene el directorio del archivo.
     */
    $pathPattern =
        'Data/Chat/Uploads/'
        . $user_id
        . '/%/'
        . $session_id
        . '/';


    $sql = "
        SELECT
            id_,
            user_id_,
            Nombre,
            Encriptado,
            Tamano,
            Metadatos,
            Ruta,
            Found,
            AccessType,
            Fecha
        FROM FileS3
        WHERE user_id_ = ?
          AND Ruta LIKE ?
        ORDER BY Fecha DESC, id_ DESC
    ";


    $stmt = $db_connection->prepare($sql);

    if (!$stmt) {

        jexit([
            'ok' => false,
            'error' =>
                'Error preparando consulta FileS3: '
                . $db_connection->error
        ], 500);
    }


    $stmt->bind_param(
        'is',
        $user_id,
        $pathPattern
    );


    if (!$stmt->execute()) {

        $error = $stmt->error;

        $stmt->close();

        jexit([
            'ok' => false,
            'error' =>
                'Error consultando FileS3: '
                . $error
        ], 500);
    }


    $result = $stmt->get_result();

    $attachments = [];


    while ($row = $result->fetch_assoc()) {

        $acciones = get_file_actions(
            (string)($row['Ruta'] ?? ''),
            (string)($row['Encriptado'] ?? ''),
            (string)($row['Nombre'] ?? ''),
            (string)($row['AccessType'] ?? 'normal')
        );


        /*
         * MIME type:
         *
         * Primero intentamos obtenerlo de Metadatos.
         * Si no existe, devolvemos application/octet-stream.
         */
        $mimeType = 'application/octet-stream';

        if (!empty($row['Metadatos'])) {

            $metadata = json_decode(
                (string)$row['Metadatos'],
                true
            );

            if (
                is_array($metadata) &&
                !empty($metadata['tipo'])
            ) {
                $mimeType = (string)$metadata['tipo'];
            }
            elseif (
                is_array($metadata) &&
                !empty($metadata['mime_type'])
            ) {
                $mimeType = (string)$metadata['mime_type'];
            }
        }


        /*
         * FileS3 es ahora el ID que identifica
         * directamente al adjunto.
         *
         * No existe SessionAttachments.id_.
         */
        $attachments[] = [

            'id' =>
                (int)$row['id_'],

            'session_id' =>
                $session_id,

            'files3_id' =>
                (int)$row['id_'],

            's3_key' =>
                build_file_s3_key(
                    (string)($row['Ruta'] ?? ''),
                    (string)($row['Encriptado'] ?? '')
                ),

            'filename' =>
                (string)($row['Nombre'] ?? ''),

            'mime_type' =>
                $mimeType,

            'size_bytes' =>
                (int)($row['Tamano'] ?? 0),

            /*
             * FileS3 no tiene status.
             *
             * Found indica que el registro corresponde
             * al archivo localizado.
             */
            'status' =>
                ((int)($row['Found'] ?? 0) === 1)
                    ? 'indexed'
                    : 'error',

            'found' =>
                (int)($row['Found'] ?? 0),

            'created_at' =>
                (string)($row['Fecha'] ?? ''),

            'ruta' =>
                (string)($row['Ruta'] ?? ''),

            'access_type' =>
                (string)($row['AccessType'] ?? 'normal'),

            'edit_url' =>
                $acciones['edit'],

            'view_url' =>
                $acciones['view'],

            'download_url' =>
                $acciones['download']
        ];
    }


    $stmt->close();


    jexit([
        'ok' => true,
        'attachments' => $attachments
    ]);
}


/* ============================================================
 * ELIMINAR ARCHIVO
 * ============================================================ */

if ($action === 'remove') {

    /*
     * El JS nuevo manda attachment_id.
     *
     * También aceptamos file_id para compatibilidad
     * con la versión anterior.
     */
    $file_id = 0;

    if (
        isset($_POST['attachment_id']) &&
        is_numeric($_POST['attachment_id'])
    ) {

        $file_id = (int)$_POST['attachment_id'];

    }
    elseif (
        isset($_POST['file_id']) &&
        is_numeric($_POST['file_id'])
    ) {

        $file_id = (int)$_POST['file_id'];
    }


    if ($file_id <= 0) {

        jexit([
            'ok' => false,
            'error' => 'ID de archivo inválido'
        ], 400);
    }


    /*
     * Primero obtenemos el archivo.
     *
     * Validamos:
     *   - ID
     *   - propietario
     *   - que pertenezca a Chat/Uploads
     */
    $sql = "
        SELECT
            id_,
            user_id_,
            Nombre,
            Encriptado,
            Ruta,
            Found,
            AccessType
        FROM FileS3
        WHERE id_ = ?
          AND user_id_ = ?
        LIMIT 1
    ";


    $stmt = $db_connection->prepare($sql);

    if (!$stmt) {

        jexit([
            'ok' => false,
            'error' =>
                'Error preparando archivo: '
                . $db_connection->error
        ], 500);
    }


    $stmt->bind_param(
        'ii',
        $file_id,
        $user_id
    );


    if (!$stmt->execute()) {

        $error = $stmt->error;

        $stmt->close();

        jexit([
            'ok' => false,
            'error' =>
                'Error consultando archivo: '
                . $error
        ], 500);
    }


    $result = $stmt->get_result();


    if (!$result || $result->num_rows === 0) {

        $stmt->close();

        jexit([
            'ok' => false,
            'error' => 'Archivo no encontrado'
        ], 404);
    }


    $file = $result->fetch_assoc();

    $stmt->close();


    /*
     * Seguridad:
     *
     * Solamente permitimos eliminar archivos
     * dentro del espacio de Chat Uploads del usuario.
     */
    $expectedPrefix =
        'Data/Chat/Uploads/'
        . $user_id
        . '/';


    $ruta = (string)($file['Ruta'] ?? '');


    if (
        $ruta === '' ||
        strpos($ruta, $expectedPrefix) !== 0
    ) {

        jexit([
            'ok' => false,
            'error' =>
                'El archivo no pertenece al directorio de Chat'
        ], 403);
    }


    /*
     * IMPORTANTE:
     *
     * Aquí eliminamos el registro de FileS3.
     *
     * No intentamos borrar físicamente el objeto S3
     * porque este archivo no contiene una función
     * confirmada de S3Manager para eliminar objetos.
     *
     * Así evitamos inventar un método de S3Manager.
     */
    $deleteSql = "
        DELETE FROM FileS3
        WHERE id_ = ?
          AND user_id_ = ?
    ";


    $deleteStmt = $db_connection->prepare($deleteSql);

    if (!$deleteStmt) {

        jexit([
            'ok' => false,
            'error' =>
                'Error preparando eliminación: '
                . $db_connection->error
        ], 500);
    }


    $deleteStmt->bind_param(
        'ii',
        $file_id,
        $user_id
    );


    if (!$deleteStmt->execute()) {

        $error = $deleteStmt->error;

        $deleteStmt->close();

        jexit([
            'ok' => false,
            'error' =>
                'Error eliminando registro FileS3: '
                . $error
        ], 500);
    }


    $deleted = $deleteStmt->affected_rows;

    $deleteStmt->close();


    if ($deleted !== 1) {

        jexit([
            'ok' => false,
            'error' => 'No se pudo eliminar el archivo'
        ], 500);
    }


    jexit([
        'ok' => true,
        'message' => 'Archivo eliminado correctamente',
        'file_id' => $file_id
    ]);
}


/* ============================================================
 * REINDEXAR
 * ============================================================ */

if ($action === 'reindex') {

    $file_id = 0;

    if (
        isset($_POST['attachment_id']) &&
        is_numeric($_POST['attachment_id'])
    ) {

        $file_id = (int)$_POST['attachment_id'];

    }
    elseif (
        isset($_POST['file_id']) &&
        is_numeric($_POST['file_id'])
    ) {

        $file_id = (int)$_POST['file_id'];
    }


    if ($file_id <= 0) {

        jexit([
            'ok' => false,
            'error' => 'ID de archivo inválido'
        ], 400);
    }


    /*
     * Verificamos que el archivo pertenezca al usuario
     * y que sea un archivo de Chat.
     */
    $sql = "
        SELECT
            id_,
            Ruta,
            Found
        FROM FileS3
        WHERE id_ = ?
          AND user_id_ = ?
        LIMIT 1
    ";


    $stmt = $db_connection->prepare($sql);

    if (!$stmt) {

        jexit([
            'ok' => false,
            'error' =>
                'Error preparando reindexación: '
                . $db_connection->error
        ], 500);
    }


    $stmt->bind_param(
        'ii',
        $file_id,
        $user_id
    );


    if (!$stmt->execute()) {

        $error = $stmt->error;

        $stmt->close();

        jexit([
            'ok' => false,
            'error' =>
                'Error consultando archivo: '
                . $error
        ], 500);
    }


    $result = $stmt->get_result();


    if (!$result || $result->num_rows === 0) {

        $stmt->close();

        jexit([
            'ok' => false,
            'error' => 'Archivo no encontrado'
        ], 404);
    }


    $file = $result->fetch_assoc();

    $stmt->close();


    $expectedPrefix =
        'Data/Chat/Uploads/'
        . $user_id
        . '/';


    if (
        empty($file['Ruta']) ||
        strpos(
            (string)$file['Ruta'],
            $expectedPrefix
        ) !== 0
    ) {

        jexit([
            'ok' => false,
            'error' =>
                'El archivo no pertenece al directorio de Chat'
        ], 403);
    }


    /*
     * FileS3 no posee un campo "status".
     *
     * Found es el indicador existente en el esquema.
     *
     * Marcamos Found = 1 porque el registro existe
     * y está siendo utilizado por el sistema.
     */
    $updateSql = "
        UPDATE FileS3
        SET Found = 1
        WHERE id_ = ?
          AND user_id_ = ?
    ";


    $updateStmt = $db_connection->prepare($updateSql);

    if (!$updateStmt) {

        jexit([
            'ok' => false,
            'error' =>
                'Error preparando actualización FileS3: '
                . $db_connection->error
        ], 500);
    }


    $updateStmt->bind_param(
        'ii',
        $file_id,
        $user_id
    );


    if (!$updateStmt->execute()) {

        $error = $updateStmt->error;

        $updateStmt->close();

        jexit([
            'ok' => false,
            'error' =>
                'Error actualizando FileS3: '
                . $error
        ], 500);
    }


    $updateStmt->close();


    jexit([
        'ok' => true,
        'message' => 'Archivo marcado como encontrado',
        'file_id' => $file_id
    ]);
}


/* ============================================================
 * ACCIÓN NO VÁLIDA
 * ============================================================ */

jexit([
    'ok' => false,
    'error' => 'Acción no válida'
], 400);