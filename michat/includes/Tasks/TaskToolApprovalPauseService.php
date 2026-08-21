<?php
declare(strict_types=1);
require_once __DIR__.'/TaskToolApprovalGateContracts.php';

/** Atomic persistence boundary for a Tool approval pause; it never executes a Tool. */
final class TaskToolApprovalPauseService implements TaskToolApprovalPauseInterface
{
    private TaskStateMachine $taskStates;
    private TaskStepStateMachine $stepStates;
    private TaskExecutionStateMachine $executionStates;
    public function __construct(private mysqli $db,private TaskToolApprovalProposalFactory $proposals,?TaskStateMachine $taskStates=null,?TaskStepStateMachine $stepStates=null,?TaskExecutionStateMachine $executionStates=null)
    {
        $this->taskStates=$taskStates??new TaskStateMachine();$this->stepStates=$stepStates??new TaskStepStateMachine();$this->executionStates=$executionStates??new TaskExecutionStateMachine();
    }

    /** @param array<string,mixed> $arguments @return array{paused:bool,idempotent:bool,task_id:int,step_id:int,execution_id:int} */
    public function pause(int $executionId,array $arguments,TaskToolApprovalProposal $proposal):array
    {
        if($executionId<1)throw new TaskValidationException('execution_id_invalid');
        $this->db->begin_transaction();
        try{
            $row=$this->lockedExecution($executionId);
            $checkpoint=$this->checkpoint((string)($row['checkpoint_json']??''));
            $hasPersistedApproval=array_key_exists('tool_approval',$checkpoint);
            $persistedFingerprint=$checkpoint['tool_approval']['proposal']['fingerprint']??null;
            if($hasPersistedApproval&&!is_string($persistedFingerprint))throw new TaskConcurrencyException('tool_approval_conflict');

            if($row['execution_status']==='waiting'&&$row['step_status']==='waiting_user'&&$row['task_status']==='waiting_user'){
                if(is_string($persistedFingerprint)&&hash_equals($persistedFingerprint,$proposal->fingerprint)){
                    $this->db->commit();return$this->result($row,true);
                }
                throw new TaskConcurrencyException('tool_approval_conflict');
            }
            if($row['execution_status']!=='running'||$row['step_status']!=='running'||$row['task_status']!=='running'||(int)$row['current_step_id_']!==(int)$row['step_id_'])throw new TaskTransitionException('tool_approval_pause_invalid');
            if($hasPersistedApproval)throw new TaskConcurrencyException('tool_approval_conflict');
            $this->executionStates->assertTransition('running','waiting');$this->stepStates->assertTransition('running','waiting_user');$this->taskStates->assertTransition('running','waiting_user');

            $scope=['task_id'=>(int)$row['task_id_'],'step_id'=>(int)$row['step_id_'],'execution_id'=>$executionId,'user_id'=>(int)$row['user_id_'],'project_id'=>$row['project_id_']===null?null:(int)$row['project_id_'],'session_id'=>(int)$row['session_id_']];
            $expected=$this->proposals->create($proposal->toolKey,$arguments,$scope);
            if(!hash_equals($expected->fingerprint,$proposal->fingerprint)||$expected->toArray()!==$proposal->toArray())throw new TaskValidationException('tool_approval_proposal_mismatch');
            $checkpoint['tool_approval']=['format_version'=>1,'identity'=>(new TaskToolApprovalIdentity($executionId))->toArray(),'proposal'=>$proposal->toArray()];
            $checkpointJson=json_encode($checkpoint,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

            $s=$this->prepare("UPDATE TaskExecutions SET status='waiting',lease_expires_at=NULL WHERE id_=? AND status='running'");$s->bind_param('i',$executionId);$this->executeOne($s,'tool_approval_execution_conflict');
            $taskId=(int)$row['task_id_'];$stepId=(int)$row['step_id_'];$s=$this->prepare("UPDATE TaskSteps SET status='waiting_user',checkpoint_json=?,lock_version=lock_version+1 WHERE id_=? AND task_id_=? AND status='running'");$s->bind_param('sii',$checkpointJson,$stepId,$taskId);$this->executeOne($s,'tool_approval_step_conflict');
            $s=$this->prepare("UPDATE Tasks SET status='waiting_user',lock_version=lock_version+1 WHERE id_=? AND current_step_id_=? AND status='running'");$s->bind_param('ii',$taskId,$stepId);$this->executeOne($s,'tool_approval_task_conflict');
            $details=json_encode($proposal->toArray(),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$summary='Se requiere aprobación: '.$proposal->safeSummary;
            $s=$this->prepare("INSERT INTO TaskEvents(task_id_,step_id_,execution_id_,actor_type,event_key,from_status,to_status,summary,details_json) VALUES(?,?,?,'agent','tool_approval_requested','running','waiting_user',?,?)");$s->bind_param('iiiss',$taskId,$stepId,$executionId,$summary,$details);if(!$s->execute())throw new RuntimeException('database_error');$s->close();
            $this->db->commit();return$this->result($row,false);
        }catch(Throwable$e){$this->db->rollback();throw$e;}
    }

    private function lockedExecution(int$id):array
    {
        $s=$this->prepare('SELECT e.id_,e.task_id_,e.step_id_,e.status execution_status,s.status step_status,s.checkpoint_json,t.status task_status,t.current_step_id_,t.user_id_,t.project_id_,t.session_id_ FROM TaskExecutions e JOIN TaskSteps s ON s.id_=e.step_id_ AND s.task_id_=e.task_id_ JOIN Tasks t ON t.id_=e.task_id_ WHERE e.id_=? LIMIT 1 FOR UPDATE');$s->bind_param('i',$id);if(!$s->execute())throw new RuntimeException('database_error');$row=$s->get_result()->fetch_assoc();$s->close();if(!$row)throw new TaskNotFoundException('execution_not_found');return$row;
    }
    private function checkpoint(string$json):array{if(trim($json)==='')return[];try{$value=json_decode($json,true,32,JSON_THROW_ON_ERROR);}catch(JsonException){throw new TaskValidationException('task_checkpoint_invalid');}if(!is_array($value)||array_is_list($value))throw new TaskValidationException('task_checkpoint_invalid');return$value;}
    private function result(array$row,bool$idempotent):array{return['paused'=>true,'idempotent'=>$idempotent,'task_id'=>(int)$row['task_id_'],'step_id'=>(int)$row['step_id_'],'execution_id'=>(int)$row['id_']];}
    private function prepare(string$sql):mysqli_stmt{$s=$this->db->prepare($sql);if(!$s)throw new RuntimeException('database_error');return$s;}
    private function executeOne(mysqli_stmt$s,string$error):void{if(!$s->execute())throw new RuntimeException('database_error');$affected=$s->affected_rows;$s->close();if($affected!==1)throw new TaskConcurrencyException($error);}
}
