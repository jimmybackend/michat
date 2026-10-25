<?php
declare(strict_types=1);
if(session_status()===PHP_SESSION_NONE)session_start();
require_once __DIR__.'/app_bootstrap.php';
require_once __DIR__.'/includes/Chat/ChatIdentity.php';
require_once __DIR__.'/includes/Files/ConversationDataRepository.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
try{
    $user=ChatIdentity::resolveUserId($db_connection);$sid=(int)($_GET['session_id']??0);$scope=(string)($_GET['scope']??'session');
    $catalog=new ConversationDataRepository($db_connection);$mode=(string)($_GET['mode']??'list');
    if($mode==='list')$response=$catalog->listing($user,$sid,$scope,max(0,(int)($_GET['offset']??0)));
    else{
        $file=$catalog->requireFile($user,$sid,$scope,(int)($_GET['file_id']??0));
        require_once __DIR__.'/includes/Files/FileDeliveryService.php';
        $delivery=new FileDeliveryService($db_connection,Config::getS3(),Config::getBucket());
        $resolved=$delivery->resolve($user,$file['key'],$_SESSION);
        if($mode==='download'){$delivery->send($resolved,false);exit;}
        if($mode!=='read')throw new InvalidArgumentException('Operación inválida');
        $ext=strtolower(pathinfo($file['key'],PATHINFO_EXTENSION));
        if(!in_array($ext,['json','txt','md','csv','tsv','log','srt','vtt','xml','yaml','yml','php','js','ts','py','sql','html','css','sh','ini','conf'],true)){
            $viewer=$ext==='pdf'?'ver_pdf.php':'ver_archivo.php';
            header('Location: '.$viewer.'?archivo='.rawurlencode($file['key']));exit;
        }
        $params=['Bucket'=>Config::getBucket(),'Key'=>$file['key']];$head=Config::getS3()->headObject($params);
        // Bounded preview; the download streams the complete object.
        $limit=1048576;$size=(int)$head['ContentLength'];
        if($size>0){$params['Range']='bytes=0-'.min($size-1,$limit-1);if(!empty($head['ETag']))$params['IfMatch']=$head['ETag'];
            $object=Config::getS3()->getObject($params);$body=$object['Body'];
            if(is_string($body))$text=substr($body,0,$limit);
            else{ $text='';try{while(!$body->eof()&&strlen($text)<$limit){$part=$body->read(min(65536,$limit-strlen($text)));if($part==='')break;$text.=$part;}}finally{$body->close();} }
        }else $text='';
        // Permission may have changed while S3 was responding.
        $catalog->requireFile($user,$sid,$scope,$file['id']);
        $response=['ok'=>true,'name'=>$file['name'],'text'=>mb_convert_encoding($text,'UTF-8','UTF-8'),'partial'=>$size>$limit];
    }
    header('Content-Type: application/json; charset=utf-8');echo json_encode($response,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    error_log('conversation_data: '.$e->getMessage());http_response_code($e instanceof InvalidArgumentException?400:403);
    header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>'No se pudo acceder a los datos. Comprueba la conversación y los permisos.']);
}
