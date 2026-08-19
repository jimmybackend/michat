<?php
declare(strict_types=1);
/** Shared lifecycle boundary used by HTTP orchestration and the CLI worker. */
final class TaskExecutionRunner
{
    public function __construct(private TaskQueueRepository $queue, private TaskLeaseService $leases, private TaskStepExecutionService $steps) {}
    public function run(array $context): bool
    {
        try {
            $heartbeat=function()use($context):void{if(!$this->leases->heartbeat($context))throw new TaskConcurrencyException('lease_lost');$this->leases->assertActive($context);};
            $cancelled=function()use($context):bool{try{$this->leases->assertActive($context);return false;}catch(TaskTransitionException$e){if($e->getMessage()==='cancel_requested')return true;throw$e;}};
            $heartbeat();$result=$this->steps->execute($context,$heartbeat,$cancelled);$heartbeat();
            return $this->queue->finish($context,$result->status==='waiting_user'?'waiting':'completed',$result->messageId,$result->summary,null);
        } catch (TaskTransitionException $e) {
            if($e->getMessage()==='cancel_requested')return $this->queue->finish($context,'cancelled',null,null,null);throw $e;
        } catch (TaskConcurrencyException $e) { error_log('Task worker lost lease for execution '.$context['execution_id']);return false;
        } catch (Throwable $e) {$safe=ChatTaskBridge::sanitizeError($e);$this->queue->finish($context,'failed',null,null,$safe);error_log('Task execution failed: '.$safe);return false;}
    }
}
