<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Memory/DocumentAccessRepository.php';

/** Reversible conversation binding only. No original, copy, sidecar or index is deleted. */
final class ConversationAttachmentService
{
    public function __construct(private mysqli $db) {}

    public function change(int $user,int $session,int $id,bool $link): array
    {
        $this->db->begin_transaction();
        try {
            $q=$this->db->prepare('SELECT meta,status FROM ChatSessions WHERE id_=? AND user_id_=? FOR UPDATE');
            $q->bind_param('ii',$session,$user);$q->execute();$row=$q->get_result()->fetch_assoc();$q->close();
            if(!$row||$row['status']!=='open')throw new RuntimeException('Conversación no autorizada');
            $meta=json_decode((string)$row['meta'],true)?:[];
            $ids=array_values(array_unique(array_map('intval',(array)($meta['knowledge_file_ids']??[]))));
            $detached=array_values(array_unique(array_map('intval',(array)($meta['detached_file_ids']??[]))));
            if($link) {
                if(!(new DocumentAccessRepository($this->db))->file($user,$id))throw new RuntimeException('Archivo no autorizado');
                if(!in_array($id,$ids,true)&&count($ids)>=100)throw new RuntimeException('Máximo 100 documentos vinculados');
                $ids=array_values(array_unique([...$ids,$id]));$detached=array_values(array_diff($detached,[$id]));
            } else {
                // A revoked source can still be detached using its owned copy record.
                $q=$this->db->prepare('SELECT user_id_,Ruta FROM FileS3 WHERE id_=? AND user_id_=?');
                $q->bind_param('ii',$id,$user);$q->execute();$file=$q->get_result()->fetch_assoc();$q->close();
                if(!$file||(!idx_file_belongs_to_chat_session($file,$user,$session)&&!in_array($id,$ids,true)&&!in_array($id,$detached,true)))throw new RuntimeException('Archivo fuera de esta conversación');
                if(!in_array($id,$detached,true)&&count($detached)>=1000)throw new RuntimeException('El registro de desvinculación alcanzó su límite seguro');
                $ids=array_values(array_diff($ids,[$id]));$detached=array_values(array_unique([...$detached,$id]));
                foreach(['active_attachment_id','last_requested_attachment_id'] as $field)if((int)($meta[$field]??0)===$id)unset($meta[$field]);
            }
            $meta['knowledge_file_ids']=$ids;$meta['detached_file_ids']=$detached;
            $json=json_encode($meta,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $q=$this->db->prepare('UPDATE ChatSessions SET meta=? WHERE id_=? AND user_id_=?');
            $q->bind_param('sii',$json,$session,$user);$q->execute();$q->close();$this->db->commit();
            return ['ok'=>true,'linked_file_ids'=>$ids,'indexed'=>false,'original_preserved'=>true,'copy_preserved'=>true];
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
}
