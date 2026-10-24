<?php
declare(strict_types=1);
require_once __DIR__.'/AttachmentRequestService.php';
require_once __DIR__.'/DerivedFileContent.php';
require_once __DIR__.'/ProcessingUsageMeter.php';
require_once __DIR__.'/AttachmentProgressReporter.php';

/** Durable phase checkpoints; named locks release automatically after a worker crash. */
final class AttachmentProcessingService
{
    public function __construct(private mysqli $db,private $clients=null){}
    public function available():bool
    {
        try{if($this->db->query('SELECT id_ FROM AttachmentProcessingJobs LIMIT 0')===false||$this->db->query('SELECT id_ FROM AttachmentProcessingCosts LIMIT 0')===false)throw new mysqli_sql_exception('Processing schema unavailable',$this->db->errno);return true;}
        catch(mysqli_sql_exception $e){if($e->getCode()===1146)return false;throw $e;}
    }
    public static function complex(string $name):bool
    {
        return in_array(strtolower(pathinfo($name,PATHINFO_EXTENSION)),array_merge(idx_extractable_extensions(),['jpg','jpeg','png','webp','gif','mp3','mp4','wav','flac','ogg','amr','webm','m4a','opus','mov','avi','mkv']),true);
    }
    public function plan(int $user,int $session,string $query,string $trace,bool $dryRun=false):array
    {
        $access=new DocumentAccessRepository($this->db);$owned=$access->session($user,$session);
        if(!$owned||$owned['status']!=='open')throw new RuntimeException('Conversación no autorizada');
        $meta=json_decode((string)$owned['meta'],true)?:[];$ids=array_slice(array_map('intval',(array)($meta['knowledge_file_ids']??[])),0,100);
        $extra=$ids?' OR id_ IN ('.implode(',',$ids).')':'';$pattern='Data/Chat/Uploads/'.$user.'/%/'.$session.'/';
        $q=$this->db->prepare("SELECT id_,Nombre FROM FileS3 WHERE user_id_=? AND Found=1 AND AccessType<>'secure' AND (Ruta LIKE ?$extra) LIMIT 101");
        $q->bind_param('is',$user,$pattern);$q->execute();$files=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        $files=array_values(array_filter($files,static function($file)use($access,$user,$session){try{$access->requireFile($user,$session,(int)$file['id_']);return true;}catch(Throwable){return false;}}));
        $plan=AttachmentRequestService::plan($query,$files,(int)($meta['last_requested_attachment_id']??0));$jobs=[];
        if(!$plan['requested']||$plan['strategy']==='metadata')return [];
        foreach($plan['files'] as $file)if(self::complex($file['Nombre']))$jobs[]=$dryRun?$file:$this->enqueue($user,$session,(int)$file['id_'],$trace);
        return $jobs;
    }
    public function enqueue(int $user,int $session,int $id,string $trace=''):array
    {
        if(!$this->available())throw new RuntimeException('Aplica fase14_attachment_processing para preparar archivos complejos');
        $file=(new DocumentAccessRepository($this->db))->requireFile($user,$session,$id);
        $source=(new AnalysisSidecarStore($this->client('S3'),Config::getBucket()))->identity($file);
        $revision=AnalysisSidecarStore::revisionKey($source);
        $q=$this->db->prepare("INSERT INTO AttachmentProcessingJobs(user_id_,session_id_,files3_id,revision_hash,source_etag,trace_id) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id_=LAST_INSERT_ID(id_),trace_id=IF(VALUES(trace_id)<>'',VALUES(trace_id),trace_id)");
        $q->bind_param('iiisss',$user,$session,$id,$revision,$source['etag'],$trace);$q->execute();$jobId=(int)$q->insert_id;$q->close();
        $job=$this->job($user,$jobId);
        if(in_array($job['status'],['blocked','failed'],true)){
            $q=$this->db->prepare("UPDATE AttachmentProcessingJobs SET status='pending',attempts=0,error_message=NULL,available_at=UTC_TIMESTAMP() WHERE id_=?");$q->bind_param('i',$jobId);$q->execute();$q->close();$job=$this->job($user,$jobId);
        }
        if(in_array($job['status'],['ready','partial'],true)){
            try{aiRuntimeLoad($this->db,$user);$activeModel=aiAgentActive('embedding_main',false)?aiAgentModel('embedding_main',''):'';}catch(Throwable){$activeModel='';}
            $checkpoint=json_decode((string)$job['checkpoint_json'],true)?:[];
            if($activeModel!==''&&$activeModel!==($checkpoint['embedding_model']??'')){
                $q=$this->db->prepare("UPDATE AttachmentProcessingJobs SET status='pending',phase='embeddings',error_message=NULL,available_at=UTC_TIMESTAMP() WHERE id_=?");$q->bind_param('i',$jobId);$q->execute();$q->close();$job=$this->job($user,$jobId);
            }
        }
        (new ProcessingUsageMeter($this->db,$job))->record('S3','headObject',['s3.head_request'=>1],'completed',bin2hex(random_bytes(16)));
        return $job;
    }
    public function job(int $user,int $id):array
    {
        $q=$this->db->prepare('SELECT * FROM AttachmentProcessingJobs WHERE id_=? AND user_id_=?');$q->bind_param('ii',$id,$user);$q->execute();$row=$q->get_result()->fetch_assoc();$q->close();
        if(!$row)throw new RuntimeException('Tarea no autorizada');
        (new DocumentAccessRepository($this->db))->requireFile($user,(int)$row['session_id_'],(int)$row['files3_id']);
        return $row;
    }
    public static function publicJob(array $job):array
    {
        $state=json_decode((string)($job['checkpoint_json']??''),true)?:[];
        return ['id'=>(int)$job['id_'],'session_id'=>(int)$job['session_id_'],'file_id'=>(int)$job['files3_id'],'status'=>$job['status'],'phase'=>$job['phase'],'message'=>$job['error_message']??self::label($job['phase']),
            'evidence_kind'=>$state['evidence_kind']??null,'reused'=>$state['reused']??false,'chunks'=>$state['chunks']??0];
    }
    public static function label(string $phase):string
    {
        return ['extract'=>'Leyendo formato y buscando JSON del origen','index'=>'Preparando fragmentos con localizadores','embeddings'=>'Generando embeddings por lotes','semantic'=>'Generando análisis semántico','persist'=>'Guardando JSON del origen y copia del chat','ready'=>'Contenido preparado para responder'][$phase]??$phase;
    }
    public function tick(int $user,int $id):array
    {
        $job=$this->job($user,$id);
        if(in_array($job['status'],['ready','partial','failed','blocked'],true)||strtotime($job['available_at'].' UTC')>time())return $job;
        $access=new DocumentAccessRepository($this->db);$file=$access->requireFile($user,(int)$job['session_id_'],(int)$job['files3_id']);$original=$access->original($file)?:$file;
        $lock='michat_processing:'.$user.':'.$original['id_'];
        $q=$this->db->prepare('SELECT GET_LOCK(?,0) held');$q->bind_param('s',$lock);$q->execute();$held=(int)$q->get_result()->fetch_assoc()['held']===1;$q->close();if(!$held)return $job;
        $progress=new AttachmentProgressReporter($this->db,$job['trace_id'],$user,(int)$job['session_id_']);
        try{
            $job=$this->job($user,$id);if(in_array($job['status'],['ready','partial','failed','blocked'],true))return $job;
            $this->update($id,$job['phase'],'running',json_decode((string)$job['checkpoint_json'],true)?:[]);
            $meter=new ProcessingUsageMeter($this->db,$job);$s3=$meter->wrap($this->client('S3'),'S3');$store=new AnalysisSidecarStore($s3,Config::getBucket());
            require_once dirname(__DIR__).'/SessionAttachmentKnowledgeService.php';
            $workingSource=$store->identity($file);if(!hash_equals($job['source_etag'],$workingSource['etag']))throw new RuntimeException('La revisión de trabajo cambió');
            $source=$store->identity($original);
            if($original['id_']!==$file['id_']){
                $ref=(json_decode($file['Metadatos'],true)?:[])['drive_source']??[];
                if(($ref['etag']??'')!==$source['etag'])throw new RuntimeException('El original cambió; selecciona su nueva revisión');
            }
            $derived=new DerivedFileContent($this->db,$s3,Config::getBucket(),fn($service)=>$meter->wrap($this->client($service),$service));
            $phase=$job['phase'];$checkpoint=json_decode((string)$job['checkpoint_json'],true)?:[];
            $progress('job_'.$id.'_'.$phase.'_started','started',self::label($phase),['file_id'=>(int)$file['id_'],'job_id'=>$id]);
            $doc=$derived->read($original,$source);
            if($phase==='extract'){
                if($doc)$doc['reused']=true;
                $ext=strtolower(pathinfo($file['Nombre'],PATHINFO_EXTENSION));
                if(!$doc&&in_array($ext,['jpg','jpeg','png','gif','webp'],true)){
                    require_once dirname(__DIR__).'/SessionImageKnowledgeService.php';
                    $runtime=$meter->wrap($this->client('BedrockRuntime'),'Bedrock');
                    (new SessionImageKnowledgeService($this->db,true,$runtime,$s3))->process($user,(int)$job['session_id_'],(int)$file['id_'],false);
                    $summary=$this->summary((int)$job['session_id_'],$workingSource);
                    if(!$summary)throw new RuntimeException('La visión no produjo información recuperable');
                    $doc=['extracted'=>['content'=>$summary,'ext'=>'txt','extractor'=>'bedrock-visual-description','source_sha256'=>null,'truncated'=>false],'evidence_kind'=>'visual_description','summary'=>$summary,'vectors'=>[],'reused'=>false];
                }
                $doc??=$derived->extract($original,$file,$source);
                $derived->save($original,$source,$doc,$file);
                $checkpoint=['evidence_kind'=>$doc['evidence_kind'],'reused'=>!empty($doc['reused']),'partial'=>!empty($doc['extracted']['truncated'])];
                $this->update($id,'index','pending',$checkpoint);
            }else{
                if(!$doc)throw new RuntimeException('Falta el checkpoint JSON verificado de extracción');
                $data=$doc['extracted'];$data['source_etag']=$workingSource['etag'];$data['s3_key']=$workingSource['key'];$data['evidence_kind']=$doc['evidence_kind'];$data['segments']=$doc['segments']??[];
                $runtime=$meter->wrap($this->client('BedrockRuntime'),'Bedrock');
                $knowledge=new SessionAttachmentKnowledgeService($this->db,true,$runtime,$s3);
                if($phase==='index'){
                    $result=$knowledge->indexDerived($user,(int)$job['session_id_'],(int)$file['id_'],$data);$checkpoint['chunks']=$result['chunks'];
                    aiRuntimeLoad($this->db,$user);$model=aiAgentModel('embedding_main','');
                    foreach($this->blocks((int)$job['session_id_'],$workingSource) as $block){
                        $v=$doc['vectors'][hash('sha256',$block['content_preview'])]??null;
                        if($model!==''&&($doc['embedding_model']??'')===$model&&is_array($v)&&$v&&count($v)<=4096&&count(array_filter($v,static fn($x)=>!is_numeric($x)||!is_finite((float)$x)))===0){
                            $json=json_encode($v,JSON_THROW_ON_ERROR);$q=$this->db->prepare('UPDATE SessionContextBlocks SET embedding_json=?,embedding_model=? WHERE id_=?');$q->bind_param('ssi',$json,$model,$block['id_']);$q->execute();$q->close();
                        }
                    }
                    $this->update($id,'embeddings','pending',$checkpoint);
                }elseif($phase==='embeddings'){
                    $embedding=$knowledge->embedPending($user,(int)$job['session_id_'],(int)$file['id_'],4);
                    $checkpoint['embedding_model']=$embedding['model'];$this->update($id,$embedding['done']?'semantic':'embeddings','pending',$checkpoint);
                }elseif($phase==='semantic'){
                    $summary=$doc['summary']??'';if(!$summary)$summary=$this->summary((int)$job['session_id_'],$workingSource);
                    if(!$summary){$knowledge->summarizeDerived($user,(int)$job['session_id_'],(int)$file['id_'],$data);$summary=$this->summary((int)$job['session_id_'],$workingSource);}
                    if(!$summary)throw new RuntimeException('El análisis semántico no produjo información');
                    if(!$this->summary((int)$job['session_id_'],$workingSource)){
                        $meta=json_encode(['files3_id'=>(int)$file['id_'],'filename'=>$file['Nombre'],'source_etag'=>$workingSource['etag'],'type'=>'semantic_summary','extractor'=>'verified-derived-json','evidence_kind'=>$doc['evidence_kind']],JSON_THROW_ON_ERROR);$tokens=(int)ceil(mb_strlen($summary)/4);
                        $q=$this->db->prepare("INSERT INTO SessionContextBlocks(session_id_,block_type,content_preview,s3_path,source_ids,is_locked,token_count) VALUES(?,'file',?,?,?,1,?)");$q->bind_param('isssi',$job['session_id_'],$summary,$workingSource['key'],$meta,$tokens);$q->execute();$q->close();
                    }
                    $doc['summary']=$summary;$derived->save($original,$source,$doc,$file);$this->update($id,'persist','pending',$checkpoint);
                }elseif($phase==='persist'){
                    $doc['vectors']=[];$bytes=0;
                    foreach($this->blocks((int)$job['session_id_'],$workingSource) as $block){
                        $v=json_decode((string)$block['embedding_json'],true);$bytes+=strlen((string)$block['embedding_json']);
                        if($bytes<=4194304&&is_array($v))$doc['vectors'][hash('sha256',$block['content_preview'])]=$v;
                    }
                    $doc['vectors_cache_partial']=$bytes>4194304;$doc['embedding_model']=$checkpoint['embedding_model']??'';
                    $derived->save($original,$source,$doc,$file);
                    // Persist/import normal analysis and Q&A sibling using verified SQL chunks.
                    $analysis=(new DriveAnalysisService($this->db,new AnalysisSidecarStore($s3,Config::getBucket())))->analyze($user,(int)$job['session_id_'],(int)$file['id_'],false,false);
                    if(!$analysis['ok']||empty($analysis['sidecar_saved']))throw new RuntimeException($analysis['error']??'No se pudo guardar el JSON de análisis del origen');
                    (new FileKnowledgeState($this->db))->save($user,(int)$job['session_id_'],(int)$file['id_'],['status'=>!empty($checkpoint['partial'])?'partial':'ready','message'=>!empty($checkpoint['partial'])?'Extracción parcial: solo se puede responder sobre el contenido recuperado.':'Contenido derivado, chunks, embeddings y análisis disponibles.','source_etag'=>$workingSource['etag'],'evidence_kind'=>$doc['evidence_kind'],'job_id'=>$id]);
                    if(($doc['evidence_kind']??'')==='audio_transcript'&&!empty($doc['provider_started_here'])){
                        $seconds=(float)($doc['duration_seconds']??0);
                        $meter->record('Transcribe','transcription_result',$seconds>0?['transcribe.standard_batch_second'=>max(15,ceil($seconds))]:['transcribe.duration_unknown'=>1],'completed',(string)($doc['raw_json_key']??$source['key']));
                    }
                    $this->update($id,'ready',!empty($checkpoint['partial'])?'partial':'ready',$checkpoint);
                }else throw new RuntimeException('Fase desconocida');
            }
            $progress('job_'.$id.'_'.$phase.'_done','completed','Etapa del archivo completada',['job_id'=>$id,'phase'=>$phase]);
        }catch(AttachmentProviderPending|PdfOcrPendingException $e){
            $this->update($id,'extract','waiting',json_decode((string)$job['checkpoint_json'],true)?:[],$e->getMessage(),15);
            $progress('job_'.$id.'_provider_wait','waiting','Esperando resultado de AWS',['job_id'=>$id]);
        }catch(Throwable $e){
            $attempts=(int)$job['attempts']+1;$blocked=(bool)preg_match('/configur|Activa|desactivad|Falta.*model|Falta.*cliente/i',$e->getMessage());
            $missingReader=$e instanceof DocumentProcessingException&&$e->reason==='missing_reader';
            $blocked=$blocked||$missingReader;
            $terminal=$blocked||$attempts>=5;
            $state=FileKnowledgeState::reportFailure($e,'processing_job',['user_id'=>$user,'session_id'=>(int)$job['session_id_'],'file_id'=>(int)$job['files3_id']]);
            $q=$this->db->prepare('UPDATE AttachmentProcessingJobs SET attempts=? WHERE id_=?');$q->bind_param('ii',$attempts,$id);$q->execute();$q->close();
            $this->update($id,$job['phase'],$blocked?'blocked':($terminal?'failed':'retry'),json_decode((string)$job['checkpoint_json'],true)?:[],$missingReader?$state['message']:($blocked?'Falta configuración del servicio o modelo requerido. Revisa los ajustes de IA y reintenta la tarea.':$state['message']),min(60,5*$attempts));
            $progress('job_'.$id.'_failed_'.$attempts,$terminal?'error':'waiting','El trabajo requiere revisión o reintento',['job_id'=>$id,'stage'=>$job['phase']]);
        }finally{$q=$this->db->prepare('SELECT RELEASE_LOCK(?)');$q->bind_param('s',$lock);$q->execute();$q->close();}
        return $this->job($user,$id);
    }
    private function update(int $id,string $phase,string $status,array $state,?string $error=null,int $delay=0):void
    {
        $json=json_encode($state,JSON_THROW_ON_ERROR);$q=$this->db->prepare('UPDATE AttachmentProcessingJobs SET phase=?,status=?,checkpoint_json=?,error_message=?,available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND) WHERE id_=?');$q->bind_param('ssssii',$phase,$status,$json,$error,$delay,$id);$q->execute();$q->close();
    }
    private function blocks(int $session,array $source):array
    {
        $q=$this->db->prepare("SELECT id_,content_preview,source_ids,embedding_json,embedding_model FROM SessionContextBlocks WHERE session_id_=? AND s3_path=? AND block_type='file_chunk' ORDER BY id_");$q->bind_param('is',$session,$source['key']);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        return array_values(array_filter($rows,static fn($r)=>(json_decode($r['source_ids'],true)['source_etag']??'')===$source['etag']));
    }
    private function summary(int $session,array $source):string
    {
        $q=$this->db->prepare("SELECT content_preview,source_ids FROM SessionContextBlocks WHERE session_id_=? AND s3_path=? AND block_type='file' ORDER BY id_ DESC");$q->bind_param('is',$session,$source['key']);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        foreach($rows as $r)if((json_decode($r['source_ids'],true)['source_etag']??'')===$source['etag'])return $r['content_preview'];return '';
    }
    private function client(string $service):object
    {
        if(is_callable($this->clients))return ($this->clients)($service);
        $method=['BedrockRuntime'=>'getBedrockRuntime','S3'=>'getS3','Transcribe'=>'getTranscribe','Textract'=>'getTextract'][$service]??'';
        if($method&&method_exists('Config',$method))return Config::$method();
        if($service==='Transcribe'&&method_exists('Config','awsClientConfig'))return new Aws\TranscribeService\TranscribeServiceClient(Config::awsClientConfig());
        throw new RuntimeException('Falta configurar cliente '.$service);
    }
}
