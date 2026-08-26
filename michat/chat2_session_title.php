<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
if(session_status()===PHP_SESSION_NONE)session_start();
function jexit(array $data,int $code=200):never{http_response_code($code);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{
 require_once __DIR__.'/app_bootstrap.php';
 require_once __DIR__.'/includes/Chat/ChatIdentity.php';
 require_once __DIR__.'/includes/Chat/SessionLifecycleService.php';
 if(!isset($db_connection)||!($db_connection instanceof mysqli))throw new RuntimeException('DB no disponible');
 $userId=ChatIdentity::resolveUserId($db_connection);if($userId<=0)jexit(['ok'=>false,'error'=>'Sesión de usuario no válida'],401);
 if(isset($_POST['user_id'])&&is_numeric($_POST['user_id'])&&(int)$_POST['user_id']!==$userId)jexit(['ok'=>false,'error'=>'user_id no coincide con la sesión autenticada'],403);
 $sessionId=(int)($_POST['session_id']??0);if($sessionId<=0)jexit(['ok'=>false,'error'=>'session_id inválido'],400);
 $value=trim((string)($_POST['title']??''));
 $service=new SessionLifecycleService($db_connection);$row=$service->rename($userId,$sessionId,$value);
 jexit(['ok'=>true]+$row);
}catch(OutOfBoundsException $e){jexit(['ok'=>false,'error'=>'Sesión no encontrada'],404);}
catch(InvalidArgumentException $e){jexit(['ok'=>false,'error'=>$e->getMessage()],400);}
catch(Throwable $e){error_log('chat2_session_title.php: '.$e->getMessage());jexit(['ok'=>false,'error'=>'Error interno'],500);}
