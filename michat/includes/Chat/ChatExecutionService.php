<?php
declare(strict_types=1);

/** Server-side boundary shared by HTTP and workers; it never reads superglobals. */
final class ChatExecutionService
{
    private Closure $runtime;

    public function __construct(callable $runtime)
    {
        $this->runtime = Closure::fromCallable($runtime);
    }

    public function execute(ChatExecutionRequest $request, ?callable $heartbeat = null): ChatExecutionResult
    {
        $heartbeat && $heartbeat();
        $result = ($this->runtime)($request, $heartbeat);
        $heartbeat && $heartbeat();
        if (!$result instanceof ChatExecutionResult || $result->traceId !== $request->traceId) {
            throw new RuntimeException('chat_execution_result_invalid');
        }
        return $result;
    }
}
