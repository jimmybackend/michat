<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
if(session_status()===PHP_SESSION_NONE)session_start();
function jexit(array $d,int $c=200):void{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST')jexit(['ok'=>false,'error'=>'Método no permitido'],405);
try{
    require_once __DIR__.'/includes/Chat/ChatEndpointBootstrap.php';
    require_once __DIR__.'/includes/Chat/ChatIdentity.php';
    require_once __DIR__.'/includes/Chat/SessionLifecycleService.php';
    $db=ChatEndpointBootstrap::mysqli(__DIR__);$userId=ChatIdentity::resolveUserId($db);if($userId<=0)jexit(['ok'=>false,'error'=>'Sesión de usuario no válida'],401);
    $sessionId=(int)($_POST['session_id']??0);if($sessionId<=0)jexit(['ok'=>false,'error'=>'session_id inválido'],400);
    $service=new SessionLifecycleService($db);$deleted=$service->discardIfEmpty($userId,$sessionId);
    jexit(['ok'=>true,'session_id'=>$sessionId,'deleted'=>$deleted]);
}catch(Throwable $e){jexit(['ok'=>false,'error'=>$e->getMessage()],500);}
