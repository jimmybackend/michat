<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Memory/DocumentAccessRepository.php';
require_once __DIR__.'/AnalysisSidecarStore.php';
require_once __DIR__.'/FileKnowledgeState.php';

/** Explicit content requests or manual actions start analysis; attaching never invokes this service. */
final class DriveAnalysisService
{
    private DocumentAccessRepository $access;
    public function __construct(private mysqli $db,private AnalysisSidecarStore $store,private $analyzer=null,private $indexer=null)
    { $this->access=new DocumentAccessRepository($db); }
    public static function production(mysqli $db): self
    {
        return new self($db,new AnalysisSidecarStore(Config::getS3(),Config::getBucket()));
    }
    public function analyze(int $user,int $session,int $fileId,bool $summarize=true,bool $withEmbeddings=true,?callable $progress=null): array
    {
        $states=new FileKnowledgeState($this->db);
        $authorized=$this->access->requireFile($user,$session,$fileId);
        $lockFile=$this->access->original($authorized);
        $lockId=(int)($lockFile['id_']??$fileId);
        $stage='lock';
        try {
            return $this->locked($user,$lockId,function() use($user,$session,$fileId,$states,&$stage,$summarize,$withEmbeddings,$progress) {
                $stage='access_recheck';
                $file=$this->access->requireFile($user,$session,$fileId);
                $stage='state_persistence';
                $states->save($user,$session,$fileId,['status'=>'extracting','message'=>'Archivo vinculado. Comprobando contenido e índice.']);
                $warnings=[];$state=null;
                self::progress($progress,'verifying','started','Comprobando revisión y análisis guardado');
                $stage='source_verification';
                $source=$this->store->identity($file);
                $origin=$this->access->original($file);$cacheFile=$origin?:$file;$cacheSource=$source;
                if($origin) {
                    $cacheSource=$this->store->identity($origin);$ref=(json_decode((string)$file['Metadatos'],true)?:[])['drive_source'];
                    if(!hash_equals((string)$ref['etag'],$cacheSource['etag']) || !hash_equals((string)$ref['copy_etag'],$source['etag'])) throw new RuntimeException('La revisión de trabajo o del original cambió; selecciona la revisión que deseas analizar');
                }
                try { $state=$this->store->read($cacheFile,$cacheSource); }
                catch(Throwable $e) { error_log('analysis cache read: '.$e->getMessage());$warnings[]='No se pudo leer el JSON auxiliar; se intentará preparar el contenido sin sobrescribirlo.'; }
                $analysis=$state['analysis'] ?? null;$reused=is_array($analysis);
                $image=in_array(strtolower(pathinfo($file['Nombre'],PATHINFO_EXTENSION)),['jpg','jpeg','png','webp','gif'],true);
                $stage='index_lookup';
                $chunks=$this->chunks($session,$source);
                if (!$chunks && !empty($analysis['chunks'])) {
                    self::progress($progress,'reusing','started','Reutilizando fragmentos del JSON verificado');
                    try { $this->importChunks($session,$source,$origin?self::remapChunks($analysis['chunks'],$cacheSource,$source):$analysis['chunks']);$chunks=$this->chunks($session,$source); }
                    catch(Throwable $e) { error_log('cached chunks: '.$e->getMessage());$warnings[]='No se pudo reutilizar el índice JSON; se comprobará el original.'; }
                }
                // Literal evidence is independent of summarization, embeddings and sidecar persistence.
                if (!$chunks && !$image) {
                    self::progress($progress,'extracting','started','Extrayendo contenido y preparando fragmentos');
                    $stage='extraction_index';
                    if (is_callable($this->indexer)) $indexed=($this->indexer)($user,$session,$fileId);
                    else {
                        require_once dirname(__DIR__).'/SessionAttachmentKnowledgeService.php';
                        $indexed=(new SessionAttachmentKnowledgeService($this->db))->index($user,$session,$fileId,$withEmbeddings);
                    }
                    $warnings=array_merge($warnings,(array)($indexed['warnings'] ?? []));
                    $chunks=$this->chunks($session,$source);
                    if (!$chunks) throw new RuntimeException('La extracción no produjo fragmentos verificables');
                    self::progress($progress,'extracted','completed','Texto extraído y fragmentos guardados',['chunks'=>count($chunks)]);
                }
                if($chunks) self::progress($progress,'evidence_available','completed','Fragmentos verificados disponibles',['chunks'=>count($chunks),'reused'=>$reused]);
                $stage='summary';
                if (!$reused) {
                    $block=$this->existingSummary($session,$source);
                    if (!$block && ($summarize || $image)) {
                        try {
                            if (is_callable($this->analyzer)) ($this->analyzer)($user,$session,$fileId);
                            else {
                                require_once dirname(__DIR__).'/SessionAttachmentKnowledgeService.php';
                                require_once dirname(__DIR__).'/SessionImageKnowledgeService.php';
                                if ($image) (new SessionImageKnowledgeService($this->db))->process($user,$session,$fileId,$withEmbeddings);
                                else (new SessionAttachmentKnowledgeService($this->db))->semantic($user,$session,$fileId);
                            }
                            $block=$this->existingSummary($session,$source);
                        } catch(Throwable $e) {
                            if (!$chunks) throw $e;
                            error_log('selected file summary: '.$e->getMessage());
                            $warnings[]='El texto ya se puede consultar; no se completó el resumen semántico.';
                        }
                    }
                    $meta=$block?(json_decode((string)$block['source_ids'],true) ?: []):[];
                    $analysis=['source'=>$source,'created_at'=>gmdate('c'),'summary'=>$block?(string)$block['content_preview']:'',
                        'coverage'=>$chunks?'extracted_text':($image?'visual_description':'unknown'),'summary_partial'=>!empty($meta['truncated']),
                        'extractor'=>$meta['extractor'] ?? ($chunks[0]['meta']['extractor'] ?? 'unknown'),
                        'model'=>$meta['semantic_model'] ?? $meta['visual_model'] ?? null,'kind'=>'generated_summary_not_original_evidence','interactions'=>[]];
                }
                $analysis['chunks']=$chunks;
                if (array_filter($chunks,static fn($chunk)=>!empty($chunk['meta']['truncated']))) $analysis['coverage']='partial';
                $stage='source_recheck';
                $file=$this->access->requireFile($user,$session,$fileId);
                if (AnalysisSidecarStore::revisionKey($this->store->identity($file))!==AnalysisSidecarStore::revisionKey($source)) throw new RuntimeException('El original cambió durante el procesamiento; reintenta');
                $stage='summary_persistence';
                if (!empty($analysis['summary'])) $this->import($session,$file,['source'=>$source,'revision_key'=>AnalysisSidecarStore::revisionKey($source)],$analysis);
                $saved=false;
                if ($state!==null) {
                    try {
                        $cached=$analysis;$cached['source']=$cacheSource;
                        if($origin) $cached['chunks']=self::remapChunks($chunks,$source,$cacheSource);
                        $this->store->write($cacheFile,$state,$cached);$saved=true;
                    }
                    catch(Throwable $e) { error_log('analysis cache write: '.$e->getMessage());$warnings[]='El contenido está disponible, pero no se pudo guardar el JSON auxiliar.'; }
                }
                $message=$chunks?'Texto extraído y fragmentos disponibles para responder con citas.':'Descripción visual disponible; no equivale a texto literal del original.';
                $stage='state_persistence';
                $states->save($user,$session,$fileId,['status'=>$chunks?'text_ready':'summary_ready','message'=>$message,'source_etag'=>$source['etag'],
                    'chunks'=>count($chunks),'semantic_available'=>!empty($analysis['summary']),'sidecar_saved'=>$saved,'warnings'=>$warnings]);
                self::progress($progress,'prepared','completed','Preparación del archivo terminada',['chunks'=>count($chunks),'reused'=>$reused,'sidecar_saved'=>$saved]);
                return ['ok'=>true,'file_id'=>$fileId,'filename'=>$file['Nombre'],'reused'=>$reused,'summary'=>$analysis['summary'],
                    'coverage'=>$analysis['coverage'],'summary_partial'=>!empty($analysis['summary_partial']),'chunks'=>count($chunks),'lexical_rag_available'=>(bool)$chunks,'semantic_available'=>!empty($analysis['summary']),
                    'message'=>$message,'warnings'=>$warnings,'interaction_count'=>count($analysis['interactions'] ?? []),
                    'sidecar_name'=>basename(AnalysisSidecarStore::key($cacheFile)),'sidecar_saved'=>$saved];
            });
        } catch(Throwable $e) {
            if ($e instanceof DocumentProcessingException) $stage=$e->stage;
            $state=FileKnowledgeState::reportFailure($e,$stage,['user_id'=>$user,'session_id'=>$session,'file_id'=>$fileId]);
            try { $states->save($user,$session,$fileId,$state); }
            catch(Throwable $saveError) { error_log('FILE_ANALYSIS_STATE '.($state['error_id'] ?? 'ocr_pending').' '.get_class($saveError).' code='.$saveError->getCode()); }
            self::progress($progress,'preparation_failed',$e instanceof PdfOcrPendingException?'waiting':'error',$e instanceof PdfOcrPendingException?'OCR del archivo en proceso':'No se pudo completar la preparación',['stage'=>$stage,'error_code'=>$state['error_code'],'message'=>$state['message']]);
            return ['ok'=>false,'pending'=>$e instanceof PdfOcrPendingException,'retry_after'=>15,'file_id'=>$fileId,'error'=>$state['message'],'error_code'=>$state['error_code'],'error_id'=>$state['error_id'] ?? null,'stage'=>$stage];
        }
    }
    private static function progress(?callable $progress,string $key,string $status,string $title,array $details=[]): void
    {
        if(!$progress)return;
        try{$progress($key,$status,$title,$details);}catch(Throwable $e){error_log('ATTACHMENT_PROGRESS '.get_class($e));}
    }

    private static function remapChunks(array $chunks,array $from,array $to): array
    {
        foreach($chunks as &$chunk) {
            $m=$chunk['meta']??[];$text=$chunk['content']??'';
            if(!is_string($text) || (int)($m['files3_id']??0)!==$from['file_id'] || ($m['source_etag']??'')!==$from['etag']
                || !hash_equals(hash('sha256',$text),(string)($chunk['sha256']??''))) throw new RuntimeException('El índice auxiliar no pertenece a esta revisión');
            $chunk['meta']['files3_id']=$to['file_id'];$chunk['meta']['source_etag']=$to['etag'];$chunk['meta']['filename']=$to['filename'];
        }
        unset($chunk);return $chunks;
    }

    private function chunks(int $session,array $source): array
    {
        $s=$this->db->prepare("SELECT content_preview,source_ids FROM SessionContextBlocks WHERE session_id_=? AND s3_path=? AND block_type='file_chunk' ORDER BY id_ ASC");
        $s->bind_param('is',$session,$source['key']);$s->execute();$r=$s->get_result();$out=[];
        while($row=$r->fetch_assoc()) {
            $meta=json_decode((string)$row['source_ids'],true) ?: [];
            if ((int)($meta['files3_id'] ?? 0)!==$source['file_id'] || ($meta['source_etag'] ?? '')!==$source['etag']) continue;
            $out[]=['content'=>$row['content_preview'],'meta'=>$meta,'sha256'=>hash('sha256',(string)$row['content_preview'])];
        }
        $s->close();return $out;
    }
    private function importChunks(int $session,array $source,array $chunks): void
    {
        if(count($chunks)>1000) throw new RuntimeException('Índice JSON excedido');
        $this->db->begin_transaction();
        try {
            $seen=[];
            foreach($chunks as $chunk) {
                $meta=$chunk['meta'] ?? [];$text=$chunk['content'] ?? null;$ordinal=(int)($meta['chunk'] ?? 0);
                if (!is_string($text) || $text==='' || strlen($text)>32000 || !hash_equals(hash('sha256',$text),(string)($chunk['sha256'] ?? ''))
                    || (int)($meta['files3_id'] ?? 0)!==$source['file_id'] || ($meta['source_etag'] ?? '')!==$source['etag']
                    || (int)($meta['total'] ?? 0)!==count($chunks) || $ordinal<1 || $ordinal>count($chunks) || isset($seen[$ordinal])) throw new RuntimeException('Fragmento JSON inválido o de otra revisión');
                $seen[$ordinal]=true;$meta['filename']=$source['filename'];$json=json_encode($meta,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$tokens=(int)ceil(mb_strlen($text)/4);
                $s=$this->db->prepare("INSERT INTO SessionContextBlocks(session_id_,block_type,content_preview,s3_path,source_ids,is_locked,token_count) VALUES (?,'file_chunk',?,?,?,1,?)");
                $s->bind_param('isssi',$session,$text,$source['key'],$json,$tokens);$s->execute();$s->close();
            }
            $this->db->commit();
        } catch(Throwable $e) {$this->db->rollback();throw $e;}
    }
    private function existingSummary(int $session,array $source): ?array
    {
        $s=$this->db->prepare("SELECT content_preview,source_ids FROM SessionContextBlocks WHERE session_id_=? AND s3_path=? AND block_type='file' ORDER BY id_ DESC");
        $s->bind_param('is',$session,$source['key']);$s->execute();$r=$s->get_result();$found=null;
        while ($row=$r->fetch_assoc()) {
            $meta=json_decode((string)$row['source_ids'],true) ?: [];
            if ((int)($meta['files3_id'] ?? 0)===$source['file_id'] && ($meta['source_etag'] ?? '')===$source['etag'] && trim((string)$row['content_preview'])!=='') { $found=$row;break; }
        }
        $s->close();return $found;
    }
    private function import(int $session,array $file,array $state,array $analysis): void
    {
        $summary=(string)($analysis['summary'] ?? '');
        if ($summary==='' || !is_array($analysis['interactions'] ?? null)) throw new RuntimeException('JSON de análisis incompleto');
        $existing=$this->existingSummary($session,$state['source']);
        if ($existing && (string)$existing['content_preview']===$summary) return;
        $hash=hash('sha256',$summary);$key=$state['source']['key'];
        $meta=json_encode(['filename'=>$file['Nombre'],'files3_id'=>(int)$file['id_'],'type'=>'semantic_summary',
            'source_etag'=>$state['source']['etag'],'semantic_model'=>$analysis['model'] ?? null,
            'extractor'=>$analysis['extractor'] ?? 'sidecar','truncated'=>!empty($analysis['summary_partial'])||($analysis['coverage'] ?? 'partial')==='partial',
            'sidecar_revision'=>$state['revision_key'],'summary_sha256'=>$hash],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $tokens=(int)ceil(mb_strlen($summary)/4);
        $s=$this->db->prepare("INSERT INTO SessionContextBlocks (session_id_,block_type,content_preview,s3_path,source_ids,is_locked,token_count) VALUES (?,'file',?,?,?,1,?)");
        $s->bind_param('isssi',$session,$summary,$key,$meta,$tokens);$s->execute();$s->close();
    }
    /** Only files actually selected by ContextBuilder and already analyzed get Q&A. */
    public function recordTurn(int $user,int $session,int $question,int $answer,object $bundle): array
    {
        $ids=[];
        foreach ($bundle->selectedItems('attachments') as $item) {
            $id=(int)($item->metadata['files3_id'] ?? 0);
            if ($id>0 && !empty($item->metadata['source_etag'])) $ids[$id]=(string)$item->metadata['source_etag'];
        }
        if (!$ids) return [];
        $s=$this->db->prepare("SELECT id_,role,content,model_id FROM ChatMessages WHERE session_id_=? AND user_id_=? AND id_ IN (?,?)");
        $s->bind_param('iiii',$session,$user,$question,$answer);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();$messages=[];
        foreach ($rows as $row) $messages[(int)$row['id_']]=$row;
        if (($messages[$question]['role'] ?? '')!=='user' || ($messages[$answer]['role'] ?? '')!=='assistant') throw new RuntimeException('Interacción no verificable');
        $errors=[];
        foreach ($ids as $id=>$sourceEtag) {
            try {
                $authorized=$this->access->requireFile($user,$session,$id);$lockFile=$this->access->original($authorized);
                $this->locked($user,(int)($lockFile['id_']??$id),function() use($user,$session,$id,$question,$answer,$messages,$sourceEtag) {
                    $file=$this->access->requireFile($user,$session,$id);$origin=$this->access->original($file);
                    $workingSource=$this->store->identity($file);
                    if($workingSource['etag']!==$sourceEtag) throw new RuntimeException('La copia usada para responder cambió');
                    $cacheFile=$origin?:$file;$state=$this->store->read($cacheFile);
                    $expected=$origin?((json_decode((string)$file['Metadatos'],true)?:[])['drive_source']['etag']??''):$sourceEtag;
                    if ($state['source']['etag']!==$expected) throw new RuntimeException('La revisión usada para responder ya cambió; no se mezclaron análisis');
                    if (!is_array($state['analysis'])) return; // no implicit analysis/LLM during a turn
                    $analysis=$state['analysis'];$event=$session.':'.$question.':'.$answer;
                    if (isset($analysis['interactions'][$event])) return;
                    $analysis['interactions'][$event]=['session_id'=>$session,'question_id'=>$question,'answer_id'=>$answer,
                        'question'=>$messages[$question]['content'],'answer'=>$messages[$answer]['content'],'model'=>$messages[$answer]['model_id'],
                        'working_file_id'=>$id,'working_key'=>idx_build_s3_key($file),
                        'created_at'=>gmdate('c'),'note'=>'Respuesta generada con contexto de este archivo; puede incluir otras fuentes. No es texto literal del original.'];
                    $this->access->requireFile($user,$session,$id);
                    $this->store->write($cacheFile,$state,$analysis);
                });
            } catch (Throwable $e) { error_log('analysis sidecar '.$id.': '.$e->getMessage()); $errors[]='No se pudo actualizar el JSON del archivo #'.$id.'. La interacción permanece en el chat.'; }
        }
        return $errors;
    }
    private function locked(int $user,int $file,callable $work): mixed
    {
        $name='michat_analysis:'.$user.':'.$file;
        $s=$this->db->prepare('SELECT GET_LOCK(?,3) acquired');$s->bind_param('s',$name);$s->execute();$ok=(int)$s->get_result()->fetch_assoc()['acquired']===1;$s->close();
        if (!$ok) throw new RuntimeException('Este archivo ya está siendo analizado; reintenta en unos segundos');
        try { return $work(); }
        finally { $s=$this->db->prepare('SELECT RELEASE_LOCK(?)');$s->bind_param('s',$name);$s->execute();$s->close(); }
    }
}
