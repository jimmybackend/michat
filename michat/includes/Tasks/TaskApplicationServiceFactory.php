<?php
declare(strict_types=1);
final class TaskApplicationServiceFactory{
 public function __construct(private mysqli$db){}
 public function create(bool$plannerEnabled=false):TaskApplicationService{
  $tasks=new TaskRepository($this->db);$steps=new TaskStepRepository($this->db);$events=new TaskEventRepository($this->db);$orchestrator=new TaskOrchestrator($this->db,$tasks,$events);$dependencies=new TaskDependencyRepository($this->db);$planning=new TaskPlanningService($this->db,null,new TaskPlanValidator(),$steps,$events);
  return new TaskApplicationService($tasks,$steps,$dependencies,new TaskExecutionRepository($this->db),$events,$orchestrator,new TaskInputValidator(),new TaskDependencyService($tasks,$dependencies,$orchestrator),new TaskStepApprovalService($this->db),new TaskArtifactRepository($this->db),new TaskArtifactResourceResolver($this->db),new TaskToolApprovalDecisionService($this->db),null,$planning,$plannerEnabled);
 }
}
