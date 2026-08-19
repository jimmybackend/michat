<?php

declare(strict_types=1);

/**
 * Resultado único del ContextBuilder con candidatos, selección y ranking.
 */
final class ContextBundle
{
    /** @var array<string,ContextItem[]> */
    private array $items = [];
    /** @var array<string,string> */
    private array $blocks = [];
    /** @var array<string,mixed> */
    private array $telemetry = [];
    /** @var array<string,mixed> */
    private array $legacy = [];
    /** @var array<int,array<string,mixed>> */
    private array $requests = [];
    private ?RankingResult $rankingResult = null;

    /** @param array<int,array<string,mixed>> $requests */
    public function setRequests(array $requests): void
    {
        $this->requests = $requests;
    }

    public function addItem(string $bucket, ContextItem $item): void
    {
        $this->items[$bucket] ??= [];
        $this->items[$bucket][] = $item;
    }

    /** @param ContextItem[] $items */
    public function addItems(string $bucket, array $items): void
    {
        foreach ($items as $item) {
            if ($item instanceof ContextItem) $this->addItem($bucket, $item);
        }
    }

    /** @return ContextItem[] */
    public function items(string $bucket): array
    {
        return $this->items[$bucket] ?? [];
    }

    /** @return array<string,ContextItem[]> */
    public function allItems(): array
    {
        return $this->items;
    }

    public function setRankingResult(RankingResult $result): void
    {
        $this->rankingResult = $result;
    }

    public function rankingResult(): ?RankingResult
    {
        return $this->rankingResult;
    }

    /** @return ContextItem[] */
    public function selectedItems(string $bucket): array
    {
        if ($this->rankingResult) return $this->rankingResult->selected($bucket);
        return array_values(array_filter($this->items($bucket), static fn(ContextItem $item): bool => $item->selected));
    }

    public function setBlock(string $name, string $content): void
    {
        $this->blocks[$name] = trim($content);
    }

    public function block(string $name): string
    {
        return $this->blocks[$name] ?? '';
    }

    /** @return array<string,string> */
    public function blocks(): array
    {
        return $this->blocks;
    }

    /** @param mixed $value */
    public function setTelemetry(string $source, $value): void
    {
        $this->telemetry[$source] = $value;
    }

    /** @return array<string,mixed> */
    public function telemetry(): array
    {
        return $this->telemetry;
    }

    /** @param mixed $value */
    public function setLegacy(string $key, $value): void
    {
        $this->legacy[$key] = $value;
    }

    /** @return mixed */
    public function legacy(string $key, $default = null)
    {
        return $this->legacy[$key] ?? $default;
    }

    /**
     * Resumen seguro para devolver al navegador sin duplicar bloques enormes.
     *
     * @return array<string,mixed>
     */
    public function toPublicArray(): array
    {
        $sources = [];
        $items = [];

        foreach ($this->items as $bucket => $bucketItems) {
            $sources[$bucket] = count($bucketItems);
            $items[$bucket] = array_map(
                static fn(ContextItem $item): array => $item->toArray(false),
                $bucketItems
            );
        }

        $blockChars = [];
        foreach ($this->blocks as $name => $block) {
            $blockChars[$name] = mb_strlen($block);
        }

        $selectedSources = [];
        foreach ($this->items as $bucket => $_) {
            $selectedSources[$bucket] = count($this->selectedItems($bucket));
        }

        return [
            'version' => 4.1,
            'scope_guard' => $this->telemetry['scope_guard'] ?? null,
            'requests' => $this->requests,
            'sources' => $sources,
            'selected_sources' => $selectedSources,
            'items' => $items,
            'ranking' => $this->rankingResult ? $this->rankingResult->summary() : null,
            'block_chars' => $blockChars,
        ];
    }

    /** @return array<string,mixed> */
    public function toActivityArray(): array
    {
        $items = [];
        foreach ($this->items as $bucket => $bucketItems) {
            $items[$bucket] = array_map(
                static fn(ContextItem $item): array => $item->toArray(true),
                $bucketItems
            );
        }

        return [
            'version' => 4.1,
            'requests' => $this->requests,
            'items' => $items,
            'ranking' => $this->rankingResult ? $this->rankingResult->summary() : null,
            'blocks' => $this->blocks,
            'telemetry' => $this->telemetry,
        ];
    }
}
