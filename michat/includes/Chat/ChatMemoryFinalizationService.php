<?php
declare(strict_types=1);

/** Finalizes memory for the one user-visible answer of a Task chat turn. */
final class ChatMemoryFinalizationService
{
    public function __construct(private mysqli $db, private $bedrock) {}

    /**
     * All identity values come from the persisted Task/chat turn, never from request state.
     *
     * @param array<string,mixed> $route
     * @param array<int,string> $successfulTools
     */
    public function finalize(
        int $userId,
        int $sessionId,
        ?int $projectId,
        int $questionMessageId,
        int $answerMessageId,
        array $route = [],
        array $successfulTools = []
    ): void {
        if ($userId < 1 || $sessionId < 1 || $questionMessageId < 1 || $answerMessageId < 1) {
            throw new InvalidArgumentException('chat_memory_finalization_invalid');
        }

        [$question, $answer] = $this->loadTurn($userId, $sessionId, $questionMessageId, $answerMessageId);
        $lockName = 'chat_memory:' . substr(hash('sha256', $questionMessageId . ':' . $answerMessageId), 0, 52);
        if (!$this->acquireLock($lockName)) throw new RuntimeException('chat_memory_finalization_lock_failed');

        try {
            $blockId = $this->ensureSessionBlock($sessionId, $questionMessageId, $answerMessageId, $question, $answer);
            $this->ensureEmbeddingJob($userId, $blockId);

            if ((new PipelineFeatureFlags($this->db, $userId))->enabled('memory_writer')) {
                // MemoryWriter's MemoryWriteEvents unique key makes retries safe for this Q&A.
                (new MemoryWriter($this->db, $this->bedrock))->write(
                    $userId,
                    $sessionId,
                    $projectId ?? 0,
                    $questionMessageId,
                    $answerMessageId,
                    $question,
                    $answer,
                    $route,
                    $successfulTools
                );
            }
        } finally {
            $this->releaseLock($lockName);
        }
    }

    /** @return array{0:string,1:string} */
    private function loadTurn(int $userId, int $sessionId, int $questionId, int $answerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT q.content question, a.content answer
             FROM ChatMessages q JOIN ChatMessages a
               ON a.id_=? AND a.session_id_=q.session_id_ AND a.user_id_=q.user_id_
             WHERE q.id_=? AND q.session_id_=? AND q.user_id_=?
               AND q.role='user' AND a.role='assistant' LIMIT 1"
        );
        if (!$stmt) throw new RuntimeException('database_error');
        $stmt->bind_param('iiii', $answerId, $questionId, $sessionId, $userId);
        if (!$stmt->execute()) throw new RuntimeException('database_error');
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) throw new RuntimeException('chat_memory_turn_invalid');
        return [(string)$row['question'], (string)$row['answer']];
    }

    private function ensureSessionBlock(int $sessionId, int $questionId, int $answerId, string $question, string $answer): int
    {
        $stmt = $this->db->prepare("SELECT id_ FROM SessionContextBlocks WHERE question_msg_id=? AND answer_msg_id=? AND is_memory_summary=0 LIMIT 1");
        if (!$stmt) throw new RuntimeException('database_error');
        $stmt->bind_param('ii', $questionId, $answerId);
        if (!$stmt->execute()) throw new RuntimeException('database_error');
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return (int)$row['id_'];

        $raw = "Pregunta: {$question}\nRespuesta: {$answer}";
        $preview = mb_substr($raw, 0, 8000);
        $tokens = (int)ceil(mb_strlen($raw) / 4);
        $id = $this->nextId('SessionContextBlocks');
        $stmt = $this->db->prepare("INSERT INTO SessionContextBlocks (id_,session_id_,block_type,question_msg_id,answer_msg_id,content_preview,is_locked,token_count,is_memory_summary) VALUES (?,?,'level_0',?,?,?,0,?,0)");
        if (!$stmt) throw new RuntimeException('database_error');
        $stmt->bind_param('iiiisi', $id, $sessionId, $questionId, $answerId, $preview, $tokens);
        if (!$stmt->execute()) throw new RuntimeException('database_error');
        $stmt->close();
        return $id;
    }

    private function ensureEmbeddingJob(int $userId, int $blockId): void
    {
        $configs = loadDynamicAIAgentConfigs($this->db, $userId);
        $config = $configs['embedding_main'] ?? null;
        $model = trim((string)($config['model_id'] ?? ''));
        if (!$config || (int)($config['is_active'] ?? 0) !== 1 || $model === '') return;
        $id = $this->nextId('EmbeddingJobs');
        $stmt = $this->db->prepare("INSERT IGNORE INTO EmbeddingJobs (id_,target_type,target_id,model_id,status,attempts) VALUES (?,'session_block',?,?,'pending',0)");
        if (!$stmt) throw new RuntimeException('database_error');
        $stmt->bind_param('iis', $id, $blockId, $model);
        if (!$stmt->execute()) throw new RuntimeException('database_error');
        $stmt->close();
    }

    private function nextId(string $table): int
    {
        if (!in_array($table, ['SessionContextBlocks', 'EmbeddingJobs'], true)) throw new LogicException('invalid_table');
        $result = $this->db->query("SELECT COALESCE(MAX(id_),0)+1 next_id FROM {$table}");
        if (!$result) throw new RuntimeException('database_error');
        return max(1, (int)$result->fetch_assoc()['next_id']);
    }

    private function acquireLock(string $name): bool
    {
        $stmt = $this->db->prepare('SELECT GET_LOCK(?,10) acquired');
        if (!$stmt) throw new RuntimeException('database_error');
        $stmt->bind_param('s', $name);
        if (!$stmt->execute()) throw new RuntimeException('database_error');
        $ok = (int)($stmt->get_result()->fetch_assoc()['acquired'] ?? 0) === 1;
        $stmt->close();
        return $ok;
    }

    private function releaseLock(string $name): void
    {
        $stmt = $this->db->prepare('SELECT RELEASE_LOCK(?)');
        if (!$stmt) return;
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $stmt->close();
    }
}
