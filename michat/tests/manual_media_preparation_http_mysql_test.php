<?php
declare(strict_types=1);
$host=getenv('DOCUMENT_TEST_DB_HOST');
if(!$host){echo "SKIP manual media HTTP: isolated DB missing\n";exit;}
if(!in_array($host,['127.0.0.1','localhost','::1'],true))throw new RuntimeException('Loopback DB only');
if(PHP_SAPI==='cli-server') {
    // The built-in server does not prepend its router script automatically.
    $prepend=(string)ini_get('auto_prepend_file');if($prepend!=='')require_once $prepend;
    // Real isolated temporary tables and revision-aware S3 fixture, no live resources.
    ob_start();require __DIR__.'/drive_chat_working_copy_mysql_test.php';ob_end_clean();
    $db_connection=$db;
    if(($_SERVER['HTTP_X_SCHEMA']??'ready')==='ready')foreach(explode(';',file_get_contents(dirname(__DIR__).'/sql/fase14_attachment_processing.sql')) as $sql)if(trim($sql)!=='')$db->query(str_replace('CREATE TABLE ','CREATE TEMPORARY TABLE ',$sql));
    $bytes=require __DIR__.'/fixtures/silent_mp3.php';$key='Data/Chat/Uploads/1/2026/10/24/1/legal.mp3';
    $db->query("INSERT INTO FileS3(id_,user_id_,Nombre,Ruta,Encriptado,Tamano,Found,Metadatos) VALUES(9001,1,'legal actual.mp3','Data/Chat/Uploads/1/2026/10/24/1/','legal.mp3',".strlen($bytes).",1,'{}')");
    Config::$s3->objects[$key]=['bytes'=>$bytes,'etag'=>'"audio-v1"'];
    session_start();$_SESSION=['user_id_'=>(int)($_SERVER['HTTP_X_FIXTURE_USER']??1),'document_link_csrf'=>'fixture-token'];
    register_shutdown_function(static function()use($key){
        file_put_contents(getenv('MEDIA_HTTP_LOG'),json_encode(['source_reads'=>count(array_filter(Config::$s3->gets,static fn($k)=>$k===$key)),'models'=>Config::$modelCalls])."\n",FILE_APPEND);
    });
    $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    if(!in_array($path,['/index_session_file.php','/semantic_session_file.php','/drive_file_analysis.php','/attachment_processing.php'],true)){http_response_code(404);exit;}
    require dirname(__DIR__).$path;exit;
}
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException($error);$address=stream_socket_get_name($socket,false);fclose($socket);
$log=tempnam(sys_get_temp_dir(),'media-http-');$serverLog=tempnam(sys_get_temp_dir(),'media-server-');putenv('MEDIA_HTTP_LOG='.$log);
$process=proc_open([PHP_BINARY,'-d','auto_prepend_file='.(string)ini_get('auto_prepend_file'),'-S',$address,__FILE__],[0=>['pipe','r'],1=>['file',$serverLog,'a'],2=>['file',$serverLog,'a']],$pipes,dirname(__DIR__,2));if(!is_resource($process))throw new RuntimeException('Cannot start HTTP fixture');
$count=0;$check=static function(bool $ok,string $label)use(&$count){$count++;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";};
$request=static function(string $path,int $user=1,string $schema='ready')use($address){$body=http_build_query(['file_id'=>9001,'session_id'=>1,'csrf'=>'fixture-token','action'=>'prepare','query'=>'Transcribe el archivo "legal actual.mp3"']);$ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/x-www-form-urlencoded\r\nX-Fixture-User: $user\r\nX-Schema: $schema",'content'=>$body,'ignore_errors'=>true,'timeout'=>20]]);$raw=file_get_contents('http://'.$address.'/'.$path,false,$ctx);preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$m);return [(int)($m[1]??0),json_decode($raw,true),$raw];};
try{
    for($i=0;$i<50;$i++){if($s=@stream_socket_client('tcp://'.$address,$e,$err,0.1)){fclose($s);break;}usleep(100000);}
    foreach(['index_session_file.php','semantic_session_file.php','drive_file_analysis.php'] as $endpoint){
        [$code,$data,$raw]=$request($endpoint);$check($code===200&&!empty($data['ok'])&&!empty($data['queued'])&&($data['processing_jobs'][0]['phase']??'')==='extract',$endpoint.' queues MP3 through the multimodal pipeline instead of the document reader: '.($data?'':$raw));
        $states=array_map(static fn($s)=>json_decode($s,true),file($log,FILE_IGNORE_NEW_LINES));$state=end($states);$check($state['source_reads']===0&&$state['models']===0,$endpoint.' does not read audio as text or invoke a model before polling');
        [$code,$data]=$request($endpoint,2);$check($code>=400&&empty($data['ok']),$endpoint.' rejects a foreign conversation/file before processing');
    }
    [$code,$data]=$request('attachment_processing.php');$check($code===200&&($data['jobs'][0]['phase']??'')==='extract','Question preflight identifies the named MP3 from live metadata');
    [$code,$data]=$request('attachment_processing.php',1,'missing');$check($code===200&&!empty($data['ok'])&&($data['jobs'][0]['phase']??'')==='extract','Missing processing tables are created automatically and the MP3 request is queued');
    echo "OK $count manual media HTTP checks\n";
}finally{proc_terminate($process);proc_close($process);unlink($log);unlink($serverLog);}
