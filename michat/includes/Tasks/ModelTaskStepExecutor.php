<?php
declare(strict_types=1);

final class ModelTaskStepExecutor implements TaskStepExecutorInterface
{
    public function __construct(private ChatExecutionService $chat) {}
    public function execute(array $context, callable $heartbeat, callable $isCancelled): TaskStepExecutionResult
    {
        if ($isCancelled()) throw new TaskTransitionException('cancel_requested');
        $input = $context['input'] ?? [];
        $request = new ChatExecutionRequest((int)$context['user_id'], (int)$context['session_id'], $context['project_id'] ?? null, $context['origin_message_id'] ?? null, (string)($input['request_id'] ?? $context['trace_id']), isset($input['compilation_id']) ? (int)$input['compilation_id'] : null, (string)($input['prompt'] ?? $input['original_text'] ?? $context['objective'] ?? 'Execute task step'), $input['compiled_prompt'] ?? null, $input['model_id'] ?? null, (float)($input['temperature'] ?? 0.7), (int)($input['max_tokens'] ?? 2048), (float)($input['top_p'] ?? 0.9), (string)$context['trace_id'], $context);
        $result = $this->chat->execute($request, $heartbeat);
        if($result->isPauseAlreadyPersisted())return TaskStepExecutionResult::persistedWaitingUser($result->controlDecision->safeSummary);
        if ($isCancelled()) throw new TaskTransitionException('cancel_requested');
        return TaskStepExecutionResult::completed($result->replyText, $result->artifacts, null, $result->assistantMessageId, $result->modelId);
    }
}
