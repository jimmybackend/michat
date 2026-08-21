<?php
declare(strict_types=1);

/** Generic pre-execution boundary; implementations must treat context as server-owned. */
interface ToolExecutionGateInterface
{
    /** @param array<string,mixed> $arguments @param array<string,mixed> $context */
    public function beforeExecute(string $toolKey, array $arguments, array $context): ToolExecutionGateDecision;
}
