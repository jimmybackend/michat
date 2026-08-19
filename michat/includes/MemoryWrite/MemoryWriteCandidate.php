<?php

declare(strict_types=1);

final class MemoryWriteCandidate
{
    public string $target;
    public string $type;
    public string $title;
    public string $content;
    public float $confidence;
    /** @var array<string,mixed> */
    public array $metadata;

    /** @param array<string,mixed> $metadata */
    public function __construct(
        string $target,
        string $type,
        string $title,
        string $content,
        float $confidence = 0.80,
        array $metadata = []
    ) {
        $this->target = $target;
        $this->type = $type;
        $this->title = trim($title);
        $this->content = trim($content);
        $this->confidence = max(0.0, min(1.0, $confidence));
        $this->metadata = $metadata;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'type' => $this->type,
            'title' => $this->title,
            'content' => $this->content,
            'confidence' => $this->confidence,
            'metadata' => $this->metadata,
        ];
    }
}
