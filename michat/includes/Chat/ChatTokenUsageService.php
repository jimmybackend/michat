<?php
declare(strict_types=1);

/** Persists usage for the single final, user-visible assistant message. */
final class ChatTokenUsageService
{
    private const PHASES = ['compile','respond','lint_fix','embedding','classify','scout','plan','rag','edit','summarize','review'];

    public function __construct(private mysqli $db) {}

    /** @param array<string,mixed> $usage */
    public function recordFinal(
        int $userId,
        int $sessionId,
        int $messageId,
        string $modelId,
        array $usage,
        ?int $durationMs
    ): void {
        $modelId = trim($modelId);
        if ($userId < 1 || $sessionId < 1 || $messageId < 1 || $modelId === '') {
            throw new InvalidArgumentException('chat_token_usage_invalid');
        }

        // Legacy policy records the turn even when Bedrock omitted usage, using zeroes.
        $inputTokens = max(0, (int)($usage['prompt_tokens'] ?? 0));
        $outputTokens = max(0, (int)($usage['completion_tokens'] ?? 0));
        $duration = max(0, (int)($durationMs ?? 0));
        $phase = $this->respondPhase($userId);
        $cost = self::calculateCost($modelId, $inputTokens, $outputTokens);
        // A table-wide lock also protects the legacy MAX(id_)+1 allocation policy.
        $lockName = 'chat_token_usage:persist';

        try {
            if (!$this->acquireLock($lockName)) throw new RuntimeException('chat_token_usage_lock_failed');
            try {
                $this->assertFinalMessage($sessionId, $messageId);
                if ($this->alreadyRecorded($sessionId, $messageId, $phase)) return;
                $id = $this->nextId();
                $stmt = $this->db->prepare('INSERT INTO TokenUsage (id_,session_id_,message_id_,phase,model_id,input_tokens,output_tokens,estimated_cost_usd,duration_ms) VALUES (?,?,?,?,?,?,?,?,?)');
                if (!$stmt) throw new RuntimeException('database_error');
                $stmt->bind_param('iiissiddi', $id, $sessionId, $messageId, $phase, $modelId, $inputTokens, $outputTokens, $cost, $duration);
                if (!$stmt->execute()) throw new RuntimeException('database_error');
                $stmt->close();
            } finally {
                $this->releaseLock($lockName);
            }
        } catch (Throwable $e) {
            // TokenUsage is telemetry in the legacy chat and never invalidates its answer.
            error_log('ChatTokenUsageService: ' . $e->getMessage());
        }
    }

    private function respondPhase(int $userId): string
    {
        $configs = loadDynamicAIAgentConfigs($this->db, $userId);
        $phase = trim((string)($configs['chat_main']['token_usage_phase'] ?? 'respond'));
        return in_array($phase, self::PHASES, true) ? $phase : 'respond';
    }

    private function assertFinalMessage(int $sessionId, int $messageId): void
    {
        $stmt = $this->db->prepare("SELECT id_ FROM ChatMessages WHERE id_=? AND session_id_=? AND role='assistant' AND content_type='text' LIMIT 1");
        if (!$stmt) throw new RuntimeException('database_error');
        $stmt->bind_param('ii', $messageId, $sessionId);
        if (!$stmt->execute()) throw new RuntimeException('database_error');
        $valid = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$valid) throw new RuntimeException('chat_token_usage_message_invalid');
    }

    private function alreadyRecorded(int $sessionId, int $messageId, string $phase): bool
    {
        $stmt = $this->db->prepare('SELECT id_ FROM TokenUsage WHERE session_id_=? AND message_id_=? AND phase=? LIMIT 1');
        if (!$stmt) throw new RuntimeException('database_error');
        $stmt->bind_param('iis', $sessionId, $messageId, $phase);
        if (!$stmt->execute()) throw new RuntimeException('database_error');
        $exists = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $exists;
    }

    private function nextId(): int
    {
        $result = $this->db->query('SELECT COALESCE(MAX(id_),0)+1 next_id FROM TokenUsage');
        if (!$result) throw new RuntimeException('database_error');
        return max(1, (int)$result->fetch_assoc()['next_id']);
    }

    /** Exact pricing policy used by bedrock_chat2.php. */
    public static function calculateCost(string $modelId, int $inputTokens, int $outputTokens): float
    {
        $model = strtolower($modelId);
        if (str_contains($model, 'titan-embed')) $pricing = ['input'=>0.10, 'output'=>0.00];
        elseif (str_contains($model, 'nova-micro')) $pricing = ['input'=>0.035, 'output'=>0.14];
        elseif (str_contains($model, 'nova-lite')) $pricing = ['input'=>0.06, 'output'=>0.24];
        elseif (str_contains($model, 'nova-pro')) $pricing = ['input'=>0.80, 'output'=>3.20];
        else $pricing = ['input'=>0.035, 'output'=>0.14];
        return round(($inputTokens / 1000000 * $pricing['input']) + ($outputTokens / 1000000 * $pricing['output']), 6);
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
