<?php

declare(strict_types=1);

final class RankingResult
{
    /** @var array<string,ContextItem[]> */
    private array $selectedByBucket;
    /** @var array<string,ContextItem[]> */
    private array $discardedByBucket;
    /** @var array<string,mixed> */
    private array $summary;

    /**
     * @param array<string,ContextItem[]> $selectedByBucket
     * @param array<string,ContextItem[]> $discardedByBucket
     * @param array<string,mixed> $summary
     */
    public function __construct(array $selectedByBucket, array $discardedByBucket, array $summary)
    {
        $this->selectedByBucket = $selectedByBucket;
        $this->discardedByBucket = $discardedByBucket;
        $this->summary = $summary;
    }

    /** @return ContextItem[] */
    public function selected(string $bucket): array
    {
        return $this->selectedByBucket[$bucket] ?? [];
    }

    /** @return ContextItem[] */
    public function discarded(string $bucket): array
    {
        return $this->discardedByBucket[$bucket] ?? [];
    }

    /** @return array<string,ContextItem[]> */
    public function selectedAll(): array
    {
        return $this->selectedByBucket;
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        return $this->summary;
    }
}
