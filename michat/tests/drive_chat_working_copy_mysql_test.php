<?php
declare(strict_types=1);
$host=getenv('DOCUMENT_TEST_DB_HOST');if(!$host){echo "SKIP Drive chat working copy: isolated DB missing\n";exit;}
if(!in_array($host,['127.0.0.1','localhost','::1'],true))throw new RuntimeException('Loopback test DB only');
define('APP_BOOTSTRAP_LOADED',true);
require_once __DIR__.'/fixtures/aws_result.php';
require_once dirname(__DIR__).'/includes/Drive/DriveChatAttachmentService.php';
require_once dirname(__DIR__).'/includes/Drive/DriveAnalysisService.php';
require_once dirname(__DIR__).'/includes/Memory/bootstrap.php';
require_once dirname(__DIR__).'/includes/Memory/AttachmentPromptGrounding.php';
$db=new mysqli($host,getenv('DOCUMENT_TEST_DB_USER')?:'root',getenv('DOCUMENT_TEST_DB_PASSWORD')?:'',getenv('DOCUMENT_TEST_DB_NAME')?:'mysql',(int)(getenv('DOCUMENT_TEST_DB_PORT')?:3306));$db->set_charset('utf8mb4');$GLOBALS['db_connection']=$db;
$schema=file_get_contents(dirname(__DIR__,2).'/adbbmis1_Cloud.sql');
foreach(['FileS3','ChatSessions','ChatMessages','SessionContextBlocks','EmbeddingJobs','UserAIAgentConfigs','Projects','ChatActivityEvents'] as $name){preg_match('/CREATE TABLE IF NOT EXISTS `'.$name.'` \(.*?\) ENGINE=.*?;/s',$schema,$m);$db->query(str_replace(['CREATE TABLE IF NOT EXISTS','utf8mb4_0900_ai_ci','VIRTUAL NOT NULL'],['CREATE TEMPORARY TABLE','utf8mb4_unicode_ci','VIRTUAL'],$m[0]));}
$db->query("INSERT INTO ChatSessions(id_,user_id_,title,model_id,meta) VALUES(1,1,'Current','fixture','{\"knowledge_file_ids\":[3823]}'),(2,1,'New','fixture','{}'),(3,2,'Private','fixture','{}')");
$db->query("INSERT INTO FileS3(id_,user_id_,Nombre,Ruta,Encriptado,Tamano,Found,Metadatos) VALUES(3823,1,'thankyou-old.php','Data/Jas/Amazon Linux/','thankyou-old.php',7000,1,'{\"custom\":\"keep\"}')");
final class WorkMissing extends RuntimeException {public function getAwsErrorCode():string{return 'NoSuchKey';}}
final class Config {public static int $modelCalls=0;public static function getBedrockRuntime(array $options=[]):object{self::$modelCalls++;throw new RuntimeException('No model expected for deterministic preparation');}public static object $s3;public static function getS3():object{return self::$s3;}public static function getBucket():string{return 'private-bucket';}}
$code="<?php\n".str_repeat("// Revisar bible_progress y extraer el siguiente versiculo; evidencia verificable 41837.\n",90);
$key='Data/Jas/Amazon Linux/thankyou-old.php';
Config::$s3=new class($key,$code){
    public array $objects=[],$copies=[],$gets=[],$puts=[],$heads=[];public bool $denyCopy=false;public $beforeRead=null;
    public function __construct(string $key,string $body){$this->objects[$key]=['bytes'=>$body,'etag'=>'"original-v1"'];}
    public function headObject(array $p):ArrayAccess{$this->heads[]=$p['Key'];$o=$this->objects[$p['Key']]??throw new WorkMissing();return documentSdkResult(['ETag'=>$o['etag'],'ContentLength'=>strlen($o['bytes']),'LastModified'=>new DateTimeImmutable('2026-10-20T00:00:00Z')]);}
    public function getObject(array $p):array{if(is_callable($this->beforeRead))($this->beforeRead)($p);$o=$this->objects[$p['Key']]??throw new WorkMissing();$this->gets[]=$p['Key'];if(isset($p['IfMatch'])&&$p['IfMatch']!==$o['etag'])throw new RuntimeException('Revision changed');if(isset($p['SaveAs']))file_put_contents($p['SaveAs'],$o['bytes']);return ['Body'=>$o['bytes']];}
    public function putObject(array $p):array{$old=$this->objects[$p['Key']]??null;if(isset($p['IfMatch'])&&($old['etag']??null)!==$p['IfMatch'])throw new RuntimeException('Write conflict');if(($p['IfNoneMatch']??'')==='*'&&$old)throw new RuntimeException('Already exists');$this->puts[]=$p['Key'];$this->objects[$p['Key']]=['bytes'=>$p['Body'],'etag'=>'"'.hash('sha256',$p['Body']).'"'];return [];}
    public function copyObject(array $p):array{if($this->denyCopy)throw new RuntimeException('Copy denied');$source=rawurldecode(substr($p['CopySource'],strlen('private-bucket/')));$o=$this->objects[$source]??throw new WorkMissing();if($p['CopySourceIfMatch']!==$o['etag'])throw new RuntimeException('Copy revision changed');$this->copies[]=$p;$this->objects[$p['Key']]=['bytes'=>$o['bytes'],'etag'=>'"copy-'.hash('sha256',$p['Key']).'"'];return [];}
};
$n=0;function workCheck(bool $ok,string $label):void{global $n;$n++;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$analysis=DriveAnalysisService::production($db);$prepared=$analysis->analyze(1,1,3823,false);
workCheck($prepared['ok']&&$prepared['chunks']>=4,'Original prepared with real literal chunks and no semantic model');
$attach=new DriveChatAttachmentService($db,Config::$s3,Config::getBucket());$file=$attach->attach(1,1,3823);$id=(int)$file['id_'];$copyKey=idx_build_s3_key($file);
workCheck($id!==3823&&str_starts_with($copyKey,'Data/Chat/Uploads/1/'.gmdate('Y/m/d').'/1/'),'Selection creates a FileS3 working attachment in the actual conversation path');
workCheck($file['Nombre']==='thankyou-old.php'&&Config::$s3->objects[$key]['bytes']===$code,'Visible filename retained and original bytes untouched');
workCheck(Config::$s3->copies[0]['CopySourceIfMatch']==='"original-v1"'&&str_contains(Config::$s3->copies[0]['CopySource'],'Amazon%20Linux'),'Server-side copy pins original revision and encodes source key');
$metadata=json_decode($file['Metadatos'],true);workCheck($metadata['drive_source']['file_id']===3823&&$metadata['drive_source']['key']===$key,'Real FileS3.Metadatos stores original identity and provenance');
$meta=json_decode($db->query('SELECT meta FROM ChatSessions WHERE id_=1')->fetch_assoc()['meta'],true);
workCheck($meta['knowledge_file_ids']===[$id],'Working copy replaces catalog link without duplicate original attachment');
$again=$attach->attach(1,1,3823);workCheck((int)$again['id_']===$id&&count(Config::$s3->copies)===1,'Repeated selection reuses same revision instead of duplicating copies');
$ready=$analysis->analyze(1,1,$id,false);
workCheck($ready['ok']&&$ready['reused']&&$ready['chunks']>=4,'Actual working-copy analysis reuses original cached chunks');
workCheck(!in_array($copyKey,Config::$s3->gets,true),'Cache reuse needs no download or fresh model call for the working copy');
$rows=$db->query("SELECT source_ids FROM SessionContextBlocks WHERE s3_path='".$db->real_escape_string($copyKey)."' AND block_type='file_chunk'")->fetch_all(MYSQLI_ASSOC);
workCheck((json_decode($rows[0]['source_ids'],true)['files3_id']??0)===$id&&(json_decode($rows[0]['source_ids'],true)['source_etag']??'')===$metadata['drive_source']['copy_etag'],'Chunks are rebound to exact working file ID and its distinct ETag');
$db->query("INSERT INTO UserAIAgentConfigs(scope,agent_key,display_name,model_id,system_instruction,extra_config) VALUES('global','chat_main_attachment_template','Template','none','Customized template without placeholder','{}'),('global','context_ranker','Ranker','none','', '{\"bucket_thresholds\":{\"attachments\":1},\"global_optional_limit\":8}')");
require_once dirname(__DIR__).'/includes/ai_agent_runtime.php';aiRuntimeLoad($db,1);
$builder=new ContextBuilder($db,null);$features=['procedural_memory_read'=>false,'project_memory_read'=>false,'session_memory_read'=>false,'question_memory_read'=>false,'project_rag'=>false,'attachment_rag'=>true,'context_ranking'=>true];
$query='Analiza el archivo "thankyou-old.php" y cita sus fragmentos';
$bundle=$builder->build(new MemoryRoute(['intent'=>'code','use_attachment_context'=>false]),['stage'=>'respond','user_id'=>1,'session_id'=>1,'query_text'=>'Revisa el proyecto','original_query_text'=>$query,'pipeline_features'=>$features]);
workCheck(count($bundle->selectedItems('attachments'))>=4,'Explicit named code attachment survives router rewrite and strict ranker thresholds');
$system=AttachmentPromptGrounding::append('Custom chat_main template without context slots',$bundle);
workCheck(str_contains($system,'41837')&&str_contains($system,'thankyou-old.php')&&str_contains($system,'líneas'),'Final prompt contains actual attachment content and citations even when both custom templates omit placeholders');
workCheck(AttachmentPromptGrounding::named($bundle,$query),'Named attachment gets attachment grounding instead of missing-project denial');
$follow=$builder->build(new MemoryRoute(['intent'=>'code','use_attachment_context'=>false]),['stage'=>'respond','user_id'=>1,'session_id'=>1,'query_text'=>'Qué errores tiene y cómo lo mejoramos','pipeline_features'=>$features]);
workCheck(count($follow->selectedItems('attachments'))>=4&&AttachmentPromptGrounding::named($follow,'Qué errores tiene'),'Follow-up questions retain active working attachment without repeating filename');
$db->query("INSERT INTO ChatMessages(id_,session_id_,user_id_,role,content,model_id) VALUES(10,1,1,'user','Explica 41837','fixture'),(11,1,1,'assistant','El código contiene 41837; véanse las líneas citadas.','fixture')");
workCheck($analysis->recordTurn(1,1,10,11,$bundle)===[],'Actual chat interaction is recorded after working-copy retrieval');
$sidecarKey=$key.'.michat-u1.analysis.v1.json';$doc=json_decode(Config::$s3->objects[$sidecarKey]['bytes'],true);$revision=array_values($doc['revisions'])[0];$turn=$revision['interactions']['1:10:11']??[];
workCheck(($turn['working_file_id']??0)===$id&&($turn['question']??'')==='Explica 41837','Original sibling JSON contains Q&A plus the working-copy identity');
workCheck(!array_filter(Config::$s3->puts,fn($p)=>str_starts_with($p,'Data/Chat/Uploads/')),'JSON history is written beside original, not scattered beside chat copies');
workCheck(json_decode($db->query('SELECT Metadatos FROM FileS3 WHERE id_=3823')->fetch_assoc()['Metadatos'],true)===['custom'=>'keep'],'Original FileS3 metadata stays unchanged');
$other=$attach->attach(1,2,3823);workCheck((int)$other['id_']!==$id&&idx_file_belongs_to_chat_session($other,1,2),'Another conversation gets its own isolated working copy');
$denied=false;try{(new DocumentAccessRepository($db))->requireFile(1,1,(int)$other['id_']);}catch(Throwable){$denied=true;}workCheck($denied,'Copy from another conversation cannot be read as an attachment here');
$denied=false;try{$attach->attach(2,3,3823);}catch(Throwable){$denied=true;}workCheck($denied,'Other users cannot copy the private original');
Config::$s3->objects[$key]['etag']='"original-v2"';
workCheck(!(new AttachmentContextRepository($db,null))->retrieve(1,1,'41837','always',null,null)['items'],'Changed original invalidates cached evidence even when working copy is unchanged');
Config::$s3->objects[$key]['etag']='"original-v1"';
$db->query("UPDATE FileS3 SET AccessType='secure' WHERE id_=3823");
workCheck((new DocumentAccessRepository($db))->file(1,$id)===null,'Revoking original access invalidates working-copy access');
$evidence=(new AttachmentContextRepository($db,null))->retrieve(1,1,'41837','always',null,null);workCheck($evidence['context']==='','Cached working-copy chunks do not bypass revoked access');
$db->query("UPDATE FileS3 SET AccessType='normal' WHERE id_=3823");require_once dirname(__DIR__).'/includes/Drive/MetadataSearchService.php';
$catalog=(new MetadataSearchService($db))->search(1,'thankyou-old.php',false);
workCheck(count(array_filter($catalog['files'],static fn($f)=>(int)$f['id_']===3823))===1&&!array_filter($catalog['files'],static fn($f)=>(json_decode($f['Metadatos'],true)['drive_source']['format']??'')==='michat.drive-working-copy'),'Catalog lists original once and hides working copies while exposing registered derivative JSON');
Config::$s3->denyCopy=true;
$db->query("INSERT INTO ChatSessions(id_,user_id_,title,model_id,meta) VALUES(4,1,'Copy failure','fixture','{}')");$count=(int)$db->query('SELECT COUNT(*) n FROM FileS3')->fetch_assoc()['n'];$denied=false;try{$attach->attach(1,4,3823);}catch(Throwable){$denied=true;}
workCheck($denied&&(int)$db->query('SELECT COUNT(*) n FROM FileS3')->fetch_assoc()['n']===$count,'Failed S3 copy cannot create a fake successful attachment row');
// Deferred selection: no downloads, chunks, summaries, queue or model calls.
Config::$s3->denyCopy=false;
$lazyCode="<?php\nclass LazyExample { public function run() { return 41837; } }\n// SECRET_UNUSED_CONTEXT\n".str_repeat("// Filler that must not enter the grep result.\n",120);
$lazyKey='Data/original/lazy.php';Config::$s3->objects[$lazyKey]=['bytes'=>$lazyCode,'etag'=>'"lazy-v1"'];
$db->query("INSERT INTO FileS3(id_,user_id_,Nombre,Ruta,Encriptado,Tamano,Found) VALUES(5000,1,'lazy.php','Data/original/','lazy.php',7000,1)");
$db->query("INSERT INTO ChatSessions(id_,user_id_,title,model_id,meta) VALUES(5,1,'Deferred','fixture','{}')");
$beforeGets=count(Config::$s3->gets);$beforePuts=count(Config::$s3->puts);
$lazy=$attach->attach(1,5,5000);$lazyId=(int)$lazy['id_'];$lazyCopy=idx_build_s3_key($lazy);
workCheck(count(Config::$s3->gets)===$beforeGets&&count(Config::$s3->puts)===$beforePuts,'Attach copies object server-side without reading content or creating a JSON analysis');
workCheck((int)$db->query('SELECT COUNT(*) n FROM SessionContextBlocks WHERE session_id_=5')->fetch_assoc()['n']===0,'Deferred attachment creates no content blocks');
$features=['procedural_memory_read'=>false,'project_memory_read'=>false,'session_memory_read'=>false,'question_memory_read'=>false,'project_rag'=>false,'attachment_rag'=>true,'context_ranking'=>true];
$requestNumber=0;$lastTrace='';
$build=static function(string $query)use($builder,$features,&$requestNumber,&$lastTrace){$lastTrace='attachment-test-'.str_pad((string)++$requestNumber,16,'0',STR_PAD_LEFT);return $builder->build(new MemoryRoute(['intent'=>'code','use_attachment_context'=>true]),['stage'=>'respond','user_id'=>1,'session_id'=>5,'query_text'=>$query,'pipeline_features'=>$features,'trace_id'=>$lastTrace]);};
$heads=count(Config::$s3->heads);$idle=$build('Hola, ¿cómo estás?');
workCheck(!$idle->selectedItems('attachments')&&count(Config::$s3->heads)===$heads&&Config::$modelCalls===0,'Unrelated message ignores passive attachments even when router requests them');
$metadata=$build('Cuál es el tamaño del archivo "lazy.php"');
workCheck(!$metadata->selectedItems('attachments')&&str_contains($metadata->block('attachment_request_block'),'lazy.php')&&count(Config::$s3->heads)===$heads,'Metadata-only question does not read S3 content');
$seenDuringRead=[];Config::$s3->beforeRead=static function(array $p)use($db,$lazyCopy,&$seenDuringRead){if($p['Key']===$lazyCopy)$seenDuringRead=array_column($db->query('SELECT event_key FROM ChatActivityEvents ORDER BY id_')->fetch_all(MYSQLI_ASSOC),'event_key');};
$literal=$build('Busca "41837" en el archivo "lazy.php"');
workCheck(($literal->telemetry()['attachment_request']['strategy']??'')==='literal'&&str_contains(AttachmentPromptGrounding::append('',$literal),'41837'),'Explicit literal request starts extraction and returns matching evidence');
$literalText=implode(' ',array_map(static fn($i)=>$i->content,$literal->selectedItems('attachments')));
workCheck(!str_contains($literalText,'Filler that must not enter')&&str_contains($literalText,'L2:'),'Grep narrows content and preserves original source line numbers');
workCheck(Config::$modelCalls===0&&(int)$db->query("SELECT COUNT(*) n FROM EmbeddingJobs ej JOIN SessionContextBlocks b ON b.id_=ej.target_id WHERE b.session_id_=5")->fetch_assoc()['n']===0,'Requested literal preparation creates neither model calls nor embedding jobs');
workCheck(isset(Config::$s3->objects[$lazyKey.'.michat-u1.analysis.v1.json']),'Requested extraction persists reusable JSON beside original');
$beforeCopyReads=count(array_filter(Config::$s3->gets,static fn($k)=>$k===$lazyCopy));
$structure=$build('Lista las clases y métodos del archivo "lazy.php"');$structureText=implode(' ',array_map(static fn($i)=>$i->content,$structure->selectedItems('attachments')));
workCheck(str_contains($structureText,'LazyExample')&&str_contains($structureText,'run')&&str_contains($structureText,'línea 2')&&!str_contains($structureText,'return 41837'),'PHP structure uses tokens and named declarations instead of method bodies');
workCheck(count(array_filter(Config::$s3->gets,static fn($k)=>$k===$lazyCopy))===$beforeCopyReads&&Config::$modelCalls===0,'Follow-up reuses extracted evidence without another original download or extra model');
$heads=count(Config::$s3->heads);$idle=$build('Qué hora es');workCheck(!$idle->selectedItems('attachments')&&count(Config::$s3->heads)===$heads,'Already analyzed attachments remain silent for unrelated messages');
$otherLazy=$attach->attach(1,5,3823);$heads=count(Config::$s3->heads);$ambiguous=$build('Resume el documento');
workCheck(!$ambiguous->selectedItems('attachments')&&str_contains(FileKnowledgeState::unavailableReply($ambiguous)??'','Indica el nombre')&&count(Config::$s3->heads)===$heads,'Ambiguous multi-file request asks which file without processing one arbitrarily');
$db->query("UPDATE FileS3 SET AccessType='secure' WHERE id_=5000");$revoked=$build('Analiza el archivo "lazy.php"');
workCheck(!$revoked->selectedItems('attachments'),'On-demand selection cannot use revoked originals or cached working evidence');
$db->query("UPDATE FileS3 SET AccessType='normal' WHERE id_=5000");
workCheck(in_array('attachment_file_'.$lazyId.'_extracting',$seenDuringRead,true),'Live extraction milestone is persisted before the actual S3 content read');
$events=$db->query("SELECT event_key,title,status,details_json FROM ChatActivityEvents WHERE trace_id='attachment-test-0000000000000003' ORDER BY id_")->fetch_all(MYSQLI_ASSOC);
$keys=array_column($events,'event_key');
workCheck(array_search('attachment_plan',$keys,true)<array_search('attachment_file_'.$lazyId.'_extracting',$keys,true)&&in_array('attachment_file_'.$lazyId.'_context_ready',$keys,true),'A real request records action selection, extraction and context-ready milestones in order');
workCheck(!str_contains(json_encode($events),'SECRET_UNUSED_CONTEXT'),'Progress events contain stage/count metadata, not source code');
$generic=$build('Analiza el archivo "lazy.php". Explica qué contiene y dónde se encuentra cada dato relevante.');
workCheck(($generic->telemetry()['attachment_request']['strategy']??'')==='overview'&&str_contains(AttachmentPromptGrounding::append('',$generic),'SECRET_UNUSED_CONTEXT'),'Automatic analysis request gets actual document context rather than a synthetic zero-match result');
$noHit=$build('Busca "NEVER_PRESENT_LITERAL" en "lazy.php"');
workCheck(!empty($noHit->selectedItems('attachments')[0]->metadata['literal_no_match'])&&str_contains(AttachmentPromptGrounding::append('',$noHit),'no significa que falten fragmentos'),'An actual zero-match result is distinguished from unavailable or unindexed content');
// Old conversations with a linked file and no processing policy prepare missing
// content automatically, including a second file while another index exists.
$db->query("INSERT INTO ChatSessions(id_,user_id_,title,model_id,meta) VALUES(6,1,'Legacy','fixture','{\"knowledge_file_ids\":[5000,3823]}')");
$legacy=$builder->build(new MemoryRoute(['intent'=>'code','use_attachment_context'=>true]),['stage'=>'respond','user_id'=>1,'session_id'=>6,'query_text'=>'Analiza el archivo "lazy.php" y explica dónde se encuentra cada dato.','pipeline_features'=>$features,'trace_id'=>'legacy-attachment-test-00001']);
workCheck(count($legacy->selectedItems('attachments'))>0&&($legacy->telemetry()['attachment_request']['strategy']??'')==='overview','Legacy conversation automatically prepares a named file without asking the user to index it');
$second=$builder->build(new MemoryRoute(['intent'=>'code','use_attachment_context'=>true]),['stage'=>'respond','user_id'=>1,'session_id'=>6,'query_text'=>'Analiza el archivo thankyou-old.php','pipeline_features'=>$features,'trace_id'=>'legacy-attachment-test-00002']);
workCheck(count($second->selectedItems('attachments'))>0&&str_contains(AttachmentPromptGrounding::append('',$second),'thankyou-old.php'),'Missing named-file evidence is prepared even when another attachment is already indexed');
echo "OK $n Drive chat working-copy checks\n";
