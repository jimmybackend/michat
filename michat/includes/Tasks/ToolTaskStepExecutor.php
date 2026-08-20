<?php
declare(strict_types=1);

final class ToolTaskStepExecutor implements TaskStepExecutorInterface
{
    public function __construct(private ToolRegistry $tools, private TaskArtifactRepository $artifacts) {}
    public function execute(array $context, callable $heartbeat, callable $isCancelled): TaskStepExecutionResult
    {
        $executionId = (int)($context['execution_id'] ?? 0);
        if ($executionId < 1) throw new TaskValidationException('execution_id_invalid');
        $input = $context['input'] ?? [];$key = (string)($input['tool_key'] ?? '');
        $heartbeat();
        if ($isCancelled()) throw new TaskTransitionException('cancel_requested');
        $result = $this->tools->execute($key, ['arguments'=>is_array($input['arguments'] ?? null) ? $input['arguments'] : [],'context'=>['user_id'=>$context['user_id']??null,'project_id'=>$context['project_id']??null,'session_id'=>$context['session_id']??null,'trace_id'=>$context['trace_id']??null,'execution_id'=>$context['execution_id']??null,'task_id'=>$context['task_id']??null]]);
        $heartbeat();
        if ($result->artifacts !== []) {
            if ($result->toolCallId === null || $result->toolCallId < 1) throw new TaskValidationException('tool_call_id_missing');
            foreach ($result->artifacts as $artifact) {
                if (!is_array($artifact) || count($artifact) !== 3 || array_diff(array_keys($artifact), ['relation','resource_type','resource_id']) !== []
                    || !array_key_exists('relation',$artifact) || !array_key_exists('resource_type',$artifact) || !array_key_exists('resource_id',$artifact)
                    || !is_string($artifact['relation']) || !is_string($artifact['resource_type']) || !is_int($artifact['resource_id'])) {
                    throw new TaskValidationException('tool_artifact_invalid');
                }
                $this->artifacts->record($executionId,$result->toolCallId,$artifact['relation'],$artifact['resource_type'],$artifact['resource_id']);
            }
        }
        return TaskStepExecutionResult::completed($result->summary, $result->artifacts);
    }
}
