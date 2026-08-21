<?php
declare(strict_types=1);

/** Optional final-response boundary for a pre-execution Tool gate. */
interface ToolExecutionCompletionGuardInterface
{
    /** @param array<string,mixed> $context Server-owned execution context. */
    public function assertCompletionAllowed(array $context): void;
}
