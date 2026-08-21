<?php
declare(strict_types=1);

/** One execution path for HTTP and CLI callers. Persistence stays in repositories. */
final class TaskStepExecutionService implements TaskStepExecutionInterface
{
    public function __construct(private TaskStepExecutorRegistry $registry) {}
    public function execute(array $context, callable $heartbeat, callable $isCancelled): TaskStepExecutionResult
    {
        $type=(string)($context['step_type']??'');
        return $this->registry->get($type)->execute($context,$heartbeat,$isCancelled);
    }
}
