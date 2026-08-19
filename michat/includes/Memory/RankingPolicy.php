<?php

declare(strict_types=1);

/**
 * Política determinista de ranking de contexto.
 *
 * No sustituye los umbrales semánticos de los repositorios. El cosine existente
 * sigue siendo una señal de elegibilidad para RAG/Q&A; aquí se combina con
 * autoridad, tipo, alcance, confianza, recencia y relevancia léxica.
 */
final class RankingPolicy
{
    /** @var array<string,float> */
    private array $weights;
    /** @var array<string,int> */
    private array $bucketCaps;
    /** @var array<string,float> */
    private array $bucketThresholds;
    private int $globalOptionalLimit;

    public function __construct()
    {
        $defaults = [
            'semantic' => 0.30,
            'lexical' => 0.18,
            'type' => 0.20,
            'authority' => 0.15,
            'scope' => 0.07,
            'confidence' => 0.05,
            'recency' => 0.05,
        ];
        $cfg = function_exists('aiAgentExtra') ? aiAgentExtra('context_ranker', 'weights', $defaults) : $defaults;
        $this->weights = is_array($cfg) ? array_replace($defaults, $cfg) : $defaults;

        $defaultCaps = [
            'procedural_policy' => 10,
            'procedural_answer' => 6,
            'project_context' => 8,
            'session_memory' => 6,
            'recent_messages' => 6,
            'project_rag' => 4,
            'attachments' => 6,
            'question_memory' => 4,
        ];
        $caps = function_exists('aiAgentExtra') ? aiAgentExtra('context_ranker', 'bucket_caps', $defaultCaps) : $defaultCaps;
        $this->bucketCaps = is_array($caps) ? array_replace($defaultCaps, $caps) : $defaultCaps;

        $defaultThresholds = [
            'procedural_policy' => 0.00,
            'procedural_answer' => 0.42,
            'project_context' => 0.42,
            'session_memory' => 0.34,
            'recent_messages' => 0.00,
            'project_rag' => 0.36,
            'attachments' => 0.34,
            'question_memory' => 0.36,
        ];
        $thresholds = function_exists('aiAgentExtra') ? aiAgentExtra('context_ranker', 'bucket_thresholds', $defaultThresholds) : $defaultThresholds;
        $this->bucketThresholds = is_array($thresholds) ? array_replace($defaultThresholds, $thresholds) : $defaultThresholds;

        $limit = function_exists('aiAgentExtra') ? aiAgentExtra('context_ranker', 'global_optional_limit', 24) : 24;
        $this->globalOptionalLimit = max(8, min(60, (int)$limit));
    }

    /** @return array<string,float> */
    public function weights(): array
    {
        return $this->weights;
    }

    public function bucketCap(string $bucket): int
    {
        return max(1, min(50, (int)($this->bucketCaps[$bucket] ?? 6)));
    }

    public function bucketThreshold(string $bucket): float
    {
        return max(0.0, min(1.0, (float)($this->bucketThresholds[$bucket] ?? 0.40)));
    }

    public function globalOptionalLimit(): int
    {
        return $this->globalOptionalLimit;
    }

    public function isRequiredBucket(string $bucket): bool
    {
        // Memoria procedural de política mantiene el comportamiento previo:
        // reglas/preferencias activas no dependen de similitud con la pregunta.
        return $bucket === 'procedural_policy';
    }

    public function ensureOneWhenRequested(string $bucket): bool
    {
        return in_array($bucket, [
            'procedural_answer', 'project_context', 'session_memory',
            'project_rag', 'attachments', 'question_memory',
        ], true);
    }

    public function authority(ContextItem $item, string $intent): float
    {
        if ($item->source === 'UserProceduralMemory') {
            return match ($item->type) {
                'rule', 'correction' => 0.98,
                'preference' => 0.92,
                'workflow' => 0.88,
                'pattern' => 0.84,
                default => 0.80,
            };
        }

        if ($item->source === 'ProjectContext') {
            return match ($item->type) {
                'rule' => 0.99,
                'decision' => 0.97,
                'fact' => 0.94,
                'style' => 0.90,
                'todo' => 0.88,
                'note' => 0.72,
                default => 0.78,
            };
        }

        if ($item->source === 'SourceChunks') {
            return $intent === 'code' ? 0.96 : 0.86;
        }

        if ($item->source === 'ChatSessions' && $item->type === 'context_summary') return 0.76;
        if ($item->source === 'ChatMessages') return 0.70;

        if ($item->source === 'SessionContextBlocks') {
            if ($item->scope === 'session_attachment') return 0.84;
            if ($item->type === 'qa_memory') return 0.80;
            return match ($item->type) {
                'level_3' => 0.82,
                'level_2' => 0.80,
                'level_1' => 0.77,
                'level_0' => 0.72,
                default => 0.74,
            };
        }

        return 0.70;
    }

    public function typeMatch(ContextItem $item, string $intent): float
    {
        $type = $item->type;
        $scope = $item->scope;

        return match ($intent) {
            'decision' => $type === 'decision' ? 1.0 : ($type === 'qa_memory' ? 0.68 : 0.45),
            'fact' => $type === 'fact' ? 1.0 : ($type === 'qa_memory' ? 0.66 : 0.45),
            'rule' => in_array($type, ['rule','correction'], true) ? 1.0 : ($type === 'qa_memory' ? 0.62 : 0.45),
            'preference' => in_array($type, ['preference','style','pattern','workflow'], true) ? 1.0 : 0.48,
            'todo' => $type === 'todo' ? 1.0 : ($type === 'note' ? 0.72 : 0.45),
            'conversation' => ($scope === 'session' || $scope === 'session_recent' || $type === 'qa_memory') ? 0.96 : 0.52,
            'code' => ($scope === 'project_rag' || $scope === 'session_attachment') ? 1.0 : ($type === 'decision' || $type === 'rule' ? 0.72 : 0.48),
            default => 0.55,
        };
    }

    public function scopeMatch(ContextItem $item, string $intent, bool $hasProject): float
    {
        $scope = $item->scope;

        if ($intent === 'preference' && in_array($scope, ['user','project_user','session','branch'], true)) return 1.0;
        if ($intent === 'conversation' && in_array($scope, ['session','session_recent','branch'], true)) return 1.0;
        if ($intent === 'conversation' && $item->type === 'qa_memory') return 0.96;
        if ($intent === 'code' && in_array($scope, ['project_rag','session_attachment'], true)) return 1.0;
        if ($hasProject && in_array($intent, ['decision','fact','rule','todo','code'], true) && $scope === 'project') return 1.0;
        if (in_array($scope, ['user','project_user'], true)) return 0.84;
        if ($scope === 'project') return $hasProject ? 0.86 : 0.50;
        if (str_starts_with($scope, 'session') || $scope === 'branch') return 0.80;

        return 0.65;
    }
}
