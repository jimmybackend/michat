<?php
/**
 * ============================================================
 * ARCHIVO: leer_texto.php
 * ============================================================
 * FUNCIÓN:
 * Lee un archivo de texto desde S3 usando la KEY S3.
 * ============================================================
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/S3Manager.php';

try {

    if (!isset($_GET['archivo']) || trim($_GET['archivo']) === '') {
        throw new Exception("Falta la clave del archivo.");
    }

    $archivo = trim($_GET['archivo']);

    $s3Manager = new S3Manager();
    $data = $s3Manager->getTextFile($archivo);

    echo json_encode([
        'estado' => 'ok',
        'data'   => $data
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'estado'  => 'error',
        'mensaje' => $e->getMessage()
    ]);
}