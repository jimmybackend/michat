<?php
declare(strict_types=1);
if(session_status()===PHP_SESSION_NONE)session_start();
try{
    require_once __DIR__.'/app_bootstrap.php';
    require_once __DIR__.'/includes/Chat/ChatIdentity.php';
    require_once __DIR__.'/includes/Drive/DerivedFileContent.php';
    require_once __DIR__.'/includes/Memory/DocumentAccessRepository.php';
    $user=ChatIdentity::resolveUserId($db_connection);$session=(int)($_GET['session_id']??0);$id=(int)($_GET['file_id']??0);
    $access=new DocumentAccessRepository($db_connection);$file=$access->requireFile($user,$session,$id);$original=$access->original($file)?:$file;
    $s3=Config::getS3();$store=new AnalysisSidecarStore($s3,Config::getBucket(),$db_connection);$source=$store->identity($original);
    $ref=(json_decode((string)$file['Metadatos'],true)?:[])['drive_source']??null;
    if($ref&&($source['etag']!==($ref['etag']??'')||$store->identity($file)['etag']!==($ref['copy_etag']??'')))throw new RuntimeException('Revisión modificada');
    $doc=(new DerivedFileContent($db_connection,$s3,Config::getBucket()))->read($original,$source);
    if(!$doc)throw new RuntimeException('Aún no hay información derivada de esta revisión');
    $access->requireFile($user,$session,$id);
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
    header("Content-Disposition: attachment; filename=\"derived-$id.json\"");
    echo json_encode($doc,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
}catch(Throwable $e){http_response_code(403);header('Content-Type: application/json');echo json_encode(['ok'=>false,'error'=>'No hay información derivada accesible para esta revisión.']);}
