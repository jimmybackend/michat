<?php
declare(strict_types=1);

final class ToolTaskStepExecutor implements TaskStepExecutorInterface
{
    public function __construct(
        private ToolRegistry $tools,
        private TaskArtifactRepository $artifacts,
        private TaskToolRiskPolicyInterface $risk,
        private TaskToolApprovalStateReaderInterface $approvalStates,
        private TaskToolApprovalProposalFactoryInterface $proposals,
        private TaskToolApprovalPauseInterface $pauses,
        private TaskToolApprovalConsumptionInterface $consumptions
    ) {}
    public function execute(array $context, callable $heartbeat, callable $isCancelled): TaskStepExecutionResult
    {
        $executionId = (int)($context['execution_id'] ?? 0);
        if ($executionId < 1) throw new TaskValidationException('execution_id_invalid');
        $input = $context['input'] ?? [];$key = (string)($input['tool_key'] ?? '');
        $arguments=is_array($input['arguments']??null)?$input['arguments']:[];
        $heartbeat();
        if ($isCancelled()) throw new TaskTransitionException('cancel_requested');
        $risk=$this->risk->decide($key);$approval=$this->approvalStates->read($executionId);
        if($risk->isAllowed()){
            if($approval->status!==TaskToolApprovalState::NONE)throw new TaskValidationException('tool_approval_read_state_conflict');
        }elseif($risk->requiresApproval()){
            $scope=$this->serverScope($context);
            if($approval->status===TaskToolApprovalState::NONE){
                $proposal=$this->proposals->create($key,$arguments,$scope);
                $this->pauses->pause($executionId,$arguments,$proposal);
                return TaskStepExecutionResult::persistedWaitingUser('Se requiere aprobación: '.$proposal->safeSummary);
            }
            if($approval->status===TaskToolApprovalState::PENDING)throw new TaskValidationException('tool_approval_pending_conflict');
            if($approval->status===TaskToolApprovalState::CONSUMED)throw new TaskValidationException('tool_approval_already_consumed');
            if($approval->status!==TaskToolApprovalState::APPROVED)throw new TaskValidationException('tool_approval_state_invalid');
            $this->consume($executionId,$key,$arguments);
        }else throw new TaskValidationException('tool_risk_decision_invalid');
        $result = $this->tools->execute($key, ['arguments'=>$arguments,'context'=>['user_id'=>$context['user_id']??null,'project_id'=>$context['project_id']??null,'session_id'=>$context['session_id']??null,'trace_id'=>$context['trace_id']??null,'execution_id'=>$context['execution_id']??null,'task_id'=>$context['task_id']??null]]);
        $heartbeat();
        if ($result->artifacts !== []) {
            if ($result->toolCallId === null || $result->toolCallId < 1) throw new TaskValidationException('tool_call_id_missing');
            foreach ($result->artifacts as $artifact) {
                if (!is_array($artifact) || count($artifact) !== 3 || array_diff(array_keys($artifact), ['relation','resource_type','resource_id']) !== []
                    || !array_key_exists('relation',$artifact) || !array_key_exists('resource_type',$artifact) || !array_key_exists('resource_id',$artifact)
                    || !is_string($artifact['relation']) || !is_string($artifact['resource_type']) || !is_int($artifact['resource_id'])) {
                    throw new TaskValidationException('tool_artifact_invalid');
                }
                $this->artifacts->record($executionId,$result->toolCallId,$artifact['relation'],$artifact['resource_type'],$artifact['resource_id']);
            }
        }
        return TaskStepExecutionResult::completed($result->summary, $result->artifacts);
    }

    private function serverScope(array$context):array
    {
        foreach(['execution_id','task_id','step_id','user_id','session_id']as$key)if(!isset($context[$key])||!is_int($context[$key])||$context[$key]<1)throw new TaskValidationException('tool_approval_scope_invalid');
        if(array_key_exists('project_id',$context)&&$context['project_id']!==null&&(!is_int($context['project_id'])||$context['project_id']<1))throw new TaskValidationException('tool_approval_scope_invalid');
        return['task_id'=>$context['task_id'],'step_id'=>$context['step_id'],'execution_id'=>$context['execution_id'],'user_id'=>$context['user_id'],'project_id'=>$context['project_id']??null,'session_id'=>$context['session_id']];
    }
    private function consume(int$executionId,string$key,array$arguments):void
    {
        try{$this->consumptions->consume($executionId,$key,$arguments);}
        catch(TaskConcurrencyException$e){if(in_array($e->getMessage(),['tool_approval_already_consumed','tool_approval_operation_conflict'],true))throw new TaskValidationException($e->getMessage(),0,$e);throw$e;}
        catch(TaskTransitionException$e){throw new TaskValidationException($e->getMessage(),0,$e);}
    }
}
