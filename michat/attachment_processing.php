<?php
declare(strict_types=1);
if(session_status()===PHP_SESSION_NONE)session_start();
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try{
    require_once __DIR__.'/app_bootstrap.php';
    require_once __DIR__.'/includes/Chat/ChatIdentity.php';
    require_once __DIR__.'/includes/Drive/AttachmentProcessingService.php';
    $user=ChatIdentity::resolveUserId($db_connection);$session=(int)($_POST['session_id']??0);
    $owned=(new DocumentAccessRepository($db_connection))->session($user,$session);
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||!$owned||$owned['status']!=='open'||!isset($_POST['csrf'],$_SESSION['document_link_csrf'])||!hash_equals($_SESSION['document_link_csrf'],(string)$_POST['csrf'])){
        http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Acceso denegado']);exit;
    }
    $service=new AttachmentProcessingService($db_connection);
    try{$service->available();}
    catch(Throwable $e){error_log('ATTACHMENT_SCHEMA '.$e->getMessage());http_response_code(422);echo json_encode(['ok'=>false,'code'=>'processing_schema_failed','error'=>'No se pudieron preparar automáticamente las tablas de procesamiento. Revisa los permisos CREATE y CREATE TEMPORARY TABLES de la cuenta MySQL de MiChat y el registro del servidor. El archivo adjunto se conserva.']);exit;}
    $trace=(string)($_POST['trace_id']??'');if(!preg_match('/^[A-Za-z0-9_-]{16,36}$/',$trace))$trace='';
    // Release PHP session lock so real progress can be polled while a phase runs.
    session_write_close();
    $jobs=[];
    if(($_POST['action']??'')==='prepare'){
        $query=trim((string)($_POST['query']??''));if(mb_strlen($query)>20000)throw new RuntimeException('Petición demasiado grande');
        $jobs=$service->plan($user,$session,$query,$trace);
    }else{
        $ids=json_decode((string)($_POST['job_ids']??'[]'),true,16,JSON_THROW_ON_ERROR);
        if(!is_array($ids)||count($ids)>8)throw new RuntimeException('Lista de tareas inválida');
        foreach($ids as $id){
            $job=$service->job($user,(int)$id);if((int)$job['session_id_']!==$session)throw new RuntimeException('Tarea de otra conversación');
            $jobs[]=$service->tick($user,(int)$id);
        }
    }
    echo json_encode(['ok'=>true,'available'=>true,'jobs'=>array_map([AttachmentProcessingService::class,'publicJob'],$jobs)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    error_log('ATTACHMENT_PROCESSING '.get_class($e).' '.$e->getMessage());http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'No se pudo preparar la tarea de archivos. Comprueba permisos, formato y configuración; revisa el registro del servidor.']);
}
