<?php
declare(strict_types=1);
/**
 * worker_control.php — Endpoint del panel de administración para encender/
 * apagar el worker de tareas bajo demanda.
 *
 * Seguridad (reutiliza la existente, NO crea un login paralelo):
 *   - Autenticación: ChatIdentity::resolveUserId() (sesión activa) +
 *     AuthorizationService->allows($userId,'system.reset')  == superusuario.
 *   - CSRF: CsrfGuard::assertSessionToken() con $_SESSION['csrf_token'].
 *   - Solo POST. Acciones limitadas a una lista cerrada {start,stop,status}.
 *   - El comando real pasa por TaskWorkerServiceManager -> helper sudo whitelisted.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if(session_status()===PHP_SESSION_NONE)session_start();
require_once __DIR__.'/app_bootstrap.php';
require_once __DIR__.'/includes/Chat/ChatIdentity.php';
require_once __DIR__.'/includes/Security/CsrfGuard.php';
require_once __DIR__.'/includes/Auth/AuthorizationService.php';
require_once __DIR__.'/includes/Tasks/bootstrap.php';

function workerControlRespond(int $status,array $body):never{http_response_code($status);echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}

if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST'){
    workerControlRespond(405,['ok'=>false,'error'=>'method_not_allowed']);
}

// --- Autenticación de superusuario existente (permiso system.reset) ---------
$userId=0;
try{$userId=ChatIdentity::resolveUserId($db_connection);}catch(Throwable){$userId=0;}
if($userId<1){workerControlRespond(401,['ok'=>false,'error'=>'unauthenticated']);}
try{
    if(!(new AuthorizationService($db_connection))->allows($userId,'system.reset')){
        workerControlRespond(403,['ok'=>false,'error'=>'permission_denied']);
    }
}catch(Throwable){workerControlRespond(403,['ok'=>false,'error'=>'permission_denied']);}

// --- CSRF existente ----------------------------------------------------------
$provided=(string)($_POST['csrf_token']??($_SERVER['HTTP_X_CSRF_TOKEN']??''));
try{CsrfGuard::assertSessionToken($provided);}
catch(Throwable){workerControlRespond(403,['ok'=>false,'error'=>'csrf_invalid']);}

// --- Acción: lista cerrada --------------------------------------------------
$action=(string)($_POST['action']??'');
if(!in_array($action,['start','stop','status'],true)){
    workerControlRespond(422,['ok'=>false,'error'=>'action_invalid']);
}

try{
    if($action==='stop'){
        // No permitir apagar con ejecuciones vivas (lease vigente).
        if(TaskWorkerWorkload::hasActiveExecutions($db_connection)){
            workerControlRespond(409,['ok'=>false,'error'=>'active_executions','message'=>'Hay ejecuciones activas; el worker debe terminarlas antes de apagarse.']);
        }
    }

    $result=TaskWorkerServiceManager::executeCommand($action);

    if($action==='start'&&$result['active']!==true){
        // Fallback tolerado: si el arranque inmediato falla (p.ej. permisos),
        // el timer de wakeup procesará el trabajo en <=60 s.
        error_log('worker_control.php: start no confirmó estado activo; dependerá del timer de wakeup.');
    }

    $activity=new TaskWorkerActivity(TaskWorkerRuntimeConfig::fromEnvironment()->activityFile);
    workerControlRespond(200,[
        'ok'=>true,
        'action'=>$action,
        'active'=>$activity->getStatus()['active']||($result['active']===true),
        'state'=>$result['output'],
        'pending'=>TaskWorkerWorkload::getPendingCount($db_connection),
    ]);
}catch(Throwable$e){
    error_log('worker_control.php internal error: '.$e->getMessage());
    workerControlRespond(500,['ok'=>false,'error'=>'internal_error']);
}
