<?php
declare(strict_types=1);

/** Request-agnostic production runtime. AWS clients come exclusively from Config. */
final class BedrockChatRuntime implements ChatRuntimeInterface
{
    private $configLoader;
    public function __construct(private mysqli $db, private ToolRegistry $tools,private ?TaskCancellationGuard $cancellations=null,private ?ToolExecutionObserverInterface $toolObserver=null,private ?ToolExecutionGateInterface $toolGate=null,?callable $configLoader=null,private ?object $bedrockRuntime=null)
    {
        $this->configLoader=$configLoader;
    }

    public function execute(ChatExecutionRequest $request, ?callable $heartbeat = null): ChatExecutionResult
    {
        $heartbeat && $heartbeat();
        $configs = $this->configLoader !== null ? ($this->configLoader)($this->db, $request->userId) : loadDynamicAIAgentConfigs($this->db, $request->userId);
        $agent = (string)($request->taskContext['agent_key'] ?? 'chat_main');
        $config = $configs[$agent] ?? $configs['chat_main'] ?? null;
        if (!$config || (int)($config['is_active'] ?? 0) !== 1) throw new RuntimeException('chat_agent_unavailable');
        $model = trim((string)($request->model ?: $config['model_id'] ?? ''));
        if ($model === '') throw new RuntimeException('chat_model_unavailable');
        $prompt = $request->compiledPrompt ?: $request->prompt;
        $params = [
            'modelId'=>$model,
            'messages'=>[['role'=>'user','content'=>[['text'=>$prompt]]]],
            'inferenceConfig'=>['temperature'=>$request->temperature,'maxTokens'=>$request->maxTokens,'topP'=>$request->topP],
        ];
        $instruction = trim((string)($config['system_instruction'] ?? ''));
        $preparedContext=trim((string)($request->taskContext['prepared_context']['system_context']??''));
        if($preparedContext!=='')$instruction=trim($instruction."\n\n".$preparedContext);
        if ($instruction !== '') $params['system'] = [['text'=>$instruction]];
        $bedrock = $this->bedrockRuntime ?? Config::getBedrockRuntime();
        $usage = ['prompt_tokens'=>0,'completion_tokens'=>0,'total_tokens'=>0];
        $budget=isset($request->taskContext['task_id'])?TaskExecutionBudget::serverDefaults():null;
        for ($round=0; $round<5; $round++) {
            $budget?->beforeModelRound();
            $this->checkpoint($request);
            $heartbeat && $heartbeat();
            $response = $bedrock->converse($params);
            // Cancellation may arrive while Bedrock is in flight. Do not consume the
            // response or proceed to persistence/tool execution after that point.
            $this->checkpoint($request);
            $usage['prompt_tokens'] += (int)($response['usage']['inputTokens'] ?? 0);
            $usage['completion_tokens'] += (int)($response['usage']['outputTokens'] ?? 0);
            $usage['total_tokens'] += (int)($response['usage']['totalTokens'] ?? 0);
            $budget?->recordUsage((int)($response['usage']['inputTokens']??0),(int)($response['usage']['outputTokens']??0));
            $blocks = $response['output']['message']['content'] ?? [];
            $text=''; $uses=[];
            foreach ($blocks as $block) { if(isset($block['text']))$text.=$block['text']; elseif(isset($block['toolUse']))$uses[]=$block['toolUse']; }
            if (($response['stopReason'] ?? '') !== 'tool_use' || !$uses) {
                if($this->toolGate instanceof ToolExecutionCompletionGuardInterface)$this->toolGate->assertCompletionAllowed($this->serverContext($request));
                return new ChatExecutionResult(trim($text),null,$model,$request->traceId,$usage,[],[],[],(string)($response['stopReason']??''),null);
            }
            $params['messages'][] = $response['output']['message']; $results=[];
            foreach ($uses as $use) {
                $this->checkpoint($request);
                $heartbeat && $heartbeat();
                $toolInput = ['arguments'=>(array)($use['input'] ?? []),'context'=>$this->serverContext($request)];
                $budget?->beforeTool($this->tools->effect((string)($use['name']??'')));
                $decision=$this->toolGate?->beforeExecute((string)($use['name']??''),$toolInput['arguments'],$toolInput['context'])??ToolExecutionGateDecision::allow();
                if($decision->isPauseAlreadyPersisted())return ChatExecutionResult::pauseAlreadyPersisted($model,$request->traceId,$decision->safeSummary,$usage);
                $result=$this->tools->execute((string)($use['name'] ?? ''),$toolInput);
                $this->toolObserver?->observe($toolInput['context'],$result);
                $results[]=['toolResult'=>['toolUseId'=>(string)$use['toolUseId'],'content'=>[['text'=>json_encode(['success'=>$result->success,'summary'=>$result->summary,'data'=>$result->data],JSON_UNESCAPED_UNICODE)]]]];
            }
            $params['messages'][]=['role'=>'user','content'=>$results];
        }
        throw new RuntimeException('chat_tool_round_limit');
    }
    private function checkpoint(ChatExecutionRequest$request):void{$this->cancellations?->assertActive(['task_id'=>$request->taskContext['task_id']??null,'user_id'=>$request->userId]);}
    /** @return array<string,mixed> */
    private function serverContext(ChatExecutionRequest$request):array{return['user_id'=>$request->userId,'project_id'=>$request->projectId,'session_id'=>$request->sessionId,'trace_id'=>$request->traceId,'execution_id'=>$request->taskContext['execution_id']??null,'task_id'=>$request->taskContext['task_id']??null,'step_id'=>$request->taskContext['step_id']??null];}
}
