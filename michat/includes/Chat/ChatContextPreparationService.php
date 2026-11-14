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
        // Legal document sessions are deliberately limited to their own
        // current-revision conversation and attachments. Never mix global
        // memories or other documents into a legal answer.
        $legalRevision=(string)($request->taskContext['legal_revision']??'');
        if(!empty($request->taskContext['legal_bridge'])) {
            if(!preg_match('/^[a-f0-9]{64}$/D',$legalRevision)) throw new RuntimeException('legal_revision_missing');
            foreach(['use_policy_procedural_memory','use_answer_procedural_memory',
                     'use_project_context','use_project_rag','use_question_memory',
                     'question_memory_fallback'] as $key) $route[$key]=false;
            $route['use_session_context']=true;
            $route['use_attachment_context']=true;
        }

        $features=(new PipelineFeatureFlags($this->db,$request->userId))->all();
        $bundle=$this->builder->build(new MemoryRoute($route),[
            'stage'=>'respond','user_id'=>$request->userId,'session_id'=>$request->sessionId,
            'project_id'=>$projectId,'memory_scope'=>$scope,'query_text'=>$query,'original_query_text'=>$request->prompt,
            'attachment_mode'=>$this->attachmentMode($request->userId,$request->sessionId),'question_memory_enabled'=>!empty($features['question_memory_read']),
            'question_memory_scope'=>$scope->semanticScope(),'log_message_id'=>$request->originMessageId,
            'pipeline_features'=>$features,'trace_id'=>$request->traceId,
            'legal_revision'=>$legalRevision,
        ]);

        // ContextBuilder invokes ContextRanker and returns only ranked blocks.
        $keys=['procedural_memory_block','session_memory_block','attachment_context_block',
            'question_memory_block','project_memory_block','project_rag_context_block'];
        $blocks=[];
        foreach($keys as$key){$block=trim($bundle->block($key));if($block!=='')$blocks[]=$block;}
        require_once dirname(__DIR__).'/Memory/AttachmentPromptGrounding.php';
        return ['route'=>$route,'bundle'=>$bundle,'system_context'=>AttachmentPromptGrounding::append(implode("\n\n",$blocks),$bundle),'project_id'=>$projectId];
    }
    private function attachmentMode(int $userId, int $sessionId): string
    {
        $stmt = $this->db->prepare('SELECT meta FROM ChatSessions WHERE id_=? AND user_id_=? LIMIT 1');
        if (!$stmt) return 'rag';
        $stmt->bind_param('ii',$sessionId,$userId);
        if (!$stmt->execute()) { $stmt->close(); return 'rag'; }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $meta = json_decode((string)($row['meta'] ?? ''),true);
        return is_array($meta) && (($meta['attachment_rag_mode'] ?? '') === 'always') ? 'always' : 'rag';
    }

}
