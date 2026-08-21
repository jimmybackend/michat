<?php
declare(strict_types=1);

/** Task HITL policy adapter for dynamic Tools proposed during a Model Step. */
final class TaskChatToolExecutionGate implements ToolExecutionGateInterface, ToolExecutionCompletionGuardInterface
{
    public function __construct(
        private TaskToolRiskPolicyInterface $risk,
        private TaskToolApprovalStateReaderInterface $approvalStates,
        private TaskToolApprovalProposalFactoryInterface $proposals,
        private TaskToolApprovalPauseInterface $pauses,
        private TaskToolApprovalConsumptionInterface $consumptions
    ) {}

    public function beforeExecute(string $toolKey, array $arguments, array $context): ToolExecutionGateDecision
    {
        $risk=$this->risk->decide($toolKey);
        if($risk->isAllowed())return ToolExecutionGateDecision::allow();
        if(!$risk->requiresApproval())throw new TaskValidationException('tool_risk_decision_invalid');

        $scope=$this->serverScope($context);$executionId=$scope['execution_id'];
        $approval=$this->approvalStates->read($executionId);
        if($approval->status===TaskToolApprovalState::NONE){
            $proposal=$this->proposals->create($toolKey,$arguments,$scope);
            $this->pauses->pause($executionId,$arguments,$proposal);
            return ToolExecutionGateDecision::pauseAlreadyPersisted('Se requiere aprobación: '.$proposal->safeSummary);
        }
        if($approval->status===TaskToolApprovalState::PENDING)throw new TaskValidationException('tool_approval_pending_conflict');
        if($approval->status===TaskToolApprovalState::CONSUMED)throw new TaskValidationException('tool_approval_already_consumed');
        if($approval->status!==TaskToolApprovalState::APPROVED)throw new TaskValidationException('tool_approval_state_invalid');
        $this->consume($executionId,$toolKey,$arguments);
        return ToolExecutionGateDecision::allow();
    }

    public function assertCompletionAllowed(array $context): void
    {
        $scope=$this->serverScope($context);$approval=$this->approvalStates->read($scope['execution_id']);
        if($approval->status===TaskToolApprovalState::NONE)return;
        if($approval->status===TaskToolApprovalState::PENDING)throw new TaskValidationException('tool_approval_completion_pending');
        if($approval->status===TaskToolApprovalState::APPROVED)throw new TaskValidationException('tool_approval_completion_unconsumed');
        if($approval->status===TaskToolApprovalState::CONSUMED){
            if($approval->consumerExecutionId===$scope['execution_id'])return;
            throw new TaskValidationException('tool_approval_completion_consumer_conflict');
        }
        throw new TaskValidationException('tool_approval_state_invalid');
    }

    /** @return array{task_id:int,step_id:int,execution_id:int,user_id:int,project_id:?int,session_id:int} */
    private function serverScope(array $context): array
    {
        foreach(['execution_id','task_id','step_id','user_id','session_id']as$key)if(!isset($context[$key])||!is_int($context[$key])||$context[$key]<1)throw new TaskValidationException('tool_approval_scope_invalid');
        if(array_key_exists('project_id',$context)&&$context['project_id']!==null&&(!is_int($context['project_id'])||$context['project_id']<1))throw new TaskValidationException('tool_approval_scope_invalid');
        return['task_id'=>$context['task_id'],'step_id'=>$context['step_id'],'execution_id'=>$context['execution_id'],'user_id'=>$context['user_id'],'project_id'=>$context['project_id']??null,'session_id'=>$context['session_id']];
    }

    private function consume(int $executionId,string $toolKey,array $arguments): void
    {
        try{$this->consumptions->consume($executionId,$toolKey,$arguments);}
        catch(TaskConcurrencyException$e){if(in_array($e->getMessage(),['tool_approval_already_consumed','tool_approval_operation_conflict'],true))throw new TaskValidationException($e->getMessage(),0,$e);throw$e;}
        catch(TaskTransitionException$e){throw new TaskValidationException($e->getMessage(),0,$e);}
    }
}
