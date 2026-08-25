<?php
declare(strict_types=1);

interface TaskStepProgressionInterface
{
    public function apply(array$context,TaskStepExecutionResult$result):bool;
    public function fail(array$context,string$error,?TaskFailureDisposition$disposition=null):bool;
    public function cancel(array$context):bool;
}

interface TaskLeaseInterface
{
    public function heartbeat(array$context):bool;
    public function assertActive(array$context):void;
}

interface TaskStepExecutionInterface
{
    public function execute(array$context,callable$heartbeat,callable$isCancelled):TaskStepExecutionResult;
}
