<?php
declare(strict_types=1);
final class NextWorkProposalServiceFactory{
 public function __construct(private mysqli$db){}
 public function create(bool$plannerEnabled=false):NextWorkProposalService{$policy=new AutonomyPolicyService(new AutonomyPolicyRepository($this->db));$budget=new AutonomyBudgetService($policy,new AutonomyBudgetRepository($this->db));return new NextWorkProposalService(new NextWorkProposalRepository($this->db),$policy,$budget,new NextWorkAuthorizationService($policy,$budget),(new TaskApplicationServiceFactory($this->db))->create($plannerEnabled));}
}
