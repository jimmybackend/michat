<?php
declare(strict_types=1);

/** Server-side boundary shared by HTTP and workers; it never reads superglobals. */
final class ChatExecutionService
{
    public function __construct(private ChatRuntimeInterface $runtime) {}

    public function execute(ChatExecutionRequest $request, ?callable $heartbeat = null): ChatExecutionResult
    {
        $heartbeat && $heartbeat();
        $result = $this->runtime->execute($request, $heartbeat);
        $heartbeat && $heartbeat();
        if (!$result instanceof ChatExecutionResult || $result->traceId !== $request->traceId) {
            throw new RuntimeException('chat_execution_result_invalid');
        }
        return $result;
    }
}
