<?php
declare(strict_types=1);

final class TaskStepExecutionResult
{
    public function __construct(public readonly string $status, public readonly string $summary = '', public readonly array $artifacts = [], public readonly ?array $checkpoint = null, public readonly ?int $messageId = null) {}
    public static function completed(string $summary, array $artifacts = [], ?array $checkpoint = null, ?int $messageId = null): self { return new self('completed', mb_substr($summary, 0, 1000), $artifacts, $checkpoint, $messageId); }
    public static function waiting(string $summary): self { return new self('waiting_user', mb_substr($summary, 0, 1000)); }
}
