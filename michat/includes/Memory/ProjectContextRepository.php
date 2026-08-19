<?php

declare(strict_types=1);

final class ProjectContextRepository
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Recuperación tipada y aislada por user_id_.
     *
     * @param string[] $types
     * @return ContextItem[]
     */
    public function retrieve(int $userId, int $projectId, array $types, int $limit = 20): array
    {
        if ($userId <= 0 || $projectId <= 0 || !$types) return [];

        $allowed = ['rule','decision','fact','style','todo','note'];
        $types = array_values(array_unique(array_intersect($allowed, array_map('strval', $types))));
        if (!$types) return [];

        $limit = max(1, min(100, $limit));
        $safe = array_map(fn(string $type): string => "'" . $this->db->real_escape_string($type) . "'", $types);
        $typeList = implode(',', $safe);

        $sql = "SELECT pc.id_, pc.type, pc.title, pc.content, pc.source_chunk_id,
                       pc.created_at, pc.updated_at
                FROM ProjectContext pc
                INNER JOIN Projects p ON p.id_ = pc.project_id_
                WHERE pc.project_id_ = ?
                  AND p.user_id_ = ?
                  AND p.status <> 'deleted'
                  AND pc.type IN ({$typeList})
                ORDER BY pc.updated_at DESC, pc.id_ DESC
                LIMIT {$limit}";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('ii', $projectId, $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];

        while ($row = $res->fetch_assoc()) {
            $title = trim((string)($row['title'] ?? ''));
            $content = trim((string)$row['content']);
            $rendered = $title !== '' ? $title . ": " . $content : $content;
            $items[] = new ContextItem(
                'ProjectContext',
                (int)$row['id_'],
                (string)$row['type'],
                'project',
                $rendered,
                null,
                null,
                [
                    'project_id' => $projectId,
                    'title' => $title,
                    'raw_content' => $content,
                    'source_chunk_id' => $row['source_chunk_id'] !== null ? (int)$row['source_chunk_id'] : null,
                    'created_at' => $row['created_at'] ?? null,
                    'updated_at' => $row['updated_at'] ?? null,
                ]
            );
        }
        $stmt->close();

        return $items;
    }
}
