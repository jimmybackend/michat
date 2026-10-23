<?php
declare(strict_types=1);
/** Actual MariaDB transactions on connection-local temporary canonical and new tables. */
$host=getenv('DOCUMENT_TEST_DB_HOST');
if (!$host) { echo "SKIP central document queue MariaDB: DOCUMENT_TEST_DB_HOST missing\n";exit(0); }
if (!in_array($host,['127.0.0.1','localhost','::1'],true)) throw new RuntimeException('Only isolated loopback test server is permitted');
require_once dirname(__DIR__).'/includes/ai_agent_runtime.php';
require_once dirname(__DIR__).'/includes/Memory/ContextItem.php';
require_once dirname(__DIR__).'/includes/Memory/AttachmentContextRepository.php';
require_once dirname(__DIR__).'/includes/Documents/DocumentKnowledgeService.php';
require_once dirname(__DIR__).'/includes/Migrations/SqlMigrationExecutor.php';
$db=new mysqli($host,getenv('DOCUMENT_TEST_DB_USER')?:'root',getenv('DOCUMENT_TEST_DB_PASSWORD')?:'',getenv('DOCUMENT_TEST_DB_NAME')?:'mysql',(int)(getenv('DOCUMENT_TEST_DB_PORT')?:3306));$db->set_charset('utf8mb4');
$schema=file_get_contents(dirname(__DIR__,2).'/adbbmis1_Cloud.sql');
foreach(['FileS3','ChatSessions','SessionContextBlocks'] as $name) {
    preg_match('/CREATE TABLE IF NOT EXISTS `'.preg_quote($name,'/').'` \(.*?\) ENGINE=.*?;/s',$schema,$m);
    $db->query(str_replace(['CREATE TABLE IF NOT EXISTS','utf8mb4_0900_ai_ci'],['CREATE TEMPORARY TABLE','utf8mb4_unicode_ci'],$m[0]));
}
$migration=file_get_contents(dirname(__DIR__).'/sql/fase13_document_knowledge.sql');
foreach(SqlMigrationExecutor::splitStatements($migration) as $statement) $db->query(str_replace('CREATE TABLE DocumentKnowledge','CREATE TEMPORARY TABLE DocumentKnowledge',$statement));
$checks=0;
function queueCheck(bool $ok,string $label): void { global $checks;$checks++;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n"; }
$runtime=new class implements DocumentKnowledgeRuntime {
    public string $etag='"v1"';public string $model='fixture-model';public string $text='';public bool $fail=false;public int $extractCalls=0;public int $embedCalls=0;public bool $partial=false;public bool $ocrPending=false;
    public function describe(array $file): array { return ['ETag'=>$this->etag,'ContentLength'=>strlen($this->text)]; }
    public function extract(array $file): array { $this->extractCalls++;if($this->ocrPending)throw new PdfOcrPendingException('OCR pendiente');return ['content'=>$this->text,'source_etag'=>$this->etag,'source_sha256'=>hash('sha256',$this->text),'truncated'=>$this->partial,'extractor'=>'fixture-utf8']; }
    public function embeddingModel(int $userId): string { return $this->model; }
    public function embed(int $userId,string $content,string $expectedModel): array { $this->embedCalls++;if($this->fail)throw new RuntimeException('Simulated Bedrock failure');return ['embedding'=>[1.0,0.0],'inputTokens'=>10]; }
};
class Config {
    public static object $runtime;
    public static function getBucket(): string { return 'isolated-fixtures'; }
    public static function getS3(): object { return new class { public function headObject(array $r): array { return ['ETag'=>Config::$runtime->etag]; } }; }
}
Config::$runtime=$runtime;
$runtime->text=str_repeat("function ejemplo() { return 'relleno de documento'; }\n",10000)."function importe_final() { return 987654321; }\n";
$db->query("INSERT INTO ChatSessions(id_,user_id_,title,model_id,meta) VALUES (1,1,'One','fixture','{\"knowledge_file_ids\":[1]}'),(2,1,'Two','fixture','{\"knowledge_file_ids\":[1]}'),(3,2,'Other','fixture','{}')");
$db->query("INSERT INTO FileS3(id_,Nombre,Encriptado,Tamano,Ruta,Found,user_id_) VALUES (1,'grande.php','grande',350000,'Data/',1,1)");
$GLOBALS['AI_AGENT_CONFIGS']=['embedding_main'=>['is_active'=>1,'model_id'=>'fixture-model','_extra'=>['attachment_rag_top'=>8]]];
putenv('MICHAT_DOCUMENT_KNOWLEDGE_ENABLED=1');
$service=new DocumentKnowledgeService($db,$runtime);$repo=new DocumentKnowledgeRepository($db);
$queued=$service->enqueue(1,1,1);$jobId=$queued['central_job_id'];$sourceId=$queued['central_source_id'];
$second=$service->enqueue(1,2,1);
queueCheck($sourceId===$second['central_source_id']&&$jobId===$second['central_job_id'],'Two conversations reuse one source revision and one job');
queueCheck((int)$repo->one('SELECT COUNT(*) n FROM DocumentKnowledgeSources')['n']===1,'Enqueue is idempotent without duplicating S3 or index');
$denied=false;try{$service->enqueue(2,3,1);}catch(RuntimeException $e){$denied=true;}queueCheck($denied,'Cross-user enqueue denied');
$active=$repo->claim($jobId);queueCheck($active!==null&&$repo->claim($jobId)===null,'A live worker claim excludes another worker');
$repo->write('UPDATE DocumentKnowledgeJobs SET lease_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id_=?','i',$jobId);
$replacement=$repo->claim($jobId);queueCheck($replacement!==null&&$replacement['lease_owner']!==$active['lease_owner'],'Interrupted job lease can be reclaimed');
$stale=false;try{$repo->fenced($active,fn()=>$repo->write("UPDATE DocumentKnowledgeSources SET state='ready' WHERE id_=?",'i',$sourceId));}catch(DocumentKnowledgeLeaseLost $e){$stale=true;}queueCheck($stale,'Stale worker cannot publish or mark document ready');
$repo->fenced($replacement,fn()=>$repo->release($replacement,'extract'));
$step=$service->tick($jobId,3,16);
queueCheck($step['phase']==='chunks'&&$step['status']==='pending','Extraction persists before any chunk batch');
$source=$repo->one('SELECT extracted_chars,extracted_content FROM DocumentKnowledgeSources WHERE id_=?','i',$sourceId);
queueCheck((int)$source['extracted_chars']>200000&&str_contains($source['extracted_content'],'987654321'),'New engine preserves final text after the old 200000 character cutoff');
$service->tick($jobId,3,16);
queueCheck((int)$repo->job($jobId)['cursor_ordinal']===3,'Chunk checkpoint commits a bounded batch');
$extracts=$runtime->extractCalls;
$service=new DocumentKnowledgeService($db,$runtime); // Simulated process restart: only database state survives.
for($i=0;$i<100 && $repo->job($jobId)['phase']==='chunks';$i++)$service->tick($jobId,75,16);
queueCheck($runtime->extractCalls===$extracts,'Restart resumes persisted extraction without another S3 extraction');
$counts=$repo->one('SELECT COUNT(*) n,COUNT(DISTINCT ordinal) distinct_n FROM DocumentKnowledgeChunks WHERE source_id=?','i',$sourceId);
queueCheck($counts['n']===$counts['distinct_n']&&(int)$counts['n']===(int)$repo->job($jobId)['chunks_total'],'Resumption produces complete unique chunks without duplicates');
$runtime->fail=true;$failed=$service->tick($jobId,75,16);
queueCheck($failed['status']==='retry'&&$failed['state']!=='ready','Embedding failure is retryable and never ready');
$retriever=new AttachmentContextRepository($db,null);
$r=$retriever->retrieve(1,2,'987654321','rag',null,null);
queueCheck(str_contains($r['context'],'987654321')&&str_contains($r['context'],'DKC:'),'Second conversation retrieves cited final evidence from the shared central index');
queueCheck(!$retriever->retrieve(2,3,'987654321','rag',null,null)['items'],'Central index prevents cross-user retrieval');
$before=(int)$repo->one('SELECT COUNT(*) n FROM DocumentKnowledgeChunks WHERE source_id=?','i',$sourceId)['n'];
$runtime->fail=false;$again=$service->enqueue(1,2,1);
queueCheck($again['central_source_id']===$sourceId,'Manual retry reuses the same revision');
$repo->write("UPDATE DocumentKnowledgeJobs SET available_at=UTC_TIMESTAMP() WHERE id_=?",'i',$jobId);
for($i=0;$i<100 && $repo->job($jobId)['status']!=='completed';$i++)$service->tick($jobId,75,16);
$done=$repo->job($jobId);
queueCheck($done['status']==='completed'&&$done['state']==='ready','Job becomes ready only after every chunk has an embedding');
queueCheck((int)$repo->one('SELECT COUNT(*) n FROM DocumentKnowledgeChunks WHERE source_id=?','i',$sourceId)['n']===$before,'Retry preserves original chunks and identities');
require_once dirname(__DIR__).'/includes/Memory/bootstrap.php';
$builder=new ContextBuilder($db,null);
$bundle=$builder->build(new MemoryRoute(['intent'=>'general','use_attachment_context'=>false]),[
 'stage'=>'respond','user_id'=>1,'session_id'=>2,'memory_scope'=>new ConversationScope(1,2,0,'free'),'query_text'=>'¿Qué dice el archivo grande.php sobre 987654321?',
 'pipeline_features'=>['procedural_memory_read'=>false,'project_memory_read'=>false,'session_memory_read'=>false,'question_memory_read'=>false,'project_rag'=>false,'attachment_rag'=>true,'context_ranking'=>true],
]);
queueCheck(str_contains($bundle->block('attachment_context_block'),'987654321')&&str_contains($bundle->block('attachment_context_block'),'DKC:'),'Real ContextBuilder ranks and includes central evidence and citation in the model prompt');
$centralIndex=new CentralDocumentIndex($db);
$authorized=$centralIndex->sources(1,1,(new DocumentAccessRepository($db))->session(1,1));
$page=$centralIndex->inspect($authorized);
queueCheck(count($page['rows'])===200&&$page['has_more'],'Inspector bounds central content to 200 chunks per page');
$page2=$centralIndex->inspect($authorized,$page['next_after']);
queueCheck(count($page2['rows'])>0&&!$page2['has_more'],'Inspector cursor reaches the remaining chunks');
$lastChunk=$repo->one('SELECT id_ FROM DocumentKnowledgeChunks WHERE source_id=? ORDER BY ordinal DESC LIMIT 1','i',$sourceId);
$exact=$centralIndex->inspect($authorized,0,(int)$lastChunk['id_']);
queueCheck(count($exact['rows'])===1&&str_contains($exact['rows'][0]['content_preview'],'987654321'),'Exact DKC evidence link resolves even beyond the first inspector page');
queueCheck(!$centralIndex->inspect([],0,(int)$lastChunk['id_'])['rows'],'Citation lookup requires an authorized live source');
queueCheck(str_contains($bundle->block('attachment_context_block'),'central_chunk_id='),'Model prompt includes a verifiable exact source URL');
$embedCalls=$runtime->embedCalls;$service->enqueue(1,1,1);$service->tick($jobId);
queueCheck($runtime->embedCalls===$embedCalls,'Completed revision is reused without more Bedrock requests');
$db->query("UPDATE FileS3 SET Nombre='grande.pdf' WHERE id_=1");
queueCheck(!$retriever->retrieve(1,1,'987654321','rag',null,null)['items'],'Changing the extension invalidates the previous format adapter index');
$db->query("UPDATE FileS3 SET Nombre='grande.php' WHERE id_=1");
$runtime->model='new-model';$service->enqueue(1,1,1);$modelJob=$repo->job($jobId);
queueCheck($modelJob['phase']==='embeddings'&&$modelJob['status']==='pending','Embedding model change queues only vector regeneration');
$db->query("UPDATE ChatSessions SET meta='{}' WHERE id_=2");
queueCheck(!$retriever->retrieve(1,2,'987654321','rag',null,null)['items'],'Unlink immediately revokes access to central chunks');
$db->query("UPDATE FileS3 SET AccessType='secure' WHERE id_=1");
queueCheck(!$retriever->retrieve(1,1,'987654321','rag',null,null)['items'],'Drive lock invalidates central evidence');
$db->query("UPDATE FileS3 SET AccessType='normal' WHERE id_=1");
$runtime->etag='"v2"';
queueCheck(!$retriever->retrieve(1,1,'987654321','rag',null,null)['items'],'S3 revision change invalidates old central evidence');
$new=$service->enqueue(1,1,1);
queueCheck($new['central_source_id']!==$sourceId,'Changed S3 source creates a separate immutable index revision');
queueCheck((int)$repo->one('SELECT COUNT(*) n FROM DocumentKnowledgeChunks WHERE source_id=?','i',$sourceId)['n']===$before,'Original index revision is preserved');
$runtime->model='';$disabled=$service->enqueue(1,1,1);$newJob=$disabled['central_job_id'];
for($i=0;$i<100&&!in_array($repo->job($newJob)['status'],['blocked','failed','completed'],true);$i++)$service->tick($newJob,200,16);
queueCheck($repo->job($newJob)['status']==='blocked'&&$repo->job($newJob)['state']==='indexed','Disabled embeddings leave an indexed document with explicit blocked state');
queueCheck(str_contains($retriever->retrieve(1,1,'987654321','rag',null,null)['context'],'987654321'),'Lexical RAG remains available when embeddings are disabled');
$events=$repo->one('SELECT COUNT(*) n FROM DocumentKnowledgeEvents WHERE source_id=?','i',$sourceId);
queueCheck((int)$events['n']>5,'Queue, extraction, chunk batches, embeddings and completion have persistent audit events');
// A worker that repeatedly disappears is bounded instead of leaving a running job forever.
$retry=$service->enqueue(1,1,1);$repo->write("UPDATE DocumentKnowledgeJobs SET status='running',failure_count=4,lease_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id_=?",'i',$newJob);$repo->claim($newJob);
queueCheck($repo->job($newJob)['status']==='failed','Repeated worker interruptions exhaust a finite recovery budget');
// Reader omissions remain partial even when every available chunk has a vector.
$runtime->etag='"partial-v3"';$runtime->text="[Página 1]\nImporte 654321\n[CONTENIDO NO EXTRAÍDO: página sin texto]";$runtime->partial=true;$runtime->model='fixture-model';
$partial=$service->enqueue(1,1,1);$partialId=$partial['central_job_id'];
for($i=0;$i<10&&$repo->job($partialId)['status']!=='completed';$i++)$service->tick($partialId);
queueCheck($repo->job($partialId)['state']==='partial'&&$repo->job($partialId)['coverage']==='partial','Reader omissions cannot be reported as complete knowledge');
queueCheck(!$service->enqueue(1,1,1)['ready_for_attachment_rag'],'A completed partial extraction is not an integral ready document');
// Resource excess is a visible failure; the database never receives a silent prefix.
$runtime->etag='"oversize-v4"';$runtime->partial=false;$runtime->text=str_repeat('x',DocumentKnowledgeService::MAX_EXTRACTED_BYTES+1);
$oversize=$service->enqueue(1,1,1);$oversizeId=$oversize['central_job_id'];$service->tick($oversizeId);
$oversizeSource=$repo->one('SELECT extracted_content,chunks_done FROM DocumentKnowledgeSources WHERE id_=?','i',$oversize['central_source_id']);
queueCheck($repo->job($oversizeId)['status']==='retry'&&$oversizeSource['extracted_content']===null&&(int)$oversizeSource['chunks_done']===0,'Oversized extraction fails explicitly without truncation or a false checkpoint');
for($i=0;$i<4;$i++){$repo->write('UPDATE DocumentKnowledgeJobs SET available_at=UTC_TIMESTAMP() WHERE id_=?','i',$oversizeId);$service->tick($oversizeId);}
queueCheck($repo->job($oversizeId)['status']==='failed'&&(int)$repo->job($oversizeId)['failure_count']===5,'External/resource failures stop after five attempts');
// A locked source cannot continue through the worker, either.
$runtime->etag='"locked-v5"';$runtime->text='texto de prueba';$locked=$service->enqueue(1,1,1);
$db->query("UPDATE FileS3 SET AccessType='secure' WHERE id_=1");$service->tick($locked['central_job_id']);
queueCheck($repo->job($locked['central_job_id'])['status']==='failed','Worker revalidates Drive access before external processing');
$repo->write("UPDATE FileS3 SET AccessType='normal' WHERE id_=1");
$runtime->etag='"ocr-wait"';$runtime->text='[Página 1] Texto OCR final';$runtime->ocrPending=true;
$ocr=$service->enqueue(1,1,1);$ocrId=$ocr['central_job_id'];$service->tick($ocrId);
$wait=$repo->job($ocrId);
queueCheck($wait['status']==='retry'&&(int)$wait['failure_count']===0,'Pending OCR releases lease without consuming the failure budget');
$runtime->ocrPending=false;$repo->write('UPDATE DocumentKnowledgeJobs SET available_at=UTC_TIMESTAMP() WHERE id_=?','i',$ocrId);$service->tick($ocrId);
queueCheck($repo->job($ocrId)['phase']==='chunks','OCR completion resumes the same job and extraction checkpoint');
$runtime->etag='"ocr-expired"';$runtime->ocrPending=true;$expired=$service->enqueue(1,1,1);$expiredId=$expired['central_job_id'];
$repo->write('UPDATE DocumentKnowledgeJobs SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 HOUR) WHERE id_=?','i',$expiredId);$service->tick($expiredId);
queueCheck($repo->job($expiredId)['status']==='failed','OCR pending beyond six hours stops explicitly instead of waiting forever');
putenv('MICHAT_DOCUMENT_KNOWLEDGE_ENABLED');$db->close();
echo "Central document queue MariaDB: $checks checks passed\n";
