<?php
declare(strict_types=1);

final class ValidationTaskStepExecutor implements TaskStepExecutorInterface
{
    public function execute(array $context, callable $heartbeat, callable $isCancelled): TaskStepExecutionResult
    {
        $input=$context['input']??[];$check=$input['check']??'';$path=$input['path']??'';
        if ($isCancelled()) throw new TaskTransitionException('cancel_requested');
        if ($check !== 'file_exists' || !is_string($path) || $path === '' || str_contains($path, '..') || str_starts_with($path, '/') || !is_file($path)) throw new RuntimeException('validation_failed');
        return TaskStepExecutionResult::completed('Validated file existence.', [], ['last_completed_operation'=>'file_exists']);
    }
}
