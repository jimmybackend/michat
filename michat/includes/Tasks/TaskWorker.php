<?php
declare(strict_types=1);
final class TaskWorker{
 private array$recurrenceMetrics=['rules_checked'=>0,'occurrences_reserved'=>0,'tasks_materialized'=>0,'occurrences_failed'=>0,'occurrences_skipped'=>0,'retries_claimed'=>0];
 public function __construct(private TaskClaimService$claims,private TaskExecutionRunner$runner,private TaskRecoveryService$recovery,private TaskWorkerConfig$config,private ?TaskWaitService$waits=null,private ?TaskRecurrenceEvaluator$recurrence=null){}
 public function once():bool{$this->recovery->recover($this->config->recoveryBatch);$this->waits?->reactivateDue($this->config->recoveryBatch);if($this->recurrence!==null){try{$this->recurrenceMetrics=$this->recurrence->evaluate();}catch(Throwable$e){$this->recurrenceMetrics=['rules_checked'=>0,'occurrences_reserved'=>0,'tasks_materialized'=>0,'occurrences_failed'=>0,'occurrences_skipped'=>0,'retries_claimed'=>0];error_log('Task recurrence maintenance failed: '.ChatTaskBridge::sanitizeError($e));}}$claim=$this->claims->claim();if($claim){$this->runner->run($claim);return true;}return array_sum($this->recurrenceMetrics)>0;}
 public function recurrenceMetrics():array{return$this->recurrenceMetrics;}
 public function loop(?int$maxJobs=null):void{$jobs=0;while($maxJobs===null||$jobs<$maxJobs){try{$worked=$this->once();if($worked)$jobs++;else sleep($this->config->sleepSeconds);}catch(Throwable$e){error_log('Task worker cycle failed: '.ChatTaskBridge::sanitizeError($e));sleep($this->config->sleepSeconds);}}}
}
