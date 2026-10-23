<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Memory/DocumentAccessRepository.php';
require_once __DIR__.'/AnalysisSidecarStore.php';

/** Explicit selection starts analysis. Catalog search never instantiates this service. */
final class DriveAnalysisService
{
    private DocumentAccessRepository $access;
    public function __construct(private mysqli $db,private AnalysisSidecarStore $store,private $analyzer=null)
    { $this->access=new DocumentAccessRepository($db); }
    public static function production(mysqli $db): self
    {
        return new self($db,new AnalysisSidecarStore(Config::getS3(),Config::getBucket()));
    }
    public function analyze(int $user,int $session,int $fileId): array
    {
        return $this->locked($user,$fileId,function() use($user,$session,$fileId) {
            $file=$this->access->requireFile($user,$session,$fileId);
            $state=$this->store->read($file); $analysis=$state['analysis']; $reused=is_array($analysis);
            if (!$reused) {
                $block=$this->existingSummary($session,$state['source']);
                if (!$block) {
                    if (is_callable($this->analyzer)) ($this->analyzer)($user,$session,$fileId);
                    else {
                        require_once dirname(__DIR__).'/SessionAttachmentKnowledgeService.php';
                        require_once dirname(__DIR__).'/SessionImageKnowledgeService.php';
                        if (SessionImageKnowledgeService::supportsFilename((string)$file['Nombre'])) (new SessionImageKnowledgeService($this->db))->process($user,$session,$fileId);
                        else (new SessionAttachmentKnowledgeService($this->db))->semantic($user,$session,$fileId);
                    }
                    $block=$this->existingSummary($session,$state['source']);
                }
                if (!$block) throw new RuntimeException('No se generó un resumen verificable para la revisión actual');
                $meta=json_decode((string)$block['source_ids'],true) ?: [];
                $analysis=['source'=>$state['source'],'created_at'=>gmdate('c'),'summary'=>(string)$block['content_preview'],
                    'coverage'=>!empty($meta['truncated'])?'partial':(($meta['type'] ?? '')==='visual_summary'?'visual_description':'extracted_text'),'extractor'=>$meta['extractor'] ?? 'unknown',
                    'model'=>$meta['semantic_model'] ?? $meta['visual_model'] ?? null,'kind'=>'generated_summary_not_original_evidence','interactions'=>[]];
                $this->access->requireFile($user,$session,$fileId); // live permission check immediately before publication
                $this->store->write($file,$state,$analysis);
            }
            $file=$this->access->requireFile($user,$session,$fileId);
            if (AnalysisSidecarStore::revisionKey($this->store->identity($file))!==$state['revision_key']) throw new RuntimeException('El original cambió; vuelve a seleccionar el archivo');
            $this->import($session,$file,$state,$analysis);
            return ['ok'=>true,'file_id'=>$fileId,'filename'=>$file['Nombre'],'reused'=>$reused,
                'summary'=>$analysis['summary'],'coverage'=>$analysis['coverage'],'interaction_count'=>count($analysis['interactions'] ?? []),
                'sidecar_name'=>basename(AnalysisSidecarStore::key($file)),'sidecar_saved'=>true];
        });
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
            'extractor'=>$analysis['extractor'] ?? 'sidecar','truncated'=>($analysis['coverage'] ?? 'partial')==='partial',
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
                $this->locked($user,$id,function() use($user,$session,$id,$question,$answer,$messages,$sourceEtag) {
                    $file=$this->access->requireFile($user,$session,$id);$state=$this->store->read($file);
                    if ($state['source']['etag']!==$sourceEtag) throw new RuntimeException('La revisión usada para responder ya cambió; no se mezclaron análisis');
                    if (!is_array($state['analysis'])) return; // no implicit analysis/LLM during a turn
                    $analysis=$state['analysis'];$event=$session.':'.$question.':'.$answer;
                    if (isset($analysis['interactions'][$event])) return;
                    $analysis['interactions'][$event]=['session_id'=>$session,'question_id'=>$question,'answer_id'=>$answer,
                        'question'=>$messages[$question]['content'],'answer'=>$messages[$answer]['content'],'model'=>$messages[$answer]['model_id'],
                        'created_at'=>gmdate('c'),'note'=>'Respuesta generada con contexto de este archivo; puede incluir otras fuentes. No es texto literal del original.'];
                    $this->access->requireFile($user,$session,$id);
                    $this->store->write($file,$state,$analysis);
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
