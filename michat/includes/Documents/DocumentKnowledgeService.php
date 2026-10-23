<?php
declare(strict_types=1);
require_once __DIR__.'/DocumentKnowledgeRuntime.php';
require_once __DIR__.'/DocumentKnowledgeRepository.php';
require_once dirname(__DIR__).'/Memory/DocumentAccessRepository.php';

final class DocumentKnowledgeAccessChanged extends RuntimeException {}

/** One immutable source revision, one resumable job and reusable chunks across conversations. */
final class DocumentKnowledgeService
{
    public const MAX_EXTRACTED_BYTES=8388608;
    private DocumentKnowledgeRepository $repo;
    private DocumentAccessRepository $access;
    public function __construct(private mysqli $db,private DocumentKnowledgeRuntime $runtime)
    {
        $this->repo=new DocumentKnowledgeRepository($db);
        $this->access=new DocumentAccessRepository($db);
    }
    public static function enabled(): bool { return getenv('MICHAT_DOCUMENT_KNOWLEDGE_ENABLED')==='1'; }

    public function enqueue(int $userId,int $sessionId,int $fileId): array
    {
        if (!$this->repo->available()) throw new RuntimeException('Falta la migración fase13_document_knowledge');
        $file=$this->access->requireFile($userId,$sessionId,$fileId);
        $ext=strtolower(pathinfo($file['Nombre'],PATHINFO_EXTENSION));
        if (!in_array($ext,array_merge(idx_text_extensions(),idx_extractable_extensions()),true)) throw new InvalidArgumentException('Formato no soportado por la cola documental');
        $description=$this->runtime->describe($file);
        $etag=(string)($description['ETag']??'');$key=idx_build_s3_key($file);
        if ($etag==='' || strlen($etag)>255) throw new RuntimeException('No se pudo verificar la revisión S3');
        $model=$this->runtime->embeddingModel($userId);
        $revision=hash('sha256',json_encode([$userId,$fileId,$key,$etag,$ext,'document-pipeline-v1'],JSON_THROW_ON_ERROR));
        return $this->repo->transaction(function() use($userId,$sessionId,$fileId,$etag,$key,$ext,$revision,$model) {
            $s=$this->repo->run('INSERT INTO DocumentKnowledgeSources(files3_id,user_id_,revision_hash,s3_key,source_etag,format,embedding_model) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id_=LAST_INSERT_ID(id_)','iisssss',$fileId,$userId,$revision,$key,$etag,$ext,$model);
            $sourceId=(int)$s->insert_id;$s->close();
            $source=$this->repo->one('SELECT id_,embedding_model,chunks_done,chunks_total,extracted_content IS NULL AS needs_extraction FROM DocumentKnowledgeSources WHERE id_=? FOR UPDATE','i',$sourceId);
            $this->repo->write('INSERT INTO DocumentKnowledgeJobs(source_id,available_at) VALUES (?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE id_=id_','i',$sourceId);
            $job=$this->repo->one('SELECT *,lease_until>UTC_TIMESTAMP() AS live FROM DocumentKnowledgeJobs WHERE source_id=? FOR UPDATE','i',$sourceId);
            $busy=$job['status']==='running' && $job['live'];
            if (!$busy && ($source['embedding_model']!==$model || in_array($job['status'],['failed','blocked'],true))) {
                $phase=$source['needs_extraction']?'extract':((int)$source['chunks_done']<(int)$source['chunks_total']?'chunks':'embeddings');
                $this->repo->write("UPDATE DocumentKnowledgeSources SET embedding_model=?,state='queued' WHERE id_=?",'si',$model,$sourceId);
                $this->repo->write("UPDATE DocumentKnowledgeJobs SET phase=?,status='pending',failure_count=0,lease_owner=NULL,lease_until=NULL,error_message=NULL,available_at=UTC_TIMESTAMP() WHERE id_=?",'si',$phase,(int)$job['id_']);
            }
            $current=$this->repo->job((int)$job['id_']);
            $this->repo->event($current,'enqueue',['reused_revision'=>(int)$source['chunks_done']>0]);
            return ['ok'=>true,'file_id'=>$fileId,'session_id'=>$sessionId,'queued'=>$current['status']!=='completed','central_source_id'=>$sourceId,'central_job_id'=>(int)$job['id_'],'coverage'=>$current['coverage'],'ready_for_attachment_rag'=>$current['state']==='ready' && $current['status']==='completed','lexical_rag_available'=>(int)$current['chunks_total']>0 && $current['chunks_total']===$current['chunks_done'],'index'=>['ok'=>true,'chunks'=>(int)$current['chunks_done'],'truncated'=>$current['coverage']==='partial']];
        });
    }

    /** One bounded batch. The CLI may stop after any tick without losing committed progress. */
    public function tick(?int $jobId=null,int $batchSize=75,int $embeddingBatch=4): ?array
    {
        if (!$this->repo->available()) throw new RuntimeException('Falta la migración fase13_document_knowledge');
        $job=$this->repo->claim($jobId);
        if (!$job) return null;
        try {
            $file=$this->access->file((int)$job['user_id_'],(int)$job['files3_id']);
            if (!$file || idx_build_s3_key($file)!==$job['s3_key'] || strtolower(pathinfo($file['Nombre'],PATHINFO_EXTENSION))!==$job['format']) throw new DocumentKnowledgeAccessChanged('El archivo fue eliminado, bloqueado o cambió de propietario/ruta');
            $head=$this->runtime->describe($file);
            if (!hash_equals($job['source_etag'],(string)($head['ETag']??''))) throw new DocumentKnowledgeAccessChanged('La revisión S3 cambió; solicita indexar la nueva revisión');
            match($job['phase']) {
                'extract'=>$this->extract($job,$file),
                'chunks'=>$this->chunks($job,max(1,min(200,$batchSize))),
                'embeddings'=>$this->embeddings($job,max(1,min(16,$embeddingBatch))),
                default=>throw new RuntimeException('Fase documental desconocida'),
            };
        } catch (DocumentKnowledgeLeaseLost $e) {
            return ['job_id'=>(int)$job['id_'],'status'=>'lease_lost'];
        } catch (Throwable $e) {
            try {
                $this->repo->fenced($job,function() use($job,$e) {
                    $failures=(int)$job['failure_count']+1;
                    $terminal=$failures>=5 || $e instanceof DocumentKnowledgeAccessChanged;
                    $this->repo->write('UPDATE DocumentKnowledgeJobs SET failure_count=? WHERE id_=?','ii',$failures,(int)$job['id_']);
                    $this->repo->write('UPDATE DocumentKnowledgeSources SET state=? WHERE id_=?','si',$terminal?'failed':'processing',(int)$job['source_id']);
                    $this->repo->event($job,'failed_batch',['phase'=>$job['phase'],'failure_count'=>$failures,'terminal'=>$terminal,'exception'=>get_class($e)]);
                    $this->repo->release($job,$job['phase'],$terminal?'failed':'retry',mb_substr($e->getMessage(),0,800),$terminal?0:min(900,5*(2**min(7,$failures))));
                });
            } catch (DocumentKnowledgeLeaseLost $lost) { return ['job_id'=>(int)$job['id_'],'status'=>'lease_lost']; }
        }
        $result=$this->repo->job((int)$job['id_']);
        unset($result['lease_owner']);
        return $result;
    }

    private function extract(array $job,array $file): void
    {
        $data=$this->runtime->extract($file);
        $text=(string)($data['content']??'');
        if ($text==='' || trim($text)==='') throw new RuntimeException('El archivo no produjo texto; puede requerir OCR o un adaptador externo');
        $packet=$this->repo->one('SELECT @@max_allowed_packet AS packet');
        $budget=min(self::MAX_EXTRACTED_BYTES,max(0,(int)$packet['packet']-65536));
        if (strlen($text)>$budget) throw new RuntimeException('Texto extraído supera el presupuesto seguro de '.(int)$budget.' bytes; no se guardó un prefijo incompleto');
        if (!hash_equals($job['source_etag'],(string)($data['source_etag']??''))) throw new DocumentKnowledgeAccessChanged('La revisión S3 cambió durante la extracción');
        $hash=(string)($data['source_sha256']??'');
        if (!preg_match('/^[a-f0-9]{64}$/',$hash)) throw new RuntimeException('Falta hash verificable de la fuente');
        $chunks=idx_located_chunks($text,$job['format'],IDX_CHUNK_MAX_LEN);
        if (!$chunks) throw new RuntimeException('No se generaron fragmentos');
        $this->repo->fenced($job,function() use($job,$data,$text,$hash,$chunks) {
            $this->repo->write("UPDATE DocumentKnowledgeSources SET extracted_content=?,source_sha256=?,text_sha256=?,extracted_chars=?,chunks_total=?,extractor=?,coverage=?,state='processing' WHERE id_=?",'sssiissi',$text,$hash,hash('sha256',$text),mb_strlen($text),count($chunks),(string)($data['extractor']??'unknown'),!empty($data['truncated'])?'partial':'text_extracted',(int)$job['source_id']);
            $this->repo->event($job,'extracted',['chars'=>mb_strlen($text),'chunks'=>count($chunks),'partial'=>!empty($data['truncated'])]);
            $this->repo->release($job,'chunks');
        });
    }

    private function chunks(array $job,int $batchSize): void
    {
        $source=$this->repo->one('SELECT extracted_content,text_sha256 FROM DocumentKnowledgeSources WHERE id_=?','i',(int)$job['source_id']);
        $text=(string)$source['extracted_content'];
        if (!hash_equals((string)$source['text_sha256'],hash('sha256',$text))) throw new RuntimeException('Checkpoint de texto no coincide con su hash');
        $all=idx_located_chunks($text,$job['format'],IDX_CHUNK_MAX_LEN);
        if (count($all)!==(int)$job['chunks_total']) throw new RuntimeException('El adaptador cambió; checkpoint de chunks incompatible');
        $cursor=(int)$job['cursor_ordinal'];
        $batch=array_slice($all,$cursor,$batchSize);
        if (!$batch && $cursor!==(int)$job['chunks_total']) throw new RuntimeException('Cursor documental inválido');
        $this->repo->fenced($job,function() use($job,$batch,$cursor) {
            foreach ($batch as $i=>$chunk) {
                $ordinal=$cursor+$i+1;$locator=json_encode($chunk['locator'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
                $this->repo->write('INSERT INTO DocumentKnowledgeChunks(source_id,ordinal,content,content_sha256,locator_json) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE id_=id_','iisss',(int)$job['source_id'],$ordinal,$chunk['content'],$chunk['sha256'],$locator);
                $existing=$this->repo->one('SELECT content_sha256,locator_json FROM DocumentKnowledgeChunks WHERE source_id=? AND ordinal=?','ii',(int)$job['source_id'],$ordinal);
                if (!hash_equals($chunk['sha256'],$existing['content_sha256']) || $locator!==$existing['locator_json']) throw new RuntimeException('Conflicto de idempotencia en fragmento');
            }
            $next=$cursor+count($batch);
            $this->repo->write('UPDATE DocumentKnowledgeSources SET chunks_done=? WHERE id_=?','ii',$next,(int)$job['source_id']);
            $this->repo->write('UPDATE DocumentKnowledgeJobs SET cursor_ordinal=? WHERE id_=?','ii',$next,(int)$job['id_']);
            $this->repo->event($job,'chunks_batch',['from'=>$cursor+1,'through'=>$next]);
            $this->repo->release($job,$next===(int)$job['chunks_total']?'embeddings':'chunks');
        });
    }

    private function embeddings(array $job,int $batchSize): void
    {
        $model=(string)$job['embedding_model'];
        if ($model==='') {
            $this->repo->fenced($job,function() use($job) {
                $this->repo->write("UPDATE DocumentKnowledgeSources SET state='indexed' WHERE id_=?",'i',(int)$job['source_id']);
                $this->repo->event($job,'embedding_disabled');
                $this->repo->release($job,'embeddings','blocked','embedding_main está desactivado; el texto ya se puede consultar');
            });return;
        }
        $batch=$this->repo->all('SELECT id_,content FROM DocumentKnowledgeChunks WHERE source_id=? AND (embedding_json IS NULL OR embedding_model IS NULL OR embedding_model<>?) ORDER BY ordinal LIMIT '.$batchSize,'is',(int)$job['source_id'],$model);
        foreach ($batch as $chunk) {
            $this->repo->renew($job);
            $data=$this->runtime->embed((int)$job['user_id_'],$chunk['content'],$model);
            $vector=$data['embedding']??[];
            if (!is_array($vector) || !$vector || count($vector)>4096) throw new RuntimeException('Embedding inválido');
            foreach ($vector as $v) if (!is_numeric($v) || !is_finite((float)$v)) throw new RuntimeException('Embedding no numérico o no finito');
            $this->repo->fenced($job,function() use($job,$chunk,$model,$vector,$data) {
                $this->repo->write('UPDATE DocumentKnowledgeChunks SET embedding_json=?,embedding_model=? WHERE id_=? AND source_id=?','ssii',json_encode($vector,JSON_THROW_ON_ERROR),$model,(int)$chunk['id_'],(int)$job['source_id']);
                $this->repo->event($job,'embedding_saved',['chunk_id'=>(int)$chunk['id_'],'model'=>$model,'input_tokens'=>(int)($data['inputTokens']??0)]);
            });
        }
        $this->repo->fenced($job,function() use($job,$model) {
            $counts=$this->repo->one('SELECT COUNT(*) AS total,SUM(embedding_json IS NULL OR embedding_model IS NULL OR embedding_model<>?) AS pending FROM DocumentKnowledgeChunks WHERE source_id=?','si',$model,(int)$job['source_id']);
            $done=(int)$counts['pending']===0 && (int)$counts['total']===(int)$job['chunks_total'] && (int)$counts['total']>0;
            $this->repo->write('UPDATE DocumentKnowledgeSources SET state=? WHERE id_=?','si',$done?($job['coverage']==='partial'?'partial':'ready'):'indexed',(int)$job['source_id']);
            if ($done) $this->repo->event($job,'completed',['coverage'=>$job['coverage']]);
            $this->repo->release($job,'embeddings',$done?'completed':'pending');
        });
    }
}
