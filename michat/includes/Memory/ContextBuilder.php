<?php

declare(strict_types=1);

/**
 * Fase 2 + Fase 3: punto único de recuperación, ranking y construcción.
 *
 * MemoryContextRouter decide QUÉ consultar.
 * Los Repository recuperan candidatos.
 * ContextRanker decide QUÉ candidatos entran realmente y en qué orden.
 * ContextBuilder ensambla únicamente los elementos seleccionados.
 */
final class ContextBuilder
{
    private mysqli $db;
    private $bedrock;
    private ProceduralMemoryRepository $proceduralRepo;
    private ProjectContextRepository $projectContextRepo;
    private SessionMemoryRepository $sessionRepo;
    private ProjectRagRepository $projectRagRepo;
    private AttachmentContextRepository $attachmentRepo;
    private QuestionMemoryRepository $questionMemoryRepo;
    private ContextRanker $ranker;

    public function __construct(mysqli $db, $bedrock)
    {
        $this->db = $db;
        $this->bedrock = $bedrock;
        $this->proceduralRepo = new ProceduralMemoryRepository($db);
        $this->projectContextRepo = new ProjectContextRepository($db);
        $this->sessionRepo = new SessionMemoryRepository($db);
        $this->projectRagRepo = new ProjectRagRepository($db);
        $this->attachmentRepo = new AttachmentContextRepository($db, $bedrock);
        $this->questionMemoryRepo = new QuestionMemoryRepository($db, $bedrock);
        $this->ranker = new ContextRanker();
    }

    /**
     * @param MemoryRoute|array<string,mixed> $route
     * @param array<string,mixed> $request
     */
    public function build($route, array $request): ContextBundle
    {
        $memoryRoute = $route instanceof MemoryRoute ? $route : new MemoryRoute((array)$route);
        $stage = (($request['stage'] ?? 'respond') === 'compile') ? 'compile' : 'respond';

        $userId = (int)($request['user_id'] ?? 0);
        $sessionId = (int)($request['session_id'] ?? 0);
        $projectId = (int)($request['project_id'] ?? 0);
        $queryText = trim((string)($request['query_text'] ?? ''));
        $attachmentMode = ((string)($request['attachment_mode'] ?? 'rag') === 'always') ? 'always' : 'rag';
        $questionMemoryEnabled = !empty($request['question_memory_enabled']);
        $requestedQuestionMemoryScope = ((string)($request['question_memory_scope'] ?? 'session') === 'project') ? 'project' : 'session';
        $memoryScope = ($request['memory_scope'] ?? null) instanceof ConversationScope
            ? $request['memory_scope']
            : (new ConversationScopeResolver($this->db))->resolve($userId, $sessionId);
        // Fase 4.1: el scope real lo decide ChatSessions, no el selector del navegador.
        $projectId = $memoryScope->projectId();
        $questionMemoryScope = $memoryScope->semanticScope();
        $questionMemoryMaxCandidates = max(5, min(50, (int)($request['question_memory_max_candidates'] ?? 20)));
        $questionMemoryWindowLines = max(2, min(15, (int)($request['question_memory_window_lines'] ?? 5)));
        $logMsgId = isset($request['log_message_id']) && $request['log_message_id'] !== null
            ? (int)$request['log_message_id']
            : null;

        // Fase 5: permisos funcionales independientes de la decisión del Router.
        // El Router dice QUÉ sería útil; estos flags dicen QUÉ puede ejecutarse.
        $features = array_merge([
            'procedural_memory_read' => true,
            'project_memory_read' => true,
            'session_memory_read' => true,
            'question_memory_read' => true,
            'project_rag' => true,
            'attachment_rag' => true,
            'context_ranking' => true,
        ], (array)($request['pipeline_features'] ?? []));
        foreach ($features as $featureKey => $featureValue) {
            $features[$featureKey] = (bool)$featureValue;
        }

        $bundle = new ContextBundle();
        $bundle->setRequests($memoryRoute->contextRequests($stage));
        $startedAt = microtime(true);

        // -------------------------------------------------------------
        // 1) Memoria procedural tipada
        // -------------------------------------------------------------
        $proceduralPolicy = [];
        $proceduralAnswer = [];

        if ($features['procedural_memory_read'] && $stage === 'respond' && $memoryRoute->uses('use_policy_procedural_memory')) {
            $proceduralPolicy = $this->proceduralRepo->retrieve($userId, [], 10, $memoryScope);
            $bundle->addItems('procedural_policy', $proceduralPolicy);
        }

        if ($features['procedural_memory_read'] && $memoryRoute->uses('use_answer_procedural_memory')) {
            $answerTypes = $memoryRoute->answerProceduralTypes();
            $proceduralAnswer = $this->proceduralRepo->retrieve($userId, $answerTypes, 10, $memoryScope);
            $bundle->addItems('procedural_answer', $this->deduplicateItems($proceduralAnswer, $proceduralPolicy));
        }

        $bundle->setTelemetry('scope_guard', [
            'scope' => $memoryScope->toArray(),
            'requested_question_memory_scope' => $requestedQuestionMemoryScope,
            'effective_question_memory_scope' => $questionMemoryScope,
            'cross_free_session_memory_allowed' => $memoryScope->isBranch(),
        ]);

        $bundle->setTelemetry('pipeline_features', $features);

        $bundle->setTelemetry('procedural', [
            'feature_enabled' => $features['procedural_memory_read'],
            'policy_count' => count($proceduralPolicy),
            'answer_count' => count($proceduralAnswer),
            'answer_types' => $memoryRoute->answerProceduralTypes(),
        ]);

        // -------------------------------------------------------------
        // 2) ProjectContext por tipos
        // -------------------------------------------------------------
        $projectItems = [];
        if ($features['project_memory_read'] && $projectId > 0 && $memoryRoute->uses('use_project_context')) {
            $projectItems = $this->projectContextRepo->retrieve(
                $userId,
                $projectId,
                $memoryRoute->projectContextTypes(),
                20
            );
            $bundle->addItems('project_context', $projectItems);
        }
        $bundle->setTelemetry('project_context', [
            'requested' => $memoryRoute->uses('use_project_context'),
            'feature_enabled' => $features['project_memory_read'],
            'types' => $memoryRoute->projectContextTypes(),
            'count' => count($projectItems),
        ]);

        // -------------------------------------------------------------
        // 3) Memoria consolidada de sesión
        // -------------------------------------------------------------
        $sessionResult = ['context' => '', 'items' => [], 'telemetry' => ['source' => 'router_skipped']];
        if ($features['session_memory_read'] && $memoryRoute->uses('use_session_context')) {
            $sessionResult = $this->sessionRepo->retrieve($userId, $sessionId, $queryText, 30, $memoryScope);
            $bundle->addItems('session_memory', $sessionResult['items']);
        }
        $bundle->setTelemetry('session', $sessionResult['telemetry']);

        // -------------------------------------------------------------
        // Contexto barato del compilador: ranking sí, embeddings no.
        // -------------------------------------------------------------
        if ($stage === 'compile') {
            if ($features['session_memory_read'] && $memoryRoute->uses('use_session_context')) {
                $bundle->addItems('recent_messages', $this->sessionRepo->recentMessages($userId, $sessionId, 6));
            }

            $ranking = $features['context_ranking']
                ? $this->ranker->rank($bundle, $memoryRoute, $queryText, 'compile')
                : $this->ranker->selectWithoutRanking($bundle, 'compile');
            $bundle->setRankingResult($ranking);
            $bundle->setTelemetry('ranking', $ranking->summary());

            $compilerContext = $this->formatCompilerContext(
                $bundle->selectedItems('procedural_answer'),
                $bundle->selectedItems('project_context'),
                $bundle->selectedItems('session_memory'),
                $bundle->selectedItems('recent_messages')
            );
            $bundle->setBlock('compiler_context', $compilerContext);
            $this->formatBlocks($bundle);

            $bundle->setTelemetry('builder', [
                'stage' => 'compile',
                'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
                'semantic_sources_skipped' => true,
                'ranking' => $ranking->summary(),
            ]);
            return $bundle;
        }

        // -------------------------------------------------------------
        // 4) Determinar fuentes semánticas y generar UN solo embedding.
        // -------------------------------------------------------------
        $hasAttachments = $features['attachment_rag']
            && $memoryRoute->uses('use_attachment_context')
            && $this->attachmentRepo->hasContext($userId, $sessionId);

        $effectiveQuestionMemory = $memoryRoute->shouldUseQuestionMemory(
            $questionMemoryEnabled && $features['question_memory_read'],
            count($projectItems),
            'respond'
        );

        $needsVector = (
            ($features['project_rag'] && $projectId > 0 && $memoryRoute->uses('use_project_rag'))
            || ($hasAttachments && $attachmentMode === 'rag')
            || $effectiveQuestionMemory
        );

        $embeddingProvider = new QueryEmbeddingProvider($this->db, $this->bedrock, $sessionId, $logMsgId);
        $queryVector = $needsVector ? $embeddingProvider->get($queryText) : [];
        $bundle->setTelemetry('query_embedding', $embeddingProvider->telemetry() + [
            'needed' => $needsVector,
            'shared_by_sources' => [
                'project_rag' => $features['project_rag'] && $projectId > 0 && $memoryRoute->uses('use_project_rag'),
                'attachments' => $hasAttachments && $attachmentMode === 'rag',
                'question_memory' => $effectiveQuestionMemory,
            ],
        ]);

        // -------------------------------------------------------------
        // 5) RAG del proyecto. Mantiene threshold semántico existente.
        // -------------------------------------------------------------
        $projectRag = ['context' => '', 'items' => [], 'telemetry' => ['candidates' => 0, 'selected' => []]];
        if ($features['project_rag'] && $projectId > 0 && $memoryRoute->uses('use_project_rag') && $queryVector) {
            $projectRag = $this->projectRagRepo->retrieve(
                $userId,
                $projectId,
                $queryVector,
                (string)aiAgentModel('embedding_main', ''),
                (float)aiAgentExtra('embedding_main', 'project_rag_threshold', 0.30),
                max(1, min(12, (int)aiAgentExtra('embedding_main', 'project_rag_top', 4))),
                max(10, min(500, (int)aiAgentExtra('embedding_main', 'project_rag_candidates', 150)))
            );
            $bundle->addItems('project_rag', $projectRag['items']);
        }
        $bundle->setTelemetry('project_rag', $projectRag['telemetry'] + [
            'requested' => $memoryRoute->uses('use_project_rag'),
            'feature_enabled' => $features['project_rag'],
        ]);

        // -------------------------------------------------------------
        // 6) Adjuntos de sesión
        // -------------------------------------------------------------
        $attachmentResult = ['context' => '', 'items' => [], 'telemetry' => [
            'mode' => $attachmentMode,
            'candidates' => 0,
            'selected' => [],
            'has_attachment_memory' => $hasAttachments,
        ]];
        if ($hasAttachments && ($attachmentMode === 'always' || !empty($queryVector))) {
            $attachmentResult = $this->attachmentRepo->retrieve(
                $userId,
                $sessionId,
                $queryText,
                $attachmentMode,
                $queryVector ?: null,
                $logMsgId
            );
            $attachmentResult['telemetry']['has_attachment_memory'] = true;
            $bundle->addItems('attachments', $attachmentResult['items']);
        } elseif ($hasAttachments && $attachmentMode === 'rag') {
            $attachmentResult['telemetry']['skip_reason'] = 'query_embedding_unavailable';
        }
        $bundle->setTelemetry('attachments', $attachmentResult['telemetry'] + [
            'requested' => $memoryRoute->uses('use_attachment_context'),
            'feature_enabled' => $features['attachment_rag'],
        ]);

        // -------------------------------------------------------------
        // 7) Memoria semántica Q&A: directa o fallback tipado.
        // -------------------------------------------------------------
        $questionMemory = $this->emptyQuestionMemory($questionMemoryScope);
        if ($effectiveQuestionMemory && !empty($queryVector)) {
            $qmResult = $this->questionMemoryRepo->retrieve(
                $userId,
                $sessionId,
                $projectId,
                $queryText,
                $questionMemoryScope,
                $questionMemoryMaxCandidates,
                $questionMemoryWindowLines,
                $queryVector,
                $logMsgId,
                $memoryScope
            );
            $questionMemory = $qmResult['legacy'];
            $bundle->addItems('question_memory', $qmResult['items']);
        }

        $bundle->setTelemetry('question_memory', [
            'user_preference_enabled' => $questionMemoryEnabled,
            'feature_enabled' => $features['question_memory_read'],
            'router_direct' => $memoryRoute->uses('use_question_memory'),
            'router_fallback' => $memoryRoute->uses('question_memory_fallback'),
            'fallback_activated' => $memoryRoute->uses('question_memory_fallback') && count($projectItems) === 0,
            'effective_enabled' => $effectiveQuestionMemory,
            'skip_reason' => ($effectiveQuestionMemory && empty($queryVector)) ? 'query_embedding_unavailable' : null,
            'scope' => $questionMemory['scope'] ?? $questionMemoryScope,
            'candidates' => $questionMemory['candidates'] ?? 0,
            'fragments_before_ranking' => $questionMemory['fragments'] ?? 0,
            'matches_before_ranking' => $questionMemory['matches'] ?? [],
            'candidate_scores' => $questionMemory['candidate_scores'] ?? [],
            'reindex_queued' => $questionMemory['reindex_queued'] ?? 0,
        ]);

        // -------------------------------------------------------------
        // 8) FASE 3: ranking multi-señal + deduplicación + selección.
        // -------------------------------------------------------------
        $ranking = $features['context_ranking']
            ? $this->ranker->rank($bundle, $memoryRoute, $queryText, 'respond')
            : $this->ranker->selectWithoutRanking($bundle, 'respond');
        $bundle->setRankingResult($ranking);
        $bundle->setTelemetry('ranking', $ranking->summary());

        // El contrato histórico de memoria Q&A refleja ahora lo que realmente
        // entró al prompt, no sólo lo que superó el threshold vectorial.
        $questionMemory = $this->applyQuestionMemoryRanking(
            $questionMemory,
            $bundle->selectedItems('question_memory')
        );
        $bundle->setLegacy('question_memory', $questionMemory);

        // -------------------------------------------------------------
        // 9) Bloques finales: sólo ContextItem seleccionados por Ranking.
        // -------------------------------------------------------------
        $this->formatBlocks($bundle);

        $bundle->setTelemetry('builder', [
            'stage' => 'respond',
            'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
            'retrieved_counts' => [
                'project_context' => count($bundle->items('project_context')),
                'session' => count($bundle->items('session_memory')),
                'attachments' => count($bundle->items('attachments')),
                'question_memory' => count($bundle->items('question_memory')),
                'project_rag' => count($bundle->items('project_rag')),
            ],
            'selected_counts' => [
                'project_context' => count($bundle->selectedItems('project_context')),
                'session' => count($bundle->selectedItems('session_memory')),
                'attachments' => count($bundle->selectedItems('attachments')),
                'question_memory' => count($bundle->selectedItems('question_memory')),
                'project_rag' => count($bundle->selectedItems('project_rag')),
            ],
            'ranking' => $ranking->summary(),
        ]);

        return $bundle;
    }

    private function formatBlocks(ContextBundle $bundle): void
    {
        $procedural = $this->mergeUnique(
            $bundle->selectedItems('procedural_policy'),
            $bundle->selectedItems('procedural_answer')
        );
        $proceduralBlock = '';
        if ($procedural) {
            $labelCfg = aiAgentExtra('chat_main_procedural_labels', 'type_labels', []);
            if (!is_array($labelCfg)) $labelCfg = [];
            $itemTemplate = aiAgentInstruction('chat_main_procedural_item_template', '{{index}}. [{{type_label}}] {{content}}');
            $parts = [];
            foreach ($procedural as $idx => $item) {
                $label = (string)($labelCfg[$item->type] ?? strtoupper($item->type));
                $parts[] = aiRenderTemplate($itemTemplate, [
                    'index' => $idx + 1,
                    'type_label' => $label,
                    'content' => $item->content,
                ]);
            }
            $proceduralBlock = aiRenderTemplate(
                aiAgentInstruction('chat_main_procedural_template', "[PATRONES Y PREFERENCIAS DEL USUARIO - MEMORIA PROCEDURAL]\n{{items}}"),
                ['items' => implode("\n", $parts)]
            );
        }
        $bundle->setBlock('procedural_memory_block', $proceduralBlock);

        $projectItems = $bundle->selectedItems('project_context');
        $projectBlock = '';
        if ($projectItems) {
            $lines = [
                '[MEMORIA DIRIGIDA DEL PROYECTO]',
                'Información estructurada seleccionada por Router + Context Ranker:',
            ];
            foreach ($projectItems as $item) {
                $title = trim((string)($item->metadata['title'] ?? ''));
                $raw = trim((string)($item->metadata['raw_content'] ?? $item->content));
                $prefix = '[' . strtoupper($item->type) . ']';
                if ($title !== '') $prefix .= ' ' . $title . ':';
                $lines[] = $prefix . ' ' . $raw;
            }
            $projectBlock = implode("\n", $lines);
        }
        $bundle->setBlock('project_memory_block', $projectBlock);

        $sessionContext = $this->formatSessionItems($bundle->selectedItems('session_memory'));
        $bundle->setBlock(
            'session_memory_block',
            $sessionContext !== ''
                ? aiRenderTemplate(
                    aiAgentInstruction('chat_main_session_memory_template', "[MEMORIA DE ESTA SESIÓN]\n{{session_memory}}"),
                    ['session_memory' => $sessionContext]
                )
                : ''
        );

        $attachmentContext = $this->formatAttachmentItems($bundle->selectedItems('attachments'));
        $bundle->setBlock(
            'attachment_context_block',
            $attachmentContext !== ''
                ? aiRenderTemplate(
                    aiAgentInstruction('chat_main_attachment_template', "[ARCHIVOS ADJUNTOS DE ESTA SESIÓN]\n{{attachment_context}}"),
                    ['attachment_context' => $attachmentContext]
                )
                : ''
        );

        $questionContext = $this->formatQuestionItems($bundle->selectedItems('question_memory'));
        $bundle->setBlock(
            'question_memory_block',
            $questionContext !== ''
                ? aiRenderTemplate(
                    aiAgentInstruction('chat_main_question_memory_template', "[MEMORIA SELECTIVA DE PREGUNTAS ANTERIORES]\n{{question_memory}}"),
                    ['question_memory' => $questionContext]
                )
                : ''
        );

        $ragContext = $this->formatProjectRagItems($bundle->selectedItems('project_rag'));
        $bundle->setBlock(
            'project_rag_context_block',
            $ragContext !== ''
                ? aiRenderTemplate(
                    aiAgentInstruction('chat_main_rag_context_template', "[CONTEXTO DE ARCHIVOS]\n{{rag_context}}"),
                    ['rag_context' => $ragContext]
                )
                : ''
        );
    }

    /**
     * @param ContextItem[] $procedural
     * @param ContextItem[] $projectItems
     * @param ContextItem[] $sessionItems
     * @param ContextItem[] $recentItems
     */
    private function formatCompilerContext(array $procedural, array $projectItems, array $sessionItems, array $recentItems): string
    {
        $parts = [];

        if ($procedural) {
            $lines = [];
            foreach ($procedural as $item) $lines[] = '[' . strtoupper($item->type) . '] ' . $item->content;
            $parts[] = "MEMORIA PROCEDURAL RELEVANTE:\n" . implode("\n", $lines);
        }

        if ($projectItems) {
            $lines = [];
            foreach ($projectItems as $item) $lines[] = '[' . strtoupper($item->type) . '] ' . $item->content;
            $parts[] = "MEMORIA ESTRUCTURADA DEL PROYECTO:\n" . implode("\n", $lines);
        }

        $sessionContext = $this->formatSessionItems($sessionItems);
        if ($sessionContext !== '') $parts[] = "MEMORIA DE SESIÓN:\n" . $sessionContext;

        if ($recentItems) {
            $userLabel = aiAgentInstruction('prompt_compiler_context_recent_user_label', 'USUARIO');
            $assistantLabel = aiAgentInstruction('prompt_compiler_context_recent_assistant_label', 'ASISTENTE');
            $itemTemplate = aiAgentInstruction('prompt_compiler_context_recent_item_template', '[{{role_label}}]: {{content}}');
            $lines = [];
            foreach ($recentItems as $item) {
                $lines[] = aiRenderTemplate($itemTemplate, [
                    'role_label' => $item->type === 'user' ? $userLabel : $assistantLabel,
                    'content' => mb_substr($item->content, 0, 240),
                ]);
            }
            $parts[] = aiAgentInstruction(
                'prompt_compiler_context_recent_header',
                'ÚLTIMOS MENSAJES DE LA CONVERSACIÓN (para entender el contexto):'
            ) . "\n" . implode("\n", $lines);
        }

        return trim(implode("\n\n", $parts));
    }

    /** @param ContextItem[] $items */
    private function formatSessionItems(array $items): string
    {
        if (!$items) return '';
        $lines = [];
        foreach ($items as $idx => $item) {
            $lines[] = ($idx + 1) . '. [' . strtoupper($item->type) . '] ' . mb_substr($item->content, 0, 500);
        }
        return implode("\n", $lines);
    }

    /** @param ContextItem[] $items */
    private function formatAttachmentItems(array $items): string
    {
        if (!$items) return '';
        $parts = [];
        foreach ($items as $idx => $item) {
            $filename = trim((string)($item->metadata['filename'] ?? 'adjunto')) ?: 'adjunto';
            $score = $item->score !== null ? ', similitud ' . number_format((float)$item->score, 2, '.', '') : '';
            $parts[] = '--- Adjunto ' . ($idx + 1) . ' (' . $filename . $score . ") ---\n" . $item->content;
        }
        return implode("\n\n", $parts);
    }

    /** @param ContextItem[] $items */
    private function formatQuestionItems(array $items): string
    {
        if (!$items) return '';
        $parts = [];
        foreach ($items as $idx => $item) {
            $score = $item->score !== null ? ' · similitud ' . number_format((float)$item->score, 2, '.', '') : '';
            $parts[] = '--- Memoria ' . ($idx + 1) . $score . " ---\n" . $item->content;
        }
        return implode("\n\n", $parts);
    }

    /** @param ContextItem[] $items */
    private function formatProjectRagItems(array $items): string
    {
        if (!$items) return '';
        $out = "[CONTEXTO DE TUS ARCHIVOS INDEXADOS]\n";
        foreach ($items as $idx => $item) {
            $filename = (string)($item->metadata['filename'] ?? 'archivo');
            $name = (string)($item->metadata['name'] ?? '');
            $label = $filename !== '' ? $filename : ($name !== '' ? $name : 'fragmento');
            $semantic = $item->score !== null ? number_format((float)$item->score, 2, '.', '') : 'n/a';
            $rank = $item->rankingScore !== null ? number_format((float)$item->rankingScore, 2, '.', '') : 'n/a';
            $out .= '--- Fragmento ' . ($idx + 1) . ' (Archivo: ' . $label . ', Similitud: ' . $semantic . ', Ranking: ' . $rank . ") ---\n";
            $out .= $item->content . "\n\n";
        }
        return trim($out);
    }

    /**
     * @param array<string,mixed> $legacy
     * @param ContextItem[] $selected
     * @return array<string,mixed>
     */
    private function applyQuestionMemoryRanking(array $legacy, array $selected): array
    {
        $blockIds = [];
        $questionIds = [];
        foreach ($selected as $item) {
            if ($item->sourceId !== null) $blockIds[] = (int)$item->sourceId;
            $qid = $item->metadata['question_msg_id'] ?? null;
            if ($qid !== null) $questionIds[] = (int)$qid;
        }
        $blockIds = array_values(array_unique(array_filter($blockIds)));
        $questionIds = array_values(array_unique(array_filter($questionIds)));

        $matches = [];
        foreach ((array)($legacy['matches'] ?? []) as $match) {
            $bid = isset($match['block_id']) ? (int)$match['block_id'] : 0;
            if ($bid > 0 && in_array($bid, $blockIds, true)) $matches[] = $match;
        }

        $legacy['used'] = !empty($selected);
        $legacy['block_ids'] = $blockIds;
        $legacy['question_ids'] = $questionIds;
        $legacy['fragments'] = count($selected);
        $legacy['matches'] = $matches;
        $legacy['context'] = $this->formatQuestionItems($selected);
        $legacy['ranking_selected'] = array_map(
            static fn(ContextItem $item): array => $item->toArray(false),
            $selected
        );

        return $legacy;
    }

    /** @return array<string,mixed> */
    private function emptyQuestionMemory(string $scope): array
    {
        return [
            'context' => '',
            'used' => false,
            'question_ids' => [],
            'block_ids' => [],
            'fragments' => 0,
            'candidates' => 0,
            'reindex_queued' => 0,
            'scope' => $scope,
            'matches' => [],
            'candidate_scores' => [],
        ];
    }

    /**
     * @param ContextItem[] $items
     * @param ContextItem[] $already
     * @return ContextItem[]
     */
    private function deduplicateItems(array $items, array $already): array
    {
        if (!$already) return $items;
        $known = [];
        foreach ($already as $item) $known[$item->source . ':' . (string)$item->sourceId] = true;
        return array_values(array_filter($items, static function(ContextItem $item) use ($known): bool {
            return !isset($known[$item->source . ':' . (string)$item->sourceId]);
        }));
    }

    /**
     * @param ContextItem[] $a
     * @param ContextItem[] $b
     * @return ContextItem[]
     */
    private function mergeUnique(array $a, array $b): array
    {
        $out = [];
        $known = [];
        foreach (array_merge($a, $b) as $item) {
            $key = $item->source . ':' . (string)$item->sourceId;
            if (isset($known[$key])) continue;
            $known[$key] = true;
            $out[] = $item;
        }
        usort($out, static fn(ContextItem $x, ContextItem $y): int => (float)($y->rankingScore ?? 0.0) <=> (float)($x->rankingScore ?? 0.0));
        return $out;
    }
}
