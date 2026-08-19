<?php

declare(strict_types=1);

/**
 * Fase 3: ranking multi-señal, selección y deduplicación del contexto.
 */
final class ContextRanker
{
    private RankingPolicy $policy;
    private ContextDeduplicator $deduplicator;

    public function __construct(?RankingPolicy $policy = null, ?ContextDeduplicator $deduplicator = null)
    {
        $this->policy = $policy ?? new RankingPolicy();
        $this->deduplicator = $deduplicator ?? new ContextDeduplicator();
    }

    /**
     * Fase 5: selección determinista cuando el ranking está apagado.
     * Mantiene caps y deduplicación para proteger el tamaño del prompt, pero no
     * calcula relevancia, thresholds ni pesos multi-señal.
     */
    public function selectWithoutRanking(ContextBundle $bundle, string $stage = 'respond'): RankingResult
    {
        $startedAt = microtime(true);
        $stage = $stage === 'compile' ? 'compile' : 'respond';
        $selectedByBucket = [];
        $discardedByBucket = [];
        $candidatesForDedupe = [];
        $retrieved = 0;

        foreach ($bundle->allItems() as $bucket => $items) {
            $retrieved += count($items);
            $required = $this->policy->isRequiredBucket($bucket) || $this->bucketIsRuntimeRequired($bucket, $items);
            $cap = $required ? max($this->policy->bucketCap($bucket), count($items)) : $this->policy->bucketCap($bucket);
            $selectedLocal = [];
            $discardedLocal = [];

            foreach ($items as $item) {
                $item->rankingScore = null;
                $item->rankingSignals = [];
                $item->metadata['ranking_disabled'] = true;

                if (count($selectedLocal) < $cap) {
                    $item->selected = true;
                    $item->exclusionReason = null;
                    $selectedLocal[] = $item;
                    $candidatesForDedupe[] = ['bucket' => $bucket, 'item' => $item, 'required' => $required];
                } else {
                    $item->selected = false;
                    $item->exclusionReason = 'bucket_cap_without_ranking';
                    $discardedLocal[] = $item;
                }
            }

            $selectedByBucket[$bucket] = $selectedLocal;
            $discardedByBucket[$bucket] = $discardedLocal;
        }

        $dedup = $this->deduplicator->deduplicate($candidatesForDedupe);
        $selectedByBucket = [];
        foreach ($bundle->allItems() as $bucket => $_) $selectedByBucket[$bucket] = [];
        foreach ($dedup['kept'] as $candidate) {
            $selectedByBucket[$candidate['bucket']][] = $candidate['item'];
        }

        // Presupuesto opcional global, conservando el orden determinista de llegada.
        $optionalSeen = 0;
        foreach ($selectedByBucket as $bucket => $items) {
            $required = $this->policy->isRequiredBucket($bucket) || $this->bucketIsRuntimeRequired($bucket, $items);
            if ($required) continue;
            $kept = [];
            foreach ($items as $item) {
                if ($optionalSeen < $this->policy->globalOptionalLimit()) {
                    $kept[] = $item;
                    $optionalSeen++;
                } else {
                    $item->selected = false;
                    $item->exclusionReason = 'global_context_budget_without_ranking';
                    $discardedByBucket[$bucket][] = $item;
                }
            }
            $selectedByBucket[$bucket] = $kept;
        }

        $rank = 1;
        foreach ($selectedByBucket as $items) {
            foreach ($items as $item) $item->rank = $rank++;
        }

        foreach ($bundle->allItems() as $bucket => $items) {
            $known = [];
            foreach ($discardedByBucket[$bucket] ?? [] as $item) $known[spl_object_id($item)] = true;
            foreach ($items as $item) {
                if (!$item->selected && !isset($known[spl_object_id($item)])) {
                    $discardedByBucket[$bucket][] = $item;
                }
            }
        }

        $selectedCounts = [];
        $discardedCounts = [];
        $selectedTotal = 0;
        foreach ($bundle->allItems() as $bucket => $items) {
            $selectedCounts[$bucket] = count($selectedByBucket[$bucket] ?? []);
            $discardedCounts[$bucket] = count($discardedByBucket[$bucket] ?? []);
            $selectedTotal += $selectedCounts[$bucket];
        }

        return new RankingResult($selectedByBucket, $discardedByBucket, [
            'version' => 5,
            'stage' => $stage,
            'enabled' => false,
            'mode' => 'deterministic_selection',
            'retrieved' => $retrieved,
            'selected' => $selectedTotal,
            'discarded' => max(0, $retrieved - $selectedTotal),
            'selected_by_source' => $selectedCounts,
            'discarded_by_source' => $discardedCounts,
            'duplicates_removed' => (int)$dedup['duplicates'],
            'global_optional_limit' => $this->policy->globalOptionalLimit(),
            'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
        ]);
    }

    public function rank(ContextBundle $bundle, MemoryRoute $route, string $queryText, string $stage = 'respond'): RankingResult
    {
        $startedAt = microtime(true);
        $stage = $stage === 'compile' ? 'compile' : 'respond';
        $intent = $route->intent();
        $hasProject = !empty($route->toArray()['has_project']);
        $queryTerms = $this->terms($queryText);

        $selectedByBucket = [];
        $discardedByBucket = [];
        $candidatesForDedupe = [];
        $retrieved = 0;
        $thresholdDiscarded = 0;
        $capDiscarded = 0;

        foreach ($bundle->allItems() as $bucket => $items) {
            $retrieved += count($items);
            $required = $this->policy->isRequiredBucket($bucket) || $this->bucketIsRuntimeRequired($bucket, $items);

            foreach ($items as $item) {
                $this->scoreItem($item, $bucket, $intent, $hasProject, $queryTerms);
            }

            usort($items, static function(ContextItem $a, ContextItem $b): int {
                return (float)($b->rankingScore ?? 0.0) <=> (float)($a->rankingScore ?? 0.0);
            });

            $cap = $required ? max($this->policy->bucketCap($bucket), count($items)) : $this->policy->bucketCap($bucket);
            $threshold = $this->policy->bucketThreshold($bucket);
            $selectedLocal = [];
            $discardedLocal = [];

            foreach ($items as $idx => $item) {
                $score = (float)($item->rankingScore ?? 0.0);
                $keep = $required || ($score >= $threshold && count($selectedLocal) < $cap);

                if (!$keep && !$required && $idx === 0 && $this->policy->ensureOneWhenRequested($bucket)) {
                    // Si el Router pidió expresamente la fuente y el repositorio ya la
                    // consideró elegible, conservamos al menos el mejor elemento.
                    $keep = true;
                    $item->metadata['ranking_floor_override'] = true;
                }

                if ($keep && count($selectedLocal) < $cap) {
                    $item->selected = true;
                    $item->exclusionReason = null;
                    $selectedLocal[] = $item;
                    $candidatesForDedupe[] = ['bucket' => $bucket, 'item' => $item, 'required' => $required];
                    continue;
                }

                $item->selected = false;
                if (count($selectedLocal) >= $cap) {
                    $item->exclusionReason = 'bucket_cap';
                    $capDiscarded++;
                } else {
                    $item->exclusionReason = 'below_ranking_threshold';
                    $thresholdDiscarded++;
                }
                $discardedLocal[] = $item;
            }

            $selectedByBucket[$bucket] = $selectedLocal;
            $discardedByBucket[$bucket] = $discardedLocal;
        }

        $dedup = $this->deduplicator->deduplicate($candidatesForDedupe);
        $kept = $dedup['kept'];

        // Reconstruir por bucket después de deduplicar.
        $selectedByBucket = [];
        foreach ($bundle->allItems() as $bucket => $_) $selectedByBucket[$bucket] = [];
        foreach ($kept as $candidate) {
            $selectedByBucket[$candidate['bucket']][] = $candidate['item'];
        }

        // Presupuesto global para contexto opcional. Las políticas obligatorias
        // no consumen este presupuesto.
        $optional = [];
        foreach ($kept as $candidate) {
            if (!$candidate['required']) $optional[] = $candidate;
        }
        usort($optional, static fn(array $a, array $b): int => (float)($b['item']->rankingScore ?? 0.0) <=> (float)($a['item']->rankingScore ?? 0.0));
        $allowedOptionalKeys = [];
        foreach (array_slice($optional, 0, $this->policy->globalOptionalLimit()) as $candidate) {
            $allowedOptionalKeys[$this->identity($candidate['bucket'], $candidate['item'])] = true;
        }

        $globalBudgetDiscarded = 0;
        foreach ($selectedByBucket as $bucket => $items) {
            $required = $this->policy->isRequiredBucket($bucket) || $this->bucketIsRuntimeRequired($bucket, $items);
            $filtered = [];
            foreach ($items as $item) {
                if ($required || isset($allowedOptionalKeys[$this->identity($bucket, $item)])) {
                    $filtered[] = $item;
                    continue;
                }
                $item->selected = false;
                $item->exclusionReason = 'global_context_budget';
                $discardedByBucket[$bucket][] = $item;
                $globalBudgetDiscarded++;
            }
            $selectedByBucket[$bucket] = $filtered;
        }

        // Ranking global final para trazabilidad.
        $globalSelected = [];
        foreach ($selectedByBucket as $bucket => $items) {
            foreach ($items as $item) $globalSelected[] = ['bucket' => $bucket, 'item' => $item];
        }
        usort($globalSelected, static fn(array $a, array $b): int => (float)($b['item']->rankingScore ?? 0.0) <=> (float)($a['item']->rankingScore ?? 0.0));
        foreach ($globalSelected as $idx => $entry) {
            $entry['item']->rank = $idx + 1;
        }

        // Asegurar que descartados incluya también duplicados.
        foreach ($bundle->allItems() as $bucket => $items) {
            $known = [];
            foreach ($discardedByBucket[$bucket] ?? [] as $item) $known[spl_object_id($item)] = true;
            foreach ($items as $item) {
                if (!$item->selected && !isset($known[spl_object_id($item)])) {
                    $discardedByBucket[$bucket][] = $item;
                }
            }
        }

        $selectedCounts = [];
        $discardedCounts = [];
        foreach ($bundle->allItems() as $bucket => $items) {
            $selectedCounts[$bucket] = count($selectedByBucket[$bucket] ?? []);
            $discardedCounts[$bucket] = count($discardedByBucket[$bucket] ?? []);
        }

        $summary = [
            'version' => 5,
            'stage' => $stage,
            'enabled' => true,
            'mode' => 'multi_signal',
            'intent' => $intent,
            'retrieved' => $retrieved,
            'selected' => count($globalSelected),
            'discarded' => max(0, $retrieved - count($globalSelected)),
            'selected_by_source' => $selectedCounts,
            'discarded_by_source' => $discardedCounts,
            'duplicates_removed' => (int)$dedup['duplicates'],
            'threshold_discarded' => $thresholdDiscarded,
            'bucket_cap_discarded' => $capDiscarded,
            'global_budget_discarded' => $globalBudgetDiscarded,
            'global_optional_limit' => $this->policy->globalOptionalLimit(),
            'weights' => $this->policy->weights(),
            'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
        ];

        return new RankingResult($selectedByBucket, $discardedByBucket, $summary);
    }

    /** @param string[] $queryTerms */
    private function scoreItem(ContextItem $item, string $bucket, string $intent, bool $hasProject, array $queryTerms): void
    {
        $weights = $this->policy->weights();
        $signals = [];
        $availableWeights = [];

        if ($item->score !== null) {
            $signals['semantic'] = $this->clamp((float)$item->score);
            $availableWeights['semantic'] = (float)$weights['semantic'];
        }

        $signals['lexical'] = $this->lexicalScore($queryTerms, $item->content);
        $availableWeights['lexical'] = (float)$weights['lexical'];

        $signals['type'] = $this->policy->typeMatch($item, $intent);
        $availableWeights['type'] = (float)$weights['type'];

        $signals['authority'] = $this->policy->authority($item, $intent);
        $availableWeights['authority'] = (float)$weights['authority'];

        $signals['scope'] = $this->policy->scopeMatch($item, $intent, $hasProject);
        $availableWeights['scope'] = (float)$weights['scope'];

        if ($item->confidence !== null) {
            $signals['confidence'] = $this->clamp((float)$item->confidence);
            $availableWeights['confidence'] = (float)$weights['confidence'];
        }

        $signals['recency'] = $this->recencyScore($item);
        $availableWeights['recency'] = (float)$weights['recency'];

        $numerator = 0.0;
        $denominator = 0.0;
        foreach ($availableWeights as $key => $weight) {
            $numerator += ((float)($signals[$key] ?? 0.0)) * $weight;
            $denominator += $weight;
        }
        $score = $denominator > 0.0 ? $numerator / $denominator : 0.0;

        // Bonus pequeño por coincidencia exacta de frase/términos. No puede
        // sobrepasar 1.0 y no sustituye ninguna señal de autoridad.
        if ($queryTerms && $signals['lexical'] >= 0.80) $score += 0.03;

        $item->rankingScore = round($this->clamp($score), 6);
        $item->rankingSignals = array_map(static fn($v) => round((float)$v, 6), $signals);
        $item->metadata['ranking_bucket'] = $bucket;
    }

    /** @return string[] */
    private function terms(string $text): array
    {
        $text = $this->normalize($text);
        if ($text === '') return [];
        $stop = array_flip([
            'que','como','para','por','con','sin','del','las','los','una','uno','unos','unas','esto','esta','este','esa','ese',
            'son','fue','era','hay','quiero','puedes','puede','hacer','sobre','donde','cuando','cual','cuales','porque','pero','tambien',
            'the','and','for','with','from','this','that','what','how',
        ]);
        $parts = preg_split('/\s+/u', $text) ?: [];
        $out = [];
        foreach ($parts as $part) {
            if (mb_strlen($part, 'UTF-8') < 3 || isset($stop[$part])) continue;
            $out[$part] = true;
        }
        return array_keys($out);
    }

    /** @param string[] $queryTerms */
    private function lexicalScore(array $queryTerms, string $content): float
    {
        if (!$queryTerms) return 0.50;
        $normalized = $this->normalize($content);
        if ($normalized === '') return 0.0;
        $contentTerms = array_flip(preg_split('/\s+/u', $normalized) ?: []);
        $hits = 0;
        foreach ($queryTerms as $term) {
            if (isset($contentTerms[$term]) || str_contains($normalized, $term)) $hits++;
        }
        return $this->clamp($hits / max(1, count($queryTerms)));
    }

    private function recencyScore(ContextItem $item): float
    {
        $raw = $item->metadata['updated_at'] ?? $item->metadata['created_at'] ?? $item->metadata['last_memory_used_at'] ?? null;
        if (!$raw) return 0.68;
        $ts = strtotime((string)$raw);
        if ($ts === false) return 0.68;
        $days = max(0.0, (time() - $ts) / 86400.0);
        return $this->clamp(0.55 + (0.45 * exp(-$days / 365.0)));
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = strtr($text, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
        $text = preg_replace('/[^a-z0-9_.$\/-]+/u', ' ', $text) ?: $text;
        return trim(preg_replace('/\s+/u', ' ', $text) ?: $text);
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }


    /** @param ContextItem[] $items */
    private function bucketIsRuntimeRequired(string $bucket, array $items): bool
    {
        if ($bucket !== 'attachments') return false;
        foreach ($items as $item) {
            if (($item->metadata['mode'] ?? null) === 'always') return true;
        }
        return false;
    }

    private function identity(string $bucket, ContextItem $item): string
    {
        return $bucket . '|' . $item->source . '|' . (string)$item->sourceId . '|' . spl_object_id($item);
    }
}
