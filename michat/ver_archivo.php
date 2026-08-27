<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/S3Manager.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const STREAM_CHUNK_BYTES = 1048576; // 1 MB

function out_text(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function resolve_user_id(): int
{
    $candidates = [
        $_SESSION['user_id_'] ?? null,
        $_SESSION['user_id'] ?? null,
        $_SESSION['id_usuario'] ?? null,
        $_SESSION['id_user'] ?? null,
        $_SESSION['id'] ?? null,
    ];

    foreach ($candidates as $value) {
        if ($value !== null && $value !== '' && ctype_digit((string)$value)) {
            return (int)$value;
        }
    }

    return 0;
}

function normalize_prefix_local(string $prefix): string
{
    $prefix = trim($prefix);
    $prefix = str_replace('\\', '/', $prefix);
    $prefix = preg_replace('~\.\.(?:/|$)~', '', $prefix) ?? $prefix;
    $prefix = preg_replace('~/+~', '/', $prefix) ?? $prefix;
    $prefix = ltrim($prefix, '/');

    if ($prefix === '' || $prefix === '/') {
        $prefix = defined('Config::RUTA_RAIZ') ? Config::RUTA_RAIZ : 'Data/';
    }

    if (substr($prefix, -1) !== '/') {
        $prefix .= '/';
    }

    return $prefix;
}

function normalize_file_key_local(string $key): string
{
    $key = trim($key);
    $key = str_replace('\\', '/', $key);
    $key = preg_replace('~/+~', '/', $key) ?? $key;
    $key = ltrim($key, '/');

    $parts = array_values(array_filter(explode('/', $key), static function ($p) {
        return $p !== '';
    }));

    $isDataRoot = static function (string $s): bool {
        return preg_match('/^Data\d*$/i', $s) === 1;
    };

    if (count($parts) >= 2 && strcasecmp($parts[0], $parts[1]) === 0 && $isDataRoot($parts[0])) {
        array_shift($parts);
    }

    $len = count($parts);
    for ($n = 1; $n * 2 <= $len - 1; $n++) {
        $a = array_slice($parts, 0, $n);
        $b = array_slice($parts, $n, $n);
        if ($a === $b) {
            $parts = array_slice($parts, $n);
            break;
        }
    }

    return implode('/', $parts);
}

function build_stored_file_key_local(array $row): string
{
    $ruta = normalize_prefix_local((string)($row['Ruta'] ?? ''));
    $enc  = normalize_file_key_local((string)($row['Encriptado'] ?? ''));

    if ($enc === '') {
        return '';
    }

    if (strpos($enc, $ruta) === 0) {
        return $enc;
    }

    return normalize_file_key_local($ruta . $enc);
}

function security_lookup_file(mysqli $db, int $userId, string $requestedKey): ?array
{
    $requestedKey = normalize_file_key_local($requestedKey);

    $sql = "
        SELECT
            id_,
            user_id_,
            Nombre,
            Ruta,
            Encriptado,
            AccessType,
            PasswordHash,
            SecureHint,
            Found
        FROM FileS3
        WHERE user_id_ = ?
          AND Found = 1
          AND (
                Encriptado = ?
                OR CONCAT(
                    TRIM(TRAILING '/' FROM REPLACE(Ruta, '\\\\', '/')),
                    '/',
                    TRIM(LEADING '/' FROM REPLACE(Encriptado, '\\\\', '/'))
                ) = ?
              )
        ORDER BY id_ DESC
        LIMIT 10
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la consulta de seguridad.');
    }

    $stmt->bind_param('iss', $userId, $requestedKey, $requestedKey);

    if (!$stmt->execute()) {
        $error = $stmt->error ?: $db->error;
        $stmt->close();
        throw new RuntimeException('No se pudo ejecutar la consulta de seguridad: ' . $error);
    }

    $res = $stmt->get_result();
    $match = null;

    while ($row = $res->fetch_assoc()) {
        $realKey = build_stored_file_key_local($row);
        if ($realKey === $requestedKey) {
            $row['_real_key'] = $realKey;
            $match = $row;
            break;
        }
    }

    $stmt->close();
    return $match;
}

function is_secure_file(array $row): bool
{
    $accessType = strtolower(trim((string)($row['AccessType'] ?? '')));
    $passwordHash = trim((string)($row['PasswordHash'] ?? ''));

    return ($accessType === 'secure' || $passwordHash !== '');
}

function security_session_is_unlocked(array $keys): bool
{
    $sessionBuckets = [
        'secure_ok_files',
        'secure_files_ok',
        'unlocked_files',
    ];

    foreach ($sessionBuckets as $bucketName) {
        if (!isset($_SESSION[$bucketName]) || !is_array($_SESSION[$bucketName])) {
            continue;
        }

        foreach ($keys as $key) {
            $key = (string)$key;
            if ($key === '') {
                continue;
            }

            if (!array_key_exists($key, $_SESSION[$bucketName])) {
                continue;
            }

            $value = $_SESSION[$bucketName][$key];

            if (is_numeric($value)) {
                if ((int)$value < time()) {
                    unset($_SESSION[$bucketName][$key]);
                    continue;
                }
            } elseif (!$value) {
                continue;
            }

            return true;
        }
    }

    return false;
}

function parse_http_range(?string $rangeHeader, int $fileSize, int $maxChunkBytes): ?array
{
    if ($fileSize <= 0) {
        return null;
    }

    if ($rangeHeader === null || trim($rangeHeader) === '') {
        $end = min($fileSize - 1, $maxChunkBytes - 1);
        return [
            'start'  => 0,
            'end'    => $end,
            'length' => ($end - 0) + 1,
            'status' => 200,
        ];
    }

    if (!preg_match('/bytes\s*=\s*(\d*)-(\d*)/i', $rangeHeader, $m)) {
        return null;
    }

    $startRaw = $m[1];
    $endRaw   = $m[2];

    if ($startRaw === '' && $endRaw === '') {
        return null;
    }

    if ($startRaw === '') {
        $suffixLength = (int)$endRaw;
        if ($suffixLength <= 0) {
            return null;
        }

        $length = min($suffixLength, $maxChunkBytes, $fileSize);
        $start  = max(0, $fileSize - $length);
        $end    = $fileSize - 1;

        return [
            'start'  => $start,
            'end'    => $end,
            'length' => ($end - $start) + 1,
            'status' => 206,
        ];
    }

    $start = (int)$startRaw;
    if ($start < 0 || $start >= $fileSize) {
        return null;
    }

    if ($endRaw === '') {
        $end = min($fileSize - 1, $start + $maxChunkBytes - 1);
    } else {
        $requestedEnd = (int)$endRaw;
        if ($requestedEnd < $start) {
            return null;
        }
        $end = min($requestedEnd, $fileSize - 1, $start + $maxChunkBytes - 1);
    }

    return [
        'start'  => $start,
        'end'    => $end,
        'length' => ($end - $start) + 1,
        'status' => 206,
    ];
}

function output_stream_body($body): void
{
    if (is_object($body) && method_exists($body, 'rewind')) {
        try {
            $body->rewind();
        } catch (Throwable $e) {
        }
    }

    if (is_object($body) && method_exists($body, 'read')) {
        while (!$body->eof()) {
            echo $body->read(8192);
            if (function_exists('ob_flush')) {
                @ob_flush();
            }
            flush();
        }
        return;
    }

    echo (string)$body;
}

/* ===================== MAIN ===================== */

$requestedKey = isset($_GET['archivo'])
    ? normalize_file_key_local((string)$_GET['archivo'])
    : '';

if ($requestedKey === '') {
    out_text(400, 'Falta parámetro archivo');
}

$userId = resolve_user_id();
if ($userId <= 0) {
    out_text(401, 'Sesión inválida');
}

try {
    global $db_connection;

    if (!$db_connection instanceof mysqli) {
        throw new RuntimeException('No existe una conexión mysqli válida en $db_connection.');
    }

    $fileRow = security_lookup_file($db_connection, $userId, $requestedKey);
    if (!$fileRow) {
        out_text(404, 'Archivo no encontrado');
    }

    $realKey = (string)($fileRow['_real_key'] ?? '');
    if ($realKey === '') {
        out_text(404, 'No se pudo resolver la clave real del archivo');
    }

    if (is_secure_file($fileRow)) {
        $unlockKeys = [
            $realKey,
            $requestedKey,
            (string)($fileRow['Encriptado'] ?? ''),
            (string)($fileRow['id_'] ?? ''),
        ];

        if (!security_session_is_unlocked($unlockKeys)) {
            out_text(423, 'Archivo protegido. Debes desbloquearlo antes de visualizarlo.');
        }
    }

    $manager = new S3Manager();
    $s3      = Config::getS3();
    $bucket  = $manager->getBucket();

    $head = $s3->headObject([
        'Bucket' => $bucket,
        'Key'    => $realKey,
    ]);

    $fileSize = (int)($head['ContentLength'] ?? 0);
    $mime     = trim((string)($head['ContentType'] ?? ''));
    $etag     = trim((string)($head['ETag'] ?? ''));
    $lastMod  = isset($head['LastModified']) ? $head['LastModified'] : null;

    if ($mime === '') {
        $mime = 'application/octet-stream';
    }

    $rangeInfo = parse_http_range($_SERVER['HTTP_RANGE'] ?? null, $fileSize, STREAM_CHUNK_BYTES);

    if ($rangeInfo === null) {
        http_response_code(416);
        header('Content-Type: text/plain; charset=utf-8');
        header('Accept-Ranges: bytes');
        if ($fileSize >= 0) {
            header('Content-Range: bytes */' . $fileSize);
        }
        echo 'Rango no válido';
        exit;
    }

    $start = (int)$rangeInfo['start'];
    $end   = (int)$rangeInfo['end'];
    $len   = (int)$rangeInfo['length'];

    $getParams = [
        'Bucket' => $bucket,
        'Key'    => $realKey,
        'Range'  => 'bytes=' . $start . '-' . $end,
    ];

    $result = $s3->getObject($getParams);

    http_response_code((int)$rangeInfo['status']);

    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $len);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    if ($etag !== '') {
        header('ETag: ' . $etag);
    }

    if ($lastMod instanceof DateTimeInterface) {
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastMod->getTimestamp()) . ' GMT');
    }

    $nombreSalida = trim((string)($fileRow['Nombre'] ?? ''));
    if ($nombreSalida !== '') {
        $nombreSalida = str_replace(["\r", "\n", '"'], ['', '', "'"], $nombreSalida);
        header('Content-Disposition: inline; filename="' . $nombreSalida . '"');
    }

    output_stream_body($result['Body']);
    exit;

} catch (\Aws\S3\Exception\S3Exception $e) {
    $awsCode = (string)$e->getAwsErrorCode();
    $status  = (int)$e->getStatusCode();

    if ($awsCode === 'NoSuchKey' || $status === 404) {
        out_text(404, 'El archivo no existe en S3');
    }

    out_text(500, 'Error al obtener el archivo desde S3: ' . $e->getMessage());

} catch (Throwable $e) {
    out_text(500, 'No se pudo cargar el archivo: ' . $e->getMessage());
}