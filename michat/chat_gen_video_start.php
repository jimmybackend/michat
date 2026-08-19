<?php
// chat_gen_video_start.php — Nova Reel (Async Invoke) → escribe en S3 y crea placeholder en ChatMessages
// POST: session_id, prompt?, duration?, model (OBLIGATORIO)
// RESP: { ok, message_id, invocationArn, status, prefix, notes? }

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

@ini_set('max_execution_time', '300');
@set_time_limit(300);

// Opcional: usa la TZ por defecto del servidor; si quieres forzarla, descomenta:
// date_default_timezone_set('America/Mexico_City');

function jexit($arr, $code=200){ http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

$errors = [];

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

  if (!$bootstrap || !is_file($bootstrap)) throw new RuntimeException('app_bootstrap.php no encontrado.');
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
  $errors[] = 'S3Manager: '.$e->getMessage();
}

/* ============================
   AWS SDK cargado?
   ============================ */
$aws_sdk_loaded = class_exists('Aws\\BedrockRuntime\\BedrockRuntimeClient');
if (!$aws_sdk_loaded) {
  $errors[] = 'AWS SDK no está cargado (vendor/autoload.php). Revisa app_bootstrap.php';
}

/* ============================
   Helpers
   ============================ */
function next_id(mysqli $db, $table, $col){
  $table=preg_replace('/[^A-Za-z0-9_]+/','',$table);
  $col=preg_replace('/[^A-Za-z0-9_]+/','',$col);
  $rs=$db->query("SELECT IFNULL(MAX($col),0)+1 AS nxt FROM $table");
  if(!$rs) return 1;
  $row=$rs->fetch_assoc();
  return (int)($row['nxt'] ?? 1);
}
function is_admin_like($r){
  $r=strtolower((string)$r);
  return in_array($r,['administración','soporte','admin','administrator','support'],true);
}

/* ============================
   Inputs
   ============================ */
$session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
if ($session_id <= 0) jexit(['ok'=>false,'error'=>'session_id inválido'],400);

$user_id = 0;
if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) $user_id=(int)$_SESSION['user_id'];
if (!$user_id && isset($_POST['user_id']) && is_numeric($_POST['user_id'])) $user_id=(int)$_POST['user_id'];
if (!$user_id) $user_id = 1;

$role = isset($_SESSION['role']) ? (string)$_SESSION['role'] : '';

$prompt = trim((string)($_POST['prompt'] ?? ''));

// Reel (TEXT_VIDEO) solo acepta 6s
$duration_s = 6;

// ✅ Modelo OBLIGATORIO (lo envías desde el <select>)
$model_id = isset($_POST['model']) ? trim((string)$_POST['model']) : '';
if ($model_id === '') jexit(['ok'=>false,'error'=>'Falta parámetro model'], 400);

// Solo permitir Reel en este endpoint
$allowed_video_models = [
  'amazon.nova-reel-v1:0',
  'amazon.nova-reel-v1:1',
];
if (!in_array($model_id, $allowed_video_models, true)) {
  jexit(['ok'=>false,'error'=>'Modelo no permitido para video','model'=>$model_id], 400);
}

// Seed opcional
$seed = (isset($_POST['seed']) && $_POST['seed'] !== '') ? (int)$_POST['seed'] : 42;

/* ============================
   Permisos sesión
   ============================ */
$sqlS="SELECT id_,user_id_,status FROM ChatSessions WHERE id_=?"; 
$stmtS=$db_connection->prepare($sqlS);
if(!$stmtS) jexit(['ok'=>false,'error'=>'Error preparando SELECT sesión: '.$db_connection->error],500);
$stmtS->bind_param('i',$session_id);
if(!$stmtS->execute()){ $e=$stmtS->error; $stmtS->close(); jexit(['ok'=>false,'error'=>'Error ejecutando SELECT sesión: '.$e],500); }
$resS=$stmtS->get_result();
if(!$resS||!$resS->num_rows){ $stmtS->close(); jexit(['ok'=>false,'error'=>'Sesión no encontrada'],404); }
$sessionRow=$resS->fetch_assoc(); 
$stmtS->close();

$owner_id=(int)$sessionRow['user_id_'];
if(!( $owner_id===$user_id || is_admin_like($role))) jexit(['ok'=>false,'error'=>'Sin permisos para esta sesión'],403);

/* ============================
   Placeholder en DB
   ============================ */
$msg_id = next_id($db_connection,'ChatMessages','id_');

$rootPrefix = (class_exists('Config') && defined('Config::RUTA_RAIZ') && Config::RUTA_RAIZ) ? rtrim(Config::RUTA_RAIZ,'/').'/' : '';
$relPrefix  = $rootPrefix . "Chat/GenerationsVideos/{$session_id}/msg_{$msg_id}/"; // Reel creará subfolder por invocationId

$role_assistant='assistant';
$content_type='video';
$content = $prompt !== '' ? "🎬 Generando video…\n\n**Prompt:** ".$prompt : "🎬 Generando video…";
$s3_key=null; $mime=null; $size_bytes=null; $thumb=null;
$duration_ms = $duration_s * 1000;
$stop_reason=null; $prompt_tok=null; $compl_tok=null; $latency_ms=null;

$meta = [
  'kind'           => 'video_job',
  'provider'       => 'bedrock-nova-reel',
  'status'         => 'queued',
  'created_at'     => date('c'),
  'output_prefix'  => $relPrefix,  // Reel pondrá un subfolder con invocationId
  'invocationArn'  => null,
  'model_id'       => $model_id,
  'duration_s'     => $duration_s,
];
$meta_json = json_encode($meta, JSON_UNESCAPED_UNICODE);

$sqlI = "INSERT INTO ChatMessages (
  id_, session_id_, user_id_, role, content_type, content,
  s3_key, mime_type, size_bytes, thumb_s3_key, duration_ms,
  model_id, stop_reason, prompt_tokens, completion_tokens, latency_ms, meta
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
$stmtI = $db_connection->prepare($sqlI);
if(!$stmtI) jexit(['ok'=>false,'error'=>'INSERT placeholder: '.$db_connection->error],500);
$types="iiisssssisissiiis";
$stmtI->bind_param($types, $msg_id,$session_id,$owner_id,$role_assistant,$content_type,$content,
  $s3_key,$mime,$size_bytes,$thumb,$duration_ms,$model_id,$stop_reason,$prompt_tok,$compl_tok,$latency_ms,$meta_json);
if(!$stmtI->execute()){ $e=$stmtI->error; $stmtI->close(); jexit(['ok'=>false,'error'=>'INSERT placeholder: '.$e],500); }
$stmtI->close();

/* ============================
   Preflight S3: comprobar escritura
   ============================ */
if (!$have_s3 || !class_exists('S3Manager') || !class_exists('Config')) {
  jexit(['ok'=>false,'error'=>'S3Manager/Config no disponible para video async'], 500);
}

try{
  $s3 = Config::getS3();
  $bucket = (new S3Manager())->getBucket();
  $markerKey = $relPrefix.'preflight.txt';
  $s3->putObject(['Bucket'=>$bucket,'Key'=>$markerKey,'Body'=>'ok','ContentType'=>'text/plain']);
}catch(Throwable $e){
  jexit(['ok'=>false,'error'=>'No se puede escribir en S3 (PutObject falló): '.$e->getMessage()],500);
}

/* ============================
   Start Async Invoke (Nova Reel escribe a S3 bajo ese prefijo)
   ============================ */
$invocationArn = null;

try {
  if (!$aws_sdk_loaded) throw new RuntimeException('AWS SDK no cargado.');

  $region = (class_exists('Config') && defined('Config::REGION') && Config::REGION) ? Config::REGION : 'us-east-1';

  $bedrock = new Aws\BedrockRuntime\BedrockRuntimeClient([
    'region'  => $region,
    'version' => 'latest',
    'http'    => ['connect_timeout'=>20,'timeout'=>240],
  ]);

  $modelInput = [
    'taskType' => 'TEXT_VIDEO',
    'textToVideoParams' => [
      'text' => ($prompt !== '' ? $prompt : 'Short cinematic establishing shot')
    ],
    'videoGenerationConfig' => [
      'durationSeconds' => $duration_s,   // 6s requerido
      'fps'             => 24,
      'dimension'       => '1280x720',
      'seed'            => $seed
    ]
  ];

  $bucket = (new S3Manager())->getBucket();
  $s3Uri = "s3://{$bucket}/{$relPrefix}";

  $resp = $bedrock->startAsyncInvoke([
    'modelId' => $model_id,
    'modelInput' => $modelInput,
    'outputDataConfig' => [
      's3OutputDataConfig' => [ 's3Uri' => $s3Uri ]
    ],
    'clientRequestToken' => 'msg-'.$msg_id.'-'.bin2hex(random_bytes(6)),
  ]);

  if (isset($resp['invocationArn'])) {
    $invocationArn = (string)$resp['invocationArn'];
  }

} catch (Throwable $e) {
  $errors[] = 'StartAsyncInvoke: '.$e->getMessage();
}

/* ============================
   Persistir meta con invocationArn / estado
   ============================ */
$meta['status'] = $invocationArn ? 'in_progress' : 'queued';
$meta['invocationArn'] = $invocationArn;
$meta_json = json_encode($meta, JSON_UNESCAPED_UNICODE);

$stmtU = $db_connection->prepare("UPDATE ChatMessages SET meta=? WHERE id_=?");
if($stmtU){ $stmtU->bind_param('si', $meta_json, $msg_id); $stmtU->execute(); $stmtU->close(); }

$out = [
  'ok'=>true,
  'message_id'=>$msg_id,
  'invocationArn'=>$invocationArn,
  'status'=>$meta['status'],
  'prefix'=>$relPrefix,
];
if(!empty($errors)) $out['notes'] = $errors;

jexit($out);
