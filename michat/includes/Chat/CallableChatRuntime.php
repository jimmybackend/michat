<?php
declare(strict_types=1);

/** Test/compatibility adapter. Production composition uses BedrockChatRuntime. */
final class CallableChatRuntime implements ChatRuntimeInterface
{
    private Closure $callback;
    public function __construct(callable $callback) { $this->callback = Closure::fromCallable($callback); }
    public function execute(ChatExecutionRequest $request, ?callable $heartbeat = null): ChatExecutionResult
    {
        return ($this->callback)($request, $heartbeat);
    }
}
