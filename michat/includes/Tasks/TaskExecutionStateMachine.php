<?php
declare(strict_types=1);

final class TaskExecutionStateMachine
{
    private const TRANSITIONS = [
        'queued' => ['running', 'cancelled'],
        'running' => ['completed', 'failed', 'cancelled'],
        'waiting' => ['running', 'failed', 'cancelled'],
        'completed' => [],
        'failed' => [],
        'cancelled' => [],
        'abandoned' => [],
    ];

    public function canTransition(string $from, string $to): bool
    {
        TaskExecutionStatus::assertValid($from);
        TaskExecutionStatus::assertValid($to);
        return in_array($to, self::TRANSITIONS[$from], true);
    }

    public function assertTransition(string $from, string $to): void
    {
        if (!$this->canTransition($from, $to)) {
            throw new TaskTransitionException('execution_transition_invalid');
        }
    }

    /** @return string[] */
    public function transitionsFrom(string $status): array
    {
        TaskExecutionStatus::assertValid($status);
        return self::TRANSITIONS[$status];
    }
}
