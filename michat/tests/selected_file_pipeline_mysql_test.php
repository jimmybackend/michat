<?php
declare(strict_types=1);
$host=getenv('DOCUMENT_TEST_DB_HOST');
if (!$host){echo "SKIP selected-file production pipeline DB\n";exit;}
if(!in_array($host,['127.0.0.1','localhost','::1'],true))throw new RuntimeException('Isolated loopback DB only');
define('APP_BOOTSTRAP_LOADED',true);
require_once dirname(__DIR__).'/includes/Drive/DriveAnalysisService.php';
require_once dirname(__DIR__).'/includes/SessionAttachmentKnowledgeService.php';
require_once dirname(__DIR__).'/includes/Memory/bootstrap.php';
$db=new mysqli($host,getenv('DOCUMENT_TEST_DB_USER')?:'root',getenv('DOCUMENT_TEST_DB_PASSWORD')?:'',getenv('DOCUMENT_TEST_DB_NAME')?:'mysql',(int)(getenv('DOCUMENT_TEST_DB_PORT')?:3306));$db->set_charset('utf8mb4');$GLOBALS['db_connection']=$db;
$schema=file_get_contents(dirname(__DIR__,2).'/adbbmis1_Cloud.sql');
foreach(['FileS3','ChatSessions','SessionContextBlocks','EmbeddingJobs','TokenUsage','UserAIAgentConfigs'] as $name){
    preg_match('/CREATE TABLE IF NOT EXISTS `'.$name.'` \(.*?\) ENGINE=.*?;/s',$schema,$m);
    $sql=str_replace(['CREATE TABLE IF NOT EXISTS','utf8mb4_0900_ai_ci'],['CREATE TEMPORARY TABLE','utf8mb4_unicode_ci'],$m[0]);
    // MariaDB does not accept MySQL's NOT NULL after VIRTUAL. Same schema and keys.
    $db->query(str_replace('VIRTUAL NOT NULL','VIRTUAL',$sql));
}
$db->query("INSERT INTO ChatSessions(id_,user_id_,title,model_id,meta) VALUES(1,1,'Analysis','fixture','{\"knowledge_file_ids\":[1,2]}')");
$db->query("INSERT INTO FileS3(id_,user_id_,Nombre,Ruta,Encriptado,Tamano,Found) VALUES(1,1,'codigo.txt','Data/','codigo.txt',100,1),(2,1,'convenio.pdf','Data/','convenio.pdf',100,1)");
$db->query("INSERT INTO UserAIAgentConfigs(scope,agent_key,display_name,model_id,is_active) VALUES('global','embedding_main','Embedding','',1)");
final class FixtureMissing extends RuntimeException {public function getAwsErrorCode():string{return 'NoSuchKey';}}
final class Config {
    public static object $s3;
    public static bool $embeddingFail=false;
    public static function getBedrockRuntime(array $options=[]):object {
        return new class {public function invokeModel(array $input):array {
            if(Config::$embeddingFail)throw new RuntimeException('embedding provider unavailable');
            return ['body'=>json_encode(['embedding'=>array_fill(0,1024,0.1),'inputTextTokenCount'=>6])];
        }};
    }
    public static function getS3():object{return self::$s3;}
    public static function getBucket():string{return 'configured-bucket';}
}
Config::$s3=new class {
    public array $objects=['Data/codigo.txt'=>"Primera linea\nImporte verificable 41837\nUltima linea"];
    public bool $fail=false;
    public function headObject(array $p):array{if($this->fail)throw new FixtureMissing('missing original');$b=$this->objects[$p['Key']]??throw new FixtureMissing('missing');return ['ETag'=>'"'.hash('sha256',$b).'"','ContentLength'=>strlen($b)];}
    public function getObject(array $p):array{$b=$this->objects[$p['Key']]??throw new FixtureMissing('missing');if(isset($p['SaveAs']))file_put_contents($p['SaveAs'],$b);return ['Body'=>$b];}
    public function putObject(array $p):array{$this->objects[$p['Key']]=$p['Body'];return [];}
};
$n=0;function pipelineCheck(bool $ok,string $label):void{global $n;$n++;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$service=DriveAnalysisService::production($db);
$first=$service->analyze(1,1,1);
pipelineCheck($first['ok']&&$first['chunks']>0,'Actual selected-file pipeline preserves text despite active embedding without model');
pipelineCheck(!empty($first['warnings'])&&!empty($first['lexical_rag_available']),'Unconfigured embeddings reported as warning, literal retrieval available');
$chunk=$db->query("SELECT content_preview,source_ids FROM SessionContextBlocks WHERE block_type='file_chunk' LIMIT 1")->fetch_assoc();
pipelineCheck(str_contains($chunk['content_preview'],'41837')&&(json_decode($chunk['source_ids'],true)['locator']['line_start']??0)===1,'Actual extractor and indexer persist original evidence and line locator');
pipelineCheck((int)$db->query('SELECT COUNT(*) n FROM EmbeddingJobs')->fetch_assoc()['n']===0,'No fake successful or pending embedding job without a configured model');

if(idx_command_exists('pdftotext')){
    $objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 6 0 R >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 7 0 R >>','<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
    foreach(['Portada','Importe convenio 88317']as$text){$stream='BT /F1 12 Tf 72 720 Td ('.$text.') Tj ET';$objects[]='<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream";}
    $pdf="%PDF-1.4\n";$offsets=[];foreach($objects as$i=>$o){$offsets[]=strlen($pdf);$pdf.=($i+1)." 0 obj\n$o\nendobj\n";}$xref=strlen($pdf);$pdf.="xref\n0 8\n0000000000 65535 f \n";foreach($offsets as$o)$pdf.=sprintf('%010d 00000 n ',$o)."\n";$pdf.="trailer\n<< /Size 8 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    Config::$s3->objects['Data/convenio.pdf']=$pdf;
    $result=$service->analyze(1,1,2);
    pipelineCheck($result['ok']&&$result['chunks']>0,'Real digital PDF passes production selection, extraction and SQL index pipeline');
    $found=false;$r=$db->query("SELECT content_preview,source_ids FROM SessionContextBlocks WHERE s3_path='Data/convenio.pdf' AND block_type='file_chunk'");while($row=$r->fetch_assoc()){if(str_contains($row['content_preview'],'88317'))$found=(json_decode($row['source_ids'],true)['locator']['page']??0)===2;}
    pipelineCheck($found,'PDF actual amount is stored with original page 2');
    $evidence=(new AttachmentContextRepository($db,null))->retrieve(1,1,'88317','rag',null,null);
    pipelineCheck(str_contains($evidence['context'],'88317')&&str_contains($evidence['context'],'página 2'),'Actual RAG retrieval returns the PDF amount and verifiable page without embeddings');
    pipelineCheck(isset(Config::$s3->objects['Data/convenio.pdf.michat-u1.analysis.v1.json']),'Actual analysis sidecar persisted beside original PDF');
}elseif(getenv('DOCUMENT_REQUIRE_PDF')==='1')throw new RuntimeException('Required pdftotext missing');
else echo "SKIP actual PDF pipeline: pdftotext missing\n";

// Missing AI configuration table must not disable extraction or reuse stale user configs.
$db->query('DROP TEMPORARY TABLE UserAIAgentConfigs');
Config::$s3->objects['Data/codigo.txt'].="\nNueva revision 77331";
$GLOBALS['AI_AGENT_CONFIGS']=['embedding_main'=>['is_active'=>1,'model_id'=>'stale-private-model']];
$result=$service->analyze(1,1,1);
pipelineCheck($result['ok']&&$result['chunks']>0,'Missing optional AI configuration cannot erase literal evidence');
pipelineCheck($GLOBALS['AI_AGENT_CONFIGS']===[],'Failed runtime load clears stale model configuration');
pipelineCheck(!empty($result['warnings']),'Configuration failure remains visible as warning');
// Queue availability is optional for saving/replacing literal text, too.
$db->query(str_replace('VIRTUAL NOT NULL','VIRTUAL',$sql));
$db->query("INSERT INTO UserAIAgentConfigs(scope,agent_key,display_name,model_id,is_active) VALUES('global','embedding_main','Embedding','amazon.titan-embed-text-v2:0',1)");
$db->query('DROP TEMPORARY TABLE EmbeddingJobs');
Config::$s3->objects['Data/codigo.txt'].="\nRevision sin cola 66331";
$result=$service->analyze(1,1,1);
pipelineCheck($result['ok']&&$result['chunks']>0&&!empty($result['warnings']),'Unavailable embedding queue cannot roll back a replacement literal index');
$chunks=$db->query("SELECT content_preview FROM SessionContextBlocks WHERE block_type='file_chunk' AND s3_path='Data/codigo.txt'")->fetch_all(MYSQLI_ASSOC);
pipelineCheck(str_contains(implode(' ',array_column($chunks,'content_preview')),'66331'),'Newest original content remains persisted when queue cleanup and enqueue fail');
// Existing queue and valid model still produce completed vectors; provider
// failures retain both the literal index and retryable jobs.
preg_match('/CREATE TABLE IF NOT EXISTS `EmbeddingJobs` \(.*?\) ENGINE=.*?;/s',$schema,$queueSchema);
$db->query(str_replace(['CREATE TABLE IF NOT EXISTS','utf8mb4_0900_ai_ci'],['CREATE TEMPORARY TABLE','utf8mb4_unicode_ci'],$queueSchema[0]));
Config::$s3->objects['Data/codigo.txt'].="\nRevision vectorizable 55331";
$result=$service->analyze(1,1,1);
pipelineCheck($result['ok']&&(int)$db->query("SELECT COUNT(*) n FROM EmbeddingJobs WHERE status='completed'")->fetch_assoc()['n']>0,'Valid model and queue still complete actual embedding jobs');
$row=$db->query("SELECT embedding_json FROM SessionContextBlocks WHERE block_type='file_chunk' AND s3_path='Data/codigo.txt' LIMIT 1")->fetch_assoc();
pipelineCheck(count(json_decode($row['embedding_json'],true)??[])===1024,'Completed embedding persists validated vector dimensions');
Config::$embeddingFail=true;Config::$s3->objects['Data/codigo.txt'].="\nRevision con proveedor caido 44331";
$result=$service->analyze(1,1,1);
pipelineCheck($result['ok']&&!empty($result['warnings'])&&(int)$db->query("SELECT COUNT(*) n FROM EmbeddingJobs WHERE status='pending' AND error_message IS NOT NULL")->fetch_assoc()['n']>0,'Provider failure preserves text and actual pending retry jobs');
Config::$s3->fail=true;$result=$service->analyze(1,1,1);
pipelineCheck(!$result['ok']&&($result['error_code']??'')==='source_missing'&&($result['stage']??'')==='source_verification','Real source failure classified at original-read stage');
pipelineCheck(preg_match('/^AK-[a-f0-9]{12}$/',$result['error_id']??'')===1&&str_contains($result['error'],$result['error_id']),'Error reference returned to user for server log correlation');
$state=json_decode($db->query('SELECT meta FROM ChatSessions WHERE id_=1')->fetch_assoc()['meta'],true)['knowledge_file_states']['1'];
pipelineCheck($state['error_id']===$result['error_id']&&$state['status']==='failed','Failure reference and stage persist without marking success');
echo "OK $n production selection pipeline checks\n";
