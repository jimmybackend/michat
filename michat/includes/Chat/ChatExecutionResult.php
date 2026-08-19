<?php
declare(strict_types=1);

final class ChatExecutionResult
{
    public function __construct(
        public readonly string $replyText,
        public readonly ?int $assistantMessageId,
        public readonly string $modelId,
        public readonly string $traceId,
        public readonly array $tokenUsage = [],
        public readonly array $tools = [],
        public readonly array $artifacts = [],
        public readonly array $warnings = [],
        public readonly ?string $stopReason = null,
        public readonly ?int $latencyMs = null
    ) {}
}
