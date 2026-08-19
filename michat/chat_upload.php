<?php
/**
 * chat_upload.php
 * Sube $_FILES['file'] a S3 usando Config::getS3()
 * RESP: { ok, s3_key, mime_type, size_bytes, thumb_s3_key? }
 */
if (session_status()===PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

// Opcional: usa TZ del servidor; si quieres forzarla, descomenta:
// date_default_timezone_set('America/Merida');

function jerr($m,$c=400){ http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m], JSON_UNESCAPED_UNICODE); exit; }
function jok($a){ echo json_encode(array_merge(['ok'=>true],$a), JSON_UNESCAPED_UNICODE); exit; }

/* ============================
   Resolver rutas (bootstrap)
   ============================ */
function resolve_root_candidates(): array {
  $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? (string)$_SERVER['DOCUMENT_ROOT'] : '';
  $rootFromDoc = $docRoot !== '' ? realpath($docRoot . '/..') : false;

  $candidates = [];
  foreach ([
    $rootFromDoc,
    realpath(__DIR__ . '/../../'),
    realpath(__DIR__ . '/../..'),
    realpath(__DIR__ . '/../../../'),
    realpath(__DIR__ . '/../'),
    realpath(__DIR__),
  ] as $p) {
    if ($p && is_dir($p)) $candidates[$p] = true;
  }
  return array_keys($candidates);
}
function find_file_in_candidates(string $filename, array $bases, array $subfolders): ?string {
  $filename = ltrim($filename, '/');
  foreach ($bases as $base) {
    foreach ($subfolders as $sub) {
      $sub = ($sub === '' ? '' : '/' . trim($sub,'/'));
      $try = rtrim($base,'/') . $sub . '/' . $filename;
      if (is_file($try)) return $try;
    }
  }
  return null;
}

/* ============================
   Auth
   ============================ */
if (empty($_SESSION['user_id'])) jerr('No autenticado', 401);
$user_id = (int)$_SESSION['user_id'];

/* ============================
   Cargar bootstrap (autoload + Config + db si aplica)
   ============================ */
try {
  $bootstrap = __DIR__ . '/app_bootstrap.php';
  if (!is_file($bootstrap)) $bootstrap = __DIR__ . '/../app_bootstrap.php';

  if (!is_file($bootstrap)) {
    $bases = resolve_root_candidates();
    $bootstrap = find_file_in_candidates('app_bootstrap.php', $bases, ['', 'public_html', 'api', 'app', 'www']);
  }

  if (!$bootstrap || !is_file($bootstrap)) {
    jerr('app_bootstrap.php no encontrado', 500);
  }
  require_once $bootstrap;
} catch (Throwable $e) {
  jerr('bootstrap: '.$e->getMessage(), 500);
}

if (!class_exists('Config')) jerr('Config no disponible (bootstrap)', 500);

if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) jerr('Archivo faltante');

$fn   = (string)$_FILES['file']['name'];
$tmp  = (string)$_FILES['file']['tmp_name'];
$size = (int)($_FILES['file']['size'] ?? 0);

$mime = '';
if (function_exists('mime_content_type')) $mime = mime_content_type($tmp) ?: '';
if (!$mime) $mime = (string)($_FILES['file']['type'] ?? 'application/octet-stream');

$cleanName = preg_replace('~[^A-Za-z0-9._-]+~', '-', basename($fn));
$subdir    = 'Data/Chat/Uploads/'.$user_id.'/'.date('Y/m').'/';
$key       = $subdir . (uniqid('chat_', true) . '_' . $cleanName);

try {
  $s3 = Config::getS3();

  // Preferimos el bucket desde S3Manager si existe (para mantener consistencia con el resto)
  $bucket = null;
  if (class_exists('S3Manager')) {
    try { $bucket = (new S3Manager())->getBucket(); } catch(Throwable $e) { /* ignore */ }
  }
  if (!$bucket) {
    $bucket = defined('Config::BUCKET') ? Config::BUCKET : (defined('Config::S3_BUCKET') ? Config::S3_BUCKET : null);
  }
  if (!$bucket) jerr('Bucket no definido en Config/S3Manager', 500);

  $s3->putObject([
    'Bucket'      => $bucket,
    'Key'         => $key,
    'SourceFile'  => $tmp,
    'ACL'         => 'private',
    'ContentType' => $mime,
  ]);
} catch (Throwable $e) {
  jerr('S3 error: '.$e->getMessage(), 500);
}

jok([
  's3_key'       => $key,
  'mime_type'    => $mime,
  'size_bytes'   => $size,
  'thumb_s3_key' => ''
]);
