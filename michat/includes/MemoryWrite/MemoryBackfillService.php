<?php

declare(strict_types=1);

/**
 * Backfill dirigido y acotado para memoria estructurada.
 * Se activa únicamente cuando el Router pide ProjectContext y aún no existe
 * ninguna memoria del tipo solicitado. Busca Q&A level_0 históricas del mismo
 * proyecto, las ordena por relevancia léxica y procesa como máximo 2.
 */
final class MemoryBackfillService
{
    private mysqli $db;
    private $bedrock;

    public function __construct(mysqli $db, $bedrock)
    {
        $this->db = $db;
        $this->bedrock = $bedrock;
    }

    /** @param array<string,mixed> $route @return array<string,mixed> */
    public function backfill(int $userId, int $projectId, string $query, array $route): array
    {
        $out = [
            'version' => 4.1,
            'attempted' => false,
            'reason' => 'not_needed',
            'scanned' => 0,
            'eligible' => 0,
            'processed' => 0,
            'writes' => 0,
            'model_id' => '',
            'usage' => ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0],
            'results' => [],
        ];

        if ($userId <= 0 || $projectId <= 0 || empty($route['use_project_context'])) return $out;

        $repo = new MemoryWriteRepository($this->db);
        if (!$repo->schemaReady()) {
            $out['reason'] = 'schema_missing_memory_write_events';
            return $out;
        }

        $types = array_values(array_intersect(
            ['rule','decision','fact','todo'],
            array_map('strval', (array)($route['project_context_types'] ?? []))
        ));
        if (!$types) return $out;

        if ($this->hasStructuredMemory($userId, $projectId, $types)) {
            $out['reason'] = 'structured_memory_already_exists';
            return $out;
        }

        $rows = $this->loadHistoricalPairs($userId, $projectId, 80);
        $out['scanned'] = count($rows);
        if (!$rows) {
            $out['reason'] = 'no_historical_qa';
            return $out;
        }

        $ranked = [];
        foreach ($rows as $row) {
            $question = (string)($row['question'] ?? '');
            if (!$this->looksProjectWorthy($question)) continue;
            $score = $this->relevance($query, $question . ' ' . (string)($row['answer'] ?? ''));
            if ($score <= 0.0) continue;
            $row['_score'] = $score;
            $ranked[] = $row;
        }
        usort($ranked, static fn(array $a, array $b): int => (float)$b['_score'] <=> (float)$a['_score']);
        $out['eligible'] = count($ranked);
        if (!$ranked) {
            $out['reason'] = 'no_relevant_historical_qa';
            return $out;
        }

        $out['attempted'] = true;
        $out['reason'] = 'processed_historical_qa';
        $writer = new MemoryWriter($this->db, $this->bedrock);

        foreach (array_slice($ranked, 0, 2) as $row) {
            $result = $writer->write(
                $userId,
                (int)$row['session_id'],
                $projectId,
                (int)$row['question_msg_id'],
                (int)$row['answer_msg_id'],
                (string)$row['question'],
                (string)$row['answer'],
                $route,
                []
            );
            $public = $result->toArray(false);
            $public['historical_session_id'] = (int)$row['session_id'];
            $public['relevance'] = round((float)$row['_score'], 4);
            $out['results'][] = $public;
            $out['processed']++;
            $out['writes'] += (int)($public['write_count'] ?? 0);
            if (!empty($public['model_id'])) $out['model_id'] = (string)$public['model_id'];
            foreach (['input_tokens','output_tokens','total_tokens'] as $uk) {
                $out['usage'][$uk] += (int)($public['usage'][$uk] ?? 0);
            }

            if ($this->hasStructuredMemory($userId, $projectId, $types)) {
                $out['reason'] = 'structured_memory_recovered';
                break;
            }
        }

        return $out;
    }

    /** @param string[] $types */
    private function hasStructuredMemory(int $userId, int $projectId, array $types): bool
    {
        $safe = array_map(fn(string $t): string => "'" . $this->db->real_escape_string($t) . "'", $types);
        $sql = "SELECT pc.id_ FROM ProjectContext pc INNER JOIN Projects p ON p.id_=pc.project_id_ WHERE pc.project_id_=? AND p.user_id_=? AND p.status<>'deleted' AND pc.type IN (" . implode(',', $safe) . ") LIMIT 1";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param('ii', $projectId, $userId);
        $stmt->execute();
        $found = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $found;
    }

    /** @return array<int,array<string,mixed>> */
    private function loadHistoricalPairs(int $userId, int $projectId, int $limit): array
    {
        $limit = max(10, min(150, $limit));
        $sql = "SELECT scb.question_msg_id, scb.answer_msg_id, cs.id_ AS session_id, q.content AS question, a.content AS answer, scb.created_at
                FROM SessionContextBlocks scb
                INNER JOIN ChatSessions cs ON cs.id_=scb.session_id_
                INNER JOIN ChatMessages q ON q.id_=scb.question_msg_id AND q.user_id_=?
                INNER JOIN ChatMessages a ON a.id_=scb.answer_msg_id AND a.user_id_=?
                LEFT JOIN MemoryWriteEvents mwe ON mwe.question_msg_id=scb.question_msg_id AND mwe.answer_msg_id=scb.answer_msg_id AND mwe.writer_version='phase4.1-v1' AND mwe.status IN ('completed','skipped')
                WHERE cs.project_id_=? AND cs.user_id_=?
                  AND scb.block_type='level_0'
                  AND scb.question_msg_id IS NOT NULL
                  AND scb.answer_msg_id IS NOT NULL
                  AND mwe.id_ IS NULL
                ORDER BY scb.created_at DESC, scb.id_ DESC
                LIMIT {$limit}";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('iiii', $userId, $userId, $projectId, $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        $stmt->close();
        return $rows;
    }

    private function looksProjectWorthy(string $text): bool
    {
        $q = $this->normalize($text);
        return (bool)preg_match(
            '/\\b(?:decidimos|acordamos|definimos|fijamos)\\s+(?:usar|que|el|la|los|las|un|una)|\\b(?:queda definido|usaremos|vamos a usar|usa|utiliza|implementa|aplica|debe quedar|configuramos|configura|cambia|establece|fija|regla:|pendiente:|todo:|arquitectura:)\\b/u',
            $q
        );
    }

    private function relevance(string $query, string $candidate): float
    {
        $qTerms = $this->terms($query);
        $c = $this->normalize($candidate);
        if (!$qTerms || $c === '') return 0.0;
        $hits = 0;
        foreach ($qTerms as $term) {
            if (str_contains($c, $term)) $hits++;
        }
        return $hits / max(1, count($qTerms));
    }

    /** @return string[] */
    private function terms(string $text): array
    {
        $n = $this->normalize($text);
        $parts = preg_split('/[^a-z0-9_.-]+/u', $n) ?: [];
        $stop = ['que','como','para','sobre','esto','esta','este','del','las','los','una','uno','con','por','hemos','hablado','decidimos','cual','donde','cuando'];
        $terms = [];
        foreach ($parts as $part) {
            if (mb_strlen($part) < 4 || in_array($part, $stop, true)) continue;
            $terms[$part] = true;
        }
        return array_keys($terms);
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = strtr($text, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
        return trim(preg_replace('/\\s+/u', ' ', $text) ?? $text);
    }
}
