<?php
declare(strict_types=1);

/** Separates the historical Execution ordinal from the technical retry budget. */
final class TaskClaimAttemptBudget
{
    /**
     * @param list<array{id_:int|string,task_id_:int|string,step_id_:int|string,status:string,attempt_number:int|string}> $history
     */
    public function nextAttemptNumber(int $taskId,int $stepId,int $maxAttempts,array $history,?string $checkpointJson): int
    {
        if($taskId<1||$stepId<1||$maxAttempts<1)throw new TaskValidationException('claim_attempt_context_invalid');
        $highest=0;$latest=null;
        foreach($history as$row){
            if((int)($row['task_id_']??0)!==$taskId||(int)($row['step_id_']??0)!==$stepId)throw new TaskValidationException('claim_attempt_history_invalid');
            $attempt=(int)($row['attempt_number']??0);$id=(int)($row['id_']??0);
            if($attempt<1||$id<1)throw new TaskValidationException('claim_attempt_history_invalid');
            if($attempt>$highest||($attempt===$highest&&($latest===null||$id>(int)$latest['id_']))){$highest=$attempt;$latest=$row;}
        }
        $next=$highest+1;
        if($next<=$maxAttempts)return$next;
        if(!$this->isApprovedContinuation($taskId,$stepId,$history,$latest,$checkpointJson))throw new TaskTransitionException('attempt_limit');
        return$next;
    }

    private function isApprovedContinuation(int$taskId,int$stepId,array$history,?array$latest,?string$checkpointJson):bool
    {
        if($latest===null||$checkpointJson===null||trim($checkpointJson)==='')return false;
        try{$state=TaskToolApprovalState::fromCheckpoint($checkpointJson);}catch(TaskValidationException){return false;}
        if($state->status!==TaskToolApprovalState::APPROVED||$state->identity===null)return false;
        $proposalExecutionId=$state->identity->proposalExecutionId;$origin=null;
        foreach($history as$row)if((int)$row['id_']===$proposalExecutionId){$origin=$row;break;}
        return$origin!==null
            &&(int)$origin['task_id_']===$taskId&&(int)$origin['step_id_']===$stepId
            &&(string)$origin['status']==='completed'
            &&(int)$latest['id_']===$proposalExecutionId;
    }
}
