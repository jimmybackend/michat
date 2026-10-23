<?php
declare(strict_types=1);
/** Uses connection-local TEMPORARY copies of canonical tables; never changes persisted tables. */
$host=getenv('DOCUMENT_TEST_DB_HOST');
if (!$host) { echo "SKIP document evidence MariaDB: set DOCUMENT_TEST_DB_HOST to loopback\n"; exit(0); }
if (!in_array($host,['127.0.0.1','localhost','::1'],true)) throw new RuntimeException('Test only permits local isolated database');
require_once dirname(__DIR__).'/includes/ai_agent_runtime.php';
require_once dirname(__DIR__).'/includes/Memory/ContextItem.php';
require_once dirname(__DIR__).'/includes/Memory/AttachmentContextRepository.php';
$db=new mysqli($host,getenv('DOCUMENT_TEST_DB_USER')?:'root',getenv('DOCUMENT_TEST_DB_PASSWORD')?:'',getenv('DOCUMENT_TEST_DB_NAME')?:'mysql',(int)(getenv('DOCUMENT_TEST_DB_PORT')?:3306));
$db->set_charset('utf8mb4');
$schema=file_get_contents(dirname(__DIR__,2).'/adbbmis1_Cloud.sql');
foreach (['FileS3','ChatSessions','SessionContextBlocks','EmbeddingJobs','TokenUsage'] as $name) {
    if (!preg_match('/CREATE TABLE IF NOT EXISTS `'.preg_quote($name,'/').'` \(.*?\) ENGINE=.*?;/s',$schema,$m)) throw new RuntimeException('Canonical schema missing '.$name);
    $sql=str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE',$m[0]);
    $sql=str_replace('utf8mb4_0900_ai_ci','utf8mb4_unicode_ci',$sql);
    $db->query($sql);
}
function docCheck(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$GLOBALS['AI_AGENT_CONFIGS']=['embedding_main'=>['is_active'=>0,'model_id'=>'test-model','_extra'=>['attachment_rag_top'=>8]]];
$db->query("INSERT INTO ChatSessions(id_,user_id_,title,model_id,meta) VALUES (1,1,'Test','test','{}'),(2,2,'Other','test','{}')");
$db->query("INSERT INTO FileS3(id_,Nombre,Encriptado,Tamano,Ruta,Found,user_id_) VALUES (1,'contrato.php','original',10,'Data/Chat/Uploads/1/2026/10/23/1/',1,1),(2,'privado.txt','private',10,'Data2/',1,2),(3,'drive.txt','drive',10,'Data/',1,1)");
$insert=$db->prepare("INSERT INTO SessionContextBlocks(session_id_,block_type,content_preview,s3_path,source_ids,embedding_json,embedding_model) VALUES (1,'file_chunk',?,?,?,?,?)");
$path='Data/Chat/Uploads/1/2026/10/23/1/original';
for ($i=1;$i<=240;$i++) {
    $text=$i===240?'Importe exacto 41837 función impuesto':'Relleno sin información buscada';
    $meta=json_encode(['filename'=>'contrato.php','files3_id'=>1,'chunk'=>$i,'locator'=>['line_start'=>$i,'line_end'=>$i]]);
    $vector=null;$model=null;$insert->bind_param('sssss',$text,$path,$meta,$vector,$model);$insert->execute();
}
$repo=new AttachmentContextRepository($db,null);
$r=$repo->retrieve(1,1,'41837','rag',null,null);
docCheck(count($r['items'])===1&&str_contains($r['context'],'41837'),'Finds exact evidence after block 200 without embeddings');
docCheck(str_contains($r['context'],'líneas 240–240'),'Cites original source line 240');
docCheck($r['telemetry']['scan_complete'],'Scans complete index with keyset batches');
docCheck(!$repo->retrieve(2,1,'41837','always',null,null)['items'],'Other user cannot retrieve session');
$meta=json_encode(['filename'=>'privado.txt','files3_id'=>2,'chunk'=>1]);$text='SECRETO 41837';$path='Data2/private';$vector=null;$model=null;
$insert->bind_param('sssss',$text,$path,$meta,$vector,$model);$insert->execute();
docCheck(!str_contains($repo->retrieve(1,1,'41837','always',null,null)['context'],'SECRETO'),'Malicious cross-owner block rejected');
$db->query("UPDATE FileS3 SET AccessType='secure' WHERE id_=1");
docCheck(!$repo->retrieve(1,1,'41837','rag',null,null)['items'],'Locking source immediately invalidates cached evidence');
$db->query("UPDATE FileS3 SET AccessType='normal' WHERE id_=1");
$db->query("UPDATE FileS3 SET Found=0 WHERE id_=1");
docCheck(!$repo->retrieve(1,1,'41837','rag',null,null)['items'],'Deleted source cannot be recovered from cached chunks');
$db->query("UPDATE FileS3 SET Found=1 WHERE id_=1");
$meta=json_encode(['filename'=>'drive.txt','files3_id'=>3,'chunk'=>1]);$text='Drive importe 99331';$path='Data/drive';
$insert->bind_param('sssss',$text,$path,$meta,$vector,$model);$insert->execute();
docCheck(!$repo->retrieve(1,1,'99331','rag',null,null)['items'],'Unlinked owned Drive file excluded');
$db->query("UPDATE ChatSessions SET meta='{\"knowledge_file_ids\":[3]}' WHERE id_=1");
docCheck(str_contains($repo->retrieve(1,1,'99331','rag',null,null)['context'],'99331'),'Linked Drive file uses existing S3 key');
$db->query("UPDATE ChatSessions SET meta='{}' WHERE id_=1");
docCheck(!$repo->retrieve(1,1,'99331','rag',null,null)['items'],'Revoking Drive link invalidates retrieval immediately');
$GLOBALS['AI_AGENT_CONFIGS']['embedding_main']['is_active']=1;
$db->query("UPDATE SessionContextBlocks SET embedding_json='[1,0]',embedding_model='test-model' WHERE id_=240");
docCheck(str_contains($repo->retrieve(1,1,'Unrelated semantic query','rag',[1,0],null)['context'],'41837'),'Semantic branch retrieves valid current-model vectors');
$db->query("UPDATE SessionContextBlocks SET embedding_model='old-model' WHERE id_=240");
docCheck(!$repo->retrieve(1,1,'Unrelated semantic query','rag',[1,0],null)['items'],'Wrong model vectors are excluded');
$insert->close();
// Exercise actual index persistence and queue transitions with a deterministic Bedrock double.
// APP_BOOTSTRAP_LOADED prevents external production configuration; mysqli is real.
define('APP_BOOTSTRAP_LOADED',true);
require_once dirname(__DIR__).'/includes/SessionAttachmentKnowledgeService.php';
$GLOBALS['AI_AGENT_CONFIGS']=['embedding_main'=>['is_active'=>1,'model_id'=>'amazon.titan-embed-text-v2:0','_extra'=>['dimensions'=>256,'attachment_immediate_blocks'=>1]]];
$service=new SessionAttachmentKnowledgeService($db);
$property=new ReflectionProperty($service,'bedrock');
$property->setValue($service,new class {
    public bool $fail=false;
    public function invokeModel(array $request): array {
        if ($this->fail) throw new RuntimeException('Simulated Bedrock outage');
        return ['body'=>json_encode(['embedding'=>array_fill(0,256,0.125),'inputTextTokenCount'=>10])];
    }
});
$method=new ReflectionMethod($service,'indexPrepared');
$file=['Nombre'=>'contrato.php','Ruta'=>'Data/Chat/Uploads/1/2026/10/23/1/','Encriptado'=>'original'];
$extracted=['content'=>"<?php\nfunction impuesto() { return 41837; }",'ext'=>'php','extractor'=>'raw_text','truncated'=>false,'source_sha256'=>hash('sha256','fixture')];
$result=$method->invoke($service,1,1,$file,$extracted);
docCheck($result['embedding_ready']===true&&$result['embedding_ready_count']===1,'Actual index service persists successful embedding');
docCheck((int)$db->query("SELECT COUNT(*) AS n FROM EmbeddingJobs WHERE status='completed'")->fetch_assoc()['n']===1,'Successful embedding job becomes completed');
$property->getValue($service)->fail=true;
$result=$method->invoke($service,1,1,$file,$extracted);
docCheck(!$result['embedding_ready']&&$result['embedding_pending_count']===1,'Failed embedding never becomes ready');
docCheck((int)$db->query("SELECT COUNT(*) AS n FROM EmbeddingJobs WHERE status='pending'")->fetch_assoc()['n']===1,'Failed embedding remains retryable');
$index=$repo->retrieve(1,1,'41837','rag',null,null);
docCheck(str_contains($index['context'],'41837')&&str_contains($index['context'],'líneas 1–2'),'Actual extraction chunk index retrieval citation despite Bedrock failure');
// Exercise the real S3 extraction API with an isolated object-storage double.
class Config {
    public static string $fixture='';
    public static string $etag='"revision-1"';
    public static function getBucket(): string { return 'isolated-fixtures'; }
    public static function getS3(): object { return new class {
        public function headObject(array $request): array { return ['ContentLength'=>filesize(Config::$fixture),'ETag'=>Config::$etag]; }
        public function getObject(array $request): array {
            if ($request['IfMatch']!==Config::$etag) throw new RuntimeException('Object revision changed');
            copy(Config::$fixture,$request['SaveAs']);return ['ETag'=>Config::$etag];
        }
    }; }
}
$GLOBALS['db_connection']=$db;
Config::$fixture=tempnam(sys_get_temp_dir(),'doc_s3_');
try {
    file_put_contents(Config::$fixture,"<?php\nfunction impuesto() { return 99123; }");
    $extracted=idx_extract_files3_text($file);
    docCheck($extracted['source_sha256']===hash_file('sha256',Config::$fixture),'S3 extractor records original source hash');
    $method->invoke($service,1,1,$file,$extracted);
    docCheck(str_contains($repo->retrieve(1,1,'99123','rag',null,null)['context'],'99123'),'S3 API extraction to real MySQL chunks to cited retrieval');
    Config::$etag='"revision-2"';
    docCheck(!$repo->retrieve(1,1,'99123','rag',null,null)['items'],'Changed S3 object revision invalidates old knowledge');
} finally { @unlink(Config::$fixture); }
$property->getValue($service)->fail=false;
$extracted['content']=str_repeat("line containing a full chunk of code and numbers 41837\n",100);
$result=$method->invoke($service,1,1,$file,$extracted);
docCheck($result['chunks']>1&&$result['embedding_ready_count']===1&&$result['embedding_pending_count']>0,'Immediate embedding budget leaves all other chunks queued');
$db->close();
echo "Document evidence real MariaDB: 21 checks passed\n";
