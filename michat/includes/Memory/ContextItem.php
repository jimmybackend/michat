<?php

declare(strict_types=1);

/**
 * Unidad normalizada de contexto recuperado.
 * Fase 3 conserva score/confidence de origen y añade ranking multi-señal trazable.
 */
final class ContextItem
{
    public string $source;
    /** @var int|string|null */
    public $sourceId;
    public string $type;
    public string $scope;
    public string $content;
    public ?float $score;
    public ?float $confidence;
    public ?float $rankingScore = null;
    public ?int $rank = null;
    public bool $selected = true;
    /** @var array<string,float> */
    public array $rankingSignals = [];
    public ?string $exclusionReason = null;
    public ?string $duplicateOf = null;
    /** @var array<string,mixed> */
    public array $metadata;

    /**
     * @param int|string|null $sourceId
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        string $source,
        $sourceId,
        string $type,
        string $scope,
        string $content,
        ?float $score = null,
        ?float $confidence = null,
        array $metadata = []
    ) {
        $this->source = $source;
        $this->sourceId = $sourceId;
        $this->type = $type;
        $this->scope = $scope;
        $this->content = trim($content);
        $this->score = $score;
        $this->confidence = $confidence;
        $this->metadata = $metadata;
    }

    /** @return array<string,mixed> */
    public function toArray(bool $withContent = true): array
    {
        $out = [
            'source' => $this->source,
            'source_id' => $this->sourceId,
            'type' => $this->type,
            'scope' => $this->scope,
            'score' => $this->score,
            'confidence' => $this->confidence,
            'ranking_score' => $this->rankingScore,
            'rank' => $this->rank,
            'selected' => $this->selected,
            'ranking_signals' => $this->rankingSignals,
            'exclusion_reason' => $this->exclusionReason,
            'duplicate_of' => $this->duplicateOf,
            'metadata' => $this->metadata,
        ];

        if ($withContent) {
            $out['content'] = $this->content;
        } else {
            $out['preview'] = mb_substr($this->content, 0, 240);
            $out['chars'] = mb_strlen($this->content);
        }

        return $out;
    }
}
