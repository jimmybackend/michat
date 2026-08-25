<?php
declare(strict_types=1);
final class AutonomyBudgetRequest{
 public function __construct(public readonly int$decisions=0,public readonly int$tasks=0,public readonly int$replans=0,public readonly int$inputTokens=0,public readonly int$outputTokens=0,public readonly int$toolCalls=0,public readonly int$writeToolCalls=0,public readonly int$runtimeSeconds=0,public readonly int$descendantDepth=0){foreach([$decisions,$tasks,$replans,$inputTokens,$outputTokens,$toolCalls,$writeToolCalls,$runtimeSeconds,$descendantDepth]as$v)if($v<0)throw new TaskValidationException('autonomy_reservation_invalid');if(array_sum([$decisions,$tasks,$replans,$inputTokens,$outputTokens,$toolCalls,$writeToolCalls,$runtimeSeconds])<1)throw new TaskValidationException('autonomy_reservation_empty');}
 public function values():array{return['decisions'=>$this->decisions,'tasks'=>$this->tasks,'replans'=>$this->replans,'input_tokens'=>$this->inputTokens,'output_tokens'=>$this->outputTokens,'tool_calls'=>$this->toolCalls,'write_tool_calls'=>$this->writeToolCalls,'runtime_seconds'=>$this->runtimeSeconds,'descendant_depth'=>$this->descendantDepth];}
}
final class AutonomyBudgetReservation{
 public function __construct(public readonly bool$allowed,public readonly string$reasonCode,public readonly ?string$reservationPublicId,public readonly bool$idempotent,public readonly array$usage,public readonly array$remaining){}
}
interface AutonomyBudgetStoreInterface{
 public function startCycle(int$userId,int$projectId,string$cyclePublicId):array;
 public function findActiveCycle(int$userId,int$projectId):?array;
 public function reserve(int$userId,int$projectId,string$cyclePublicId,string$idempotencyKey,AutonomyBudgetRequest$request,AutonomyPolicy$policy):AutonomyBudgetReservation;
 public function consume(int$userId,int$projectId,string$reservationPublicId):array;
 public function release(int$userId,int$projectId,string$reservationPublicId):array;
}
