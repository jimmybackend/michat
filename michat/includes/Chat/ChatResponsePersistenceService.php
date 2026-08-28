<?php
declare(strict_types=1);

/** Persists the single user-visible assistant response of a chat Task. */
final class ChatResponsePersistenceService
{
    public function __construct(private mysqli $db,private TaskRepository $tasks) {}

    public function persist(int $taskId,int $userId,int $sessionId,string $content,string $modelId,string $traceId,array $usage=[],?string $stopReason=null,?int $latencyMs=null):int
    {
        if($taskId<1||$userId<1||$sessionId<1||trim($content)===''||trim($modelId)==='')throw new InvalidArgumentException('chat_response_invalid');
        $this->db->begin_transaction();
        try{
            $task=$this->tasks->lockOwnedForResponse($taskId,$userId)??throw new TaskNotFoundException('not_found');
            if((int)$task['session_id_']!==$sessionId)throw new TaskValidationException('session_invalid');
            $existing=(int)($task['result_message_id_']??0);
            if($existing>0){
                if(!$this->validAssistantMessage($existing,$userId,$sessionId))throw new RuntimeException('task_result_message_invalid');
                $this->db->commit();return$existing;
            }
            $role='assistant';$type='text';$phase='respond';$meta=$traceId!==''?json_encode(['trace_id'=>$traceId],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null;
            $promptTokens=(int)($usage['prompt_tokens']??0);$completionTokens=(int)($usage['completion_tokens']??0);$primordial=0;$parent=null;
            $sql='INSERT INTO ChatMessages (session_id_,user_id_,role,content_type,content,s3_key,mime_type,size_bytes,thumb_s3_key,duration_ms,model_id,stop_reason,prompt_tokens,completion_tokens,latency_ms,meta,is_primordial,phase,parent_msg_id) VALUES (?,?,?,?,?,NULL,NULL,NULL,NULL,NULL,?,?,?,?,?,?,?,?,?)';
            $stmt=$this->db->prepare($sql);if(!$stmt)throw new RuntimeException('database_error');
            $stmt->bind_param('iisssssiiisisi',$sessionId,$userId,$role,$type,$content,$modelId,$stopReason,$promptTokens,$completionTokens,$latencyMs,$meta,$primordial,$phase,$parent);
            if(!$stmt->execute())throw new RuntimeException('database_error');
            $messageId=(int)$this->db->insert_id;$stmt->close();
            if($messageId<1)throw new RuntimeException('chat_response_insert_failed');
            $summary=mb_substr(trim((string)preg_replace('/\s+/u',' ',$content)),0,240);
            if(!$this->tasks->assignResultIfEmpty($taskId,$userId,$messageId,$summary))throw new TaskConcurrencyException('task_result_conflict');
            $this->db->commit();return$messageId;
        }catch(Throwable $e){$this->db->rollback();throw$e;}
    }

    private function validAssistantMessage(int $id,int $userId,int $sessionId):bool
    {
        $stmt=$this->db->prepare("SELECT id_ FROM ChatMessages WHERE id_=? AND user_id_=? AND session_id_=? AND role='assistant' AND content_type='text' LIMIT 1");
        if(!$stmt)throw new RuntimeException('database_error');$stmt->bind_param('iii',$id,$userId,$sessionId);if(!$stmt->execute())throw new RuntimeException('database_error');
        $valid=(bool)$stmt->get_result()->fetch_assoc();$stmt->close();return$valid;
    }
}
