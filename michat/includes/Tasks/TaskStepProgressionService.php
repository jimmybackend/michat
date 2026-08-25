<?php
declare(strict_types=1);
final class TaskStepProgressionService implements TaskStepProgressionInterface
{
 public function __construct(private TaskQueueRepository $queue){}
 public function apply(array$c,TaskStepExecutionResult$r):bool{return$this->queue->finish($c,str_starts_with($r->status,'waiting_')?$r->status:'completed',$r->messageId,$r->summary,null,$r->checkpoint);}
 public function fail(array$c,string$e,?TaskFailureDisposition$d=null):bool{return$this->queue->finish($c,'failed',null,null,$e,null,$d);}
 public function cancel(array$c):bool{return$this->queue->finish($c,'cancelled',null,null,null);}
}
