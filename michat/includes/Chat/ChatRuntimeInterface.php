<?php
declare(strict_types=1);

interface ChatRuntimeInterface
{
    public function execute(ChatExecutionRequest $request, ?callable $heartbeat = null): ChatExecutionResult;
}
