<?php
declare(strict_types=1);

/**
 * Persists the user-side chat message that represents a Task created manually
 * from Task Center. Autonomous/system Tasks never use this service.
 */
final class TaskManualChatMessageService
{
    public function __construct(private mysqli $db, private TaskRepository $tasks) {}

    public function ensureOrigin(int $taskId,int $userId,int $sessionId,string $objective,string $taskPublicId): int
    {
        if($taskId<1||$userId<1||$sessionId<1||trim($objective)===''||trim($taskPublicId)==='')throw new InvalidArgumentException('manual_task_origin_invalid');
        $this->db->begin_transaction();
        try{
            $task=$this->tasks->lockOwnedForResponse($taskId,$userId)??throw new TaskNotFoundException('not_found');
            if((string)$task['origin_type']!=='manual'||(int)$task['session_id_']!==$sessionId)throw new TaskValidationException('manual_task_origin_scope_invalid');
            $existing=(int)($task['origin_message_id_']??0);
            if($existing>0){
                if(!$this->validUserMessage($existing,$userId,$sessionId))throw new RuntimeException('manual_task_origin_message_invalid');
                $this->db->commit();
                return$existing;
            }

            $role='user';$type='text';$phase='respond';$primordial=0;
            $meta=json_encode(['source'=>'task_center','task_public_id'=>$taskPublicId],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $stmt=$this->db->prepare("INSERT INTO ChatMessages(session_id_,user_id_,role,content_type,content,meta,is_primordial,phase) VALUES(?,?,?,?,?,?,?,?)");
            if(!$stmt)throw new RuntimeException('database_error');
            $stmt->bind_param('iissssis',$sessionId,$userId,$role,$type,$objective,$meta,$primordial,$phase);
            if(!$stmt->execute())throw new RuntimeException('database_error');
            $messageId=(int)$this->db->insert_id;$stmt->close();
            if($messageId<1)throw new RuntimeException('manual_task_origin_insert_failed');

            $stmt=$this->db->prepare("UPDATE Tasks SET origin_message_id_=? WHERE id_=? AND user_id_=? AND origin_type='manual' AND origin_message_id_ IS NULL");
            if(!$stmt)throw new RuntimeException('database_error');
            $stmt->bind_param('iii',$messageId,$taskId,$userId);
            if(!$stmt->execute())throw new RuntimeException('database_error');
            $affected=$stmt->affected_rows;$stmt->close();
            if($affected!==1)throw new TaskConcurrencyException('manual_task_origin_conflict');

            $this->db->commit();
            return$messageId;
        }catch(Throwable $e){
            $this->db->rollback();
            throw$e;
        }
    }

    private function validUserMessage(int $id,int $userId,int $sessionId): bool
    {
        $stmt=$this->db->prepare("SELECT id_ FROM ChatMessages WHERE id_=? AND user_id_=? AND session_id_=? AND role='user' AND content_type='text' LIMIT 1");
        if(!$stmt)throw new RuntimeException('database_error');
        $stmt->bind_param('iii',$id,$userId,$sessionId);
        if(!$stmt->execute())throw new RuntimeException('database_error');
        $valid=(bool)$stmt->get_result()->fetch_assoc();$stmt->close();
        return$valid;
    }
}
