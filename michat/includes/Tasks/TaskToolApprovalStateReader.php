<?php
declare(strict_types=1);
require_once __DIR__.'/TaskToolApprovalGateContracts.php';

/** Read-only, server-side resolver for the Tool approval state of one Execution. */
final class TaskToolApprovalStateReader implements TaskToolApprovalStateReaderInterface
{
    public function __construct(private mysqli$db){}

    public function read(int$executionId):TaskToolApprovalState
    {
        if($executionId<1)throw new TaskValidationException('execution_id_invalid');
        $s=$this->prepare('SELECT e.id_ execution_id,e.task_id_ execution_task_id,e.step_id_ execution_step_id,s.id_ step_id,s.task_id_ step_task_id,s.checkpoint_json,t.id_ task_id FROM TaskExecutions e LEFT JOIN TaskSteps s ON s.id_=e.step_id_ LEFT JOIN Tasks t ON t.id_=e.task_id_ WHERE e.id_=? LIMIT 1');
        $s->bind_param('i',$executionId);if(!$s->execute())throw new RuntimeException('database_error');$row=$s->get_result()->fetch_assoc();$s->close();
        if(!$row)throw new TaskNotFoundException('execution_not_found');
        if($row['step_id']===null||$row['task_id']===null||(int)$row['execution_step_id']!==(int)$row['step_id']
            ||(int)$row['execution_task_id']!==(int)$row['task_id']||(int)$row['step_task_id']!==(int)$row['task_id'])throw new TaskValidationException('tool_approval_state_invalid');
        $state=TaskToolApprovalState::fromCheckpoint($row['checkpoint_json']===null?null:(string)$row['checkpoint_json']);
        if($state->identity!==null)$this->assertRelatedExecution($state->identity->proposalExecutionId,(int)$row['task_id'],(int)$row['step_id']);
        if($state->consumerExecutionId!==null)$this->assertRelatedExecution($state->consumerExecutionId,(int)$row['task_id'],(int)$row['step_id']);
        return$state;
    }

    private function assertRelatedExecution(int$id,int$taskId,int$stepId):void
    {
        $s=$this->prepare('SELECT id_ FROM TaskExecutions WHERE id_=? AND task_id_=? AND step_id_=? LIMIT 1');$s->bind_param('iii',$id,$taskId,$stepId);
        if(!$s->execute())throw new RuntimeException('database_error');$found=$s->get_result()->fetch_assoc();$s->close();if(!$found)throw new TaskValidationException('tool_approval_state_invalid');
    }
    private function prepare(string$sql):mysqli_stmt{$s=$this->db->prepare($sql);if(!$s)throw new RuntimeException('database_error');return$s;}
}
