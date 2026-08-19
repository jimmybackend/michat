<?php
declare(strict_types=1);
final class TaskStepProgressionService
{
 public function __construct(private TaskQueueRepository $queue){}
 public function apply(array$c,TaskStepExecutionResult$r):bool{return$this->queue->finish($c,$r->status==='waiting_user'?'waiting':'completed',$r->messageId,$r->summary,null);}
 public function fail(array$c,string$e):bool{return$this->queue->finish($c,'failed',null,null,$e);}
 public function cancel(array$c):bool{return$this->queue->finish($c,'cancelled',null,null,null);}
}
