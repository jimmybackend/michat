<?php
declare(strict_types=1);

final class ToolExecutionResult
{
    public function __construct(public readonly string $summary, public readonly array $artifacts = [], public readonly array $data = [], public readonly bool $success = true, public readonly string $status = 'ok', public readonly ?int $toolCallId = null) {}
}
