<?php
// chat_gen_video_status.php — Consulta estado con GetAsyncInvoke y cierra el mensaje cuando aparece output.mp4 en S3
// GET: message_id (int), wait_secs (int, opcional)
// RESP: { ok, status, message_id, s3_key?, mime_type?, size_bytes?, duration_ms?, notes? }

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

@ini_set('max_execution_time','300');
@set_time_limit(300);

// Opcional: usa TZ del servidor; si quieres forzarla, descomenta:
// date_default_timezone_set('America/Mexico_City');

function jexit($arr,$code=200){ http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

$notes = [];

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
function is_admin_like($role){
  $r = strtolower((string)$role);
  return in_array($r, ['administración','soporte','admin','administrator','support'], true);
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
   Cargar S3Manager
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
  $notes[] = 'S3Manager: '.$e->getMessage();
}

/* ============================
   AWS SDK cargado?
   ============================ */
$aws_sdk_loaded = class_exists('Aws\\BedrockRuntime\\BedrockRuntimeClient');
if (!$aws_sdk_loaded) {
  $notes[] = 'AWS SDK no está cargado (vendor/autoload.php). Revisa app_bootstrap.php';
}

/* ============================
   Inputs
   ============================ */
$message_id = isset($_GET['message_id']) ? (int)$_GET['message_id'] : 0;
$wait_secs  = isset($_GET['wait_secs']) ? max(0, (int)$_GET['wait_secs']) : 0;
if ($message_id <= 0) jexit(['ok'=>false,'error'=>'message_id inválido'],400);

/* ============================
   Cargar mensaje
   ============================ */
$stmt = $db_connection->prepare("SELECT id_, session_id_, user_id_, role, content_type, s3_key, mime_type, size_bytes, duration_ms, model_id, meta FROM ChatMessages WHERE id_=? LIMIT 1");
if (!$stmt) jexit(['ok'=>false,'error'=>'Error preparando SELECT mensaje: '.$db_connection->error],500);
$stmt->bind_param('i',$message_id);
$stmt->execute();
$res=$stmt->get_result(); $row=$res?$res->fetch_assoc():null; $stmt->close();
if(!$row) jexit(['ok'=>false,'error'=>'Mensaje no encontrado'],404);

$session_id  = (int)$row['session_id_'];
$owner_id    = (int)$row['user_id_']; // dueño del mensaje (se usa para permisos básicos)
$s3_key      = $row['s3_key'];
$mime        = $row['mime_type'];
$size_bytes  = $row['size_bytes'];
$duration_ms = $row['duration_ms'];
$model_id    = $row['model_id'];
$meta        = json_decode($row['meta'] ?: '{}', true); if (!is_array($meta)) $meta=[];
$status      = (string)($meta['status'] ?? 'queued');
$prefix      = (string)($meta['output_prefix'] ?? '');

/* ============================
   Permisos: dueño sesión o admin
   ============================ */
$user_id = 0;
if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) $user_id = (int)$_SESSION['user_id'];
if (!$user_id && isset($_GET['user_id']) && is_numeric($_GET['user_id'])) $user_id = (int)$_GET['user_id'];
if (!$user_id) $user_id = 1;

$role = isset($_SESSION['role']) ? (string)$_SESSION['role'] : '';

$stmtS = $db_connection->prepare("SELECT user_id_ FROM ChatSessions WHERE id_=? LIMIT 1");
if ($stmtS) {
  $stmtS->bind_param('i', $session_id);
  if ($stmtS->execute()) {
    $rsS = $stmtS->get_result();
    $sRow = $rsS ? $rsS->fetch_assoc() : null;
    if ($sRow) {
      $session_owner = (int)$sRow['user_id_'];
      if (!($session_owner === $user_id || is_admin_like($role))) {
        $stmtS->close();
        jexit(['ok'=>false,'error'=>'Sin permisos para esta sesión'],403);
      }
    }
  }
  $stmtS->close();
}

/* ============================
   Reconstruir prefijo si no estaba
   ============================ */
if ($prefix === '') {
  $rootPrefix = (class_exists('Config') && defined('Config::RUTA_RAIZ') && Config::RUTA_RAIZ) ? rtrim(Config::RUTA_RAIZ,'/').'/' : '';
  $prefix = $rootPrefix . "Chat/GenerationsVideos/{$session_id}/msg_{$message_id}/";
  $meta['output_prefix'] = $prefix;
}

/* ============================
   Si ya tenemos s3_key y existe → completed
   ============================ */
if ($have_s3 && class_exists('Config') && class_exists('S3Manager')) {
  try{
    $s3 = Config::getS3();
    $bucket = (new S3Manager())->getBucket();
    if ($s3_key) {
      try {
        $head = $s3->headObject(['Bucket'=>$bucket,'Key'=>$s3_key]);
        $size = (int)($head['ContentLength'] ?? 0);
        $ct   = (string)($head['ContentType'] ?? ($mime ?: 'video/mp4'));

        $meta['status'] = 'completed';
        $meta['completed_at'] = date('c');
        $mj = json_encode($meta, JSON_UNESCAPED_UNICODE);

        $stmtU = $db_connection->prepare("UPDATE ChatMessages SET mime_type=?, size_bytes=?, meta=? WHERE id_=?");
        if($stmtU){ $stmtU->bind_param('sisi',$ct,$size,$mj,$message_id); $stmtU->execute(); $stmtU->close(); }

        jexit(['ok'=>true,'status'=>'completed','message_id'=>$message_id,'s3_key'=>$s3_key,'mime_type'=>$ct,'size_bytes'=>$size,'duration_ms'=>$duration_ms]);
      } catch(Throwable $e){ /* seguir */ }
    }
  } catch(Throwable $e){
    $notes[] = 'S3 init: '.$e->getMessage();
  }
} else {
  $notes[] = 'S3 no disponible para verificar output.';
}

/* ============================
   Helpers S3 scan
   ============================ */
function listVideosUnder($s3,$bucket,$prefix){
  $found=[]; $cont=null;
  do{
    $args=['Bucket'=>$bucket,'Prefix'=>$prefix]; if($cont) $args['ContinuationToken']=$cont;
    $res=$s3->listObjectsV2($args);
    foreach (($res['Contents'] ?? []) as $obj){
      $key=(string)$obj['Key']; $lower=strtolower($key);
      if (preg_match('/\.(mp4|mov|webm|mkv|gif)$/',$lower)) {
        $found[]=['Key'=>$key,'Size'=>(int)($obj['Size'] ?? 0),'LM'=>(string)($obj['LastModified'] ?? '')];
      }
      if (function_exists('str_ends_with') && str_ends_with($lower,'video-generation-status.json')) {
        $found[]=['StatusKey'=>$key];
      }
    }
    $cont = $res['NextContinuationToken'] ?? null;
  }while($cont);
  return $found;
}
function tryParseStatus($s3,$bucket,$key){
  try{
    $obj=$s3->getObject(['Bucket'=>$bucket,'Key'=>$key]);
    $txt=(string)$obj['Body']; $j=json_decode($txt,true);
    if(is_array($j)) return $j;
  }catch(Throwable $e){}
  return null;
}

/* ============================
   Bedrock getAsyncInvoke (best-effort)
   ============================ */
$have_bedrock = $aws_sdk_loaded;
$bedrock = null;

if ($have_bedrock) {
  try{
    $region = (class_exists('Config') && defined('Config::REGION') && Config::REGION) ? Config::REGION : 'us-east-1';
    $bedrock = new Aws\BedrockRuntime\BedrockRuntimeClient([
      'region'=>$region,'version'=>'latest',
      'http'=>['connect_timeout'=>10,'timeout'=>60],
    ]);
  }catch(Throwable $e){
    $notes[]='Bedrock init: '.$e->getMessage();
    $bedrock=null;
  }
}

$started = time();
$deadline = $started + $wait_secs;
$invocationArn = (string)($meta['invocationArn'] ?? '');

do {
  // 1) Estado Bedrock si hay invocationArn
  if ($bedrock && $invocationArn) {
    try{
      $resp = $bedrock->getAsyncInvoke(['invocationArn'=>$invocationArn]);
      $st = strtolower((string)($resp['status'] ?? ''));
      if ($st) $status = $st; // completed | inprogress | failed

      if ($st === 'failed') {
        $meta['status'] = 'failed';
        $meta['finished_at'] = date('c');
        $mj = json_encode($meta, JSON_UNESCAPED_UNICODE);
        $stmtUF = $db_connection->prepare("UPDATE ChatMessages SET meta=? WHERE id_=?");
        if($stmtUF){ $stmtUF->bind_param('si',$mj,$message_id); $stmtUF->execute(); $stmtUF->close(); }

        // Intentar leer status json en S3 (si existe)
        $statusJson = null;
        try{
          if ($have_s3 && class_exists('Config') && class_exists('S3Manager')) {
            $s3 = Config::getS3(); $bucket=(new S3Manager())->getBucket();
            $all = listVideosUnder($s3,$bucket,$prefix);
            $statusKey=null; foreach($all as $it){ if(isset($it['StatusKey'])){ $statusKey=$it['StatusKey']; break; } }
            $statusJson = $statusKey ? tryParseStatus($s3,$bucket,$statusKey) : null;
          }
        }catch(Throwable $e){}

        $out = ['ok'=>true,'status'=>'failed','message_id'=>$message_id];
        if (!empty($notes)) $out['notes'] = $notes;
        if ($statusJson !== null) $out['status_json'] = $statusJson;
        jexit($out);
      }
    }catch(Throwable $e){
      $notes[]='GetAsyncInvoke: '.$e->getMessage();
    }
  }

  // 2) Buscar salida en S3
  try{
    if ($have_s3 && class_exists('Config') && class_exists('S3Manager')) {
      $s3 = Config::getS3();
      $bucket = (new S3Manager())->getBucket();

      $all = listVideosUnder($s3,$bucket,$prefix);

      // ¿hay status JSON con failure?
      $statusKey=null; foreach($all as $it){ if(isset($it['StatusKey'])){ $statusKey=$it['StatusKey']; break; } }
      if ($statusKey) {
        $statusJson = tryParseStatus($s3,$bucket,$statusKey);
        if ($statusJson && isset($statusJson['fullVideo']['status']) && strtoupper((string)$statusJson['fullVideo']['status'])==='FAILURE') {
          $meta['status']='failed'; $meta['finished_at']=date('c');
          $mj=json_encode($meta,JSON_UNESCAPED_UNICODE);
          $stmtUF=$db_connection->prepare("UPDATE ChatMessages SET meta=? WHERE id_=?");
          if($stmtUF){ $stmtUF->bind_param('si',$mj,$message_id); $stmtUF->execute(); $stmtUF->close(); }

          $out = ['ok'=>true,'status'=>'failed','message_id'=>$message_id,'status_json'=>$statusJson];
          if (!empty($notes)) $out['notes'] = $notes;
          jexit($out);
        }
      }

      // elegir mejor video (prefiere output.mp4; si no, el mayor)
      $best=null;
      foreach($all as $it){
        if(!isset($it['Key'])) continue;
        $k=$it['Key'];
        $lk=strtolower($k);
        if (function_exists('str_ends_with') && str_ends_with($lk, '/output.mp4')) { $best=$it; break; }
        if (!$best || ((int)$it['Size']) > ((int)$best['Size'])) $best=$it;
      }

      if ($best && isset($best['Key'])) {
        // HEAD
        $head = $s3->headObject(['Bucket'=>$bucket,'Key'=>$best['Key']]);
        $mime_final = (string)($head['ContentType'] ?? 'video/mp4');
        $size_final = (int)($head['ContentLength'] ?? ($best['Size'] ?? 0));

        // Nombre único final
        $rootPrefix = (class_exists('Config') && defined('Config::RUTA_RAIZ') && Config::RUTA_RAIZ) ? rtrim(Config::RUTA_RAIZ,'/').'/' : '';
        $uniqueName = date('Ymd_His')."_msg_{$message_id}_".bin2hex(random_bytes(3)).".mp4";
        $finalKey   = $rootPrefix . "Chat/GenerationsVideos/{$session_id}/" . $uniqueName;

        // COPY → destino final
        $s3->copyObject([
          'Bucket' => $bucket,
          'CopySource' => rawurlencode($bucket.'/'.$best['Key']),
          'Key'    => $finalKey,
          'ACL'    => 'private',
          'ContentType' => $mime_final,
          'ContentDisposition' => 'attachment; filename="'.$uniqueName.'"',
          'MetadataDirective' => 'REPLACE'
        ]);

        // Actualizar DB → usar el key final único
        $meta['status']='completed';
        $meta['completed_at']=date('c');
        $meta['source_key']=$best['Key'];
        $mj=json_encode($meta,JSON_UNESCAPED_UNICODE);

        $stmtU=$db_connection->prepare("UPDATE ChatMessages SET content_type='video', s3_key=?, mime_type=?, size_bytes=?, meta=? WHERE id_=?");
        if($stmtU){ $stmtU->bind_param('ssisi',$finalKey,$mime_final,$size_final,$mj,$message_id); $stmtU->execute(); $stmtU->close(); }

        $out = ['ok'=>true,'status'=>'completed','message_id'=>$message_id,'s3_key'=>$finalKey,'mime_type'=>$mime_final,'size_bytes'=>$size_final,'duration_ms'=>$duration_ms];
        if (!empty($notes)) $out['notes'] = $notes;
        jexit($out);
      }
    } else {
      $notes[] = 'S3 no disponible (no se puede escanear outputs).';
    }
  } catch(Throwable $e){
    $notes[]='S3 scan: '.$e->getMessage();
  }

  // 3) timeout de espera del servidor → estado intermedio
  if (time() >= $deadline) {
    $meta['status'] = ($status==='queued') ? 'processing' : $status;
    $meta['last_check_at'] = date('c');
    $mj=json_encode($meta,JSON_UNESCAPED_UNICODE);

    $stmtUP=$db_connection->prepare("UPDATE ChatMessages SET meta=? WHERE id_=?");
    if($stmtUP){ $stmtUP->bind_param('si',$mj,$message_id); $stmtUP->execute(); $stmtUP->close(); }

    $out = ['ok'=>true,'status'=>$meta['status'],'message_id'=>$message_id];
    if (!empty($notes)) $out['notes'] = $notes;
    jexit($out);
  }

  sleep(3);
} while(true);
