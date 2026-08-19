<?php

declare(strict_types=1);

require_once __DIR__ . '/TraceMetricsCalculator.php';

/**
 * Fase 7.1 · API unificada de trazabilidad.
 *
 * Esta clase es deliberadamente READ-ONLY. Une la telemetría histórica de
 * ChatActivityEvents con los registros vivos relacionados. El snapshot del
 * trace nunca se reescribe: una futura UI podrá editar la fuente viva mediante
 * los CRUD existentes sin alterar lo que realmente ocurrió en una respuesta.
 */
final class UnifiedTraceRepository
{
    public const VERSION = '7.7';

    private mysqli $db;
    private int $viewerUserId;
    private bool $adminLike;
    private int $targetUserId;

    /** @var array<string,bool> */
    private array $tableCache = [];

    public function __construct(mysqli $db, int $viewerUserId, bool $adminLike, ?int $targetUserId = null)
    {
        $this->db = $db;
        $this->viewerUserId = $viewerUserId;
        $this->adminLike = $adminLike;
        $requested = (int)($targetUserId ?? $viewerUserId);

        if ($requested <= 0) {
            throw new InvalidArgumentException('user_id inválido.');
        }
        if ($requested !== $viewerUserId && !$adminLike) {
            throw new RuntimeException('No tienes permisos para consultar otro usuario.');
        }

        $this->targetUserId = $requested;
    }

    public function targetUserId(): int
    {
        return $this->targetUserId;
    }

    /** @return array<string,mixed> */
    public function capabilities(): array
    {
        $tables = [];
        foreach ([
            'ChatSessions', 'ChatMessages', 'ChatActivityEvents', 'PromptCompilations',
            'SessionContextBlocks', 'ProjectContext', 'UserProceduralMemory',
            'SourceChunks', 'ProjectSources', 'TokenUsage', 'ToolCalls',
            'MemoryWriteEvents', 'Projects',
        ] as $table) {
            $tables[$table] = $this->tableExists($table);
        }

        return [
            'api_version' => self::VERSION,
            'read_only' => true,
            'historical_trace_immutable' => true,
            'private_reasoning_exposed' => false,
            'actions' => ['capabilities', 'selectors', 'turns', 'trace'],
            'trace_lookup' => ['trace_id', 'answer_message_id', 'question_message_id'],
            'tables' => $tables,
        ];
    }

    /** @return array<string,mixed> */
    public function selectors(): array
    {
        $users = [];
        if ($this->adminLike && $this->tableExists('Users')) {
            $res = $this->db->query("SELECT id, firstname, lastname, email, role FROM Users ORDER BY firstname, lastname, id LIMIT 500");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $users[] = [
                        'id' => (int)$row['id'],
                        'firstname' => (string)$row['firstname'],
                        'lastname' => (string)$row['lastname'],
                        'email' => (string)$row['email'],
                        'role' => (string)$row['role'],
                    ];
                }
                $res->free();
            }
        } elseif ($this->tableExists('Users')) {
            $stmt = $this->db->prepare("SELECT id, firstname, lastname, email, role FROM Users WHERE id=? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $this->targetUserId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $users[] = [
                        'id' => (int)$row['id'],
                        'firstname' => (string)$row['firstname'],
                        'lastname' => (string)$row['lastname'],
                        'email' => (string)$row['email'],
                        'role' => (string)$row['role'],
                    ];
                }
            }
        }

        $projects = [];
        if ($this->tableExists('Projects')) {
            $stmt = $this->db->prepare(
                "SELECT id_, name, slug, status, language, framework, updated_at
                 FROM Projects
                 WHERE user_id_=? AND status<>'deleted'
                 ORDER BY name ASC, id_ ASC
                 LIMIT 500"
            );
            if ($stmt) {
                $stmt->bind_param('i', $this->targetUserId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $projects[] = [
                        'id' => (int)$row['id_'],
                        'name' => (string)$row['name'],
                        'slug' => (string)$row['slug'],
                        'status' => (string)$row['status'],
                        'language' => $row['language'] !== null ? (string)$row['language'] : null,
                        'framework' => $row['framework'] !== null ? (string)$row['framework'] : null,
                        'updated_at' => (string)$row['updated_at'],
                    ];
                }
                $stmt->close();
            }
        }

        $sessions = [];
        if ($this->tableExists('ChatSessions')) {
            $stmt = $this->db->prepare(
                "SELECT cs.id_, cs.project_id_, cs.title, cs.status, cs.model_id, cs.provider,
                        cs.context_level, cs.created_at, cs.updated_at, p.name AS project_name
                 FROM ChatSessions cs
                 LEFT JOIN Projects p ON p.id_=cs.project_id_
                 WHERE cs.user_id_=?
                 ORDER BY cs.updated_at DESC, cs.id_ DESC
                 LIMIT 1000"
            );
            if ($stmt) {
                $stmt->bind_param('i', $this->targetUserId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $sessions[] = [
                        'id' => (int)$row['id_'],
                        'project_id' => $row['project_id_'] !== null ? (int)$row['project_id_'] : null,
                        'project_name' => $row['project_name'] !== null ? (string)$row['project_name'] : null,
                        'title' => (string)$row['title'],
                        'status' => (string)$row['status'],
                        'model_id' => (string)$row['model_id'],
                        'provider' => $row['provider'] !== null ? (string)$row['provider'] : null,
                        'context_level' => (int)$row['context_level'],
                        'created_at' => (string)$row['created_at'],
                        'updated_at' => (string)$row['updated_at'],
                    ];
                }
                $stmt->close();
            }
        }

        return [
            'api_version' => self::VERSION,
            'viewer_user_id' => $this->viewerUserId,
            'target_user_id' => $this->targetUserId,
            'admin_like' => $this->adminLike,
            'users' => $users,
            'projects' => $projects,
            'sessions' => $sessions,
        ];
    }

    /**
     * Devuelve turnos pregunta→respuesta de una sesión. SessionContextBlocks es
     * la relación autoritativa cuando existe; para respuestas históricas cuyo
     * level_0 fue comprimido se usa el usuario anterior más cercano.
     *
     * @return array<string,mixed>
     */
    public function turns(int $sessionId, int $limit = 300): array
    {
        $session = $this->sessionForAccess($sessionId);
        $limit = max(1, min(2000, $limit));

        $blockLinks = [];
        if ($this->tableExists('SessionContextBlocks')) {
            $stmt = $this->db->prepare(
                "SELECT question_msg_id, answer_msg_id, id_
                 FROM SessionContextBlocks
                 WHERE session_id_=? AND question_msg_id IS NOT NULL AND answer_msg_id IS NOT NULL
                 ORDER BY id_ DESC"
            );
            if ($stmt) {
                $stmt->bind_param('i', $sessionId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $answerId = (int)$row['answer_msg_id'];
                    if (!isset($blockLinks[$answerId])) {
                        $blockLinks[$answerId] = [
                            'question_id' => (int)$row['question_msg_id'],
                            'block_id' => (int)$row['id_'],
                        ];
                    }
                }
                $stmt->close();
            }
        }

        $stmt = $this->db->prepare(
            "SELECT id_, session_id_, user_id_, role, content_type, content, s3_key, mime_type,
                    size_bytes, model_id, stop_reason, prompt_tokens, completion_tokens, latency_ms, meta,
                    is_primordial, phase, parent_msg_id, created_at
             FROM ChatMessages
             WHERE session_id_=? AND content_type='text' AND role IN ('user','assistant')
             ORDER BY id_ ASC
             LIMIT {$limit}"
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudieron cargar los mensajes: ' . $this->db->error);
        }
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $res = $stmt->get_result();
        $messages = [];
        $byId = [];
        while ($row = $res->fetch_assoc()) {
            $msg = $this->normalizeMessageRow($row);
            $messages[] = $msg;
            $byId[(int)$msg['id']] = $msg;
        }
        $stmt->close();

        $turns = [];
        $lastUser = null;
        foreach ($messages as $msg) {
            if ($msg['role'] === 'user') {
                $lastUser = $msg;
                continue;
            }
            if ($msg['role'] !== 'assistant' || $msg['phase'] !== 'respond') {
                continue;
            }

            $answerId = (int)$msg['id'];
            $question = null;
            $linkMethod = 'nearest_user_before_answer';
            $contextBlockId = null;

            if (isset($blockLinks[$answerId])) {
                $qid = (int)$blockLinks[$answerId]['question_id'];
                if (isset($byId[$qid]) && $byId[$qid]['role'] === 'user') {
                    $question = $byId[$qid];
                    $linkMethod = 'session_context_block';
                    $contextBlockId = (int)$blockLinks[$answerId]['block_id'];
                }
            }
            if (!$question && $lastUser) {
                $question = $lastUser;
            }
            if (!$question) {
                continue;
            }

            $traceId = $this->traceIdFromMeta($msg['meta']);
            $turns[] = [
                'question' => $question,
                'answer' => $msg,
                'trace_id' => $traceId !== '' ? $traceId : null,
                'link_method' => $linkMethod,
                'session_context_block_id' => $contextBlockId,
            ];
        }

        return [
            'api_version' => self::VERSION,
            'session' => $session,
            'turn_count' => count($turns),
            'turns' => array_values($turns),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function trace(
        int $sessionId,
        ?string $traceId = null,
        ?int $answerMessageId = null,
        ?int $questionMessageId = null
    ): array {
        $warnings = [];
        $session = $this->sessionForAccess($sessionId);
        $project = null;
        if (!empty($session['project_id'])) {
            $project = $this->projectForAccess((int)$session['project_id']);
        }

        $traceId = trim((string)$traceId);
        if ($traceId !== '' && !preg_match('/^[A-Za-z0-9_-]{16,36}$/', $traceId)) {
            throw new InvalidArgumentException('trace_id inválido.');
        }

        $question = null;
        $answer = null;
        $linkMethod = null;

        if ($answerMessageId && $answerMessageId > 0) {
            $answer = $this->messageForSession($sessionId, $answerMessageId, 'assistant');
            if (!$answer) throw new RuntimeException('La respuesta indicada no pertenece a esta sesión.');
            [$question, $linkMethod] = $this->resolveQuestionForAnswer($sessionId, (int)$answer['id']);
        } elseif ($questionMessageId && $questionMessageId > 0) {
            $question = $this->messageForSession($sessionId, $questionMessageId, 'user');
            if (!$question) throw new RuntimeException('La pregunta indicada no pertenece a esta sesión.');
            [$answer, $linkMethod] = $this->resolveAnswerForQuestion($sessionId, (int)$question['id']);
        }

        if ($traceId === '' && $answer) {
            $traceId = $this->traceIdFromMeta($answer['meta']);
            if ($traceId === '') {
                $traceId = $this->findTraceIdByAssistantMessage($sessionId, (int)$answer['id']);
            }
        }

        // Si sólo llegó trace_id, resolver primero el answer/question desde eventos.
        $events = [];
        if ($traceId !== '') {
            $events = $this->loadEvents($sessionId, $traceId);
            if (!$events) {
                $warnings[] = 'El trace_id no tiene eventos accesibles en ChatActivityEvents.';
            }

            if (!$answer) {
                $answerIdFromTrace = $this->assistantIdFromEvents($events);
                if ($answerIdFromTrace > 0) {
                    $answer = $this->messageForSession($sessionId, $answerIdFromTrace, 'assistant');
                    if ($answer) {
                        [$question, $linkMethod] = $this->resolveQuestionForAnswer($sessionId, (int)$answer['id']);
                    }
                }
            }
            if (!$question) {
                $questionIdFromTrace = $this->questionIdFromEvents($events);
                if ($questionIdFromTrace > 0) {
                    $question = $this->messageForSession($sessionId, $questionIdFromTrace, 'user');
                    if ($question && !$answer) {
                        [$answer, $linkMethod] = $this->resolveAnswerForQuestion($sessionId, (int)$question['id']);
                    }
                }
            }
        }

        // Último intento de resolver trace desde la respuesta encontrada.
        if ($traceId === '' && $answer) {
            $traceId = $this->traceIdFromMeta($answer['meta']);
            if ($traceId === '') {
                $traceId = $this->findTraceIdByAssistantMessage($sessionId, (int)$answer['id']);
            }
            if ($traceId !== '') {
                $events = $this->loadEvents($sessionId, $traceId);
            }
        }

        if (!$question && !$answer && $traceId === '') {
            throw new InvalidArgumentException('Indica trace_id, answer_message_id o question_message_id.');
        }

        $questionId = $question ? (int)$question['id'] : 0;
        $answerId = $answer ? (int)$answer['id'] : 0;

        $pipeline = $this->extractPipeline($events);
        $contextItems = $this->extractContextItems($events);
        $selectedItems = array_values(array_filter(
            $contextItems,
            static fn(array $item): bool => !empty($item['selected'])
        ));
        $discardedItems = array_values(array_filter(
            $contextItems,
            static fn(array $item): bool => empty($item['selected'])
        ));

        $resources = [
            'context_items_historical' => $selectedItems,
            'context_items_discarded_historical' => $discardedItems,
            'project_context_live' => [],
            'procedural_memory_live' => [],
            'session_context_blocks_live' => [],
            'source_chunks_live' => [],
            'chat_messages_live' => [],
            'prompt_compilations' => [],
            'memory_write_events' => [],
            'token_usage' => [],
            'tool_calls' => [],
            'live_missing_records' => [],
        ];

        try {
            $hydrated = $this->hydrateHistoricalContextItems($contextItems);
            foreach ($hydrated as $key => $value) $resources[$key] = $value;
        } catch (Throwable $e) {
            $warnings[] = 'No se pudieron hidratar todos los recursos de contexto: ' . $e->getMessage();
        }

        if ($questionId > 0 && $answerId > 0) {
            try {
                $resources['prompt_compilations'] = $this->loadPromptCompilations($sessionId, $questionId);
            } catch (Throwable $e) {
                $warnings[] = 'PromptCompilations: ' . $e->getMessage();
            }

            try {
                $resources['memory_write_events'] = $this->loadMemoryWriteEvents($sessionId, $questionId, $answerId);
            } catch (Throwable $e) {
                $warnings[] = 'MemoryWriteEvents: ' . $e->getMessage();
            }

            try {
                $relatedBlocks = $this->loadQaBlocks($sessionId, $questionId, $answerId);
                $resources['session_context_blocks_live'] = $this->mergeResourceRows(
                    $resources['session_context_blocks_live'],
                    $relatedBlocks,
                    'id'
                );
            } catch (Throwable $e) {
                $warnings[] = 'SessionContextBlocks Q&A: ' . $e->getMessage();
            }

            try {
                $resources['token_usage'] = $this->loadTokenUsageForTurn($sessionId, $questionId, $answerId);
            } catch (Throwable $e) {
                $warnings[] = 'TokenUsage: ' . $e->getMessage();
            }
        }

        if ($traceId !== '') {
            try {
                $resources['tool_calls'] = $this->loadToolCallsForTrace(
                    $sessionId,
                    $project ? (int)$project['id'] : 0,
                    $events,
                    $questionId,
                    $answerId
                );
            } catch (Throwable $e) {
                $warnings[] = 'ToolCalls: ' . $e->getMessage();
            }
        }

        $traceMeta = $this->traceMeta($events);
        $totals = $this->totals($events, $resources['token_usage'], $resources['tool_calls'], $resources['memory_write_events']);

        return [
            'api_version' => self::VERSION,
            'scope' => [
                'viewer_user_id' => $this->viewerUserId,
                'target_user_id' => $this->targetUserId,
                'session' => $session,
                'project' => $project,
            ],
            'turn' => [
                'question' => $question,
                'answer' => $answer,
                'link_method' => $linkMethod,
                'trace_id' => $traceId !== '' ? $traceId : null,
            ],
            'trace' => [
                'trace_id' => $traceId !== '' ? $traceId : null,
                'status' => $traceMeta['status'],
                'started_at' => $traceMeta['started_at'],
                'completed_at' => $traceMeta['completed_at'],
                'duration_ms' => $traceMeta['duration_ms'],
                'event_count' => count($events),
                'events' => $events,
            ],
            'pipeline' => $pipeline,
            'resources' => $resources,
            'totals' => $totals,
            'provenance' => [
                'historical_trace_immutable' => true,
                'historical_context_source' => 'ChatActivityEvents.details_json',
                'live_records_are_current' => true,
                'live_records_may_have_changed_since_trace' => true,
                'private_reasoning_exposed' => false,
                'note' => 'Los elementos *_historical son el snapshot del turno. Los elementos *_live son el estado actual de la BD.',
            ],
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /** @return array<string,mixed> */
    private function sessionForAccess(int $sessionId): array
    {
        if ($sessionId <= 0) throw new InvalidArgumentException('session_id inválido.');
        if (!$this->tableExists('ChatSessions')) throw new RuntimeException('ChatSessions no existe.');

        $stmt = $this->db->prepare(
            "SELECT id_, user_id_, project_id_, title, model_id, provider, status, meta,
                    context_summary, context_level, last_compressed_at, pending_summary,
                    created_at, updated_at
             FROM ChatSessions WHERE id_=? LIMIT 1"
        );
        if (!$stmt) throw new RuntimeException($this->db->error);
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) throw new RuntimeException('Sesión no encontrada.');

        $owner = (int)$row['user_id_'];
        if ($owner !== $this->targetUserId) {
            throw new RuntimeException('La sesión no pertenece al usuario seleccionado.');
        }

        return [
            'id' => (int)$row['id_'],
            'user_id' => $owner,
            'project_id' => $row['project_id_'] !== null ? (int)$row['project_id_'] : null,
            'title' => (string)$row['title'],
            'model_id' => (string)$row['model_id'],
            'provider' => $row['provider'] !== null ? (string)$row['provider'] : null,
            'status' => (string)$row['status'],
            'meta' => $this->decodeJsonOrRaw($row['meta']),
            'context_summary' => $row['context_summary'] !== null ? (string)$row['context_summary'] : null,
            'context_level' => (int)$row['context_level'],
            'last_compressed_at' => $row['last_compressed_at'] !== null ? (string)$row['last_compressed_at'] : null,
            'pending_summary' => (int)$row['pending_summary'] === 1,
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
        ];
    }

    /** @return array<string,mixed>|null */
    private function projectForAccess(int $projectId): ?array
    {
        if ($projectId <= 0 || !$this->tableExists('Projects')) return null;
        $stmt = $this->db->prepare(
            "SELECT id_, user_id_, name, slug, description, language, framework, status, created_at, updated_at
             FROM Projects WHERE id_=? AND user_id_=? LIMIT 1"
        );
        if (!$stmt) return null;
        $stmt->bind_param('ii', $projectId, $this->targetUserId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return null;

        return [
            'id' => (int)$row['id_'],
            'user_id' => (int)$row['user_id_'],
            'name' => (string)$row['name'],
            'slug' => (string)$row['slug'],
            'description' => $row['description'] !== null ? (string)$row['description'] : null,
            'language' => $row['language'] !== null ? (string)$row['language'] : null,
            'framework' => $row['framework'] !== null ? (string)$row['framework'] : null,
            'status' => (string)$row['status'],
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
        ];
    }

    /** @return array<string,mixed>|null */
    private function messageForSession(int $sessionId, int $messageId, ?string $role = null): ?array
    {
        if ($messageId <= 0) return null;
        $sql = "SELECT id_, session_id_, user_id_, role, content_type, content, s3_key, mime_type,
                       size_bytes, model_id, stop_reason, prompt_tokens, completion_tokens,
                       latency_ms, meta, is_primordial, phase, parent_msg_id, created_at
                FROM ChatMessages WHERE id_=? AND session_id_=?";
        if ($role !== null) $sql .= " AND role=?";
        $sql .= " LIMIT 1";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return null;
        if ($role !== null) {
            $stmt->bind_param('iis', $messageId, $sessionId, $role);
        } else {
            $stmt->bind_param('ii', $messageId, $sessionId);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? $this->normalizeMessageRow($row) : null;
    }

    /** @return array{0:?array,1:?string} */
    private function resolveQuestionForAnswer(int $sessionId, int $answerId): array
    {
        if ($this->tableExists('SessionContextBlocks')) {
            $stmt = $this->db->prepare(
                "SELECT question_msg_id FROM SessionContextBlocks
                 WHERE session_id_=? AND answer_msg_id=? AND question_msg_id IS NOT NULL
                 ORDER BY id_ DESC LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('ii', $sessionId, $answerId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $q = $this->messageForSession($sessionId, (int)$row['question_msg_id'], 'user');
                    if ($q) return [$q, 'session_context_block'];
                }
            }
        }

        $stmt = $this->db->prepare(
            "SELECT id_ FROM ChatMessages
             WHERE session_id_=? AND role='user' AND content_type='text' AND id_<?
             ORDER BY id_ DESC LIMIT 1"
        );
        if (!$stmt) return [null, null];
        $stmt->bind_param('ii', $sessionId, $answerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row
            ? [$this->messageForSession($sessionId, (int)$row['id_'], 'user'), 'nearest_user_before_answer']
            : [null, null];
    }

    /** @return array{0:?array,1:?string} */
    private function resolveAnswerForQuestion(int $sessionId, int $questionId): array
    {
        if ($this->tableExists('SessionContextBlocks')) {
            $stmt = $this->db->prepare(
                "SELECT answer_msg_id FROM SessionContextBlocks
                 WHERE session_id_=? AND question_msg_id=? AND answer_msg_id IS NOT NULL
                 ORDER BY id_ DESC LIMIT 1"
            );
            if ($stmt) {
                $stmt->bind_param('ii', $sessionId, $questionId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $a = $this->messageForSession($sessionId, (int)$row['answer_msg_id'], 'assistant');
                    if ($a) return [$a, 'session_context_block'];
                }
            }
        }

        $stmt = $this->db->prepare(
            "SELECT id_ FROM ChatMessages
             WHERE session_id_=? AND role='assistant' AND content_type='text' AND phase='respond' AND id_>?
             ORDER BY id_ ASC LIMIT 1"
        );
        if (!$stmt) return [null, null];
        $stmt->bind_param('ii', $sessionId, $questionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row
            ? [$this->messageForSession($sessionId, (int)$row['id_'], 'assistant'), 'nearest_answer_after_question']
            : [null, null];
    }

    private function findTraceIdByAssistantMessage(int $sessionId, int $answerId): string
    {
        if (!$this->tableExists('ChatActivityEvents')) return '';
        $needle = (string)$answerId;
        $stmt = $this->db->prepare(
            "SELECT trace_id FROM ChatActivityEvents
             WHERE session_id_=? AND user_id_=?
               AND event_key IN ('response_saved','trace_completed')
               AND JSON_UNQUOTE(JSON_EXTRACT(details_json, '$.assistant_message_id'))=?
             ORDER BY id_ DESC LIMIT 1"
        );
        if (!$stmt) return '';
        $stmt->bind_param('iis', $sessionId, $this->targetUserId, $needle);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (string)$row['trace_id'] : '';
    }

    /** @return array<int,array<string,mixed>> */
    private function loadEvents(int $sessionId, string $traceId): array
    {
        if (!$this->tableExists('ChatActivityEvents')) return [];
        $stmt = $this->db->prepare(
            "SELECT id_, trace_id, phase, event_key, status, title, summary,
                    details_json, model_id, duration_ms, created_at
             FROM ChatActivityEvents
             WHERE trace_id=? AND session_id_=? AND user_id_=?
             ORDER BY id_ ASC LIMIT 1000"
        );
        if (!$stmt) throw new RuntimeException($this->db->error);
        $stmt->bind_param('sii', $traceId, $sessionId, $this->targetUserId);
        $stmt->execute();
        $res = $stmt->get_result();
        $events = [];
        while ($row = $res->fetch_assoc()) {
            $details = $this->decodeJsonOrRaw($row['details_json']);
            $events[] = [
                'id' => (int)$row['id_'],
                'trace_id' => (string)$row['trace_id'],
                'phase' => (string)$row['phase'],
                'event_key' => (string)$row['event_key'],
                'category' => $this->eventCategory((string)$row['event_key'], (string)$row['phase']),
                'status' => (string)$row['status'],
                'title' => (string)$row['title'],
                'summary' => $row['summary'] !== null ? (string)$row['summary'] : null,
                'details' => $this->sanitizeTelemetry($details),
                'model_id' => $row['model_id'] !== null ? (string)$row['model_id'] : null,
                'duration_ms' => $row['duration_ms'] !== null ? (int)$row['duration_ms'] : null,
                'created_at' => (string)$row['created_at'],
            ];
        }
        $stmt->close();
        return $events;
    }

    /** @param array<int,array<string,mixed>> $events */
    private function assistantIdFromEvents(array $events): int
    {
        for ($i = count($events) - 1; $i >= 0; $i--) {
            $details = $events[$i]['details'] ?? null;
            if (is_array($details) && isset($details['assistant_message_id']) && is_numeric($details['assistant_message_id'])) {
                return (int)$details['assistant_message_id'];
            }
        }
        return 0;
    }

    /** @param array<int,array<string,mixed>> $events */
    private function questionIdFromEvents(array $events): int
    {
        foreach ($events as $event) {
            $details = $event['details'] ?? null;
            if (!is_array($details)) continue;
            foreach (['user_message_id', 'question_message_id', 'question_msg_id'] as $key) {
                if (isset($details[$key]) && is_numeric($details[$key])) return (int)$details[$key];
            }
        }
        return 0;
    }

    /** @param array<int,array<string,mixed>> $events @return array<string,mixed> */
    private function extractPipeline(array $events): array
    {
        $out = [
            'compiler' => [],
            'router' => null,
            'feature_flags' => null,
            'ranking' => null,
            'context_builder' => null,
            'system_blocks' => null,
            'final_prompt' => null,
            'model_rounds' => [],
            'tools' => [],
            'memory_backfill' => null,
            'memory_writer' => null,
            'response_saved' => null,
            'trace_completed' => null,
        ];

        foreach ($events as $event) {
            $key = (string)($event['event_key'] ?? '');
            $details = is_array($event['details'] ?? null) ? $event['details'] : [];

            if (($event['phase'] ?? '') === 'compile' || str_contains($key, 'compiler') || str_contains($key, 'compilation')) {
                $out['compiler'][] = $event;
            }
            if ($key === 'memory_router_decision') $out['router'] = $details;
            if ($key === 'pipeline_features_resolved') $out['feature_flags'] = $details;
            if ($key === 'context_ranking_completed') $out['ranking'] = $details['ranking'] ?? $details;
            if ($key === 'context_builder_completed') {
                $out['context_builder'] = $details['context_builder'] ?? $details;
                $out['system_blocks'] = $details['system_blocks'] ?? null;
            }
            if (in_array($key, ['final_prompt_prepared', 'prompt_final_ready', 'final_prompt_ready'], true)) {
                $out['final_prompt'] = $details;
            }
            if ($key === 'model_round_completed') $out['model_rounds'][] = $event;
            if ($key === 'tool_started' || $key === 'tool_completed') $out['tools'][] = $event;
            if (str_contains($key, 'memory_backfill')) $out['memory_backfill'] = $details;
            if ($key === 'memory_writer_completed') $out['memory_writer'] = $details;
            if ($key === 'response_saved') $out['response_saved'] = $details;
            if ($key === 'trace_completed') $out['trace_completed'] = $details;
        }

        return $out;
    }

    /** @param array<int,array<string,mixed>> $events @return array<int,array<string,mixed>> */
    private function extractContextItems(array $events): array
    {
        $items = [];
        foreach ($events as $event) {
            if (($event['event_key'] ?? '') !== 'context_builder_completed') continue;
            $details = $event['details'] ?? null;
            if (!is_array($details)) continue;
            $builder = $details['context_builder'] ?? null;
            if (!is_array($builder)) continue;
            $buckets = $builder['items'] ?? null;
            if (!is_array($buckets)) continue;

            foreach ($buckets as $bucket => $bucketItems) {
                if (!is_array($bucketItems)) continue;
                foreach ($bucketItems as $item) {
                    if (!is_array($item)) continue;
                    $normalized = $this->sanitizeTelemetry($item);
                    if (!is_array($normalized)) continue;
                    $normalized['bucket'] = (string)$bucket;
                    $normalized['snapshot_role'] = 'historical';
                    $normalized['selected'] = array_key_exists('selected', $normalized)
                        ? (bool)$normalized['selected']
                        : true;
                    $items[] = $normalized;
                }
            }
        }
        return $items;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function hydrateHistoricalContextItems(array $items): array
    {
        $ids = [
            'ProjectContext' => [],
            'UserProceduralMemory' => [],
            'SessionContextBlocks' => [],
            'SourceChunks' => [],
            'ChatMessages' => [],
            'ChatSessions' => [],
        ];
        $snapshots = [];

        foreach ($items as $item) {
            $source = (string)($item['source'] ?? '');
            $id = isset($item['source_id']) && is_numeric($item['source_id']) ? (int)$item['source_id'] : 0;
            if ($id <= 0 || !isset($ids[$source])) continue;
            $ids[$source][$id] = true;
            $snapshots[$source][$id] = $item;
        }

        $hydrated = [
            'project_context_live' => $this->hydrateProjectContext(array_keys($ids['ProjectContext']), $snapshots['ProjectContext'] ?? []),
            'procedural_memory_live' => $this->hydrateProceduralMemory(array_keys($ids['UserProceduralMemory']), $snapshots['UserProceduralMemory'] ?? []),
            'session_context_blocks_live' => $this->hydrateSessionBlocks(array_keys($ids['SessionContextBlocks']), $snapshots['SessionContextBlocks'] ?? []),
            'source_chunks_live' => $this->hydrateSourceChunks(array_keys($ids['SourceChunks']), $snapshots['SourceChunks'] ?? []),
            'chat_messages_live' => $this->hydrateChatMessages(array_keys($ids['ChatMessages']), $snapshots['ChatMessages'] ?? []),
            'chat_sessions_live' => $this->hydrateChatSessions(array_keys($ids['ChatSessions']), $snapshots['ChatSessions'] ?? []),
        ];

        $sourceToKey = [
            'ProjectContext' => 'project_context_live',
            'UserProceduralMemory' => 'procedural_memory_live',
            'SessionContextBlocks' => 'session_context_blocks_live',
            'SourceChunks' => 'source_chunks_live',
            'ChatMessages' => 'chat_messages_live',
            'ChatSessions' => 'chat_sessions_live',
        ];
        $missing = [];
        foreach ($sourceToKey as $source => $key) {
            $found = [];
            foreach ($hydrated[$key] as $row) {
                if (isset($row['id'])) $found[(int)$row['id']] = true;
            }
            foreach (array_keys($ids[$source]) as $id) {
                $id = (int)$id;
                if ($id > 0 && !isset($found[$id])) {
                    $missing[] = [
                        'source' => $source,
                        'source_id' => $id,
                        'status' => 'missing_or_no_longer_accessible',
                        'snapshot_role' => 'historical',
                    ];
                }
            }
        }
        $hydrated['live_missing_records'] = $missing;
        return $hydrated;
    }

    /** @param array<int,int> $ids @param array<int,array<string,mixed>> $snapshots @return array<int,array<string,mixed>> */
    private function hydrateProjectContext(array $ids, array $snapshots): array
    {
        if (!$ids || !$this->tableExists('ProjectContext')) return [];
        $in = $this->intList($ids);
        $sql = "SELECT pc.id_, pc.project_id_, pc.type, pc.title, pc.content, pc.source_chunk_id, pc.created_at, pc.updated_at
                FROM ProjectContext pc JOIN Projects p ON p.id_=pc.project_id_
                WHERE pc.id_ IN ({$in}) AND p.user_id_=? ORDER BY pc.id_";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('i', $this->targetUserId);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $id = (int)$row['id_'];
            $live = [
                'id' => $id,
                'project_id' => (int)$row['project_id_'],
                'type' => (string)$row['type'],
                'title' => $row['title'] !== null ? (string)$row['title'] : null,
                'content' => (string)$row['content'],
                'source_chunk_id' => $row['source_chunk_id'] !== null ? (int)$row['source_chunk_id'] : null,
                'created_at' => (string)$row['created_at'],
                'updated_at' => (string)$row['updated_at'],
                'record_role' => 'live_current',
            ];
            $out[] = $this->attachHistoricalComparison($live, $snapshots[$id] ?? null);
        }
        $stmt->close();
        return $out;
    }

    /** @param array<int,int> $ids @param array<int,array<string,mixed>> $snapshots @return array<int,array<string,mixed>> */
    private function hydrateProceduralMemory(array $ids, array $snapshots): array
    {
        if (!$ids || !$this->tableExists('UserProceduralMemory')) return [];
        $in = $this->intList($ids);
        $sql = "SELECT id_, user_id_, memory_type, content, source_session_id, confidence, is_active, created_at, updated_at
                FROM UserProceduralMemory WHERE id_ IN ({$in}) AND user_id_=? ORDER BY id_";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('i', $this->targetUserId);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $id = (int)$row['id_'];
            $live = [
                'id' => $id,
                'user_id' => (int)$row['user_id_'],
                'memory_type' => (string)$row['memory_type'],
                'content' => (string)$row['content'],
                'source_session_id' => $row['source_session_id'] !== null ? (int)$row['source_session_id'] : null,
                'confidence' => (int)$row['confidence'],
                'is_active' => (int)$row['is_active'] === 1,
                'created_at' => (string)$row['created_at'],
                'updated_at' => (string)$row['updated_at'],
                'record_role' => 'live_current',
            ];
            $out[] = $this->attachHistoricalComparison($live, $snapshots[$id] ?? null);
        }
        $stmt->close();
        return $out;
    }

    /** @param array<int,int> $ids @param array<int,array<string,mixed>> $snapshots @return array<int,array<string,mixed>> */
    private function hydrateSessionBlocks(array $ids, array $snapshots): array
    {
        if (!$ids || !$this->tableExists('SessionContextBlocks')) return [];
        $in = $this->intList($ids);
        $sql = "SELECT scb.id_, scb.session_id_, scb.block_type, scb.question_msg_id, scb.answer_msg_id,
                       scb.content_preview, scb.s3_path, scb.is_locked, scb.source_ids, scb.token_count,
                       scb.embedding_model, scb.is_memory_summary, scb.memory_hits, scb.last_memory_used_at,
                       scb.created_at
                FROM SessionContextBlocks scb JOIN ChatSessions cs ON cs.id_=scb.session_id_
                WHERE scb.id_ IN ({$in}) AND cs.user_id_=? ORDER BY scb.id_";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('i', $this->targetUserId);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $id = (int)$row['id_'];
            $live = $this->normalizeSessionBlockRow($row);
            $live['record_role'] = 'live_current';
            $out[] = $this->attachHistoricalComparison($live, $snapshots[$id] ?? null);
        }
        $stmt->close();
        return $out;
    }

    /** @param array<int,int> $ids @param array<int,array<string,mixed>> $snapshots @return array<int,array<string,mixed>> */
    private function hydrateSourceChunks(array $ids, array $snapshots): array
    {
        if (!$ids || !$this->tableExists('SourceChunks')) return [];
        $in = $this->intList($ids);
        $sql = "SELECT sc.id_, sc.source_id_, sc.project_id_, sc.chunk_type, sc.name, sc.parent_name,
                       sc.signature, sc.content, sc.start_line, sc.end_line, sc.token_count, sc.checksum,
                       sc.meta, sc.created_at, sc.updated_at, ps.filename
                FROM SourceChunks sc
                JOIN Projects p ON p.id_=sc.project_id_
                LEFT JOIN ProjectSources ps ON ps.id_=sc.source_id_
                WHERE sc.id_ IN ({$in}) AND p.user_id_=? ORDER BY sc.id_";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('i', $this->targetUserId);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $id = (int)$row['id_'];
            $live = [
                'id' => $id,
                'source_id' => (int)$row['source_id_'],
                'project_id' => (int)$row['project_id_'],
                'filename' => $row['filename'] !== null ? (string)$row['filename'] : null,
                'chunk_type' => (string)$row['chunk_type'],
                'name' => $row['name'] !== null ? (string)$row['name'] : null,
                'parent_name' => $row['parent_name'] !== null ? (string)$row['parent_name'] : null,
                'signature' => $row['signature'] !== null ? (string)$row['signature'] : null,
                'content' => (string)$row['content'],
                'start_line' => (int)$row['start_line'],
                'end_line' => (int)$row['end_line'],
                'token_count' => (int)$row['token_count'],
                'checksum' => $row['checksum'] !== null ? (string)$row['checksum'] : null,
                'meta' => $this->decodeJsonOrRaw($row['meta']),
                'created_at' => (string)$row['created_at'],
                'updated_at' => (string)$row['updated_at'],
                'record_role' => 'live_current',
            ];
            $out[] = $this->attachHistoricalComparison($live, $snapshots[$id] ?? null);
        }
        $stmt->close();
        return $out;
    }

    /** @param array<int,int> $ids @param array<int,array<string,mixed>> $snapshots @return array<int,array<string,mixed>> */
    private function hydrateChatMessages(array $ids, array $snapshots): array
    {
        if (!$ids) return [];
        $in = $this->intList($ids);
        $sql = "SELECT cm.id_, cm.session_id_, cm.user_id_, cm.role, cm.content_type, cm.content,
                       cm.s3_key, cm.mime_type, cm.size_bytes, cm.model_id, cm.stop_reason,
                       cm.prompt_tokens, cm.completion_tokens, cm.latency_ms, cm.meta,
                       cm.is_primordial, cm.phase, cm.parent_msg_id, cm.created_at
                FROM ChatMessages cm JOIN ChatSessions cs ON cs.id_=cm.session_id_
                WHERE cm.id_ IN ({$in}) AND cs.user_id_=? ORDER BY cm.id_";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('i', $this->targetUserId);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $live = $this->normalizeMessageRow($row);
            $live['record_role'] = 'live_current';
            $out[] = $this->attachHistoricalComparison($live, $snapshots[(int)$live['id']] ?? null);
        }
        $stmt->close();
        return $out;
    }

    /** @param array<int,int> $ids @param array<int,array<string,mixed>> $snapshots @return array<int,array<string,mixed>> */
    private function hydrateChatSessions(array $ids, array $snapshots): array
    {
        if (!$ids) return [];
        $in = $this->intList($ids);
        $sql = "SELECT id_, user_id_, project_id_, title, model_id, provider, status, context_summary,
                       context_level, created_at, updated_at
                FROM ChatSessions WHERE id_ IN ({$in}) AND user_id_=? ORDER BY id_";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('i', $this->targetUserId);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $id = (int)$row['id_'];
            $live = [
                'id' => $id,
                'user_id' => (int)$row['user_id_'],
                'project_id' => $row['project_id_'] !== null ? (int)$row['project_id_'] : null,
                'title' => (string)$row['title'],
                'model_id' => (string)$row['model_id'],
                'provider' => $row['provider'] !== null ? (string)$row['provider'] : null,
                'status' => (string)$row['status'],
                'context_summary' => $row['context_summary'] !== null ? (string)$row['context_summary'] : null,
                'context_level' => (int)$row['context_level'],
                'created_at' => (string)$row['created_at'],
                'updated_at' => (string)$row['updated_at'],
                'record_role' => 'live_current',
            ];
            $out[] = $this->attachHistoricalComparison($live, $snapshots[$id] ?? null);
        }
        $stmt->close();
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function loadQaBlocks(int $sessionId, int $questionId, int $answerId): array
    {
        if (!$this->tableExists('SessionContextBlocks')) return [];
        $stmt = $this->db->prepare(
            "SELECT id_, session_id_, block_type, question_msg_id, answer_msg_id, content_preview,
                    s3_path, is_locked, source_ids, token_count, embedding_model,
                    is_memory_summary, memory_hits, last_memory_used_at, created_at
             FROM SessionContextBlocks
             WHERE session_id_=? AND (question_msg_id=? OR answer_msg_id=?)
             ORDER BY id_ ASC"
        );
        if (!$stmt) return [];
        $stmt->bind_param('iii', $sessionId, $questionId, $answerId);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $item = $this->normalizeSessionBlockRow($row);
            $item['record_role'] = 'live_current';
            $item['association'] = 'question_answer_link';
            $out[] = $item;
        }
        $stmt->close();
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function loadPromptCompilations(int $sessionId, int $questionId): array
    {
        if (!$this->tableExists('PromptCompilations')) return [];
        $stmt = $this->db->prepare(
            "SELECT id_, session_id_, user_msg_id, compiled_prompt, used_context_ids, used_code_refs,
                    notes_for_user, was_edited_by_user, edited_diff, status, created_at
             FROM PromptCompilations WHERE session_id_=? AND user_msg_id=? ORDER BY id_ ASC"
        );
        if (!$stmt) return [];
        $stmt->bind_param('ii', $sessionId, $questionId);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = [
                'id' => (int)$row['id_'],
                'session_id' => (int)$row['session_id_'],
                'user_message_id' => (int)$row['user_msg_id'],
                'compiled_prompt' => (string)$row['compiled_prompt'],
                'used_context_ids' => $this->decodeJsonOrRaw($row['used_context_ids']),
                'used_code_refs' => $this->decodeJsonOrRaw($row['used_code_refs']),
                'notes_for_user' => $row['notes_for_user'] !== null ? (string)$row['notes_for_user'] : null,
                'was_edited_by_user' => (int)$row['was_edited_by_user'] === 1,
                'edited_diff' => $row['edited_diff'] !== null ? (string)$row['edited_diff'] : null,
                'status' => (string)$row['status'],
                'created_at' => (string)$row['created_at'],
            ];
        }
        $stmt->close();
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function loadMemoryWriteEvents(int $sessionId, int $questionId, int $answerId): array
    {
        if (!$this->tableExists('MemoryWriteEvents')) return [];
        $stmt = $this->db->prepare(
            "SELECT id_, user_id_, session_id_, project_id_, question_msg_id, answer_msg_id,
                    writer_version, status, route_intent, reason, model_id, candidate_count,
                    write_count, candidates_json, writes_json, usage_json, error_text,
                    created_at, updated_at
             FROM MemoryWriteEvents
             WHERE user_id_=? AND session_id_=? AND question_msg_id=? AND answer_msg_id=?
             ORDER BY id_ ASC"
        );
        if (!$stmt) return [];
        $stmt->bind_param('iiii', $this->targetUserId, $sessionId, $questionId, $answerId);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = [
                'id' => (int)$row['id_'],
                'user_id' => (int)$row['user_id_'],
                'session_id' => (int)$row['session_id_'],
                'project_id' => $row['project_id_'] !== null ? (int)$row['project_id_'] : null,
                'question_message_id' => (int)$row['question_msg_id'],
                'answer_message_id' => (int)$row['answer_msg_id'],
                'writer_version' => (string)$row['writer_version'],
                'status' => (string)$row['status'],
                'route_intent' => $row['route_intent'] !== null ? (string)$row['route_intent'] : null,
                'reason' => $row['reason'] !== null ? (string)$row['reason'] : null,
                'model_id' => $row['model_id'] !== null ? (string)$row['model_id'] : null,
                'candidate_count' => (int)$row['candidate_count'],
                'write_count' => (int)$row['write_count'],
                'candidates' => $this->sanitizeTelemetry($this->decodeJsonOrRaw($row['candidates_json'])),
                'writes' => $this->sanitizeTelemetry($this->decodeJsonOrRaw($row['writes_json'])),
                'usage' => $this->sanitizeTelemetry($this->decodeJsonOrRaw($row['usage_json'])),
                'error_text' => $row['error_text'] !== null ? (string)$row['error_text'] : null,
                'created_at' => (string)$row['created_at'],
                'updated_at' => (string)$row['updated_at'],
            ];
        }
        $stmt->close();
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function loadTokenUsageForTurn(int $sessionId, int $questionId, int $answerId): array
    {
        if (!$this->tableExists('TokenUsage')) return [];
        $messageIds = [$questionId, $answerId];

        // El compilador guarda su ChatMessage system como hijo de la pregunta.
        $stmt = $this->db->prepare(
            "SELECT id_ FROM ChatMessages
             WHERE session_id_=? AND role='system' AND phase='compile' AND parent_msg_id=?"
        );
        if ($stmt) {
            $stmt->bind_param('ii', $sessionId, $questionId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $messageIds[] = (int)$row['id_'];
            $stmt->close();
        }

        $in = $this->intList($messageIds);
        $sql = "SELECT id_, session_id_, message_id_, phase, model_id, input_tokens, output_tokens,
                       estimated_cost_usd, duration_ms, created_at
                FROM TokenUsage
                WHERE session_id_=? AND message_id_ IN ({$in})
                ORDER BY id_ ASC";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[] = [
                'id' => (int)$row['id_'],
                'session_id' => (int)$row['session_id_'],
                'message_id' => $row['message_id_'] !== null ? (int)$row['message_id_'] : null,
                'phase' => (string)$row['phase'],
                'model_id' => (string)$row['model_id'],
                'input_tokens' => (int)$row['input_tokens'],
                'output_tokens' => (int)$row['output_tokens'],
                'total_tokens' => (int)$row['input_tokens'] + (int)$row['output_tokens'],
                'estimated_cost_usd' => (float)$row['estimated_cost_usd'],
                'duration_ms' => (int)$row['duration_ms'],
                'created_at' => (string)$row['created_at'],
                'association' => 'message_id',
            ];
        }
        $stmt->close();
        return $out;
    }

    /**
     * ToolCalls antiguos no siempre tienen message_id_. Se retornan primero los
     * enlaces directos; luego, si hace falta, llamadas de la misma sesión dentro
     * de la ventana temporal exacta del trace, marcadas explícitamente como
     * asociación temporal (no se finge causalidad absoluta).
     *
     * @param array<int,array<string,mixed>> $events
     * @return array<int,array<string,mixed>>
     */
    private function loadToolCallsForTrace(
        int $sessionId,
        int $projectId,
        array $events,
        int $questionId,
        int $answerId
    ): array {
        if (!$this->tableExists('ToolCalls')) return [];
        $ids = array_values(array_filter([$questionId, $answerId], static fn(int $v): bool => $v > 0));
        $clauses = [];
        if ($ids) $clauses[] = 'message_id_ IN (' . $this->intList($ids) . ')';

        $start = $events[0]['created_at'] ?? null;
        $end = $events ? ($events[count($events)-1]['created_at'] ?? null) : null;
        if ($start && $end) {
            $startEsc = $this->db->real_escape_string((string)$start);
            $endEsc = $this->db->real_escape_string((string)$end);
            $clauses[] = "(message_id_ IS NULL AND created_at >= '{$startEsc}' AND created_at <= '{$endEsc}')";
        }
        if (!$clauses) return [];

        $sql = "SELECT id_, session_id_, project_id_, message_id_, tool, params, target_path, result,
                       status, duration_ms, created_at
                FROM ToolCalls
                WHERE session_id_=? AND (" . implode(' OR ', $clauses) . ")
                ORDER BY id_ ASC LIMIT 200";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            if ($projectId > 0 && $row['project_id_'] !== null && (int)$row['project_id_'] !== $projectId) continue;
            $out[] = [
                'id' => (int)$row['id_'],
                'session_id' => (int)$row['session_id_'],
                'project_id' => $row['project_id_'] !== null ? (int)$row['project_id_'] : null,
                'message_id' => $row['message_id_'] !== null ? (int)$row['message_id_'] : null,
                'tool' => (string)$row['tool'],
                'params' => $this->sanitizeTelemetry($this->decodeJsonOrRaw($row['params'])),
                'target_path' => $row['target_path'] !== null ? (string)$row['target_path'] : null,
                'result' => $this->sanitizeTelemetry($this->decodeJsonOrRaw($row['result'])),
                'status' => (string)$row['status'],
                'duration_ms' => (int)$row['duration_ms'],
                'created_at' => (string)$row['created_at'],
                'association' => $row['message_id_'] !== null ? 'message_id' : 'trace_time_window',
            ];
        }
        $stmt->close();
        return $out;
    }

    /** @param array<int,array<string,mixed>> $events @return array<string,mixed> */
    private function traceMeta(array $events): array
    {
        if (!$events) {
            return ['status' => 'unavailable', 'started_at' => null, 'completed_at' => null, 'duration_ms' => null];
        }
        $status = 'running';
        $duration = null;
        $completedAt = null;
        foreach ($events as $event) {
            $key = (string)$event['event_key'];
            if ($key === 'trace_completed') {
                $status = 'completed';
                $completedAt = $event['created_at'];
                $duration = $event['duration_ms'];
            } elseif ($key === 'trace_error') {
                $status = 'error';
                $completedAt = $event['created_at'];
                $duration = $event['duration_ms'];
            } elseif ($key === 'trace_cancelled') {
                $status = 'cancelled';
                $completedAt = $event['created_at'];
                $duration = $event['duration_ms'];
            }
        }
        return [
            'status' => $status,
            'started_at' => $events[0]['created_at'] ?? null,
            'completed_at' => $completedAt,
            'duration_ms' => $duration,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $events
     * @param array<int,array<string,mixed>> $tokenUsage
     * @param array<int,array<string,mixed>> $toolCalls
     * @param array<int,array<string,mixed>> $memoryWrites
     * @return array<string,mixed>
     */
    private function totals(array $events, array $tokenUsage, array $toolCalls, array $memoryWrites): array
    {
        $tokenMetrics = TraceMetricsCalculator::aggregateTokenRows($tokenUsage);

        $traceInput = 0;
        $traceOutput = 0;
        $modelDuration = 0;
        $modelRounds = 0;
        $eventDurations = [];
        foreach ($events as $event) {
            $key = (string)($event['event_key'] ?? 'unknown');
            $duration = (int)($event['duration_ms'] ?? 0);
            if ($duration > 0) {
                if (!isset($eventDurations[$key])) $eventDurations[$key] = ['event_key'=>$key,'calls'=>0,'duration_ms'=>0];
                $eventDurations[$key]['calls']++;
                $eventDurations[$key]['duration_ms'] += $duration;
            }
            if ($key !== 'model_round_completed') continue;
            $modelRounds++;
            $modelDuration += $duration;
            $d = $event['details'] ?? [];
            if (is_array($d)) {
                $traceInput += (int)($d['input_tokens'] ?? 0);
                $traceOutput += (int)($d['output_tokens'] ?? 0);
            }
        }
        $eventDurationValues = array_values($eventDurations);
        usort($eventDurationValues, static fn(array $a, array $b): int => ($b['duration_ms'] <=> $a['duration_ms']));

        $writes = 0;
        $memoryCandidates = 0;
        $memoryInput = 0;
        $memoryOutput = 0;
        $memoryModels = [];
        foreach ($memoryWrites as $row) {
            $writes += (int)($row['write_count'] ?? 0);
            $memoryCandidates += (int)($row['candidate_count'] ?? 0);
            $usage = is_array($row['usage'] ?? null) ? $row['usage'] : [];
            $memoryInput += (int)($usage['input_tokens'] ?? 0);
            $memoryOutput += (int)($usage['output_tokens'] ?? 0);
            $model = trim((string)($row['model_id'] ?? ''));
            if ($model !== '') $memoryModels[$model] = true;
        }

        $toolDuration = 0;
        $toolOk = 0;
        $toolErrors = 0;
        $toolTimeouts = 0;
        foreach ($toolCalls as $row) {
            $toolDuration += (int)($row['duration_ms'] ?? 0);
            $status = (string)($row['status'] ?? '');
            if ($status === 'ok') $toolOk++;
            elseif ($status === 'timeout') $toolTimeouts++;
            elseif ($status === 'error') $toolErrors++;
        }

        $memoryCost = 0.0;
        if ($memoryInput > 0 || $memoryOutput > 0) {
            $model = array_key_first($memoryModels) ?: '';
            $memoryCost = (float)TraceMetricsCalculator::calculate($model, $memoryInput, $memoryOutput)['cost'];
        }

        return [
            'event_count' => count($events),
            'token_accounting' => [
                'calls' => (int)$tokenMetrics['calls'],
                'input_tokens' => (int)$tokenMetrics['input_tokens'],
                'output_tokens' => (int)$tokenMetrics['output_tokens'],
                'total_tokens' => (int)$tokenMetrics['total_tokens'],
                // Compatibilidad con 7.1–7.6: este campo sigue siendo el costo
                // histórico almacenado en TokenUsage.
                'estimated_cost_usd' => (float)$tokenMetrics['stored_cost_usd'],
                'stored_cost_usd' => (float)$tokenMetrics['stored_cost_usd'],
                'recalculated_cost_usd' => (float)$tokenMetrics['recalculated_cost_usd'],
                'recorded_duration_ms_sum' => (int)$tokenMetrics['duration_ms_sum'],
                'by_phase' => $tokenMetrics['by_phase'],
                'by_model' => $tokenMetrics['by_model'],
                'fallback_pricing_models' => $tokenMetrics['fallback_pricing_models'],
                'pricing_basis' => $tokenMetrics['pricing_basis'],
                'source' => 'TokenUsage linked by message_id',
            ],
            'model_rounds_trace' => [
                'rounds' => $modelRounds,
                'input_tokens' => $traceInput,
                'output_tokens' => $traceOutput,
                'total_tokens' => $traceInput + $traceOutput,
                'duration_ms_sum' => $modelDuration,
                'source' => 'ChatActivityEvents model_round_completed',
            ],
            'duration_breakdown' => [
                'model_rounds_ms' => $modelDuration,
                'token_usage_calls_ms' => (int)$tokenMetrics['duration_ms_sum'],
                'tool_calls_ms' => $toolDuration,
                'events_with_duration' => $eventDurationValues,
            ],
            'tool_call_count' => count($toolCalls),
            'tools' => [
                'calls' => count($toolCalls),
                'ok' => $toolOk,
                'error' => $toolErrors,
                'timeout' => $toolTimeouts,
                'duration_ms_sum' => $toolDuration,
            ],
            'memory_write_event_count' => count($memoryWrites),
            'memory_write_count' => $writes,
            'memory_writer' => [
                'events' => count($memoryWrites),
                'candidates' => $memoryCandidates,
                'writes' => $writes,
                'input_tokens' => $memoryInput,
                'output_tokens' => $memoryOutput,
                'total_tokens' => $memoryInput + $memoryOutput,
                'estimated_cost_usd' => round($memoryCost, 6),
                'models' => array_values(array_keys($memoryModels)),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeMessageRow(array $row): array
    {
        return [
            'id' => (int)$row['id_'],
            'session_id' => (int)$row['session_id_'],
            'user_id' => (int)$row['user_id_'],
            'role' => (string)$row['role'],
            'content_type' => (string)$row['content_type'],
            'content' => (string)$row['content'],
            's3_key' => $row['s3_key'] ?? null,
            'mime_type' => $row['mime_type'] ?? null,
            'size_bytes' => isset($row['size_bytes']) && $row['size_bytes'] !== null ? (int)$row['size_bytes'] : null,
            'model_id' => $row['model_id'] !== null ? (string)$row['model_id'] : null,
            'stop_reason' => $row['stop_reason'] !== null ? (string)$row['stop_reason'] : null,
            'prompt_tokens' => $row['prompt_tokens'] !== null ? (int)$row['prompt_tokens'] : null,
            'completion_tokens' => $row['completion_tokens'] !== null ? (int)$row['completion_tokens'] : null,
            'latency_ms' => $row['latency_ms'] !== null ? (int)$row['latency_ms'] : null,
            'meta' => $this->decodeJsonOrRaw($row['meta']),
            'is_primordial' => (int)$row['is_primordial'] === 1,
            'phase' => (string)$row['phase'],
            'parent_msg_id' => $row['parent_msg_id'] !== null ? (int)$row['parent_msg_id'] : null,
            'created_at' => (string)$row['created_at'],
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeSessionBlockRow(array $row): array
    {
        return [
            'id' => (int)$row['id_'],
            'session_id' => (int)$row['session_id_'],
            'block_type' => (string)$row['block_type'],
            'question_message_id' => $row['question_msg_id'] !== null ? (int)$row['question_msg_id'] : null,
            'answer_message_id' => $row['answer_msg_id'] !== null ? (int)$row['answer_msg_id'] : null,
            'content' => $row['content_preview'] !== null ? (string)$row['content_preview'] : '',
            's3_path' => $row['s3_path'] !== null ? (string)$row['s3_path'] : null,
            'is_locked' => (int)$row['is_locked'] === 1,
            'source_ids' => $this->decodeJsonOrRaw($row['source_ids']),
            'token_count' => (int)$row['token_count'],
            'embedding_model' => $row['embedding_model'] !== null ? (string)$row['embedding_model'] : null,
            'is_memory_summary' => (int)$row['is_memory_summary'] === 1,
            'memory_hits' => (int)$row['memory_hits'],
            'last_memory_used_at' => $row['last_memory_used_at'] !== null ? (string)$row['last_memory_used_at'] : null,
            'created_at' => (string)$row['created_at'],
        ];
    }

    /** @param array<string,mixed> $live @param array<string,mixed>|null $snapshot @return array<string,mixed> */
    private function attachHistoricalComparison(array $live, ?array $snapshot): array
    {
        if (!$snapshot) {
            $live['historical_snapshot_found'] = false;
            $live['historical_comparison_mode'] = 'none';
            $live['changed_since_trace'] = null;
            return $live;
        }

        $snapshotMeta = is_array($snapshot['metadata'] ?? null) ? $snapshot['metadata'] : [];
        $liveContent = (string)($live['content'] ?? $live['context_summary'] ?? '');

        $historicalContent = '';
        $comparisonMode = 'none';

        if (array_key_exists('raw_content', $snapshotMeta) && $snapshotMeta['raw_content'] !== null) {
            $historicalContent = (string)$snapshotMeta['raw_content'];
            $comparisonMode = 'exact';
        } elseif (array_key_exists('content', $snapshot) && $snapshot['content'] !== null) {
            $historicalContent = (string)$snapshot['content'];
            $comparisonMode = 'exact';
        } elseif (array_key_exists('preview', $snapshot) && $snapshot['preview'] !== null) {
            $historicalContent = (string)$snapshot['preview'];
            $historicalChars = isset($snapshot['chars']) && is_numeric($snapshot['chars'])
                ? (int)$snapshot['chars']
                : null;
            $comparisonMode = ($historicalChars !== null && $historicalChars <= mb_strlen($historicalContent))
                ? 'exact'
                : 'prefix_only';
        }

        $live['historical_snapshot_found'] = true;
        $live['historical_comparison_mode'] = $comparisonMode;
        $live['changed_since_trace'] = null;

        if ($historicalContent === '' || $liveContent === '') {
            return $live;
        }

        if ($comparisonMode === 'exact') {
            $historicalHash = hash('sha256', $historicalContent);
            $currentHash = hash('sha256', $liveContent);
            $live['historical_content_sha256'] = $historicalHash;
            $live['current_content_sha256'] = $currentHash;
            $live['changed_since_trace'] = !hash_equals($historicalHash, $currentHash);
            return $live;
        }

        if ($comparisonMode === 'prefix_only') {
            $currentPrefix = mb_substr($liveContent, 0, mb_strlen($historicalContent));
            $live['historical_preview_sha256'] = hash('sha256', $historicalContent);
            $live['current_prefix_sha256'] = hash('sha256', $currentPrefix);
            $live['historical_prefix_matches_current'] = hash_equals(
                $live['historical_preview_sha256'],
                $live['current_prefix_sha256']
            );
        }

        return $live;
    }

    /** @param array<int,array<string,mixed>> $a @param array<int,array<string,mixed>> $b @return array<int,array<string,mixed>> */
    private function mergeResourceRows(array $a, array $b, string $idKey): array
    {
        $out = [];
        foreach (array_merge($a, $b) as $row) {
            if (!is_array($row) || !isset($row[$idKey])) continue;
            $out[(string)$row[$idKey]] = $row;
        }
        return array_values($out);
    }

    private function traceIdFromMeta($meta): string
    {
        $decoded = is_array($meta) ? $meta : $this->decodeJsonOrRaw($meta);
        if (!is_array($decoded)) return '';
        $trace = trim((string)($decoded['trace_id'] ?? ''));
        return preg_match('/^[A-Za-z0-9_-]{16,36}$/', $trace) ? $trace : '';
    }

    private function eventCategory(string $eventKey, string $phase): string
    {
        if ($phase === 'compile' || str_contains($eventKey, 'compiler') || str_contains($eventKey, 'compilation')) return 'compiler';
        if (str_contains($eventKey, 'router')) return 'router';
        if (str_contains($eventKey, 'pipeline_features')) return 'feature_flags';
        if (str_contains($eventKey, 'ranking')) return 'ranking';
        if (str_contains($eventKey, 'context_builder') || str_contains($eventKey, 'context_ready')) return 'context';
        if (str_contains($eventKey, 'rag') || str_contains($eventKey, 'embedding')) return 'retrieval';
        if (str_contains($eventKey, 'prompt')) return 'prompt';
        if (str_contains($eventKey, 'model_round')) return 'model';
        if (str_contains($eventKey, 'tool')) return 'tool';
        if (str_contains($eventKey, 'memory_writer') || str_contains($eventKey, 'memory_backfill') || str_contains($eventKey, 'memory_queued')) return 'memory';
        if (str_contains($eventKey, 'response')) return 'response';
        if (str_contains($eventKey, 'trace_')) return 'trace';
        return 'pipeline';
    }

    private function decodeJsonOrRaw($value)
    {
        if ($value === null || $value === '') return null;
        if (is_array($value) || is_object($value)) return $value;
        $text = (string)$value;
        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE) return $decoded;
        return $text;
    }

    private function sanitizeTelemetry($value, int $depth = 0)
    {
        if ($depth > 12) return '[profundidad omitida]';
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $normalizedKey = strtolower((string)$key);
                if (in_array($normalizedKey, [
                    'thinking', 'reasoning', 'reasoning_content', 'chain_of_thought',
                    'chain-of-thought', 'cot', 'internal_reasoning', 'private_reasoning',
                    'analysis',
                ], true)) {
                    $out[$key] = '[razonamiento privado omitido]';
                    continue;
                }
                $out[$key] = $this->sanitizeTelemetry($item, $depth + 1);
            }
            return $out;
        }
        if (is_object($value)) return $this->sanitizeTelemetry((array)$value, $depth + 1);
        if (is_string($value)) {
            $value = preg_replace('/<thinking>[\s\S]*?<\/thinking>/i', '[razonamiento privado omitido]', $value) ?? $value;
            $value = preg_replace('/<reasoning>[\s\S]*?<\/reasoning>/i', '[razonamiento privado omitido]', $value) ?? $value;
            return $value;
        }
        return $value;
    }

    private function tableExists(string $table): bool
    {
        if (isset($this->tableCache[$table])) return $this->tableCache[$table];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
        $stmt = $this->db->prepare(
            "SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1"
        );
        if (!$stmt) return $this->tableCache[$table] = false;
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $this->tableCache[$table] = $exists;
    }

    /** @param array<int,int> $ids */
    private function intList(array $ids): string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $v): bool => $v > 0)));
        return $ids ? implode(',', $ids) : '0';
    }
}
