<?php
declare(strict_types=1);

final class FinalizeTaskStepExecutor implements TaskStepExecutorInterface { public function execute(array $c, callable $h, callable $x): TaskStepExecutionResult { if($x())throw new TaskTransitionException('cancel_requested');$h();return TaskStepExecutionResult::completed((string)(($c['input']['summary']??'Task finalized.'))); } }
final class ApprovalTaskStepExecutor implements TaskStepExecutorInterface { public function execute(array $c, callable $h, callable $x): TaskStepExecutionResult { return TaskStepExecutionResult::waiting('Approval required.'); } }
final class WaitTaskStepExecutor implements TaskStepExecutorInterface
{
    public function execute(array $context, callable $heartbeat, callable $isCancelled): TaskStepExecutionResult
    {
        if ($isCancelled()) throw new TaskTransitionException('cancel_requested');
        $raw = trim((string)(($context['input'] ?? [])['wait_until'] ?? ''));
        if ($raw === '') throw new TaskValidationException('wait_until_required');
        try { $until = (new DateTimeImmutable($raw))->setTimezone(new DateTimeZone('UTC')); }
        catch (Throwable) { throw new TaskValidationException('wait_until_invalid'); }
        $now = isset($context['now']) ? new DateTimeImmutable((string)$context['now']) : new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($until <= $now) return TaskStepExecutionResult::completed('Wait condition reached.');
        $heartbeat();
        return TaskStepExecutionResult::waitingDependency('Waiting until '.$until->format(DateTimeInterface::ATOM).'.', ['wait_until'=>$until->format('Y-m-d H:i:s.u')]);
    }
}
final class PlanTaskStepExecutor implements TaskStepExecutorInterface { public function execute(array $c, callable $h, callable $x): TaskStepExecutionResult { throw new TaskValidationException('recursive_plan_not_supported'); } }
