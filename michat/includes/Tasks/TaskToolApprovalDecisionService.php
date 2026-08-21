<?php
declare(strict_types=1);

/** Atomic human decision for one exact, persisted Tool approval proposal. */
final class TaskToolApprovalDecisionService
{
    private TaskStateMachine $taskStates;private TaskStepStateMachine $stepStates;private TaskExecutionStateMachine $executionStates;
    public function __construct(private mysqli$db,?TaskStateMachine$taskStates=null,?TaskStepStateMachine$stepStates=null,?TaskExecutionStateMachine$executionStates=null){$this->taskStates=$taskStates??new TaskStateMachine();$this->stepStates=$stepStates??new TaskStepStateMachine();$this->executionStates=$executionStates??new TaskExecutionStateMachine();}

    /** @return array{task:array{public_id:string,status:string,lock_version:int},step:array{step_key:string,status:string,lock_version:int},decision:string} */
    public function decide(int$userId,string$publicId,string$stepKey,int$stepLock,string$fingerprint,bool$approved):array
    {
        if($userId<1)throw new TaskNotFoundException('not_found');if(preg_match('/^[a-f0-9]{64}$/D',$fingerprint)!==1)throw new TaskValidationException('tool_approval_fingerprint_invalid');
        $this->db->begin_transaction();try{
            $task=$this->one('SELECT * FROM Tasks WHERE public_id=? AND user_id_=? FOR UPDATE','si',[$publicId,$userId])??throw new TaskNotFoundException('not_found');
            if($task['status']!=='waiting_user')throw new TaskTransitionException('tool_approval_not_waiting');
            $step=$this->one('SELECT * FROM TaskSteps WHERE task_id_=? AND step_key=? FOR UPDATE','is',[(int)$task['id_'],$stepKey])??throw new TaskNotFoundException('step_not_found');
            if($step['status']!=='waiting_user'||(int)$task['current_step_id_']!==(int)$step['id_'])throw new TaskTransitionException('tool_approval_not_waiting');
            if((int)$step['lock_version']!==$stepLock)throw new TaskConcurrencyException('step_concurrency_conflict');
            $executions=$this->all("SELECT * FROM TaskExecutions WHERE task_id_=? AND step_id_=? AND status='waiting' ORDER BY id_ FOR UPDATE",'ii',[(int)$task['id_'],(int)$step['id_']]);
            if(count($executions)!==1)throw new TaskTransitionException('tool_approval_execution_invalid');$execution=$executions[0];
            [$checkpoint,$identity,$proposal]=$this->pendingCheckpoint((string)($step['checkpoint_json']??''));
            if($identity->proposalExecutionId!==(int)$execution['id_'])throw new TaskValidationException('tool_approval_identity_context_invalid');
            if(!hash_equals($proposal->fingerprint,$fingerprint))throw new TaskConcurrencyException('tool_approval_fingerprint_conflict');
            $decision=$approved?'approved':'rejected';$checkpoint['tool_approval']['decision']=['status'=>$decision,'fingerprint'=>$proposal->fingerprint];if($approved)$checkpoint['tool_approval']['decision']['consumed']=false;
            $checkpointJson=json_encode($checkpoint,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $executionTo=$approved?'completed':'cancelled';$stepTo=$approved?'ready':'cancelled';$taskTo=$approved?'ready':'cancelled';
            $this->executionStates->assertTransition('waiting',$executionTo);$this->stepStates->assertTransition('waiting_user',$stepTo);$this->taskStates->assertTransition('waiting_user',$taskTo);
            $executionId=(int)$execution['id_'];$s=$this->p("UPDATE TaskExecutions SET status=?,finished_at=NOW(6),worker_id=NULL,lease_token=NULL,lease_expires_at=NULL WHERE id_=? AND status='waiting'");$s->bind_param('si',$executionTo,$executionId);$this->oneUpdate($s,'tool_approval_execution_conflict');
            $stepId=(int)$step['id_'];$taskId=(int)$task['id_'];$s=$this->p("UPDATE TaskSteps SET status=?,checkpoint_json=?,lock_version=lock_version+1 WHERE id_=? AND task_id_=? AND status='waiting_user' AND lock_version=?");$s->bind_param('ssiii',$stepTo,$checkpointJson,$stepId,$taskId,$stepLock);$this->oneUpdate($s,'step_concurrency_conflict');
            $s=$this->p("UPDATE Tasks SET status=?,cancelled_at=IF(?='cancelled',NOW(6),cancelled_at),lock_version=lock_version+1 WHERE id_=? AND user_id_=? AND current_step_id_=? AND status='waiting_user'");$s->bind_param('ssiii',$taskTo,$taskTo,$taskId,$userId,$stepId);$this->oneUpdate($s,'tool_approval_task_conflict');
            $details=json_encode(['decision'=>$decision,'tool_key'=>$proposal->toolKey,'effect'=>$proposal->effect,'safe_summary'=>$proposal->safeSummary,'safe_target'=>$proposal->safeTarget,'fingerprint'=>$proposal->fingerprint],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$eventKey='tool_approval_'.$decision;$summary=$approved?'Tool aprobada por el usuario.':'Tool rechazada por el usuario.';
            $s=$this->p("INSERT INTO TaskEvents(task_id_,step_id_,execution_id_,actor_type,actor_user_id_,event_key,from_status,to_status,summary,details_json) VALUES(?,?,?,'user',?,?,?,?,?,?)");$s->bind_param('iiiisssss',$taskId,$stepId,$executionId,$userId,$eventKey,$step['status'],$stepTo,$summary,$details);if(!$s->execute())throw new RuntimeException('database_error');$s->close();
            $this->db->commit();return['task'=>['public_id'=>(string)$task['public_id'],'status'=>$taskTo,'lock_version'=>(int)$task['lock_version']+1],'step'=>['step_key'=>(string)$step['step_key'],'status'=>$stepTo,'lock_version'=>$stepLock+1],'decision'=>$decision];
        }catch(Throwable$e){$this->db->rollback();throw$e;}
    }
    /** @return array{array,TaskToolApprovalIdentity,TaskToolApprovalProposal} */private function pendingCheckpoint(string$json):array{try{$checkpoint=json_decode($json,true,32,JSON_THROW_ON_ERROR);}catch(JsonException){throw new TaskValidationException('tool_approval_checkpoint_invalid');}if(!is_array($checkpoint)||array_is_list($checkpoint)||($checkpoint['tool_approval']['format_version']??null)!==1||isset($checkpoint['tool_approval']['decision'])||!is_array($checkpoint['tool_approval']['identity']??null)||!is_array($checkpoint['tool_approval']['proposal']??null))throw new TaskValidationException('tool_approval_checkpoint_invalid');return[$checkpoint,TaskToolApprovalIdentity::fromArray($checkpoint['tool_approval']['identity']),TaskToolApprovalProposal::fromArray($checkpoint['tool_approval']['proposal'])];}
    private function one(string$q,string$t,array$v):?array{$rows=$this->all($q,$t,$v);return$rows[0]??null;}private function all(string$q,string$t,array$v):array{$s=$this->p($q);$s->bind_param($t,...$v);if(!$s->execute())throw new RuntimeException('database_error');$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return$rows;}
    private function p(string$q):mysqli_stmt{$s=$this->db->prepare($q);if(!$s)throw new RuntimeException('database_error');return$s;}private function oneUpdate(mysqli_stmt$s,string$error):void{if(!$s->execute())throw new RuntimeException('database_error');$n=$s->affected_rows;$s->close();if($n!==1)throw new TaskConcurrencyException($error);}
}
