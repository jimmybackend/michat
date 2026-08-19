<?php
declare(strict_types=1);
final class TaskRecoveryService{
 public function __construct(private TaskQueueRepository$queue){}
 public function recover(int$limit=50):int{$this->queue->begin();try{$rows=$this->queue->expired(max(1,min(200,$limit)));foreach($rows as$row)$this->queue->abandon($row);$this->queue->commit();return count($rows);}catch(Throwable$e){$this->queue->rollback();throw$e;}}
}
