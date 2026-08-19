<?php
// chat_gen_image.php
// Genera imagen con Bedrock (Titan Image v2 / Nova Canvas) y guarda en S3/DB.

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

$errors = [];

/* ============================
   Helpers (salida)
   ============================ */
function jexit($a, $c = 200) { http_response_code($c); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function next_id(mysqli $db, $t, $c){
  $t = preg_replace('/[^A-Za-z0-9_]+/','',$t);
  $c = preg_replace('/[^A-Za-z0-9_]+/','',$c);
  $rs = $db->query("SELECT COALESCE(MAX($c),0)+1 AS nxt FROM $t");
  if(!$rs) return 1;
  $row = $rs->fetch_assoc();
  return (int)($row['nxt'] ?? 1);
}
function is_admin_like($r){
  $r = strtolower((string)$r);
  return in_array($r, ['administración','soporte','admin','administrator','support'], true);
}
function clamp_dim($n){
  $n = max(128, min(2048, (int)$n));
  $n = (int)(round($n / 8) * 8);
  return $n;
}

/* ============================
   Resolver rutas (bootstrap/S3Manager)
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
   Cargar bootstrap (vendor + Config + db)
   ============================ */
try {
  $bootstrap = __DIR__ . '/app_bootstrap.php';
  if (!is_file($bootstrap)) $bootstrap = __DIR__ . '/../app_bootstrap.php';

  if (!is_file($bootstrap)) {
    $bases = resolve_root_candidates();
    $bootstrap = find_file_in_candidates('app_bootstrap.php', $bases, ['', 'public_html', 'api', 'app', 'www']);
  }

  if (!$bootstrap || !is_file($bootstrap)) {
    throw new RuntimeException('app_bootstrap.php no encontrado.');
  }

  require_once $bootstrap;

} catch (Throwable $e) {
  jexit(['ok'=>false,'error'=>'bootstrap: '.$e->getMessage()], 500);
}

/* ============================
   Validar DB
   ============================ */
if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
  jexit(['ok'=>false,'error'=>'DB no disponible (bootstrap)'], 500);
}

/* ============================
   Cargar S3Manager (si existe)
   ============================ */
$have_s3 = false;
try {
  $s3Path = __DIR__ . '/S3Manager.php';
  if (!is_file($s3Path)) $s3Path = __DIR__ . '/../S3Manager.php';
  if (!is_file($s3Path)) {
    $bases = resolve_root_candidates();
    $s3Path = find_file_in_candidates('S3Manager.php', $bases, ['', 'bd', 'config', 'app', 'includes', 'lib']);
  }
  if ($s3Path && is_file($s3Path)) {
    require_once $s3Path;
    $have_s3 = true;
  }
} catch (Throwable $e) {
  $errors[] = 'S3Manager: ' . $e->getMessage();
}

/* ============================
   AWS SDK cargado?
   ============================ */
$aws_sdk_loaded = class_exists('Aws\\BedrockRuntime\\BedrockRuntimeClient');
if (!$aws_sdk_loaded) {
  $errors[] = 'AWS SDK no está cargado (vendor/autoload.php). Revisa app_bootstrap.php';
}

/* ============================
   Params
   ============================ */
$session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
$prompt     = isset($_POST['prompt']) ? trim((string)$_POST['prompt']) : '';
if ($session_id <= 0) jexit(['ok'=>false,'error'=>'session_id inválido'], 400);
if ($prompt === '')   jexit(['ok'=>false,'error'=>'prompt vacío'], 400);

$user_id = 0;
if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) $user_id = (int)$_SESSION['user_id'];
if (!$user_id && isset($_POST['user_id']) && is_numeric($_POST['user_id'])) $user_id = (int)$_POST['user_id'];
if (!$user_id && class_exists('Config') && defined('Config::DEFAULT_USER_ID')) $user_id = (int)Config::DEFAULT_USER_ID;
if (!$user_id) $user_id = 1;
$role = isset($_SESSION['role']) ? (string)$_SESSION['role'] : '';

// ✅ Modelo OBLIGATORIO (lo envías desde el <select>)
$model_id = isset($_POST['model']) ? trim((string)$_POST['model']) : '';
if ($model_id === '') jexit(['ok'=>false,'error'=>'Falta parámetro model'], 400);

// Permitir SOLO modelos de IMAGEN en este endpoint
$allowed_image_models = [
  'amazon.titan-image-generator-v2:0',
  'amazon.nova-canvas-v1:0',
];
if (!in_array($model_id, $allowed_image_models, true)) {
  jexit(['ok'=>false,'error'=>'Modelo no permitido para imágenes','model'=>$model_id], 400);
}

$width   = clamp_dim(isset($_POST['width']) ? (int)$_POST['width'] : 1024);
$height  = clamp_dim(isset($_POST['height']) ? (int)$_POST['height'] : 1024);
$cfgScale= isset($_POST['cfg_scale']) ? (float)$_POST['cfg_scale'] : 8.0;
$seed    = (isset($_POST['seed']) && $_POST['seed'] !== '') ? (int)$_POST['seed'] : null;

/* ============================
   Permisos sesión
   ============================ */
$sqlS = "SELECT id_, user_id_, status FROM ChatSessions WHERE id_=?";
$stmtS = $db_connection->prepare($sqlS);
if (!$stmtS) jexit(['ok'=>false,'error'=>'Error preparando SELECT sesión: '.$db_connection->error], 500);
$stmtS->bind_param('i', $session_id);
if (!$stmtS->execute()) { $e=$stmtS->error; $stmtS->close(); jexit(['ok'=>false,'error'=>'Error ejecutando SELECT sesión: '.$e], 500); }
$resS = $stmtS->get_result();
if (!$resS || !$resS->num_rows) { $stmtS->close(); jexit(['ok'=>false,'error'=>'Sesión no encontrada'], 404); }
$sessionRow = $resS->fetch_assoc();
$stmtS->close();

$owner_id = (int)$sessionRow['user_id_'];
if (!($owner_id === $user_id || is_admin_like($role))) jexit(['ok'=>false,'error'=>'Sin permisos para esta sesión'], 403);

/* ============================
   Invocar modelo de imagen
   ============================ */
try {
  if (!$aws_sdk_loaded) throw new RuntimeException('AWS SDK no cargado.');

  $region = (class_exists('Config') && defined('Config::REGION') && Config::REGION) ? Config::REGION : 'us-east-1';

  // Usar provider chain por defecto; si necesitas forzar creds, hazlo en Config::getAwsConfig() o similar
  $bedrock = new Aws\BedrockRuntime\BedrockRuntimeClient([
    'region'  => $region,
    'version' => 'latest',
    'http'    => ['connect_timeout' => 20, 'timeout' => 240],
  ]);

  // Titan Image v2 (invokeModel) request
  // Nota: si en el futuro usas Nova Canvas, este body puede cambiar; por ahora mantenemos el de Titan v2.
  $body = [
    'taskType' => 'TEXT_IMAGE',
    'textToImageParams' => [
      'text' => $prompt,
    ],
    'imageGenerationConfig' => [
      'numberOfImages' => 1,
      'height' => $height,
      'width'  => $width,
      'cfgScale' => $cfgScale,
    ],
  ];
  if ($seed !== null) $body['imageGenerationConfig']['seed'] = $seed;

  $resp = $bedrock->invokeModel([
    'modelId'     => $model_id,
    'accept'      => 'application/json',
    'contentType' => 'application/json',
    'body'        => json_encode($body, JSON_UNESCAPED_UNICODE),
  ]);

  $jsonTxt = (string)$resp->get('body');
  $data = json_decode($jsonTxt, true);

  $image_b64 = null;
  $mime = 'image/png';

  // Titan v2: images[0] suele ser string base64, o images[0]['image']
  if (isset($data['images'][0]['image'])) {
    $image_b64 = (string)$data['images'][0]['image'];
    if (!empty($data['images'][0]['mimeType'])) $mime = (string)$data['images'][0]['mimeType'];
  } elseif (isset($data['images'][0]) && is_string($data['images'][0])) {
    $image_b64 = (string)$data['images'][0];
  } else {
    $snippet = function_exists('mb_substr') ? mb_substr($jsonTxt, 0, 280, 'UTF-8') : substr($jsonTxt, 0, 280);
    throw new RuntimeException('Respuesta sin imagen. JSON: '.$snippet);
  }

  $bin = base64_decode($image_b64, true);
  if ($bin === false) throw new RuntimeException('Base64 inválido');

  $ext = '.png';
  if (stripos($mime, 'jpeg') !== false || stripos($mime, 'jpg') !== false) $ext = '.jpg';
  elseif (stripos($mime, 'webp') !== false) $ext = '.webp';

  $tmp = tempnam(sys_get_temp_dir(), 'titan_img_');
  $tmpFile = $tmp . $ext;
  @rename($tmp, $tmpFile);
  file_put_contents($tmpFile, $bin);
  $size_bytes = (int)@filesize($tmpFile);

  // Subir a S3 (si existe)
  $s3_key = null;
  if ($have_s3 && class_exists('S3Manager') && class_exists('Config')) {
    try {
      $manager = new S3Manager();
      $bucket  = $manager->getBucket();
      $s3      = Config::getS3();

      $prefix = 'Chat/GenerationsImages/'.$session_id.'/';
      if (defined('Config::RUTA_RAIZ') && Config::RUTA_RAIZ) {
        $prefix = rtrim(Config::RUTA_RAIZ,'/').'/'.$prefix;
      }

      $key = $prefix . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . $ext;

      $s3->putObject([
        'Bucket'      => $bucket,
        'Key'         => $key,
        'SourceFile'  => $tmpFile,
        'ContentType' => $mime,
        'ACL'         => 'private'
      ]);

      $s3_key = $key;
    } catch (Throwable $e) {
      $errors[] = 'S3 putObject: '.$e->getMessage();
    }
  }

  // Guardar en ChatMessages como assistant/image
  $idA = next_id($db_connection, 'ChatMessages', 'id_');
  $sqlA = "INSERT INTO ChatMessages (
    id_,session_id_,user_id_,role,content_type,content,
    s3_key,mime_type,size_bytes,thumb_s3_key,duration_ms,
    model_id,stop_reason,prompt_tokens,completion_tokens,latency_ms,meta
  ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

  $stmtA = $db_connection->prepare($sqlA);
  if (!$stmtA) jexit(['ok'=>false,'error'=>'Error preparando INSERT imagen: '.$db_connection->error], 500);

  $role_assistant = 'assistant';
  $content_type   = 'image';
  $content_txt    = $prompt;
  $thumb_key      = null;
  $duration_ms    = null;
  $stop_reason    = null;
  $prompt_tok     = null;
  $compl_tok      = null;
  $latency_ms     = null;
  $meta           = null;

  $types = "iiisssssisissiiis";
  $stmtA->bind_param(
    $types,
    $idA, $session_id, $owner_id, $role_assistant, $content_type, $content_txt,
    $s3_key, $mime, $size_bytes, $thumb_key, $duration_ms,
    $model_id, $stop_reason, $prompt_tok, $compl_tok, $latency_ms, $meta
  );

  if (!$stmtA->execute()) { $e=$stmtA->error; $stmtA->close(); jexit(['ok'=>false,'error'=>'Error insertando imagen en DB: '.$e], 500); }
  $stmtA->close();

  @unlink($tmpFile);

  $out = ['ok'=>true,'message_id'=>(int)$idA,'s3_key'=>$s3_key,'mime_type'=>$mime,'size_bytes'=>$size_bytes];
  if (!empty($errors)) $out['notes'] = $errors;
  jexit($out);

} catch (Throwable $e) {
  jexit(['ok'=>false,'error'=>'Fallo Bedrock imagen: '.$e->getMessage(),'details'=>$errors], 500);
}
