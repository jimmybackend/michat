<?php

declare(strict_types=1);

/**
 * Deduplicación entre fuentes después de puntuar.
 * Conserva el elemento de mayor autoridad/ranking y marca los demás para
 * trazabilidad, en lugar de borrarlos del ContextBundle.
 */
final class ContextDeduplicator
{
    /**
     * @param array<int,array{bucket:string,item:ContextItem,required:bool}> $candidates
     * @return array{kept:array<int,array{bucket:string,item:ContextItem,required:bool}>,duplicates:int}
     */
    public function deduplicate(array $candidates): array
    {
        usort($candidates, static function(array $a, array $b): int {
            if ($a['required'] !== $b['required']) return $a['required'] ? -1 : 1;
            $as = (float)($a['item']->rankingScore ?? 0.0);
            $bs = (float)($b['item']->rankingScore ?? 0.0);
            return $bs <=> $as;
        });

        $kept = [];
        $duplicates = 0;
        $exact = [];

        foreach ($candidates as $candidate) {
            $item = $candidate['item'];
            $normalized = $this->normalize($item->content);
            $hash = hash('sha256', $normalized);

            // Una fuente obligatoria (política global o adjuntos mode=always)
            // nunca se elimina. Sí se registra como referencia para que un
            // candidato opcional posterior pueda ser descartado contra ella.
            if ($candidate['required']) {
                if ($normalized !== '' && !isset($exact[$hash])) $exact[$hash] = $candidate;
                $kept[] = $candidate;
                continue;
            }

            if ($normalized !== '' && isset($exact[$hash])) {
                $this->markDuplicate($item, $exact[$hash]['item'], 'duplicate_exact');
                $duplicates++;
                continue;
            }

            $duplicateOf = null;
            if (mb_strlen($normalized, 'UTF-8') >= 50) {
                foreach ($kept as $existing) {
                    $existingNorm = $this->normalize($existing['item']->content);
                    if (mb_strlen($existingNorm, 'UTF-8') < 50) continue;
                    if ($this->tokenContainment($normalized, $existingNorm) >= 0.92) {
                        $duplicateOf = $existing['item'];
                        break;
                    }
                }
            }

            if ($duplicateOf instanceof ContextItem) {
                $this->markDuplicate($item, $duplicateOf, 'duplicate_near');
                $duplicates++;
                continue;
            }

            if ($normalized !== '') $exact[$hash] = $candidate;
            $kept[] = $candidate;
        }

        return ['kept' => $kept, 'duplicates' => $duplicates];
    }

    private function markDuplicate(ContextItem $item, ContextItem $kept, string $reason): void
    {
        $item->selected = false;
        $item->exclusionReason = $reason;
        $item->duplicateOf = $kept->source . ':' . (string)$kept->sourceId;
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = strtr($text, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
        $text = preg_replace('/\b(?:pregunta|fragmento|archivo|contexto|memoria)\s*:\s*/u', ' ', $text) ?: $text;
        $text = preg_replace('/[^a-z0-9_.$\/-]+/u', ' ', $text) ?: $text;
        return trim(preg_replace('/\s+/u', ' ', $text) ?: $text);
    }

    private function tokenContainment(string $a, string $b): float
    {
        $ta = array_values(array_unique(array_filter(explode(' ', $a), static fn(string $v): bool => mb_strlen($v, 'UTF-8') >= 3)));
        $tb = array_values(array_unique(array_filter(explode(' ', $b), static fn(string $v): bool => mb_strlen($v, 'UTF-8') >= 3)));
        if (!$ta || !$tb) return 0.0;
        $intersection = count(array_intersect($ta, $tb));
        return $intersection / max(1, min(count($ta), count($tb)));
    }
}
