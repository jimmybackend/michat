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
        $result = $this->tools->execute($key, ['arguments'=>is_array($input['arguments'] ?? null) ? $input['arguments'] : [],'context'=>['user_id'=>$context['user_id']??null,'project_id'=>$context['project_id']??null,'session_id'=>$context['session_id']??null,'trace_id'=>$context['trace_id']??null,'execution_id'=>$context['execution_id']??null,'task_id'=>$context['task_id']??null]]);
        $heartbeat();
        return TaskStepExecutionResult::completed($result->summary, $result->artifacts);
    }
}
