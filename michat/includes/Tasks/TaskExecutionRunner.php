<?php
declare(strict_types=1);
/** Shared lifecycle boundary used by HTTP orchestration and the CLI worker. */
final class TaskExecutionRunner{
 private Closure$pipeline;
 public function __construct(private TaskQueueRepository$queue,private TaskLeaseService$leases,callable$pipeline){$this->pipeline=Closure::fromCallable($pipeline);}
 public function run(array$c):bool{try{$this->leases->assertActive($c);if(!$this->leases->heartbeat($c))throw new TaskConcurrencyException('lease_lost');$this->leases->assertActive($c);$result=($this->pipeline)($c,function()use($c):void{if(!$this->leases->heartbeat($c))throw new TaskConcurrencyException('lease_lost');$this->leases->assertActive($c);});if(!$this->leases->heartbeat($c))throw new TaskConcurrencyException('lease_lost');$this->leases->assertActive($c);return$this->queue->finish($c,'completed',(int)($result['message_id']??0),(string)($result['summary']??''),null);}catch(TaskTransitionException$e){if($e->getMessage()==='cancel_requested')return$this->queue->finish($c,'cancelled',null,null,null);throw$e;}catch(TaskConcurrencyException$e){error_log('Task worker lost lease for execution '.$c['execution_id']);return false;}catch(Throwable$e){$safe=ChatTaskBridge::sanitizeError($e);$this->queue->finish($c,'failed',null,null,$safe);error_log('Task execution failed: '.$safe);return false;}}
}
