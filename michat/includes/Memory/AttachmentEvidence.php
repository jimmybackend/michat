<?php
declare(strict_types=1);

/** Pure ranking/citation policy shared by retrieval and executable fixture tests. */
final class AttachmentEvidence
{
    public static function lexical(string $query, string $text): float
    {
        $query = mb_strtolower(trim($query));
        $text = mb_strtolower($text);
        if ($query === '' || $text === '') return 0.0;
        if (str_contains($text, $query)) return 1.0;
        preg_match_all('/[\pL\pN_.$]+/u', $query, $matches);
        $stop = array_flip(['que','qué','dice','del','las','los','una','con','por','para','sobre','archivo','documento','compara','entre','estos','este','esta','dónde','como','cómo']);
        $terms = array_unique(array_filter($matches[0], static fn($t) => mb_strlen($t) >= 2 && !isset($stop[$t])));
        if (!$terms) return 0.0;
        $hits = 0;
        foreach ($terms as $term) if (str_contains($text, $term)) $hits++;
        return $hits / count($terms);
    }

    public static function cosine(?array $a, ?array $b): ?float
    {
        if (!$a || !$b || count($a) !== count($b)) return null;
        $dot = $aa = $bb = 0.0;
        foreach ($a as $i => $v) {
            if (!is_numeric($v) || !is_numeric($b[$i]) || !is_finite((float)$v) || !is_finite((float)$b[$i])) return null;
            $dot += $v * $b[$i]; $aa += $v * $v; $bb += $b[$i] * $b[$i];
        }
        return $aa > 0 && $bb > 0 ? $dot / sqrt($aa * $bb) : null;
    }

    public static function citation(array $row): string
    {
        $meta = json_decode((string)($row['source_ids'] ?? ''), true) ?: [];
        $location = (array)($meta['locator'] ?? []);
        $parts = ['SCB:' . (int)$row['id_'], (string)($meta['filename'] ?? 'adjunto')];
        if (isset($location['page'])) $parts[] = 'página ' . (int)$location['page'];
        if (isset($location['slide'])) $parts[] = 'diapositiva ' . (int)$location['slide'];
        if (isset($location['slide_part'])) $parts[] = 'parte interna ' . $location['slide_part'] . '; posición no verificada';
        if (isset($location['table'])) $parts[] = 'tabla ' . $location['table'];
        if (isset($location['sheet'])) $parts[] = 'hoja ' . $location['sheet'];
        if (isset($location['line_start'])) $parts[] = 'líneas ' . (int)$location['line_start'] . '–' . (int)$location['line_end'];
        if (isset($meta['chunk'])) $parts[] = 'fragmento ' . (int)$meta['chunk'];
        if (($row['block_type'] ?? '') === 'file') $parts[] = 'resumen generado; comprobar en fragmentos originales';
        if (!empty($meta['truncated'])) $parts[] = 'EXTRACCIÓN PARCIAL';
        return '[' . implode(' · ', $parts) . ']';
    }

    /** Round-robin by file prevents a long first file from hiding comparison inputs. */
    public static function diversify(array $rows, int $top): array
    {
        $groups = [];
        foreach ($rows as $row) $groups[(string)$row['s3_path']][] = $row;
        $out = [];
        while ($groups && count($out) < $top) {
            foreach ($groups as $key => &$group) {
                $out[] = array_shift($group);
                if (!$group) unset($groups[$key]);
                if (count($out) >= $top) break;
            }
            unset($group);
        }
        return $out;
    }
}
