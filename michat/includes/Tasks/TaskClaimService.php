<?php
declare(strict_types=1);
final class TaskClaimService{
 public function __construct(private TaskQueueRepository$queue,private TaskWorkerConfig$config){}
 public function claim():?array{$this->queue->begin();try{$task=$this->queue->lockNextAsyncRespond();if(!$task||!$this->queue->dependenciesSatisfied((int)$task['id_'])){$this->queue->commit();return null;}$claim=$this->queue->createClaim($task,$this->config->workerId,TaskWorkerConfig::leaseToken(),$this->config->leaseSeconds,TaskPublicId::generate());$this->queue->commit();return$claim;}catch(mysqli_sql_exception$e){$this->queue->rollback();if((int)$e->getCode()===1062)return null;throw$e;}catch(Throwable$e){$this->queue->rollback();throw$e;}}
}
