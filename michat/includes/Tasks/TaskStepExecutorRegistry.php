<?php
declare(strict_types=1);

final class TaskStepExecutorRegistry
{
    private array $executors = [];
    public function register(string $type, TaskStepExecutorInterface $executor): void { $this->executors[$type] = $executor; }
    public function get(string $type): TaskStepExecutorInterface
    {
        if (!isset($this->executors[$type])) throw new TaskValidationException('step_executor_not_supported');
        return $this->executors[$type];
    }
}
