<?php
declare(strict_types=1);

/** Shared production composition for HTTP and CLI Task step execution. */
final class TaskStepExecutionServiceFactory
{
    public function __construct(private mysqli $db) {}

    public function create(): TaskStepExecutionService
    {
        $artifacts = new TaskArtifactRepository($this->db);
        $toolObserver = new TaskToolExecutionArtifactObserver($artifacts);
        $cancellations=new TaskCancellationGuard($this->db);
        $tools=(new ToolRegistryFactory($this->db,$cancellations))->create();
        $risk=new TaskToolRiskPolicy($tools);
        $proposals=new TaskToolApprovalProposalFactory($risk,new TaskToolApprovalFingerprint());
        $approvalStates=new TaskToolApprovalStateReader($this->db);
        $pauses=new TaskToolApprovalPauseService($this->db,$proposals);
        $consumptions=new TaskToolApprovalConsumptionService($this->db,$proposals);
        $modelGate=new TaskChatToolExecutionGate($risk,$approvalStates,$proposals,$pauses,$consumptions);
        $registry = new TaskStepExecutorRegistry();
        $registry->register('model', new ModelTaskStepExecutor((new ChatExecutionServiceFactory($this->db,$toolObserver,$tools,$modelGate,$cancellations))->create()));
        $registry->register('tool',new ToolTaskStepExecutor($tools,$artifacts,$risk,$approvalStates,$proposals,$pauses,$consumptions));
        $registry->register('validation', new ValidationTaskStepExecutor());
        $registry->register('finalize', new FinalizeTaskStepExecutor());
        $registry->register('approval', new ApprovalTaskStepExecutor());
        $registry->register('wait', new WaitTaskStepExecutor());
        $registry->register('plan', new PlanTaskStepExecutor());
        return new TaskStepExecutionService($registry);
    }
}
