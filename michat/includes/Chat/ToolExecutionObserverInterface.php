<?php
declare(strict_types=1);

/** Optional boundary for observing a physical Tool execution without coupling Chat to Tasks. */
interface ToolExecutionObserverInterface
{
    /** @param array<string,mixed> $context */
    public function observe(array $context, ToolExecutionResult $result): void;
}
