<?php
declare(strict_types=1);
require_once __DIR__.'/TaskToolApprovalGateContracts.php';

/**
 * Atomically consumes one approved Tool permission before any physical effect.
 * A committed consumption is deliberately never reset automatically: this is
 * at-most-once permission consumption, not an exactly-once external effect.
 */
final class TaskToolApprovalConsumptionService implements TaskToolApprovalConsumptionInterface
{
    public function __construct(private mysqli$db,private TaskToolApprovalProposalFactory$proposals){}

    /** @param array<string,mixed> $arguments @return array{consumed:bool,consumer_execution_id:int,fingerprint:string} */
    public function consume(int$executionId,string$toolKey,array$arguments):array
    {
        if($executionId<1)throw new TaskValidationException('execution_id_invalid');
        $this->db->begin_transaction();try{
            $current=$this->currentExecution($executionId);
            if($current['execution_status']!=='running'||$current['step_status']!=='running'||$current['task_status']!=='running'||(int)$current['current_step_id_']!==(int)$current['step_id_'])throw new TaskTransitionException('tool_approval_consumption_invalid');
            [$checkpoint,$identity,$proposal,$decision]=$this->approvedCheckpoint((string)($current['checkpoint_json']??''));
            if($decision['consumed']===true)throw new TaskConcurrencyException('tool_approval_already_consumed');
            $origin=$this->originExecution($identity->proposalExecutionId,(int)$current['task_id_'],(int)$current['step_id_']);
            if((int)$origin['id_']===$executionId||$origin['status']!=='completed')throw new TaskTransitionException('tool_approval_origin_invalid');
            $scope=['task_id'=>(int)$current['task_id_'],'step_id'=>(int)$current['step_id_'],'execution_id'=>$executionId,'user_id'=>(int)$current['user_id_'],'project_id'=>$current['project_id_']===null?null:(int)$current['project_id_'],'session_id'=>(int)$current['session_id_']];
            $recreated=$this->proposals->recreateForContinuation($toolKey,$arguments,$scope,$identity);
            if($recreated->toArray()!==$proposal->toArray()||!hash_equals($proposal->fingerprint,$decision['fingerprint'])||!hash_equals($proposal->fingerprint,$recreated->fingerprint))throw new TaskConcurrencyException('tool_approval_operation_conflict');
            $checkpoint['tool_approval']['decision']['consumed']=true;$checkpoint['tool_approval']['decision']['consumer_execution_id']=$executionId;$checkpoint['tool_approval']['decision']['consumed_at']=gmdate('Y-m-d H:i:s.u');
            $checkpointJson=json_encode($checkpoint,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$stepId=(int)$current['step_id_'];$taskId=(int)$current['task_id_'];$stepLock=(int)$current['step_lock_version'];
            $s=$this->p("UPDATE TaskSteps SET checkpoint_json=?,lock_version=lock_version+1 WHERE id_=? AND task_id_=? AND status='running' AND lock_version=?");$s->bind_param('siii',$checkpointJson,$stepId,$taskId,$stepLock);$this->oneUpdate($s,'tool_approval_consumption_conflict');
            $details=json_encode(['tool_key'=>$proposal->toolKey,'effect'=>$proposal->effect,'safe_summary'=>$proposal->safeSummary,'safe_target'=>$proposal->safeTarget,'fingerprint'=>$proposal->fingerprint],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$summary='Autorización de Tool consumida.';
            $s=$this->p("INSERT INTO TaskEvents(task_id_,step_id_,execution_id_,actor_type,event_key,from_status,to_status,summary,details_json) VALUES(?,?,?,'agent','tool_approval_consumed','running','running',?,?)");$s->bind_param('iiiss',$taskId,$stepId,$executionId,$summary,$details);if(!$s->execute())throw new RuntimeException('database_error');$s->close();
            $this->db->commit();return['consumed'=>true,'consumer_execution_id'=>$executionId,'fingerprint'=>$proposal->fingerprint];
        }catch(Throwable$e){$this->db->rollback();throw$e;}
    }
    private function currentExecution(int$id):array{$s=$this->p('SELECT e.id_,e.task_id_,e.step_id_,e.status execution_status,s.status step_status,s.checkpoint_json,s.lock_version step_lock_version,t.status task_status,t.current_step_id_,t.user_id_,t.project_id_,t.session_id_ FROM TaskExecutions e JOIN TaskSteps s ON s.id_=e.step_id_ AND s.task_id_=e.task_id_ JOIN Tasks t ON t.id_=e.task_id_ WHERE e.id_=? LIMIT 1 FOR UPDATE');$s->bind_param('i',$id);if(!$s->execute())throw new RuntimeException('database_error');$row=$s->get_result()->fetch_assoc();$s->close();if(!$row)throw new TaskNotFoundException('execution_not_found');return$row;}
    private function originExecution(int$id,int$taskId,int$stepId):array{$s=$this->p('SELECT id_,status FROM TaskExecutions WHERE id_=? AND task_id_=? AND step_id_=? LIMIT 1 FOR UPDATE');$s->bind_param('iii',$id,$taskId,$stepId);if(!$s->execute())throw new RuntimeException('database_error');$row=$s->get_result()->fetch_assoc();$s->close();if(!$row)throw new TaskTransitionException('tool_approval_origin_invalid');return$row;}
    /** @return array{array,TaskToolApprovalIdentity,TaskToolApprovalProposal,array{status:string,fingerprint:string,consumed:bool}} */private function approvedCheckpoint(string$json):array{try{$checkpoint=json_decode($json,true,32,JSON_THROW_ON_ERROR);}catch(JsonException){throw new TaskValidationException('tool_approval_checkpoint_invalid');}if(!is_array($checkpoint)||array_is_list($checkpoint))throw new TaskValidationException('tool_approval_checkpoint_invalid');$approval=$checkpoint['tool_approval']??null;if(!is_array($approval)||($approval['format_version']??null)!==1||!is_array($approval['identity']??null)||!is_array($approval['proposal']??null)||!is_array($approval['decision']??null))throw new TaskValidationException('tool_approval_checkpoint_invalid');$decision=$approval['decision'];if(array_diff(['status','fingerprint','consumed'],array_keys($decision))!==[]||$decision['status']!=='approved'||!is_string($decision['fingerprint'])||preg_match('/^[a-f0-9]{64}$/D',$decision['fingerprint'])!==1||!is_bool($decision['consumed']))throw new TaskValidationException('tool_approval_checkpoint_invalid');return[$checkpoint,TaskToolApprovalIdentity::fromArray($approval['identity']),TaskToolApprovalProposal::fromArray($approval['proposal']),$decision];}
    private function p(string$q):mysqli_stmt{$s=$this->db->prepare($q);if(!$s)throw new RuntimeException('database_error');return$s;}private function oneUpdate(mysqli_stmt$s,string$error):void{if(!$s->execute())throw new RuntimeException('database_error');$n=$s->affected_rows;$s->close();if($n!==1)throw new TaskConcurrencyException($error);}
}
