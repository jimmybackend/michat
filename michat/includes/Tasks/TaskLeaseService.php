<?php
declare(strict_types=1);
final class TaskLeaseService implements TaskLeaseInterface{
 public function __construct(private TaskQueueRepository$queue,private int$leaseSeconds){}
 public function heartbeat(array$c):bool{return$this->queue->heartbeat($c['execution_id'],$c['worker_id'],$c['lease_token'],$this->leaseSeconds);}
 public function assertActive(array$c):void{$row=$this->queue->ownedRunning($c['execution_id'],$c['worker_id'],$c['lease_token']);if(!$row)throw new TaskConcurrencyException('lease_lost');if($row['cancel_requested_at']!==null||$row['task_status']==='cancelled')throw new TaskTransitionException('cancel_requested');}
}
