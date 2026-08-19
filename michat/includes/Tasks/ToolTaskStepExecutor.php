<?php
declare(strict_types=1);

final class ToolTaskStepExecutor implements TaskStepExecutorInterface
{
    public function __construct(private ToolRegistry $tools) {}
    public function execute(array $context, callable $heartbeat, callable $isCancelled): TaskStepExecutionResult
    {
        $input = $context['input'] ?? [];$key = (string)($input['tool_key'] ?? '');
        $heartbeat();
        if ($isCancelled()) throw new TaskTransitionException('cancel_requested');
        $result = $this->tools->execute($key, is_array($input['arguments'] ?? null) ? $input['arguments'] : []);
        $heartbeat();
        return TaskStepExecutionResult::completed($result->summary, $result->artifacts);
    }
}
