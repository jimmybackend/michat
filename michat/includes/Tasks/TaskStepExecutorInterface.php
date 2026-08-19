<?php
declare(strict_types=1);

interface TaskStepExecutorInterface
{
    public function execute(array $context, callable $heartbeat, callable $isCancelled): TaskStepExecutionResult;
}
