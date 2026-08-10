<?php
// descargar.php — Descarga un solo archivo con el nombre visible (BD), no el encriptado
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$esAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Autorización básica
if (empty($_SESSION['usuario'])) {
    if ($esAjax) {
        header('Content-Type: application/json; charset=UTF-8', true, 403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
    } else {
        http_response_code(403);
        echo 'Acceso denegado';
    }
    exit;
}

require 'vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';

$s3     = Config::getS3();
$bucket = Config::BUCKET;

try {
    // Validar entrada
    $key = isset($_GET['archivo']) ? trim((string)$_GET['archivo']) : '';
    if ($key === '' || substr($key, -1) === '/') {
        http_response_code(400);
        echo 'Parámetro "archivo" inválido.';
        exit;
    }

    // 1) Obtener nombre visible desde BD con Encriptado = basename(key)
    $enc = basename($key);
    $nombreVisible = null;

    if (isset($db_connection) && $db_connection instanceof mysqli) {
        if ($stmt = $db_connection->prepare('SELECT Nombre FROM FileS3 WHERE Encriptado = ? LIMIT 1')) {
            $stmt->bind_param('s', $enc);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $nombreVisible = (string)$row['Nombre'];
            }
            $stmt->close();
        }
    }

    // 2) Fallback al parámetro ?nombre= si no existe en BD (opcional)
    if ($nombreVisible === null) {
        $nombreVisible = isset($_GET['nombre']) ? (string)$_GET['nombre'] : $enc;
    }

    // 3) Asegurar extensión del archivo (si el nombre visible no la trae)
    $ext = pathinfo($enc, PATHINFO_EXTENSION);
    $ext = $ext ? ('.' . strtolower($ext)) : '';
    // Quita la última extensión del nombre visible si ya trae alguna
    $baseVisible = preg_replace('/\.[^.]+$/', '', $nombreVisible);
    $nombreDescarga = $baseVisible . $ext;

    // 4) Sanitizar nombre (quitar slashes y caracteres de control)
    $nombreDescarga = preg_replace('/[\/\\\\]/', '-', $nombreDescarga);
    $nombreDescarga = preg_replace('/[\x00-\x1F\x7F]/', '', $nombreDescarga);
    $nombreDescarga = trim($nombreDescarga);
    if ($nombreDescarga === '') {
        $nombreDescarga = $enc;
    }

    // 5) Obtener metadatos (ContentType/Length) y el Body como stream
    $head = $s3->headObject([
        'Bucket' => $bucket,
        'Key'    => $key
    ]);

    $contentType   = $head['ContentType'] ?? 'application/octet-stream';
    $contentLength = isset($head['ContentLength']) ? (int)$head['ContentLength'] : null;

    $obj = $s3->getObject([
        'Bucket' => $bucket,
        'Key'    => $key
    ]);
    $body = $obj['Body']; // Stream (GuzzleHttp\Psr7\StreamInterface)

    // 6) Enviar cabeceras de descarga
    // Limpia buffers para evitar corrupción de binarios
    while (ob_get_level()) { ob_end_clean(); }
    // (Opcional) límites y buffers
    set_time_limit(0);

    header('Content-Type: ' . $contentType);
    // Content-Disposition con compatibilidad (filename y filename* RFC 5987)
    $fnEncoded = rawurlencode($nombreDescarga);
    header('Content-Disposition: attachment; filename="' . $nombreDescarga . '"; filename*=UTF-8\'\'' . $fnEncoded);
    header('X-Filename: ' . $fnEncoded); // Para front que usa fetch + blob
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    if ($contentLength !== null) {
        header('Content-Length: ' . $contentLength);
    }

    // 7) Volcar el stream en chunks
    if (is_string($body)) {
        echo $body;
    } else {
        // StreamInterface
        $chunk = 8192;
        while (!$body->eof()) {
            echo $body->read($chunk);
            // Asegura que se envíe progresivamente
            if (function_exists('fastcgi_finish_request')) {
                // no llamar aquí; solo al final. Mantener flush:
            }
            flush();
        }
    }
    exit;

} catch (Throwable $e) {
    // Errores: devolver mensaje claro (el front con fetch lo manejará)
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Error al descargar: ' . $e->getMessage();
    exit;
}
