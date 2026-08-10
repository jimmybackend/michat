<?php
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/S3Manager.php';

/* ============================================================
   Helper: resolver el user id desde la sesión
   (mismo criterio que ver_archivo.php)
   ============================================================ */
function resolve_user_id_delete(): int
{
    $candidates = [
        $_SESSION['user_id_']   ?? null,
        $_SESSION['user_id']    ?? null,
        $_SESSION['id_usuario'] ?? null,
        $_SESSION['id_user']    ?? null,
        $_SESSION['id']         ?? null,
    ];

    foreach ($candidates as $value) {
        if ($value !== null && $value !== '' && ctype_digit((string)$value)) {
            return (int)$value;
        }
    }

    return 0;
}

/* ============================================================
   Helper: rol tipo admin (opcional, como chat2_session_archive.php)
   ============================================================ */
function is_admin_like_delete(string $role): bool
{
    return in_array(
        strtolower(trim($role)),
        ['admin', 'administrator', 'administración', 'soporte', 'support'],
        true
    );
}

try {

    /* --------------------------------------------------------
     * 0. Solo POST
     * -------------------------------------------------------- */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'ok'      => false,
            'estado'  => 'error',
            'mensaje' => 'Método no permitido',
            'error'   => 'Método no permitido'
        ]);
        exit;
    }

    /* --------------------------------------------------------
     * 1. Usuario autenticado (obligatorio)
     * -------------------------------------------------------- */
    $userId = resolve_user_id_delete();

    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode([
            'ok'      => false,
            'estado'  => 'error',
            'mensaje' => 'Sesión inválida',
            'error'   => 'Sesión inválida'
        ]);
        exit;
    }

    $rol     = (string)($_SESSION['role'] ?? $_SESSION['rol'] ?? '');
    $esAdmin = is_admin_like_delete($rol);

    /* --------------------------------------------------------
     * 2. Referencia del archivo (id numérico o clave S3)
     * -------------------------------------------------------- */
    $fileRef = null;

    if (isset($_POST['file_id']) && trim((string)$_POST['file_id']) !== '') {
        $fileRef = (int)$_POST['file_id'];
    } elseif (isset($_POST['archivo']) && trim((string)$_POST['archivo']) !== '') {
        $fileRef = trim((string)$_POST['archivo']);
    }

    if ($fileRef === null || $fileRef === '' || $fileRef === 0) {
        throw new Exception('Falta la referencia del archivo');
    }

    /* --------------------------------------------------------
     * 3. VERIFICACIÓN: el archivo debe existir y pertenecer
     *    al usuario de la sesión (admin puede cualquiera)
     * -------------------------------------------------------- */
    if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
        throw new Exception('No hay conexión a la base de datos');
    }

    if (is_int($fileRef)) {
        /* Caso A: id numérico de FileS3 */
        if ($esAdmin) {
            $stmt = $db_connection->prepare('SELECT id_ FROM FileS3 WHERE id_ = ? LIMIT 1');
            if (!$stmt) throw new Exception('Error preparando la validación');
            $stmt->bind_param('i', $fileRef);
        } else {
            $stmt = $db_connection->prepare('SELECT id_ FROM FileS3 WHERE id_ = ? AND user_id_ = ? LIMIT 1');
            if (!$stmt) throw new Exception('Error preparando la validación');
            $stmt->bind_param('ii', $fileRef, $userId);
        }
    } else {
        /* Caso B: clave S3 (completa, almacenada o basename) */
        $enc   = basename($fileRef);
        $where = "(Encriptado = ? OR Encriptado = ? OR CONCAT(TRIM(TRAILING '/' FROM Ruta), '/', Encriptado) = ?)";

        if ($esAdmin) {
            $stmt = $db_connection->prepare("SELECT id_ FROM FileS3 WHERE $where LIMIT 1");
            if (!$stmt) throw new Exception('Error preparando la validación');
            $stmt->bind_param('sss', $fileRef, $enc, $fileRef);
        } else {
            $stmt = $db_connection->prepare("SELECT id_ FROM FileS3 WHERE user_id_ = ? AND $where LIMIT 1");
            if (!$stmt) throw new Exception('Error preparando la validación');
            $stmt->bind_param('isss', $userId, $fileRef, $enc, $fileRef);
        }
    }

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception('Error ejecutando la validación: ' . $err);
    }

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        http_response_code(403);
        echo json_encode([
            'ok'      => false,
            'estado'  => 'error',
            'mensaje' => 'No tienes permisos para eliminar este archivo',
            'error'   => 'No tienes permisos para eliminar este archivo'
        ]);
        exit;
    }

    /* Usamos el id real verificado, no la referencia cruda */
    $realId = (int)$row['id_'];

    /* --------------------------------------------------------
     * 4. Recién aquí se elimina (S3 + registro FileS3)
     * -------------------------------------------------------- */
    $s3Manager = new S3Manager($db_connection);
    $resultado = $s3Manager->deleteFile($realId);

    echo json_encode([
        'ok'      => true,
        'estado'  => 'ok',
        'mensaje' => 'Archivo eliminado correctamente',
        'data'    => $resultado
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'ok'      => false,
        'estado'  => 'error',
        'mensaje' => $e->getMessage(),
        'error'   => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}