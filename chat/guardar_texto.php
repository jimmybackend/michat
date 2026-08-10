<?php
/**
 * ============================================================
 * ARCHIVO: guardar_texto.php
 * ============================================================
 * FUNCIÓN:
 * Guarda un archivo de texto en S3 usando la KEY S3.
 * ============================================================
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/S3Manager.php';

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido');
    }

    if (!isset($_POST['archivo']) || trim($_POST['archivo']) === '') {
        throw new Exception("Falta la clave del archivo.");
    }

    if (!isset($_POST['contenido'])) {
        throw new Exception("Falta contenido.");
    }

    $archivo = trim($_POST['archivo']);
    $contenido = $_POST['contenido'];

    $s3Manager = new S3Manager();
    $resultado = $s3Manager->updateTextFile($archivo, $contenido);

    echo json_encode([
        'estado' => 'ok',
        'data'   => $resultado
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'estado'  => 'error',
        'mensaje' => $e->getMessage()
    ]);
}