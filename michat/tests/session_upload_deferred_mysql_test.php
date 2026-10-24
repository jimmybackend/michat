<?php
declare(strict_types=1);
$host=getenv('DOCUMENT_TEST_DB_HOST');
if(!$host){echo "SKIP deferred HTTP upload: isolated DB missing\n";exit;}
if(!in_array($host,['127.0.0.1','localhost','::1'],true))throw new RuntimeException('Loopback DB only');
if(PHP_SAPI==='cli-server') {
    define('APP_BOOTSTRAP_LOADED',true);
    $db_connection=new mysqli($host,getenv('DOCUMENT_TEST_DB_USER')?:'root',getenv('DOCUMENT_TEST_DB_PASSWORD')?:'',getenv('DOCUMENT_TEST_DB_NAME')?:'mysql',(int)(getenv('DOCUMENT_TEST_DB_PORT')?:3306));
    $db_connection->set_charset('utf8mb4');$schema=file_get_contents(dirname(__DIR__,2).'/adbbmis1_Cloud.sql');
    foreach(['FileS3','S3Folders','ChatSessions','SessionContextBlocks','EmbeddingJobs'] as $name) {
        preg_match('/CREATE TABLE IF NOT EXISTS `'.$name.'` \(.*?\) ENGINE=.*?;/s',$schema,$m);
        $db_connection->query(str_replace(['CREATE TABLE IF NOT EXISTS','utf8mb4_0900_ai_ci','VIRTUAL NOT NULL'],['CREATE TEMPORARY TABLE','utf8mb4_unicode_ci','VIRTUAL'],$m[0]));
    }
    $db_connection->query("INSERT INTO ChatSessions(id_,user_id_,title,model_id,status,meta) VALUES(1,1,'Upload','fixture','open','{}')");
    final class Config {
        public const RUTA_RAIZ='Data/';
        public static array $calls=[];
        public static function getBucket():string{return 'private-test-bucket';}
        public static function getS3():object{return new class {
            public function putObject(array $p):array {Config::$calls[]=['put',$p['Key'],isset($p['SourceFile'])];return [];}
            public function __call(string $method,array $args):mixed {Config::$calls[]=[$method];throw new RuntimeException('Upload must not read/analyze content');}
        };}
        public static function getBedrockRuntime(array $options=[]):object{self::$calls[]=['model'];throw new RuntimeException('Upload must not invoke a model');}
    }
    session_start();$_SESSION=['user_id_'=>(int)($_SERVER['HTTP_X_FIXTURE_USER']??1)];
    register_shutdown_function(static function()use($db_connection){
        $row=$db_connection->query('SELECT Nombre,Metadatos FROM FileS3 ORDER BY id_ DESC LIMIT 1')->fetch_assoc();
        $meta=json_decode((string)$db_connection->query('SELECT meta FROM ChatSessions WHERE id_=1')->fetch_assoc()['meta'],true);
        $state=['calls'=>Config::$calls,'file'=>$row,'meta'=>$meta,
            'blocks'=>(int)$db_connection->query('SELECT COUNT(*) n FROM SessionContextBlocks')->fetch_assoc()['n'],
            'jobs'=>(int)$db_connection->query('SELECT COUNT(*) n FROM EmbeddingJobs')->fetch_assoc()['n']];
        file_put_contents(getenv('DEFERRED_UPLOAD_LOG'),json_encode($state)."\n",FILE_APPEND);
    });
    require dirname(__DIR__).'/session_upload.php';exit;
}
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException($error);
$address=stream_socket_get_name($socket,false);fclose($socket);
$log=tempnam(sys_get_temp_dir(),'deferred-upload-');$serverLog=tempnam(sys_get_temp_dir(),'deferred-http-');putenv('DEFERRED_UPLOAD_LOG='.$log);
$process=proc_open([PHP_BINARY,'-S',$address,__FILE__],[0=>['pipe','r'],1=>['file',$serverLog,'a'],2=>['file',$serverLog,'a']],$pipes,dirname(__DIR__,2));
if(!is_resource($process))throw new RuntimeException('Cannot start HTTP fixture');
$n=0;$check=static function(bool $ok,string $label)use(&$n){$n++;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";};
$request=static function(int $session,int $user=1)use($address):array {
    $boundary='michat-fixture-'.bin2hex(random_bytes(8));
    $content="<?php\nthrow new RuntimeException('SECRET_CONTENT_MUST_NOT_BE_ANALYZED');\n";
    $body='--'.$boundary."\r\nContent-Disposition: form-data; name=\"session_id\"\r\n\r\n".$session."\r\n--".$boundary."\r\nContent-Disposition: form-data; name=\"files[]\"; filename=\"direct.php\"\r\nContent-Type: application/x-php\r\n\r\n".$content."\r\n--".$boundary."--\r\n";
    $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: multipart/form-data; boundary=$boundary\r\nX-Fixture-User: $user",'content'=>$body,'ignore_errors'=>true,'timeout'=>15]]);
    $response=file_get_contents('http://'.$address.'/session_upload.php',false,$ctx);preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$m);
    return [(int)($m[1]??0),json_decode($response,true)];
};
try {
    for($i=0;$i<50;$i++){if($s=@stream_socket_client('tcp://'.$address,$e,$err,0.1)){fclose($s);break;}usleep(100000);}
    [$code,$data]=$request(1);$check($code===200&&!empty($data['success']),'Real multipart upload endpoint accepts an authorized PHP attachment');
    $check(($data['uploaded'][0]['knowledge_status']??'')==='awaiting_request'&&!empty($data['uploaded'][0]['knowledge']['deferred']),'Upload response declares deferred processing');
    $states=array_map(static fn($line)=>json_decode($line,true),file($log,FILE_IGNORE_NEW_LINES));$state=end($states);
    $check(count($state['calls'])===1&&$state['calls'][0][0]==='put'&&$state['calls'][0][2]===true,'Upload makes one storage PUT and no content reads or model calls');
    $check($state['blocks']===0&&$state['jobs']===0,'HTTP upload persists no extracted blocks or embedding jobs');
    $check($state['file']['Nombre']==='direct.php'&&!str_contains($state['file']['Metadatos'],'SECRET_CONTENT'),'FileS3 retains general metadata without source content');
    $check(($state['meta']['attachment_processing_policy']??'')==='on_request','Uploaded chat adopts the request-only processing policy');
    [$code]=$request(1,2);$check($code===403,'Other users cannot upload into the conversation');
    $states=array_map(static fn($line)=>json_decode($line,true),file($log,FILE_IGNORE_NEW_LINES));$state=end($states);
    $check($state['calls']===[],'Rejected upload never touches S3 or models');
    echo "OK $n deferred HTTP upload checks\n";
} finally {proc_terminate($process);proc_close($process);unlink($log);unlink($serverLog);}
