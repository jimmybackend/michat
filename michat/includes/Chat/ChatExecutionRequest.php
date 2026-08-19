<?php
declare(strict_types=1);

final class ChatExecutionRequest
{
    public function __construct(
        public readonly int $userId,
        public readonly int $sessionId,
        public readonly ?int $projectId,
        public readonly ?int $originMessageId,
        public readonly string $requestId,
        public readonly ?int $compilationId,
        public readonly string $prompt,
        public readonly ?string $compiledPrompt,
        public readonly ?string $model,
        public readonly float $temperature,
        public readonly int $maxTokens,
        public readonly float $topP,
        public readonly string $traceId,
        public readonly array $taskContext = []
    ) {
        if ($userId < 1 || $sessionId < 1 || trim($prompt) === '' || trim($traceId) === '') {
            throw new InvalidArgumentException('chat_execution_request_invalid');
        }
    }
}
