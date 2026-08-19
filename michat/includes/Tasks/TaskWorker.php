<?php
declare(strict_types=1);
final class TaskWorker{
 public function __construct(private TaskClaimService$claims,private TaskExecutionRunner$runner,private TaskRecoveryService$recovery,private TaskWorkerConfig$config,private ?TaskWaitService$waits=null){}
 public function once():bool{$this->recovery->recover($this->config->recoveryBatch);$this->waits?->reactivateDue($this->config->recoveryBatch);$claim=$this->claims->claim();if(!$claim)return false;$this->runner->run($claim);return true;}
 public function loop(?int$maxJobs=null):void{$jobs=0;while($maxJobs===null||$jobs<$maxJobs){try{$worked=$this->once();if($worked)$jobs++;else sleep($this->config->sleepSeconds);}catch(Throwable$e){error_log('Task worker cycle failed: '.ChatTaskBridge::sanitizeError($e));sleep($this->config->sleepSeconds);}}}
}
