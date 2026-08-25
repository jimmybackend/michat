<?php
declare(strict_types=1);
final class NextWorkAuthorizationService{
 public function __construct(private AutonomyPolicyService$policies,private AutonomyBudgetService$budgets){}
 public function authorize(NextWorkDecision$decision,int$userId,int$projectId,string$cyclePublicId,string$idempotencyKey,int$descendantDepth,string$terminalTaskStatus):AutonomyAuthorizationDecision{
  if($decision->decision!==NextWorkDecision::PROPOSE_TASK)return AutonomyAuthorizationDecision::denied('no_continuation_requested','La decisión no propone trabajo nuevo.');
  if(!in_array($terminalTaskStatus,['completed','failed','cancelled'],true))return AutonomyAuthorizationDecision::denied('invalid_scope','La Task de origen no es terminal.');
  try{$policy=$this->policies->getOrCreate($userId,$projectId);}catch(TaskNotFoundException){return AutonomyAuthorizationDecision::denied('invalid_scope','El Project no está disponible.');}
  if($policy->mode==='disabled')return AutonomyAuthorizationDecision::denied('autonomy_disabled','La autonomía del Project está deshabilitada.');
  if($policy->status==='paused')return AutonomyAuthorizationDecision::denied('autonomy_paused','La autonomía del Project está pausada.');
  if($policy->status==='stopped')return AutonomyAuthorizationDecision::denied('autonomy_stopped','La autonomía del Project fue detenida.');
  if($terminalTaskStatus==='cancelled'||$policy->mode==='supervised')return AutonomyAuthorizationDecision::approval();
  $reservation=$this->budgets->reserve($userId,$projectId,$cyclePublicId,$idempotencyKey,new AutonomyBudgetRequest(decisions:1,tasks:1,descendantDepth:$descendantDepth));
  if(!$reservation->allowed)return AutonomyAuthorizationDecision::denied($reservation->reasonCode,'El presupuesto de autonomía no permite continuar.');
  return new AutonomyAuthorizationDecision(AutonomyAuthorizationDecision::ALLOWED,'budget_reserved','La continuación está autorizada dentro del presupuesto.',$reservation->reservationPublicId,$reservation->remaining);
 }
}
