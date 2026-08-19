<?php
declare(strict_types=1);

final class TaskStepStateMachine
{
    private const TRANSITIONS = [
        'pending' => ['ready', 'waiting_user', 'cancelled'],
        'ready' => ['running', 'cancelled'],
        'running' => ['completed', 'failed', 'waiting_user', 'cancelled'],
        'waiting_user' => ['ready', 'cancelled'],
        'waiting_dependency' => ['ready', 'failed', 'cancelled'],
        'completed' => [], 'failed' => [], 'cancelled' => [], 'skipped' => [],
    ];

    public function assertTransition(string $from, string $to): void
    {
        TaskStepStatus::assertValid($from);
        TaskStepStatus::assertValid($to);
        if (!in_array($to, self::TRANSITIONS[$from], true)) {
            throw new TaskTransitionException('step_transition_invalid');
        }
    }
}
