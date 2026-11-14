<?php
declare(strict_types=1);

require_once __DIR__.'/LegalBridgeSessionService.php';
require_once dirname(__DIR__).'/Memory/DocumentAccessRepository.php';
require_once dirname(__DIR__).'/Documents/CentralDocumentIndex.php';
require_once __DIR__.'/LegalBridgeKnowledgeStatus.php';

final class LegalBridgeDocumentService
{
    /** Production uses MiChat S3/central index; callbacks allow AWS-free tests. */
    public function __construct(private mysqli $db,private LegalBridgeSessionService $sessions,private $uploadOverride=null,private $enqueueOverride=null,private $statusOverride=null) {}

    /** Copy-only upload; revisions are immutable S3 objects, never Legal originals. */
    public function attach(string $credential,int $user,int $session,array $input): array
    {
        $this->sessions->assertOwned($credential,$user,$session);
        if (getenv('MICHAT_DOCUMENT_KNOWLEDGE_ENABLED')!=='1') {
            throw new LegalBridgeError('document_pipeline_disabled',503);
        }
        $revision=(string)($input['revision_sha256']??'');
        $content=$input['text']??null;
        if (!is_string($content)||trim($content)===''||strlen($content)>524288
            || !mb_check_encoding($content,'UTF-8')
            || !preg_match('/^[a-f0-9]{64}$/D',$revision)
            || !hash_equals($revision,hash('sha256',$content))) {
            throw new LegalBridgeError('invalid_document_revision',422);
        }
        // Serialize document revision changes with Bedrock turns for this session.
        $lock=LegalBridgeSessionService::chatLock($credential,$user,$session);
        $lq=$this->db->prepare('SELECT GET_LOCK(?,10) AS acquired');
        $lq->bind_param('s',$lock);$lq->execute();
        $acquired=(int)($lq->get_result()->fetch_assoc()['acquired']??0)===1;
        $lq->close();
        if(!$acquired) throw new LegalBridgeError('session_busy',409);
        try {
        $this->db->begin_transaction();
        $temp=null;
        $created=false;
        try {
            $q=$this->db->prepare('SELECT current_revision FROM LegalBridgeSessions WHERE credential_id=? AND user_id_=? AND session_id_=? FOR UPDATE');
            $q->bind_param('sii',$credential,$user,$session);$q->execute();$binding=$q->get_result()->fetch_assoc();$q->close();
            if (!$binding) throw new LegalBridgeError('session_not_found',404);
            $q=$this->db->prepare('SELECT d.files3_id_ FROM LegalBridgeDocuments d JOIN FileS3 f ON f.id_=d.files3_id_ AND f.user_id_=d.user_id_ WHERE d.session_id_=? AND d.user_id_=? AND d.revision_sha256=? LIMIT 1');
            $q->bind_param('iis',$session,$user,$revision);$q->execute();$prior=$q->get_result()->fetch_assoc();$q->close();
            if ($prior) {
                $fileId=(int)$prior['files3_id_'];
                if (!(new DocumentAccessRepository($this->db))->file($user,$fileId)) throw new LegalBridgeError('revision_unavailable',409);
                $this->activate($credential,$user,$session,$revision,$fileId);
                $this->db->commit();
            } else {
            $q=$this->db->prepare('SELECT COUNT(*) AS total FROM LegalBridgeDocuments WHERE session_id_=? AND user_id_=?');
            $q->bind_param('ii',$session,$user);$q->execute();$total=(int)$q->get_result()->fetch_assoc()['total'];$q->close();
            if ($total>=90) throw new LegalBridgeError('revision_limit',409);
            $temp=tempnam(sys_get_temp_dir(),'legal-bridge-');
            if ($temp===false || file_put_contents($temp,$content,LOCK_EX)!==strlen($content)) throw new RuntimeException('temp_write_failed');
            @chmod($temp,0600);
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
            $prefix='Data/Chat/Uploads/'.$user.'/'.$now->format('Y/m/d').'/'.$session.'/';
            $name='legal-'.$revision.'.txt';
            $result=$this->uploadOverride!==null
                ? ($this->uploadOverride)($temp,$name,$prefix,$user,'text/plain; charset=utf-8',strlen($content))
                : $this->productionUpload($temp,$name,$prefix,$user,strlen($content));
            $fileId=(int)($result['id']??0);
            if ($fileId<1) throw new RuntimeException('upload_failed');
            $bytes=strlen($content);
            $q=$this->db->prepare('INSERT INTO LegalBridgeDocuments (session_id_,user_id_,revision_sha256,files3_id_,byte_size) VALUES (?,?,?,?,?)');
            $q->bind_param('iisii',$session,$user,$revision,$fileId,$bytes);$q->execute();$q->close();
            $this->activate($credential,$user,$session,$revision,$fileId);
            $this->db->commit();
            $created=true;
            }
        } catch (Throwable $e) {
            $this->db->rollback();
            if ($e instanceof LegalBridgeError) throw $e;
            throw new LegalBridgeError('document_attach_failed',503);
        } finally {
            if ($temp!==null && is_file($temp)) @unlink($temp);
        }
        // A failed enqueue never falsely reports ready; the object stays private
        // and document.status reports missing index until retried administratively.
        try {
            $queued=$this->enqueueOverride!==null
                ? ($this->enqueueOverride)($user,$session,$fileId)
                : $this->productionEnqueue($user,$session,$fileId);
        } catch(Throwable $e) {
            return ['file_id'=>$fileId,'revision_sha256'=>$revision,'created'=>$created,'status'=>'pending','index_enqueue'=>'failed'];
        }
        return ['file_id'=>$fileId,'revision_sha256'=>$revision,'created'=>$created,'status'=>'pending','index_enqueue'=>!empty($queued['ok'])?'accepted':'failed'];
        } finally {
            $q=$this->db->prepare('SELECT RELEASE_LOCK(?)');
            $q->bind_param('s',$lock);$q->execute();$q->close();
        }
    }

    private function productionUpload(string $temp,string $name,string $prefix,int $user,int $bytes): array
    {
        require_once dirname(__DIR__,2).'/S3Manager.php';
        return (new S3Manager())->uploadFile($temp,$name,$prefix,$user,'text/plain; charset=utf-8',$bytes);
    }

    private function productionEnqueue(int $user,int $session,int $file): array
    {
        require_once dirname(__DIR__).'/SessionAttachmentKnowledgeService.php';
        return (new SessionAttachmentKnowledgeService($this->db))->process($user,$session,$file);
    }

    /** Must run under the locked LegalBridgeSessions transaction. */
    private function activate(string $credential,int $user,int $session,string $revision,int $fileId): void
    {
        $q=$this->db->prepare('SELECT files3_id_ FROM LegalBridgeDocuments WHERE session_id_=? AND user_id_=? AND files3_id_<>?');
        $q->bind_param('iii',$session,$user,$fileId);$q->execute();
        $obsolete=array_map('intval',array_column($q->get_result()->fetch_all(MYSQLI_ASSOC),'files3_id_'));$q->close();
        // Old copies remain physically present but are detached from both the
        // legacy and central attachment retrievers in this session.
        $meta=['attachment_processing_policy'=>'on_request','knowledge_file_ids'=>[$fileId],
            'active_attachment_id'=>$fileId,'detached_file_ids'=>$obsolete,
            'legal_bridge_active_revision'=>$revision];
        $json=json_encode($meta,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $q=$this->db->prepare('UPDATE ChatSessions SET meta=? WHERE id_=? AND user_id_=? AND status=?');
        $open='open';$q->bind_param('siis',$json,$session,$user,$open);$q->execute();$changed=$q->affected_rows;$q->close();
        if ($changed<1) {
            $q=$this->db->prepare("SELECT id_ FROM ChatSessions WHERE id_=? AND user_id_=? AND status='open' AND meta=?");
            $q->bind_param('iis',$session,$user,$json);$q->execute();$same=(bool)$q->get_result()->fetch_assoc();$q->close();
            if (!$same) throw new LegalBridgeError('session_not_open',409);
        }
        $q=$this->db->prepare('UPDATE LegalBridgeSessions SET current_revision=? WHERE credential_id=? AND user_id_=? AND session_id_=?');
        $q->bind_param('ssii',$revision,$credential,$user,$session);$q->execute();$q->close();
    }

    /** Never infer readiness from S3 existence or the session metadata. */
    public function status(string $credential,int $user,int $session): array
    {
        $this->sessions->assertOwned($credential,$user,$session);
        if($this->statusOverride!==null) return ($this->statusOverride)($credential,$user,$session);
        $q=$this->db->prepare('SELECT l.current_revision,d.files3_id_,d.byte_size FROM LegalBridgeSessions l LEFT JOIN LegalBridgeDocuments d ON d.session_id_=l.session_id_ AND d.user_id_=l.user_id_ AND d.revision_sha256=l.current_revision WHERE l.credential_id=? AND l.user_id_=? AND l.session_id_=?');
        $q->bind_param('sii',$credential,$user,$session);$q->execute();$doc=$q->get_result()->fetch_assoc();$q->close();
        if (!$doc||!(int)($doc['files3_id_']??0)) return ['status'=>'pending','coverage'=>'none','revision_sha256'=>$doc['current_revision']??null,'chunks_total'=>0,'chunks_with_embedding'=>0];
        $fileId=(int)$doc['files3_id_'];
        $access=new DocumentAccessRepository($this->db);
        $sessionData=$access->session($user,$session);
        if (!$sessionData || !$access->file($user,$fileId)) throw new LegalBridgeError('document_forbidden',403);
        if (getenv('MICHAT_DOCUMENT_KNOWLEDGE_ENABLED')!=='1') return ['status'=>'pending','coverage'=>'none','revision_sha256'=>$doc['current_revision'],'file_id'=>$fileId];
        $idx=new CentralDocumentIndex($this->db);
        $source=$idx->sources($user,$session,$sessionData,false)[$fileId]??null;
        if(!$source) return ['status'=>'pending','coverage'=>'none','revision_sha256'=>$doc['current_revision'],'file_id'=>$fileId];
        require_once dirname(__DIR__).'/Documents/BedrockDocumentKnowledgeRuntime.php';
        try { $activeModel=(new BedrockDocumentKnowledgeRuntime($this->db))->embeddingModel($user); }
        catch (Throwable $e) { $activeModel=''; }
        $state=$idx->status($source,$activeModel);
        $status=LegalBridgeKnowledgeStatus::map($state,$activeModel,(string)$source['embedding_model']);
        return ['file_id'=>$fileId,'revision_sha256'=>$doc['current_revision'],'status'=>$status,
            'coverage'=>$state['coverage'],'chunks_total'=>$state['chunks_total'],
            'chunks_done'=>$state['chunks_done'],'chunks_with_embedding'=>$state['chunks_with_embedding']];
    }
}
