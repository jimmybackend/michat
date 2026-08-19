<?php

declare(strict_types=1);

/**
 * Genera como máximo un embedding de consulta por ContextBuilder y lo comparte
 * entre Project RAG, adjuntos y memoria semántica.
 */
final class QueryEmbeddingProvider
{
    private mysqli $db;
    private $bedrock;
    private int $sessionId;
    private ?int $logMsgId;
    /** @var float[]|null */
    private ?array $vector = null;
    private string $query = '';
    /** @var array<string,mixed> */
    private array $telemetry = [];

    public function __construct(mysqli $db, $bedrock, int $sessionId, ?int $logMsgId)
    {
        $this->db = $db;
        $this->bedrock = $bedrock;
        $this->sessionId = $sessionId;
        $this->logMsgId = $logMsgId;
    }

    /** @return float[] */
    public function get(string $queryText): array
    {
        $queryText = trim($queryText);
        if ($queryText === '') return [];
        if ($this->vector !== null && $this->query === $queryText) return $this->vector;

        $this->query = $queryText;
        $this->vector = [];
        $started = microtime(true);

        if (!function_exists('generateTitanEmbedding') || !aiAgentActive('embedding_main', false)) {
            $this->telemetry = ['generated' => false, 'reason' => 'embedding_main_disabled'];
            return [];
        }

        try {
            $model = aiAgentModel('embedding_main', '');
            $data = generateTitanEmbedding($this->bedrock, $queryText, $model, 'search_query');
            $this->vector = is_array($data['embedding'] ?? null) ? $data['embedding'] : [];

            if (($data['inputTokens'] ?? 0) > 0 && function_exists('logTokenUsage')) {
                logTokenUsage(
                    $this->db,
                    $this->sessionId,
                    $this->logMsgId,
                    (string)aiAgentValue('embedding_main', 'token_usage_phase', 'rag'),
                    $model,
                    (int)$data['inputTokens'],
                    0
                );
            }

            $this->telemetry = [
                'generated' => !empty($this->vector),
                'query' => $queryText,
                'model' => $model,
                'adapter' => $data['adapter'] ?? null,
                'input_type' => $data['input_type'] ?? 'search_query',
                'dimensions' => $data['dimensions'] ?? count($this->vector),
                'input_tokens' => (int)($data['inputTokens'] ?? 0),
                'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            ];
        } catch (Throwable $e) {
            $this->telemetry = [
                'generated' => false,
                'query' => $queryText,
                'error' => $e->getMessage(),
                'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            ];
            error_log('CONTEXT_BUILDER_EMBEDDING: ' . $e->getMessage());
        }

        return $this->vector;
    }

    /** @return array<string,mixed> */
    public function telemetry(): array
    {
        return $this->telemetry;
    }
}
