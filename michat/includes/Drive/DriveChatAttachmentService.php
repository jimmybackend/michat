<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Memory/DocumentAccessRepository.php';

/** Explicit selection creates a private, revision-pinned conversation working copy. */
final class DriveChatAttachmentService
{
    public function __construct(private mysqli $db,private object $s3,private string $bucket) {}
    public function attach(int $user,int $session,int $originalId): array
    {
        if($this->bucket==='' || $this->bucket==='yours3bucket') throw new DocumentProcessingException('working_copy','bucket_configuration','Falta configurar el bucket real antes de crear la copia de trabajo.');
        $access=new DocumentAccessRepository($this->db);
        $owned=$access->session($user,$session);$original=$access->file($user,$originalId);
        if(!$owned || $owned['status']!=='open' || !$original) throw new RuntimeException('Conversación o archivo no autorizado');
        // Selecting an existing working attachment does not create a copy of a copy.
        if(idx_file_belongs_to_chat_session($original,$user,$session)) {$this->focus($user,$session,(int)$original['id_']);return $original;}
        $catalogOriginal=$access->original($original);
        if($catalogOriginal) {$original=$catalogOriginal;$originalId=(int)$original['id_'];}
        $lock='michat_drive_attach:'.$user.':'.$session.':'.$originalId;
        $q=$this->db->prepare('SELECT GET_LOCK(?,3) acquired');$q->bind_param('s',$lock);$q->execute();$locked=(int)$q->get_result()->fetch_assoc()['acquired']===1;$q->close();
        if(!$locked) throw new RuntimeException('El archivo se está adjuntando; reintenta en unos segundos');
        try {
            $key=idx_build_s3_key($original);$head=$this->s3->headObject(['Bucket'=>$this->bucket,'Key'=>$key]);$etag=(string)($head['ETag']??'');
            if($etag==='') throw new RuntimeException('No se pudo verificar la revisión del original');
            if((int)($head['ContentLength']??0)>67108864) throw new RuntimeException('Archivo excede 64 MiB; requiere procesamiento por lotes.');
            $owned=$access->session($user,$session);$meta=json_decode((string)$owned['meta'],true)?:[];
            $mapKey=$originalId.':'.hash('sha256',$etag);
            $existing=$access->file($user,(int)($meta['drive_working_files'][$mapKey]??0));
            if($existing && idx_file_belongs_to_chat_session($existing,$user,$session)) {
                try {
                    $current=$this->s3->headObject(['Bucket'=>$this->bucket,'Key'=>idx_build_s3_key($existing)]);
                    $ref=(json_decode((string)$existing['Metadatos'],true)?:[])['drive_source']??[];
                    if(hash_equals((string)($ref['copy_etag']??''),(string)($current['ETag']??''))) {$this->focus($user,$session,(int)$existing['id_']);return $existing;}
                } catch(Throwable $e) {
                    $code=method_exists($e,'getAwsErrorCode')?$e->getAwsErrorCode():'';
                    if(!in_array($code,['NoSuchKey','NotFound','404'],true)) throw $e;
                }
            }
            $ruta='Data/Chat/Uploads/'.$user.'/'.gmdate('Y/m/d').'/'.$session.'/';
            $safe=idx_safe_filename((string)$original['Nombre']);$ext=pathinfo($safe,PATHINFO_EXTENSION);
            $name=substr(pathinfo($safe,PATHINFO_FILENAME),0,80).($ext!==''?'.'.substr($ext,0,12):'');
            $copyKey=$ruta.'drive_'.$originalId.'_'.bin2hex(random_bytes(12)).'_'.$name;
            $copySource=$this->bucket.'/'.str_replace('%2F','/',rawurlencode($key));
            if(!empty($head['VersionId'])&&$head['VersionId']!=='null') $copySource.='?versionId='.rawurlencode((string)$head['VersionId']);
            $this->s3->copyObject(['Bucket'=>$this->bucket,'Key'=>$copyKey,'CopySource'=>$copySource,'CopySourceIfMatch'=>$etag]);
            $copyHead=$this->s3->headObject(['Bucket'=>$this->bucket,'Key'=>$copyKey]);
            $after=$this->s3->headObject(['Bucket'=>$this->bucket,'Key'=>$key]);
            if(!hash_equals($etag,(string)($after['ETag']??'')) || (int)$copyHead['ContentLength']!==(int)$head['ContentLength'] || empty($copyHead['ETag'])) throw new RuntimeException('El original cambió durante la copia; reintenta');
            if(!$access->file($user,$originalId)) throw new RuntimeException('Acceso revocado durante la copia');
            $provenance=['format'=>'michat.drive-working-copy','version'=>1,'file_id'=>$originalId,'owner_id'=>$user,'key'=>$key,'etag'=>$etag,'copy_etag'=>(string)$copyHead['ETag'],'analysis_key'=>$key.'.michat-u'.$user.'.analysis.v1.json'];
            $json=json_encode(['drive_source'=>$provenance,'chat_session_id'=>$session,'created_at'=>gmdate('c')],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $this->db->begin_transaction();
            try {
                $q=$this->db->prepare('SELECT meta,status FROM ChatSessions WHERE id_=? AND user_id_=? FOR UPDATE');$q->bind_param('ii',$session,$user);$q->execute();$row=$q->get_result()->fetch_assoc();$q->close();
                if(!$row || $row['status']!=='open' || !$access->file($user,$originalId)) throw new RuntimeException('Conversación cerrada o acceso revocado');
                $meta=json_decode((string)$row['meta'],true)?:[];$ids=array_values(array_unique(array_map('intval',(array)($meta['knowledge_file_ids']??[]))));
                if(count(array_diff($ids,[$originalId]))>=100) throw new RuntimeException('Máximo 100 documentos por conversación');
                $size=(int)$copyHead['ContentLength'];$filename=(string)$original['Nombre'];
                $q=$this->db->prepare("INSERT INTO FileS3(user_id_,Nombre,Ruta,Encriptado,Tamano,Found,AccessType,Metadatos) VALUES(?,?,?,?,?,1,'normal',?)");
                $q->bind_param('isssis',$user,$filename,$ruta,$copyKey,$size,$json);$q->execute();$copyId=(int)$q->insert_id;$q->close();
                $meta['knowledge_file_ids']=array_values(array_unique(array_merge(array_diff($ids,[$originalId]),[$copyId])));
                $meta['drive_working_files'][$mapKey]=$copyId;
                $meta['active_attachment_id']=$copyId;
                $meta['attachment_processing_policy']='on_request';
                $encoded=json_encode($meta,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
                $q=$this->db->prepare('UPDATE ChatSessions SET meta=? WHERE id_=? AND user_id_=?');$q->bind_param('sii',$encoded,$session,$user);$q->execute();$q->close();$this->db->commit();
            }catch(Throwable $e){$this->db->rollback();throw $e;}
            return $access->requireFile($user,$session,$copyId);
        } finally {$q=$this->db->prepare('SELECT RELEASE_LOCK(?)');$q->bind_param('s',$lock);$q->execute();$q->close();}
    }
    private function focus(int $user,int $session,int $file): void
    {
        require_once __DIR__.'/ConversationAttachmentService.php';
        (new ConversationAttachmentService($this->db))->change($user,$session,$file,true);
        $q=$this->db->prepare("UPDATE ChatSessions SET meta=JSON_SET(CASE WHEN JSON_VALID(meta) THEN meta ELSE '{}' END,'$.active_attachment_id',?,'$.attachment_processing_policy','on_request') WHERE id_=? AND user_id_=? AND status='open'");
        $q->bind_param('iii',$file,$session,$user);$q->execute();$q->close();
    }

}
