<?php
declare(strict_types=1);
final class PostTaskContinuationServiceFactory{
 public function __construct(private mysqli$db){}
 public function create(int$batch=3,bool$plannerEnabled=false):PostTaskContinuationService{$policies=new AutonomyPolicyService(new AutonomyPolicyRepository($this->db));$budgets=new AutonomyBudgetService($policies,new AutonomyBudgetRepository($this->db));$proposals=(new NextWorkProposalServiceFactory($this->db))->create($plannerEnabled);$evaluator=new NextWorkEvaluator(new NextWorkSnapshotBuilder(new NextWorkSnapshotRepository($this->db)),new NextWorkAgentConfigResolver($this->db),new BedrockSingleTurnInference(new BedrockConverseClient()),new NextWorkDecisionValidator());return new PostTaskContinuationService(new PostTaskContinuationRepository($this->db),$policies,$budgets,$evaluator,$proposals,$batch);}
}
