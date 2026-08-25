<?php
declare(strict_types=1);
final class PostTaskContinuation{
 public const PENDING='pending',PROCESSING='processing',COMPLETED='completed',WAITING_USER='waiting_user',WAITING_APPROVAL='waiting_approval',FAILED='failed';
 public function __construct(public readonly string$publicId,public readonly int$userId,public readonly int$projectId,public readonly string$sourceTaskPublicId,public readonly string$cyclePublicId,public readonly string$status,public readonly string$terminalStatus,public readonly int$depth,public readonly int$attemptCount,public readonly string$leaseToken,public readonly ?string$decisionType=null){}
}
interface PostTaskContinuationStoreInterface{
 public function enrollRoot(int$userId,int$projectId,string$taskPublicId,string$cyclePublicId):array;
 public function discover(int$limit):int;
 public function claim(string$workerId,int$leaseSeconds,int$maxAttempts):?array;
 public function recordEvaluation(array$claim,NextWorkEvaluationResult$result):void;
 public function finish(array$claim,string$status,string$decision,string$reason,string$publicReason,?string$question=null,?string$proposalPublicId=null,?string$spawnedTaskPublicId=null):void;
 public function retry(array$claim,string$reason,int$maxAttempts):void;
 public function closeCycle(int$userId,int$projectId,string$cyclePublicId,string$status):void;
 public function event(array$claim,string$key,string$summary):void;
}
