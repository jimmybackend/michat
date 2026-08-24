<?php
declare(strict_types=1);

/** UTC-only one-shot eligibility for a Task that has not started yet. */
final class TaskScheduleEligibility
{
    public static function isEligible(array $task, ?DateTimeImmutable $now = null): bool
    {
        $scheduled = $task['scheduled_at'] ?? null;
        if ($scheduled === null || $scheduled === '') return true;
        $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', (string)$scheduled, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$at || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new TaskValidationException('scheduled_at_invalid');
        }
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return $at <= $now->setTimezone(new DateTimeZone('UTC'));
    }

    public static function assertEligible(array $task, ?DateTimeImmutable $now = null): void
    {
        if (!self::isEligible($task, $now)) throw new TaskTransitionException('task_not_yet_scheduled');
    }
}
