<?php
/**
 * API unificada de subida
 * - action=init|part|complete
 * - mode=local_put|remote_url|dropbox|chunked
 *
 * Ejemplos:
 *  /s3v2/api/upload.php?mode=local_put&action=init&nombre=archivo.pdf
 *  /s3v2/api/upload.php?mode=remote_url&action=init&url=https://...
 *  /s3v2/api/upload.php?mode=chunked&action=init  (POST filename, filesize, mime)
 *  /s3v2/api/upload.php?mode=chunked&action=part  (POST step=sign/resume ...)
 */

declare(strict_types=1);
session_start();

header('Content-Type: application/json; charset=utf-8');

// =====================================================
//  Bootstrap del proyecto (carga vendor + Config + db)
//  IMPORTANTE: aquí NO buscamos rutas a mano.
// =====================================================
$root = dirname(__DIR__); // .../s3v2
$bootstrap = $root . '/app_bootstrap.php';
if (!is_file($bootstrap)) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'No existe app_bootstrap.php en: ' . $bootstrap], JSON_UNESCAPED_UNICODE);
  exit;
}
require_once $bootstrap;

// =====================================================
//  Carga del módulo de subida (árbol /upload)
// =====================================================
$coreInterface = $root . '/upload/core/UploaderInterface.php';
$coreResponse  = $root . '/upload/core/UploadResponse.php';
$factoryFile   = $root . '/upload/UploadFactory.php';

if (!is_file($coreInterface) || !is_file($coreResponse) || !is_file($factoryFile)) {
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => 'Faltan archivos del módulo upload',
    'missing' => [
      'UploaderInterface.php' => $coreInterface,
      'UploadResponse.php'    => $coreResponse,
      'UploadFactory.php'     => $factoryFile,
    ]
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

require_once $coreInterface;
require_once $coreResponse;
require_once $factoryFile;

// =====================================================
//  Request
// =====================================================
$action = isset($_REQUEST['action']) ? (string)$_REQUEST['action'] : '';
$mode   = isset($_REQUEST['mode'])   ? (string)$_REQUEST['mode']   : '';

if ($action === '' || $mode === '') {
  UploadResponse::fail('Faltan parámetros action/mode', 400);
}

try {
  $uploader = UploadFactory::make($mode);

  // Importante: include también FILES para que drivers tipo dropbox lo usen
  $req = array_merge($_GET, $_POST);
  $req['_files'] = $_FILES;

  if ($action === 'init') {
    UploadResponse::ok($uploader->init($req));
  } elseif ($action === 'part') {
    UploadResponse::ok($uploader->part($req));
  } elseif ($action === 'complete') {
    UploadResponse::ok($uploader->complete($req));
  } else {
    UploadResponse::fail('Acción inválida', 400);
  }
} catch (Throwable $e) {
  // Devuelve el error en JSON (para poder verlo en el navegador/console)
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => $e->getMessage(),
    'file' => $e->getFile(),
    'line' => $e->getLine(),
  ], JSON_UNESCAPED_UNICODE);
  exit;
}