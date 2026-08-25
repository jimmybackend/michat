<?php
declare(strict_types=1);
final class TaskApplicationServiceFactory{
 public function __construct(private mysqli$db){}
 public function create(bool$plannerEnabled=false):TaskApplicationService{
  $tasks=new TaskRepository($this->db);$steps=new TaskStepRepository($this->db);$events=new TaskEventRepository($this->db);$orchestrator=new TaskOrchestrator($this->db,$tasks,$events);$dependencies=new TaskDependencyRepository($this->db);$validator=new TaskPlanValidator();$planning=new TaskPlanningService($this->db,$plannerEnabled?(new TaskPlannerFactory($this->db))->create($validator):null,$validator,$steps,$events);
  $policies=new AutonomyPolicyService(new AutonomyPolicyRepository($this->db));$budgets=new AutonomyBudgetService($policies,new AutonomyBudgetRepository($this->db));$continuations=(new PostTaskContinuationServiceFactory($this->db))->create(3,$plannerEnabled);$proposals=(new NextWorkProposalServiceFactory($this->db))->create($plannerEnabled);$replans=(new TaskReplanServiceFactory($this->db))->create();$controls=new TaskCenterAutonomyControlService($this->db,$tasks,$policies,$budgets,$continuations,$proposals,$replans);
  return new TaskApplicationService($tasks,$steps,$dependencies,new TaskExecutionRepository($this->db),$events,$orchestrator,new TaskInputValidator(),new TaskDependencyService($tasks,$dependencies,$orchestrator),new TaskStepApprovalService($this->db),new TaskArtifactRepository($this->db),new TaskArtifactResourceResolver($this->db),new TaskToolApprovalDecisionService($this->db),null,$planning,$plannerEnabled,new TaskCenterAutonomyReadService($this->db),$controls);
 }
}
