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
        $tools=(new ToolRegistryFactory($this->db))->create();
        $risk=new TaskToolRiskPolicy($tools);
        $proposals=new TaskToolApprovalProposalFactory($risk,new TaskToolApprovalFingerprint());
        $registry = new TaskStepExecutorRegistry();
        $registry->register('model', new ModelTaskStepExecutor((new ChatExecutionServiceFactory($this->db,$toolObserver))->create()));
        $registry->register('tool',new ToolTaskStepExecutor($tools,$artifacts,$risk,new TaskToolApprovalStateReader($this->db),$proposals,new TaskToolApprovalPauseService($this->db,$proposals),new TaskToolApprovalConsumptionService($this->db,$proposals)));
        $registry->register('validation', new ValidationTaskStepExecutor());
        $registry->register('finalize', new FinalizeTaskStepExecutor());
        $registry->register('approval', new ApprovalTaskStepExecutor());
        $registry->register('wait', new WaitTaskStepExecutor());
        $registry->register('plan', new PlanTaskStepExecutor());
        return new TaskStepExecutionService($registry);
    }
}
