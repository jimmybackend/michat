<?php
declare(strict_types=1);

/** Prepares the server-owned Memory/RAG context consumed by every Task runtime. */
final class ChatContextPreparationService
{
    public function __construct(
        private mysqli $db,
        private MemoryContextRouter $router,
        private ContextBuilder $builder
    ) {}

    /** @return array{route:array<string,mixed>,bundle:ContextBundle,system_context:string,project_id:?int} */
    public function prepare(ChatExecutionRequest $request): array
    {
        // ConversationScopeResolver is the ownership boundary: the persisted
        // session decides the project, never an AI response or request payload.
        $scope=(new ConversationScopeResolver($this->db))->resolve($request->userId,$request->sessionId);
        $projectId=$scope->projectId();
        $query=trim((string)($request->compiledPrompt ?: $request->prompt));
        $route=$this->router->route($query,[
            'has_project'=>$scope->isProject(),'project_id'=>$projectId,
            'scope_kind'=>$scope->kind(),'has_lineage'=>$scope->hasLineage(),
        ]);
        $route['memory_scope']=$scope->toArray();

        $features=(new PipelineFeatureFlags($this->db,$request->userId))->all();
        $bundle=$this->builder->build(new MemoryRoute($route),[
            'stage'=>'respond','user_id'=>$request->userId,'session_id'=>$request->sessionId,
            'project_id'=>$projectId,'memory_scope'=>$scope,'query_text'=>$query,
            'attachment_mode'=>'rag','question_memory_enabled'=>!empty($features['question_memory_read']),
            'question_memory_scope'=>$scope->semanticScope(),'log_message_id'=>$request->originMessageId,
            'pipeline_features'=>$features,
        ]);

        // ContextBuilder invokes ContextRanker and returns only ranked blocks.
        $keys=['procedural_memory_block','session_memory_block','attachment_context_block',
            'question_memory_block','project_memory_block','project_rag_context_block'];
        $blocks=[];
        foreach($keys as$key){$block=trim($bundle->block($key));if($block!=='')$blocks[]=$block;}
        return ['route'=>$route,'bundle'=>$bundle,'system_context'=>implode("\n\n",$blocks),'project_id'=>$projectId];
    }
}
