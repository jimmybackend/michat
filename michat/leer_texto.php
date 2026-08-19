<?php
header('Content-Type: application/json; charset=utf-8');
if(session_status()===PHP_SESSION_NONE)session_start();
require_once __DIR__.'/app_bootstrap.php';
require_once __DIR__.'/S3Manager.php';
function txt_uid():int{foreach(['user_id_','user_id','id_usuario','id_user','id']as$k)if(isset($_SESSION[$k])&&ctype_digit((string)$_SESSION[$k]))return(int)$_SESSION[$k];return 0;}
function txt_find_owned(mysqli$db,int$uid,string$key):?array{$base=basename(str_replace('\\','/',$key));$s=$db->prepare("SELECT id_,Ruta,Encriptado,Nombre FROM FileS3 WHERE user_id_=? AND Found=1 AND (Encriptado=? OR Encriptado=? OR CONCAT(TRIM(TRAILING '/' FROM REPLACE(Ruta,'\\\\','/')),'/',TRIM(LEADING '/' FROM REPLACE(Encriptado,'\\\\','/')))=?) ORDER BY id_ DESC LIMIT 10");if(!$s)return null;$s->bind_param('isss',$uid,$key,$base,$key);$s->execute();$r=$s->get_result();$row=$r->fetch_assoc();$s->close();return$row?:null;}
try{$uid=txt_uid();if($uid<=0){http_response_code(401);throw new RuntimeException('Sesión inválida');}$key=trim((string)($_GET['archivo']??''));if($key==='')throw new RuntimeException('Falta la clave del archivo.');$file=txt_find_owned($db_connection,$uid,$key);if(!$file){http_response_code(403);throw new RuntimeException('Archivo no encontrado o sin permisos.');}$m=new S3Manager();$data=$m->getTextFile((int)$file['id_']);echo json_encode(['estado'=>'ok','data'=>$data],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}catch(Throwable$e){if(http_response_code()<400)http_response_code(500);echo json_encode(['estado'=>'error','mensaje'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
