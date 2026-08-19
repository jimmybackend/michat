<?php

declare(strict_types=1);

final class ProjectRagRepository
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * @param float[] $queryVector
     * @return array{context:string,items:array<int,ContextItem>,telemetry:array<string,mixed>}
     */
    public function retrieve(
        int $userId,
        int $projectId,
        array $queryVector,
        string $embeddingModel,
        float $threshold = 0.30,
        int $topLimit = 4,
        int $candidateLimit = 150
    ): array {
        $telemetry = [
            'candidates' => 0,
            'threshold' => $threshold,
            'top_limit' => $topLimit,
            'top_scores' => [],
            'selected' => [],
            'embedding_model' => $embeddingModel,
        ];

        if ($userId <= 0 || $projectId <= 0 || !$queryVector || $embeddingModel === '') {
            return ['context' => '', 'items' => [], 'telemetry' => $telemetry];
        }

        $candidateLimit = max(10, min(500, $candidateLimit));
        $topLimit = max(1, min(12, $topLimit));

        $sql = "SELECT sc.id_, sc.content, sc.name, sc.chunk_type, sc.start_line, sc.end_line,
                       ps.filename, ce.embedding_json, ce.model_id
                FROM SourceChunks sc
                INNER JOIN ProjectSources ps ON ps.id_ = sc.source_id_
                INNER JOIN Projects p ON p.id_ = sc.project_id_
                INNER JOIN ChunkEmbeddings ce ON ce.chunk_id_ = sc.id_
                WHERE sc.project_id_ = ?
                  AND p.user_id_ = ?
                  AND p.status <> 'deleted'
                  AND ce.model_id = ?
                LIMIT {$candidateLimit}";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return ['context' => '', 'items' => [], 'telemetry' => $telemetry];
        $stmt->bind_param('iis', $projectId, $userId, $embeddingModel);
        $stmt->execute();
        $res = $stmt->get_result();

        $scored = [];
        while ($row = $res->fetch_assoc()) {
            $vector = json_decode((string)($row['embedding_json'] ?? ''), true);
            if (!is_array($vector) || !$vector || count($vector) !== count($queryVector)) continue;
            $row['score'] = $this->cosineSimilarity($queryVector, $vector);
            $scored[] = $row;
        }
        $stmt->close();

        usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        $telemetry['candidates'] = count($scored);

        foreach (array_slice($scored, 0, 12) as $candidate) {
            $telemetry['top_scores'][] = [
                'chunk_id' => (int)$candidate['id_'],
                'filename' => (string)($candidate['filename'] ?? ''),
                'chunk_name' => (string)($candidate['name'] ?? ''),
                'score' => round((float)$candidate['score'], 6),
            ];
        }

        $items = [];
        foreach ($scored as $row) {
            if (count($items) >= $topLimit) break;
            if ((float)$row['score'] <= $threshold) continue;

            $item = new ContextItem(
                'SourceChunks',
                (int)$row['id_'],
                (string)($row['chunk_type'] ?? 'other'),
                'project_rag',
                (string)$row['content'],
                (float)$row['score'],
                null,
                [
                    'project_id' => $projectId,
                    'filename' => (string)($row['filename'] ?? ''),
                    'name' => (string)($row['name'] ?? ''),
                    'start_line' => (int)($row['start_line'] ?? 0),
                    'end_line' => (int)($row['end_line'] ?? 0),
                    'embedding_model' => $embeddingModel,
                ]
            );
            $items[] = $item;
            $telemetry['selected'][] = $item->toArray(false);
        }

        if (!$items) {
            return ['context' => '', 'items' => [], 'telemetry' => $telemetry];
        }

        $out = "[CONTEXTO DE TUS ARCHIVOS INDEXADOS]\n";
        foreach ($items as $idx => $item) {
            $filename = (string)($item->metadata['filename'] ?? 'archivo');
            $name = (string)($item->metadata['name'] ?? '');
            $label = $filename !== '' ? $filename : ($name !== '' ? $name : 'fragmento');
            $out .= '--- Fragmento ' . ($idx + 1) . ' (Archivo: ' . $label . ', Similitud: ' . number_format((float)$item->score, 2, '.', '') . ") ---\n";
            $out .= $item->content . "\n\n";
        }
        $out .= '[DIAGNÓSTICO: Se encontraron ' . count($items) . " fragmentos relevantes en tus archivos. Úsalos para responder.]";

        return ['context' => trim($out), 'items' => $items, 'telemetry' => $telemetry];
    }

    /** @param float[] $a @param float[] $b */
    private function cosineSimilarity(array $a, array $b): float
    {
        if (!$a || count($a) !== count($b)) return 0.0;
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $count = count($a);
        for ($i = 0; $i < $count; $i++) {
            $av = (float)$a[$i];
            $bv = (float)$b[$i];
            $dot += $av * $bv;
            $normA += $av * $av;
            $normB += $bv * $bv;
        }
        if ($normA <= 0.0 || $normB <= 0.0) return 0.0;
        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
