<?php
declare(strict_types=1);

/** Server-side boundary shared by HTTP and workers; it never reads superglobals. */
final class ChatExecutionService
{
    public function __construct(private ChatRuntimeInterface $runtime,private ?ChatContextPreparationService $contexts=null,private ?ChatResponsePersistenceService $responses=null,private ?ChatMemoryFinalizationService $memory=null,private ?ChatTokenUsageService $tokens=null,private ?ChatActivityTelemetryService $activity=null) {}

    public function execute(ChatExecutionRequest $request, ?callable $heartbeat = null): ChatExecutionResult
    {
        $startedAt=microtime(true);$modelStartedAt=null;
        try{
        $heartbeat && $heartbeat();
        if($this->contexts){
            $prepared=$this->contexts->prepare($request);
            $selected=(array)($prepared['bundle']->toPublicArray()['selected_sources']??[]);
            $this->activity?->emit($request->traceId,$request->userId,$request->sessionId,'respond','context_builder_completed','completed','Context Builder · Fase 3','Contexto recuperado, rankeado y ensamblado.',['route'=>$prepared['route'],'telemetry'=>$prepared['bundle']->telemetry(),'selected_sources'=>$selected]);
            $memoryCount=array_sum(array_map(static fn(string $key):int=>(int)($selected[$key]??0),['procedural_memory','session_memory','question_memory','project_context']));
            if($memoryCount>0)$this->activity?->emit($request->traceId,$request->userId,$request->sessionId,'respond','memory_context_selected','completed','Memoria seleccionada',"{$memoryCount} elemento(s) de memoria incorporado(s).",['selected_sources'=>$selected]);
            $ragCount=(int)($selected['project_rag']??0)+(int)($selected['attachments']??0);
            if($ragCount>0)$this->activity?->emit($request->traceId,$request->userId,$request->sessionId,'respond','rag_context_selected','completed','RAG seleccionado',"{$ragCount} fragmento(s) RAG incorporado(s).",['selected_sources'=>$selected]);
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
        $modelStartedAt=microtime(true);
        $result = $this->runtime->execute($request, $heartbeat);
        if($result->isPauseAlreadyPersisted())return$result;
        $modelDuration=$result->latencyMs??max(0,(int)round((microtime(true)-$modelStartedAt)*1000));
        $this->activity?->emit($request->traceId,$request->userId,$request->sessionId,'respond','model_round_completed','completed','Respuesta del modelo completada','El modelo efectivo terminó la generación.',['usage'=>$result->tokenUsage,'stop_reason'=>$result->stopReason],$result->modelId,$modelDuration);
        if($this->responses&&!empty($request->taskContext['persist_final_response'])){
            $messageId=$this->responses->persist((int)$request->taskContext['task_id'],$request->userId,$request->sessionId,$result->replyText,$result->modelId,$request->traceId,$result->tokenUsage,$result->stopReason,$result->latencyMs);
            $result=new ChatExecutionResult($result->replyText,$messageId,$result->modelId,$result->traceId,$result->tokenUsage,$result->tools,$result->artifacts,$result->warnings,$result->stopReason,$result->latencyMs,$result->controlDecision);
            $this->tokens?->recordFinal($request->userId,$request->sessionId,$messageId,$result->modelId,$result->tokenUsage,$result->latencyMs);
            if($this->memory){
                $questionId=(int)($request->originMessageId??0);
                $route=(array)($request->taskContext['prepared_context']['route']??[]);
                $this->memory->finalize($request->userId,$request->sessionId,$request->projectId,$questionId,$messageId,$route,[]);
            }
            $this->activity?->emit($request->traceId,$request->userId,$request->sessionId,'respond','finalization_completed','completed','Respuesta finalizada','Assistant, TokenUsage y memoria final quedaron persistidos.',['assistant_message_id'=>$messageId,'usage'=>$result->tokenUsage],$result->modelId,max(0,(int)round((microtime(true)-$startedAt)*1000)));
        }
        $heartbeat && $heartbeat();
        if (!$result instanceof ChatExecutionResult || $result->traceId !== $request->traceId) {
            throw new RuntimeException('chat_execution_result_invalid');
        }
        $this->activity?->emit($request->traceId,$request->userId,$request->sessionId,'respond','trace_completed','completed','Respuesta terminada',$result->assistantMessageId!==null?'Pipeline Task completo: contexto, modelo y finalización concluyeron.':'Ejecución interna Task completada correctamente.',['assistant_message_id'=>$result->assistantMessageId,'usage'=>$result->tokenUsage],$result->modelId,max(0,(int)round((microtime(true)-$startedAt)*1000)));
        return $result;
        }catch(Throwable $e){
            $this->activity?->emit($request->traceId,$request->userId,$request->sessionId,'respond','runtime_error','error','Error ejecutando la respuesta Task',$e->getMessage(),['exception'=>get_class($e)],null,max(0,(int)round((microtime(true)-$startedAt)*1000)));
            throw $e;
        }
    }
}
