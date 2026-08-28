<?php
session_start();

require_once __DIR__ . '/app_bootstrap.php';

// Obtener la clave del objeto S3
$key = $_GET['archivo'] ?? '';
$bucket = Config::BUCKET;

if (!$key) {
    http_response_code(400);
    exit('Archivo no especificado');
}

$s3 = Config::getS3();

try {
    // Descarga el objeto desde S3
    $result = $s3->getObject([
        'Bucket' => $bucket,
        'Key'    => $key
    ]);

    // Forzar siempre PDF como tipo de contenido
    header('Content-Type: application/pdf');
    // Inline para que se muestre en el navegador, no se descargue
    header('Content-Disposition: inline; filename="' . basename($key) . '"');
    // Permitir rangos para scroll y búsqueda dentro del PDF
    header('Accept-Ranges: bytes');
    // (Opcional) Cacheo por un día
    header('Cache-Control: public, max-age=86400');

    // Enviar el contenido binario al navegador
    echo $result['Body'];
} catch (Aws\Exception\AwsException $e) {
    // Error de AWS SDK
    http_response_code(500);
    echo 'Error al cargar PDF: ' . htmlspecialchars($e->getMessage());
} catch (Exception $e) {
    // Otro tipo de error
    http_response_code(500);
    echo 'Error inesperado: ' . htmlspecialchars($e->getMessage());
}
