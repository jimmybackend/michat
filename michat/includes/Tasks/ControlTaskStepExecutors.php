<?php
declare(strict_types=1);

final class FinalizeTaskStepExecutor implements TaskStepExecutorInterface { public function execute(array $c, callable $h, callable $x): TaskStepExecutionResult { if($x())throw new TaskTransitionException('cancel_requested');$h();return TaskStepExecutionResult::completed((string)(($c['input']['summary']??'Task finalized.'))); } }
final class ApprovalTaskStepExecutor implements TaskStepExecutorInterface { public function execute(array $c, callable $h, callable $x): TaskStepExecutionResult { return TaskStepExecutionResult::waiting('Approval required.'); } }
final class WaitTaskStepExecutor implements TaskStepExecutorInterface { public function execute(array $c, callable $h, callable $x): TaskStepExecutionResult { return TaskStepExecutionResult::waiting('Waiting condition requires resumption.'); } }
final class PlanTaskStepExecutor implements TaskStepExecutorInterface { public function execute(array $c, callable $h, callable $x): TaskStepExecutionResult { throw new TaskValidationException('recursive_plan_not_supported'); } }
