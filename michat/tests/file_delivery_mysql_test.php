<?php
declare(strict_types=1);
$host=getenv('DOCUMENT_TEST_DB_HOST');
if (!$host) {echo "SKIP HTTP file delivery: DOCUMENT_TEST_DB_HOST missing\n";exit;}
if (!in_array($host,['127.0.0.1','localhost','::1'],true)) throw new RuntimeException('Isolated loopback DB only');

// The local HTTP fixture executes the real endpoints, with real temporary SQL
// tables and a deterministic S3 double. Nothing is written to a real bucket.
if (PHP_SAPI==='cli-server') {
    define('APP_BOOTSTRAP_LOADED',true);
    $db_connection=new mysqli($host,getenv('DOCUMENT_TEST_DB_USER')?:'root',getenv('DOCUMENT_TEST_DB_PASSWORD')?:'',getenv('DOCUMENT_TEST_DB_NAME')?:'mysql',(int)(getenv('DOCUMENT_TEST_DB_PORT')?:3306));
    $db_connection->set_charset('utf8mb4');
    $schema=file_get_contents(dirname(__DIR__,2).'/adbbmis1_Cloud.sql');
    foreach (['FileS3','ChatMessages','ChatSessions'] as $name) {
        preg_match('/CREATE TABLE IF NOT EXISTS `'.$name.'` \(.*?\) ENGINE=.*?;/s',$schema,$m);
        $db_connection->query(str_replace(['CREATE TABLE IF NOT EXISTS','utf8mb4_0900_ai_ci'],['CREATE TEMPORARY TABLE','utf8mb4_unicode_ci'],$m[0]));
    }
    $db_connection->query("INSERT INTO FileS3(id_,user_id_,Nombre,Ruta,Encriptado,Tamano,Found,AccessType,PasswordHash) VALUES
     (1,1,'CONVENIO DE CONFIDENCIALIDAD.pdf','Data/Docs/legal/Machotes/','CONVENIO DE CONFIDENCIALIDAD.pdf',42,1,'normal',NULL),
     (2,1,'Renombrado.pdf','Data/','Data/full.pdf',42,1,'normal',NULL),
     (3,2,'Privado.pdf','Other/','full.pdf',42,1,'normal',NULL),
     (4,1,'Eliminado.pdf','Data/','deleted.pdf',42,0,'normal',NULL),
     (5,1,'Protegido.pdf','Data/','locked.pdf',42,1,'secure','hash'),
     (6,1,'Con clave.pdf','Data/','hash.pdf',42,1,'normal','hash')");
    $db_connection->query("INSERT INTO ChatSessions(id_,user_id_,title,model_id,status) VALUES (1,1,'Own','fixture','open'),(2,2,'Other','fixture','open'),(3,1,'Closed','fixture','closed')");
    $db_connection->query("INSERT INTO ChatMessages(session_id_,user_id_,role,content,s3_key,thumb_s3_key) VALUES (1,1,'assistant','','Media/generated.png','Media/thumb.png'),(2,2,'assistant','','Media/private.png',NULL),(3,1,'assistant','','Media/closed.png',NULL),(1,1,'user','','Data/deleted.pdf',NULL),(1,1,'user','','Other/full.pdf',NULL)");
    final class Config {
        public const BUCKET='yours3bucket';
        public static function getBucket():string{return (string)getenv('FILE_DELIVERY_TEST_BUCKET');}
        public static function getS3():object {
            return new class {
                public function headObject(array $p):array {
                    $this->record('head',$p);
                    if (($_GET['failure']??'')==='s3') throw new RuntimeException('AccessDenied https://private-bucket.secret/credentials');
                    return ['ContentLength'=>strlen("%PDF-1.4\nfixture bytes\x00\xff"),'ContentType'=>'application/pdf','ETag'=>'"revision-1"'];
                }
                public function getObject(array $p):array {
                    $this->record('get',$p);$bytes="%PDF-1.4\nfixture bytes\x00\xff";
                    if(isset($p['Range'])){preg_match('/bytes=(\d+)-(\d+)/',$p['Range'],$m);$bytes=substr($bytes,(int)$m[1],(int)$m[2]-(int)$m[1]+1);}
                    return ['Body'=>new class($bytes) {
                        private int $pos=0;
                        public function __construct(private string $bytes){}
                        public function eof():bool{return $this->pos>=strlen($this->bytes);}
                        public function read(int $n):string{$s=substr($this->bytes,$this->pos,min($n,4));$this->pos+=strlen($s);return $s;}
                        public function close():void{}
                    }];
                }
                private function record(string $op,array $p):void {file_put_contents(getenv('FILE_DELIVERY_TEST_LOG'),json_encode([$op,$p])."\n",FILE_APPEND);}
            };
        }
    }
    session_start(); $_SESSION=[];
    if ((int)($_SERVER['HTTP_X_FIXTURE_USER']??0)>0) $_SESSION['user_id_']=(int)$_SERVER['HTTP_X_FIXTURE_USER'];
    if (isset($_SERVER['HTTP_X_FIXTURE_UNLOCK'])) $_SESSION['secure_ok_files']=['5'=>time()+60];
    if (($_GET['failure']??'')==='config') putenv('FILE_DELIVERY_TEST_BUCKET=yours3bucket');
    $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    if (!in_array($path,['/ver_pdf.php','/descargar.php'],true)) {http_response_code(404);exit;}
    require dirname(__DIR__).$path;
    exit;
}

$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException($error);
$address=stream_socket_get_name($socket,false);fclose($socket);
$log=tempnam(sys_get_temp_dir(),'delivery-s3-');$serverLog=tempnam(sys_get_temp_dir(),'delivery-http-');
putenv('FILE_DELIVERY_TEST_LOG='.$log);putenv('FILE_DELIVERY_TEST_BUCKET=runtime-configured-bucket');
$process=proc_open([PHP_BINARY,'-S',$address,__FILE__],[0=>['pipe','r'],1=>['file',$serverLog,'a'],2=>['file',$serverLog,'a']],$pipes,dirname(__DIR__,2));
if(!is_resource($process))throw new RuntimeException('Cannot start local HTTP fixture');
$n=0;
$check=static function(bool $ok,string $label)use(&$n){if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";};
$request=static function(string $path,string $key,int $user=1,array $extra=[],string $method='GET')use($address):array{
    $headers=array_merge(['X-Fixture-User: '.$user],$extra);
    $ctx=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'ignore_errors'=>true,'timeout'=>10]]);
    $body=file_get_contents('http://'.$address.$path.(str_contains($path,'?')?'&':'?').'archivo='.rawurlencode($key),false,$ctx);
    $lines=$http_response_header??[];preg_match('/\s(\d{3})\s/',$lines[0]??'',$m);
    $out=[];foreach(array_slice($lines,1) as $line){$parts=explode(':',$line,2);if(count($parts)===2)$out[strtolower($parts[0])]=trim($parts[1]);}
    return [(int)($m[1]??0),$out,$body];
};
try {
    for($i=0;$i<50;$i++){ $s=@stream_socket_client('tcp://'.$address,$e,$err,0.1);if($s){fclose($s);break;}usleep(100000); }
    $key='Data/Docs/legal/Machotes/CONVENIO DE CONFIDENCIALIDAD.pdf';$bytes="%PDF-1.4\nfixture bytes\x00\xff";
    foreach(['/ver_pdf.php','/descargar.php'] as $endpoint){
        [$code,$h,$body]=$request($endpoint,$key);
        $check($code===200&&$body===$bytes,$endpoint.' full binary bytes preserved');
        $check(($h['cache-control']??'')==='private, no-store',$endpoint.' private response');
        $check(str_starts_with($h['content-disposition']??'',$endpoint==='/ver_pdf.php'?'inline;':'attachment;'),'correct inline/download disposition');
        $check((int)($h['content-length']??0)===strlen($bytes),'accurate full content length');
        [$code,$h,$body]=$request($endpoint,$key,1,['Range: bytes=5-10']);
        $check($code===206&&$body===substr($bytes,5,6)&&($h['content-range']??'')==='bytes 5-10/'.strlen($bytes),'real partial response');
        [$code,$h,$body]=$request($endpoint,$key,1,[],'HEAD');
        $check($code===200&&$body===''&&(int)$h['content-length']===strlen($bytes),'HEAD returns headers without bytes');
    }
    $calls=array_map(fn($l)=>json_decode($l,true),file($log,FILE_IGNORE_NEW_LINES));
    $check(count($calls)===10,'HEAD does not download an S3 object');
    $check(count(array_filter($calls,fn($x)=>$x[1]['Bucket']!=='runtime-configured-bucket'))===0,'both endpoints use runtime bucket, never placeholder');
    $check(count(array_filter($calls,fn($x)=>$x[0]==='get'&&($x[1]['IfMatch']??'')!=='"revision-1"'))===0,'GET bound to inspected object revision');
    [$code,$h,$body]=$request('/descargar.php','Data/full.pdf');
    $check($code===200&&str_contains($h['content-disposition'],'Renombrado.pdf'),'full Encriptado key and visible database name');
    foreach([['/ver_pdf.php',$key,0,401],['/descargar.php',$key,2,404],['/descargar.php','Other/full.pdf',1,404],['/descargar.php','Data/deleted.pdf',1,404],['/ver_pdf.php','Data/locked.pdf',1,423],['/descargar.php','Data/hash.pdf',1,423],['/descargar.php','Data/unknown.pdf',1,404],['/descargar.php','Media/private.png',1,404],['/descargar.php','Media/closed.png',1,404],['/descargar.php','Data/',1,400]] as [$ep,$k,$u,$expected]){
        $before=filesize($log);[$code]=$request($ep,$k,$u);clearstatcache();
        $check($code===$expected&&filesize($log)===$before,'deny before S3 '.$k.' user '.$u.' status '.$expected);
    }
    [$code]=$request('/ver_pdf.php','Data/locked.pdf',1,['X-Fixture-Unlock: yes']);$check($code===200,'Drive unlock session respected');
    foreach(['Media/generated.png','Media/thumb.png'] as $k){[$code]=$request('/descargar.php',$k);$check($code===200,'own generated media remains downloadable '.$k);}
    [$code,$h,$body]=$request('/ver_pdf.php',$key,1,['Range: bytes=9999-']);$check($code===416&&$h['content-range']==='bytes */'.strlen($bytes),'unsatisfiable range is explicit');
    [$code,$h,$body]=$request('/ver_pdf.php',$key,1,['Range: bytes=1-3','If-Range: "old-revision"']);$check($code===200&&$body===$bytes,'stale If-Range receives complete current object');
    [$code,$h,$body]=$request('/ver_pdf.php?failure=s3',$key);$check($code===502&&!str_contains($body,'private-bucket')&&!str_contains($body,'credentials'),'AWS error details never reach browser');
    [$code,$h,$body]=$request('/descargar.php?failure=config',$key);$check($code===503&&str_contains($body,'AWS_S3_BUCKET'),'missing bucket produces actionable configuration error');
    [$code,$h,$body]=$request('/descargar.php',$key,0,['X-Requested-With: XMLHttpRequest']);$check($code===401&&(json_decode($body,true)['ok']??null)===false,'AJAX errors retain JSON contract');
    echo "OK $n HTTP/SQL file delivery checks\n";
} finally {
    proc_terminate($process);proc_close($process);unlink($log);unlink($serverLog);
}
