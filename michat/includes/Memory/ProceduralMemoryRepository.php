<?php

declare(strict_types=1);

final class ProceduralMemoryRepository
{
    private mysqli $db;
    public function __construct(mysqli $db) { $this->db = $db; }

    /** @param string[] $types @return ContextItem[] */
    public function retrieve(int $userId, array $types = [], int $limit = 10, ?ConversationScope $scope = null): array
    {
        if ($userId <= 0) return [];
        $allowed = ['preference','rule','pattern','correction','workflow'];
        $types = array_values(array_unique(array_intersect($allowed, array_map('strval', $types))));
        $limit = max(1, min(50, $limit));

        $sql = "SELECT upm.id_, upm.memory_type, upm.content, upm.source_session_id, upm.confidence, upm.created_at, upm.updated_at
                FROM UserProceduralMemory upm
                LEFT JOIN ChatSessions src ON src.id_=upm.source_session_id
                WHERE upm.user_id_=? AND upm.is_active=1";
        $bindTypes = 'i';
        $params = [$userId];

        if ($scope instanceof ConversationScope) {
            if ($scope->isProject()) {
                // Memoria automática del mismo proyecto + memorias manuales/globales
                // (source_session_id NULL). Nunca hereda memoria automática de otro proyecto.
                $sql .= " AND (upm.source_session_id IS NULL OR (src.user_id_=? AND src.project_id_=?))";
                $bindTypes .= 'ii';
                $params[] = $userId;
                $params[] = $scope->projectId();
            } else {
                // Chats libres no comparten memoria procedural entre sesiones.
                // Una rama sólo puede ver memorias cuyo origen esté en su linaje.
                $ids = $scope->allowedSessionIds();
                if (!$ids) return [];
                $safeIds = implode(',', array_map('intval', $ids));
                $sql .= " AND upm.source_session_id IN ({$safeIds})";
            }
        }

        if ($types) {
            $safe = array_map(fn(string $type): string => "'" . $this->db->real_escape_string($type) . "'", $types);
            $sql .= ' AND upm.memory_type IN (' . implode(',', $safe) . ')';
        }

        $sql .= " ORDER BY upm.confidence DESC, upm.updated_at DESC, upm.id_ DESC LIMIT {$limit}";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return [];
        if ($params) $stmt->bind_param($bindTypes, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        while ($row = $res->fetch_assoc()) {
            $items[] = new ContextItem(
                'UserProceduralMemory',
                (int)$row['id_'],
                (string)$row['memory_type'],
                $scope?->isProject() ? 'project_user' : ($scope?->isBranch() ? 'branch' : 'session'),
                (string)$row['content'],
                null,
                min(1.0, max(0.0, ((float)$row['confidence']) / 10.0)),
                [
                    'source_session_id' => $row['source_session_id'] !== null ? (int)$row['source_session_id'] : null,
                    'confidence_raw' => (int)$row['confidence'],
                    'created_at' => $row['created_at'] ?? null,
                    'updated_at' => $row['updated_at'] ?? null,
                    'scope_kind' => $scope?->kind(),
                ]
            );
        }
        $stmt->close();
        return $items;
    }
}
