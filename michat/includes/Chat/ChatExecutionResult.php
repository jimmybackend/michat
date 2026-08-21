<?php
declare(strict_types=1);

final class ChatExecutionResult
{
    public readonly ToolExecutionGateDecision $controlDecision;

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
        public readonly ?int $latencyMs = null,
        ?ToolExecutionGateDecision $controlDecision = null
    ) {
        $this->controlDecision = $controlDecision ?? ToolExecutionGateDecision::allow();
    }

    public static function pauseAlreadyPersisted(string $modelId, string $traceId, string $safeSummary, array $tokenUsage = []): self
    {
        return new self('', null, $modelId, $traceId, $tokenUsage, [], [], [], null, null, ToolExecutionGateDecision::pauseAlreadyPersisted($safeSummary));
    }

    public function isPauseAlreadyPersisted(): bool
    {
        return $this->controlDecision->isPauseAlreadyPersisted();
    }
}
