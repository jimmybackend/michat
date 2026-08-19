<?php
declare(strict_types=1);

final class ChatTaskContext
{
    public function __construct(
        public readonly int $taskId,
        public readonly string $publicId,
        public readonly int $stepId,
        public readonly ?int $executionId,
        public readonly ?string $traceId,
        public int $taskLockVersion,
        public int $stepLockVersion
    ) {}

    public function isRunning(): bool
    {
        return $this->executionId !== null && $this->traceId !== null;
    }
}
