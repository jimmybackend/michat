<?php
declare(strict_types=1);

/** Server-side boundary shared by HTTP and workers; it never reads superglobals. */
final class ChatExecutionService
{
    public function __construct(private ChatRuntimeInterface $runtime,private ?ChatContextPreparationService $contexts=null,private ?ChatResponsePersistenceService $responses=null) {}

    public function execute(ChatExecutionRequest $request, ?callable $heartbeat = null): ChatExecutionResult
    {
        $heartbeat && $heartbeat();
        if($this->contexts){
            $prepared=$this->contexts->prepare($request);
            $request=new ChatExecutionRequest(
                $request->userId,$request->sessionId,$prepared['project_id'],$request->originMessageId,
                $request->requestId,$request->compilationId,$request->prompt,$request->compiledPrompt,
                $request->model,$request->temperature,$request->maxTokens,$request->topP,$request->traceId,
                array_merge($request->taskContext,['prepared_context'=>[
                    'route'=>$prepared['route'],'system_context'=>$prepared['system_context'],
                    'telemetry'=>$prepared['bundle']->telemetry(),
                ]])
            );
        }
        $result = $this->runtime->execute($request, $heartbeat);
        if($this->responses&&!empty($request->taskContext['persist_final_response'])){
            $messageId=$this->responses->persist((int)$request->taskContext['task_id'],$request->userId,$request->sessionId,$result->replyText,$result->modelId,$request->traceId,$result->tokenUsage,$result->stopReason,$result->latencyMs);
            $result=new ChatExecutionResult($result->replyText,$messageId,$result->modelId,$result->traceId,$result->tokenUsage,$result->tools,$result->artifacts,$result->warnings,$result->stopReason,$result->latencyMs);
        }
        $heartbeat && $heartbeat();
        if (!$result instanceof ChatExecutionResult || $result->traceId !== $request->traceId) {
            throw new RuntimeException('chat_execution_result_invalid');
        }
        return $result;
    }
}
