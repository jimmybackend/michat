<?php
declare(strict_types=1);

/** Bounded queue maintenance for persisted wait steps; workers never sleep for a step. */
final class TaskWaitService
{
    public function __construct(private TaskQueueRepository $queue) {}
    public function reactivateDue(int $limit = 25): int
    {
        if ($limit < 1 || $limit > 100) throw new InvalidArgumentException('wait_batch_invalid');
        return $this->queue->reactivateDueWaits($limit);
    }
}
