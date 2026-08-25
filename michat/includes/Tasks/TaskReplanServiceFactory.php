<?php
declare(strict_types=1);

final class TaskReplanServiceFactory
{
    public function __construct(private mysqli$db){}
    public function create(int$batch=2):TaskReplanService{$validator=new TaskPlanValidator();$planner=(new TaskPlannerFactory($this->db))->create($validator);$planning=new TaskPlanningService($this->db,$planner,$validator,new TaskStepRepository($this->db),new TaskEventRepository($this->db));$policies=new AutonomyPolicyService(new AutonomyPolicyRepository($this->db));$budgets=new AutonomyBudgetService($policies,new AutonomyBudgetRepository($this->db));return new TaskReplanService(new TaskReplanRepository($this->db),$planning,$policies,$budgets,$batch);}
}
