<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/Documents/DocumentKnowledgeService.php';
require_once dirname(__DIR__).'/includes/Memory/bootstrap.php';
require_once dirname(__DIR__).'/includes/Memory/AttachmentContextRepository.php';

/** Intentionally no AWS SDK/network calls; verifies production index and RAG access. */
final class LegalBridgeFakeS3
{
    public function __construct(public array $objects) {}
    public function headObject(array $args): array
    {
        $row=$this->objects[(string)($args['Key']??'')]??null;
        if(!$row)throw new RuntimeException('FakeS3 NotFound');
        return ['ETag'=>'"'.hash('md5',$row['body']).'"','ContentLength'=>strlen($row['body'])];
    }
}
if(!class_exists('Config',false)){
    final class Config {
        public static ?LegalBridgeFakeS3 $fake=null;
        public static function getS3(): LegalBridgeFakeS3 {return self::$fake??throw new RuntimeException('S3 fake unavailable');}
        public static function getBucket(): string{return 'never-existing-fixture-only';}
    }
}
if(!function_exists('aiAgentModel')) {
    function aiAgentModel(string $name,string $fallback=''): string {return $name==='embedding_main'?'legal-mock-embedding':$fallback;}
}
if(!function_exists('aiAgentExtra')) {
    function aiAgentExtra(string $name,string $key,mixed $fallback=null):mixed{return $fallback;}
}

final class LegalBridgeFakeDocumentRuntime implements DocumentKnowledgeRuntime
{
    public function __construct(private LegalBridgeFakeS3 $s3) {}
    public function describe(array $file):array{return $this->s3->headObject(['Key'=>idx_build_s3_key($file)]);}
    public function extract(array $file):array {
        $key=idx_build_s3_key($file);
        $body=$this->s3->objects[$key]['body']??throw new RuntimeException('Missing fake S3 object');
        return ['content'=>$body,'source_etag'=>$this->describe($file)['ETag'],
            'source_sha256'=>hash('sha256',$body),'extractor'=>'fixture-txt','truncated'=>false];
    }
    public function embeddingModel(int $userId):string{return 'legal-mock-embedding';}
    public function embed(int $userId,string $content,string $expectedModel):array {
        if($expectedModel!=='legal-mock-embedding')throw new RuntimeException('invalid_model_fixture');
        return ['embedding'=>[0.7,0.2,0.1],'inputTokens'=>4];
    }
}

function runLegalBridgeCentralIndexMysqlContracts(mysqli $db,callable $check,int $session,int $owner,int $currentFile,int $staleFile,array $objects):void
{
    Config::$fake=new LegalBridgeFakeS3($objects);
    $indexer=new DocumentKnowledgeService($db,new LegalBridgeFakeDocumentRuntime(Config::$fake));
    $queued=$indexer->enqueue($owner,$session,$currentFile);
    $check($queued['queued']===true,'legal active revision enqueued in real central document index');
    $done=false;
    for($i=0;$i<15;$i++) {
        $job=$indexer->tick((int)$queued['central_job_id'],50,12);
        if(($job['status']??'')==='completed'){$done=true;break;}
        if(($job['status']??'')==='failed')break;
    }
    $check($done,'legal real resumable indexing pipeline reached completed using fake S3 and embedding');
    $access=new DocumentAccessRepository($db);
    $sessionRow=$access->session($owner,$session);
    $central=new CentralDocumentIndex($db);
    $sources=$central->sources($owner,$session,$sessionRow,true);
    $check(array_key_exists($currentFile,$sources)&&!array_key_exists($staleFile,$sources),'legal central RAG sources include active revision only');
    if(!isset($sources[$currentFile]))return;
    $state=$central->status($sources[$currentFile],'legal-mock-embedding');
    $check($state['chunks_total']>0&&$state['chunks_done']===$state['chunks_total']&&$state['chunks_with_embedding']===$state['chunks_total'],'legal central index reports full indexed and embedded coverage');
    $row=$central->rows($sources[$currentFile],0,20);
    $check(count($row)>0 && str_contains((string)$row[0]['content_preview'],'BORRADOR v2'),'legal RAG evidence derived from active copied text');
    $retrieved=(new AttachmentContextRepository($db,null))->retrieve($owner,$session,'BORRADOR','always',null,null);
    $evidence=implode("\n",array_map(static fn($item)=>(string)$item->content,$retrieved['items']));
    $check(count($retrieved['items'])>0 && str_contains($evidence,'BORRADOR v2') && !str_contains($evidence,'BORRADOR v1'),'legal actual attachment RAG returns only the active revision');
    $oldKey=$db->query('SELECT Encriptado FROM FileS3 WHERE id_='.$staleFile)->fetch_assoc()['Encriptado'];
    $check(isset($objects[$oldKey])&&str_contains($objects[$oldKey]['body'],'BORRADOR v1'),'legal historical immutable object remains unchanged');
    $check($access->requireFile($owner,$session,$currentFile)['id_']===$currentFile,'legal current document passes live ownership checks');
    $check(LegalBridgeKnowledgeStatus::map($state,'legal-mock-embedding',(string)$sources[$currentFile]['embedding_model'])==='ready','legal verified source and embedded chunks meet ready contract');
    Config::$fake=null;
}
