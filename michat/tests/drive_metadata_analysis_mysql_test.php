<?php
declare(strict_types=1);
$host=getenv('DOCUMENT_TEST_DB_HOST');
if (!$host) { echo "SKIP Drive metadata/analysis DB: DOCUMENT_TEST_DB_HOST missing\n";exit; }
if (!in_array($host,['127.0.0.1','localhost','::1'],true)) throw new RuntimeException('Isolated loopback DB only');
require_once dirname(__DIR__).'/includes/Drive/MetadataSearchService.php';
require_once dirname(__DIR__).'/includes/Drive/DriveAnalysisService.php';
require_once dirname(__DIR__).'/includes/Dashboard/DashboardStatisticsService.php';
$db=new mysqli($host,getenv('DOCUMENT_TEST_DB_USER')?:'root',getenv('DOCUMENT_TEST_DB_PASSWORD')?:'',getenv('DOCUMENT_TEST_DB_NAME')?:'mysql',(int)(getenv('DOCUMENT_TEST_DB_PORT')?:3306));$db->set_charset('utf8mb4');
$schema=file_get_contents(dirname(__DIR__,2).'/adbbmis1_Cloud.sql');
foreach (['FileS3','ChatSessions','ChatMessages','SessionContextBlocks','TokenUsage','LintAttempts','FileVersions','Projects'] as $name) {
    if (!preg_match('/CREATE TABLE IF NOT EXISTS `'.preg_quote($name,'/').'` \(.*?\) ENGINE=.*?;/s',$schema,$m)) throw new RuntimeException($name);
    $db->query(str_replace(['CREATE TABLE IF NOT EXISTS','utf8mb4_0900_ai_ci'],['CREATE TEMPORARY TABLE','utf8mb4_unicode_ci'],$m[0]));
}
$checks=0;function checkDrive(bool $ok,string $label): void {global $checks;$checks++;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$db->query("INSERT INTO ChatSessions(id_,user_id_,title,model_id,meta,updated_at) VALUES (1,1,'One','fixture','{\"knowledge_file_ids\":[1]}','2026-10-10'),(2,1,'Two','fixture','{\"knowledge_file_ids\":[1]}','2026-10-10'),(3,2,'Private','fixture','{}','2026-10-10')");
$db->query("INSERT INTO FileS3(id_,Nombre,Encriptado,Tamano,Ruta,Found,user_id_,AccessType,Fecha,Metadatos) VALUES
 (1,'contrato.pdf','Data/a.pdf',100,'Data/',1,1,'normal','2026-09-30 23:59:59','{\"tema\":\"impuestos\"}'),
 (2,'contrato privado.pdf','Other/private.pdf',100,'Other/',1,2,'normal','2026-09-10','{}'),
 (3,'contrato bloqueado.pdf','Data/locked.pdf',100,'Data/',1,1,'secure','2026-09-10','{}'),
 (4,'contrato eliminado.pdf','Data/deleted.pdf',100,'Data/',0,1,'normal','2026-09-10','{}'),
 (5,'contrato.xlsx','Data/a.xlsx',100,'Data/',1,1,'normal','2026-10-01 00:00:00','{}')");
$plans=0;
$search=new MetadataSearchService($db,function($query) use(&$plans) {$plans++;checkDrive($query==='contratos PDF de septiembre','Planner receives only the search request');return ['terms'=>['contrato'],'extensions'=>['pdf'],'date_from'=>'2026-09-01','date_to'=>'2026-09-30','sql'=>'DROP TABLE FileS3','user_id'=>2];});
$found=$search->search(1,'contratos PDF de septiembre',true);
checkDrive(count($found['files'])===1 && (int)$found['files'][0]['id_']===1,'AI filters use real metadata, inclusive end date, live owner/lock/deletion checks');
checkDrive($found['files'][0]['Encriptado']==='Data/a.pdf'&&isset($found['files'][0]['Metadatos']),'Authorized catalog exposes real storage key and metadata to its owner');
checkDrive($plans===1,'One planner invocation per search');
checkDrive(!isset($found['filters']['sql'],$found['filters']['user_id']),'AI cannot supply SQL or ownership filters');
checkDrive(count($search->search(1,'impuestos',false)['files'])===1,'Metadata field is searchable without analyzing content');
checkDrive(!$search->search(1,"' OR 1=1 --",false)['files'],'SQL-like text stays a bound literal');
checkDrive(!$search->search(1,'%',false)['files'],'LIKE wildcard is escaped');
checkDrive(count($search->search(2,'contrato',false)['files'])===1,'Another user sees only their own file');
$bad=false;try{MetadataSearchService::filters(['date_from'=>'2026-02-30']);}catch(InvalidArgumentException){$bad=true;}checkDrive($bad,'Invalid calendar dates rejected');
$filters=MetadataSearchService::filters(['terms'=>array_fill(0,20,'x'),'extensions'=>['.PDF','php);DROP TABLE x']]);
checkDrive(count($filters['terms'])===6 && $filters['extensions']===['pdf'],'Planner output is bounded and extensions allowlisted');
for($i=100;$i<145;$i++)$db->query("INSERT INTO FileS3(id_,Nombre,Encriptado,Tamano,Ruta,Found,user_id_) VALUES ($i,'pagina-$i.txt','Data/p$i',10,'Data/',1,1)");
$page=$search->search(1,'pagina-',false);$next=$search->search(1,'pagina-',false,[],$page['next_cursor']);
checkDrive(count($page['files'])===40&&$page['has_more']&&count($next['files'])===5&&!$next['has_more'],'Keyset pagination returns every matching metadata record');
checkDrive(!$db->query('SELECT COUNT(*) n FROM SessionContextBlocks')->fetch_assoc()['n'],'Browsing did not create knowledge blocks');

$db->query("INSERT INTO TokenUsage(session_id_,phase,model_id,input_tokens,output_tokens,estimated_cost_usd,created_at) VALUES
 (1,'respond','unknown-expensive-model',100,25,1.234567,'2026-10-02'),(2,'scout','fixture',20,5,0.010000,'2026-10-31 23:59:59'),
 (3,'respond','private-model',999,999,99.000000,'2026-10-02'),(1,'respond','other-month',1,1,5,'2026-11-01')");
$report=(new DashboardStatisticsService($db))->month(1,'2026-10');
checkDrive($report['tokens']['total']===150,'Dashboard token totals exclude other users and next month');
checkDrive(abs($report['tokens']['cost']-1.244567)<0.000001,'Dashboard preserves stored cost, including unknown models, without invented fallback prices');
checkDrive(count($report['models'])===2&&isset($report['tokens']['by_phase']['scout']),'Dashboard includes every recorded model and phase');
checkDrive(count($report['sessions'])===2,'MariaDB compression report runs without ANY_VALUE and is owner scoped');
$bad=false;try{(new DashboardStatisticsService($db))->month(1,'2026-13');}catch(InvalidArgumentException){$bad=true;}checkDrive($bad,'Dashboard rejects invalid months');

class SidecarMissing extends RuntimeException { public function getAwsErrorCode():string{return 'NoSuchKey';} }
$s3=new class {
    public array $objects=['Data/a.pdf'=>['body'=>'original-bytes','etag'=>'"original-v1"']]; public array $writes=[];public bool $outage=false;public bool $failWrites=false;
    public function headObject(array $r):array {if($this->outage)throw new RuntimeException('S3 unavailable');$o=$this->objects[$r['Key']]??throw new SidecarMissing();return ['ETag'=>$o['etag'],'ContentLength'=>strlen($o['body'])];}
    public function getObject(array $r):array { $o=$this->objects[$r['Key']]??throw new SidecarMissing();if(isset($r['IfMatch'])&&$r['IfMatch']!==$o['etag'])throw new RuntimeException('PreconditionFailed');return ['Body'=>$o['body']]; }
    public function putObject(array $r):array { if($this->failWrites)throw new RuntimeException('Sidecar writes denied');$old=$this->objects[$r['Key']]??null;if(($r['IfNoneMatch']??'')==='*'&&$old)throw new RuntimeException('PreconditionFailed');if(isset($r['IfMatch'])&&($old['etag']??null)!==$r['IfMatch'])throw new RuntimeException('PreconditionFailed');$this->objects[$r['Key']]=['body'=>$r['Body'],'etag'=>'"'.hash('sha256',$r['Body']).'"'];$this->writes[]=$r;return []; }
};
$store=new AnalysisSidecarStore($s3,'test-bucket',$db);$analyzeCalls=0;
$analyzer=function(int $user,int $session,int $file) use($db,$s3,&$analyzeCalls) {
    $analyzeCalls++;$text='Resumen comprobado de impuestos';$key='Data/a.pdf';
    $meta=json_encode(['files3_id'=>1,'source_etag'=>$s3->objects[$key]['etag'],'semantic_model'=>'fixture-model','extractor'=>'fixture-pdf','truncated'=>true]);
    $s=$db->prepare("INSERT INTO SessionContextBlocks(session_id_,block_type,content_preview,s3_path,source_ids) VALUES (?,'file',?,?,?)");$s->bind_param('isss',$session,$text,$key,$meta);$s->execute();$s->close();
};
$indexCalls=0;
$indexer=function(int $user,int $session,int $file) use($db,$s3,&$indexCalls) {
    $indexCalls++;$text='El importe del convenio es 41837 y dura tres años.';$key='Data/a.pdf';
    $meta=json_encode(['files3_id'=>1,'filename'=>'contrato.pdf','source_etag'=>$s3->objects[$key]['etag'],'chunk'=>1,'total'=>1,'locator'=>['page'=>2],'truncated'=>false]);
    $s=$db->prepare("INSERT INTO SessionContextBlocks(session_id_,block_type,content_preview,s3_path,source_ids) VALUES (?,'file_chunk',?,?,?)");$s->bind_param('isss',$session,$text,$key,$meta);$s->execute();$s->close();
};
$service=new DriveAnalysisService($db,$store,$analyzer,$indexer);
$result=$service->analyze(1,1,1);$file=(new DocumentAccessRepository($db))->file(1,1);$sidecar=AnalysisSidecarStore::key($file);
checkDrive(!$result['reused']&&$analyzeCalls===1&&isset($s3->objects[$sidecar]),'Explicit selection analyzes and writes a private standard sibling JSON');
checkDrive($result['coverage']==='extracted_text'&&!empty($result['summary_partial']),'Partial summary is distinguished from complete literal extraction in JSON and UI result');
checkDrive(dirname($sidecar)==='Data'&&$s3->objects['Data/a.pdf']['body']==='original-bytes','JSON lives beside source and original is never overwritten');
checkDrive(!isset($s3->writes[0]['ACL'])&&$s3->writes[0]['IfNoneMatch']==='*','No public ACL and first write is conditional');
$again=$service->analyze(1,2,1);
checkDrive($again['reused']&&$analyzeCalls===1,'Second conversation reuses JSON without extractor/model call');
checkDrive((int)$db->query('SELECT COUNT(*) n FROM SessionContextBlocks WHERE session_id_=2')->fetch_assoc()['n']===2,'Cached summary becomes usable context in the selected second conversation');
$service->analyze(1,2,1);
checkDrive((int)$db->query('SELECT COUNT(*) n FROM SessionContextBlocks WHERE session_id_=2')->fetch_assoc()['n']===2,'Repeated imports are idempotent');
$denied=false;try{$service->analyze(2,3,1);}catch(Throwable){$denied=true;}checkDrive($denied&&$analyzeCalls===1,'Cross-owner analysis denied before S3/AI work');
$db->query("UPDATE FileS3 SET AccessType='secure' WHERE id_=1");$denied=false;try{$service->analyze(1,2,1);}catch(Throwable){$denied=true;}checkDrive($denied,'Source locking revokes reuse of cached JSON');$db->query("UPDATE FileS3 SET AccessType='normal' WHERE id_=1");
$db->query("INSERT INTO ChatMessages(id_,session_id_,user_id_,role,content_type,content,model_id) VALUES (11,2,1,'user','text','¿Qué dice de impuestos?','fixture'),(12,2,1,'assistant','text','Respuesta con evidencia [SCB:1]','fixture')");
$bundle=new class { public string $etag='"original-v1"'; public function selectedItems(string $bucket):array{return [(object)['metadata'=>['files3_id'=>1,'source_etag'=>$this->etag]]];} };
checkDrive(!$service->recordTurn(1,2,11,12,$bundle),'Persisted file interaction updates JSON without another AI call');
$state=$store->read($file);checkDrive(count($state['analysis']['interactions'])===1&&$state['analysis']['interactions']['2:11:12']['answer']==='Respuesta con evidencia [SCB:1]','JSON retains exact Q&A and message identifiers');
$service->recordTurn(1,2,11,12,$bundle);checkDrive(count($store->read($file)['analysis']['interactions'])===1,'Retried finalization does not duplicate Q&A');
checkDrive($analyzeCalls===1,'Saving interactions does not run a summarizer');
$stale=$store->read($file);$changed=$stale['analysis'];$changed['summary']='External update';$store->write($file,$stale,$changed);
$conflict=false;try{$store->write($file,$stale,$stale['analysis']);}catch(Throwable){$conflict=true;}checkDrive($conflict,'Conditional PUT prevents lost updates from concurrent writers');
$s3->objects['Data/a.pdf']['etag']='"original-v2"';
checkDrive($store->read($file)['analysis']===null,'Changed original revision cannot reuse stale analysis');
checkDrive(count($service->recordTurn(1,2,11,12,$bundle))===1,'Answer from a stale revision is not appended to a newer revision');
$service->analyze(1,1,1);checkDrive($analyzeCalls===2&&count($store->read($file)['document']['revisions'])===2,'New source revision gets fresh analysis while old JSON history is retained');
$state=$store->read($file);$huge=$state['analysis'];$huge['summary']=str_repeat('x',AnalysisSidecarStore::MAX_BYTES);
$denied=false;try{$store->write($file,$state,$huge);}catch(RuntimeException){$denied=true;}checkDrive($denied,'JSON resource limit fails visibly without dropping history');
$s3->outage=true;$failed=$service->analyze(1,1,1);checkDrive(!$failed['ok']&&$analyzeCalls===2,'S3 outage is not mistaken for absent cache and does not trigger another model call');$s3->outage=false;
$db->query("UPDATE ChatSessions SET meta='{}' WHERE id_=2");$denied=false;try{$service->analyze(1,2,1);}catch(Throwable){$denied=true;}checkDrive($denied,'Removing Drive link revokes cached analysis access');
checkDrive(count($s3->objects)>=2&&!array_filter($s3->writes,fn($r)=>$r['Key']==='Data/a.pdf'),'Every write targets sidecar, never the original');
// Restore the Drive link and exercise lexical retrieval from the cached original fragments.
$db->query("UPDATE ChatSessions SET meta=JSON_SET(meta,'$.knowledge_file_ids',JSON_ARRAY(1)) WHERE id_=2");
require_once dirname(__DIR__).'/includes/Memory/ContextItem.php';
require_once dirname(__DIR__).'/includes/Memory/AttachmentContextRepository.php';
$GLOBALS['AI_AGENT_CONFIGS']=['embedding_main'=>['is_active'=>0,'model_id'=>'fixture']];
$indexBefore=$indexCalls;$service->analyze(1,2,1);
checkDrive($indexCalls===$indexBefore,'JSON reuses original chunks without another extraction');
require_once dirname(__DIR__).'/includes/Drive/BedrockMetadataPlanner.php';
preg_match('/CREATE TABLE IF NOT EXISTS `UserAIAgentConfigs` \(.*?\) ENGINE=.*?;/s',$schema,$m);
$db->query(str_replace(['CREATE TABLE IF NOT EXISTS','utf8mb4_0900_ai_ci','VIRTUAL NOT NULL'],['CREATE TEMPORARY TABLE','utf8mb4_unicode_ci','VIRTUAL'],$m[0]));
$db->query("INSERT INTO UserAIAgentConfigs(scope,user_id_,agent_key,display_name,model_id,is_active) VALUES ('global',NULL,'chat_main','Chat','amazon.nova-micro-v1:0',1)");
class Config {
    public static object $runtime;public static object $s3;
    public static function getS3():object{return self::$s3;}
    public static function getBucket():string{return 'test-bucket';}
    public static function getBedrockRuntime():object{return self::$runtime;}
}
Config::$runtime=new class {public array $calls=[];public string $text='{"terms":["contrato"],"extensions":["pdf"]}';public function converse(array $args):array{$this->calls[]=$args;return ['output'=>['message'=>['content'=>[['text'=>$this->text]]]],'usage'=>['inputTokens'=>23,'outputTokens'=>8]];}};
Config::$s3=$s3;
$evidence=(new AttachmentContextRepository($db,null))->retrieve(1,2,'41837','rag',null,null);
checkDrive(str_contains($evidence['context'],'41837')&&str_contains($evidence['context'],'página 2'),'Selected cached file yields literal evidence and its real page without embeddings');
$availability=(new FileKnowledgeState($db))->availability(1,2);
checkDrive($availability[0]['status']==='text_ready','Persisted status distinguishes text ready from mere Drive linking');
$planner=new BedrockMetadataPlanner($db,1,1);$realPlan=$planner('busca contratos PDF');
checkDrive($realPlan['extensions']===['pdf']&&count(Config::$runtime->calls)===1,'Actual Bedrock planner normalizes structured filters with configured model');
$call=Config::$runtime->calls[0];checkDrive($call['messages'][0]['content'][0]['text']==='busca contratos PDF'&&!str_contains(json_encode($call),'original-bytes'),'Planner sends query and filter contract, never source bytes or catalog rows');
checkDrive((int)$db->query("SELECT COUNT(*) n FROM TokenUsage WHERE session_id_=1 AND phase='scout' AND input_tokens=23")->fetch_assoc()['n']===1,'Metadata AI search records reported usage against the owned conversation');
Config::$runtime->text='not JSON';$bad=false;try{$planner('another request');}catch(Throwable){$bad=true;}checkDrive($bad,'Invalid model output cannot become executable SQL or hidden fallback filters');
checkDrive((int)$db->query("SELECT COUNT(*) n FROM TokenUsage WHERE session_id_=1 AND phase='scout' AND input_tokens=23")->fetch_assoc()['n']===2,'Even an invalid planner response retains its usage record');
$bad=false;try{(new BedrockMetadataPlanner($db,2,1))('private');}catch(Throwable){$bad=true;}checkDrive($bad&&count(Config::$runtime->calls)===2,'Planner rejects a foreign conversation before calling the model');
// A summary or cache failure must not erase literal evidence.
$s3->objects['Data/a.pdf']['etag']='"original-v3"';$s3->failWrites=true;
$unavailableSummary=static function(){throw new RuntimeException('La semántica está desactivada');};
$robust=new DriveAnalysisService($db,$store,$unavailableSummary,$indexer);$prepared=$robust->analyze(1,2,1);
checkDrive($prepared['ok']&&$prepared['chunks']===1&&!$prepared['semantic_available']&&!$prepared['sidecar_saved'],'Summary and JSON write failures preserve a successful literal index');
$evidence=(new AttachmentContextRepository($db,null))->retrieve(1,2,'41837','rag',null,null);
checkDrive(str_contains($evidence['context'],'41837')&&str_contains($evidence['context'],'página 2'),'The selected file is answerable despite unavailable summary and JSON');
$s3->failWrites=false;
$db->query("UPDATE ChatSessions SET meta=JSON_SET(meta,'$.knowledge_file_ids',JSON_ARRAY(1,5)) WHERE id_=2");
(new FileKnowledgeState($db))->save(1,2,5,['status'=>'ocr_pending','message'=>'OCR del PDF en proceso.']);
require_once dirname(__DIR__).'/includes/Memory/bootstrap.php';
$builder=new ContextBuilder($db,null);
$features=['procedural_memory_read'=>false,'project_memory_read'=>false,'session_memory_read'=>false,'question_memory_read'=>false,'project_rag'=>false,'attachment_rag'=>true,'context_ranking'=>true];
$bundle=$builder->build(new MemoryRoute(['intent'=>'code_project','use_attachment_context'=>false]),['stage'=>'respond','user_id'=>1,'session_id'=>2,'query_text'=>'Clasifica el proyecto','original_query_text'=>'Qué dice el archivo "contrato.pdf" sobre 41837','pipeline_features'=>$features]);
checkDrive(str_contains($bundle->block('attachment_context_block'),'41837')&&str_contains($bundle->block('attachment_context_block'),'página 2'),'Real ContextBuilder keeps original filename and includes cited evidence despite a rewritten code route');
checkDrive(FileKnowledgeState::unavailableReply($bundle)===null,'Valid recovered content is never replaced by a missing-file diagnostic');
$empty=$builder->build(new MemoryRoute(['intent'=>'code_project']),['stage'=>'respond','user_id'=>1,'session_id'=>2,'query_text'=>'Analiza el archivo "contrato.xlsx"','pipeline_features'=>$features]);
$diagnostic=FileKnowledgeState::unavailableReply($empty);
checkDrive($diagnostic!==null&&str_contains($diagnostic,'contrato.xlsx')&&str_contains($diagnostic,'no se pudo localizar')&&!str_contains($diagnostic,'no tengo acceso'),'Missing evidence is rechecked automatically and reports the actual missing S3 object rather than an old pending state');
checkDrive(!str_contains($diagnostic,'contrato.pdf'),'A named file diagnostic does not confuse it with another linked file');
echo "Drive metadata, dashboard and analysis real MariaDB: $checks checks passed\n";
