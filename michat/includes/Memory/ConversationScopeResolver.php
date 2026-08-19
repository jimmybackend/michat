<?php

declare(strict_types=1);

final class ConversationScopeResolver
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function resolve(int $userId, int $sessionId): ConversationScope
    {
        if ($userId <= 0 || $sessionId <= 0) {
            return new ConversationScope($userId, $sessionId, 0, 'free');
        }

        $current = $this->loadSession($userId, $sessionId);
        if (!$current) {
            return new ConversationScope($userId, $sessionId, 0, 'free');
        }

        $projectId = max(0, (int)($current['project_id_'] ?? 0));
        $lineage = [['session_id' => $sessionId, 'max_message_id' => null]];
        $visited = [$sessionId => true];
        $cursor = $current;

        // Una rama hereda exclusivamente el historial existente hasta el mensaje
        // desde el que fue creada. Esto aplica tanto a chats libres como a ramas
        // dentro de un proyecto; nunca cruza de proyecto ni de usuario.
        for ($depth = 0; $depth < 12; $depth++) {
            $branch = $this->branchMeta($cursor['meta'] ?? null);
            $parentSessionId = (int)($branch['parent_session_id'] ?? 0);
            $parentMessageId = (int)($branch['parent_message_id'] ?? 0);

            if ($parentSessionId <= 0 || $parentMessageId <= 0 || isset($visited[$parentSessionId])) break;

            $parent = $this->loadSession($userId, $parentSessionId);
            if (!$parent) break;

            $parentProjectId = max(0, (int)($parent['project_id_'] ?? 0));
            if ($parentProjectId !== $projectId) break;
            if (!$this->messageBelongsToSession($userId, $parentSessionId, $parentMessageId)) break;

            array_unshift($lineage, [
                'session_id' => $parentSessionId,
                'max_message_id' => $parentMessageId,
            ]);
            $visited[$parentSessionId] = true;
            $cursor = $parent;
        }

        $kind = $projectId > 0
            ? 'project'
            : (count($lineage) > 1 ? 'branch' : 'free');

        return new ConversationScope($userId, $sessionId, $projectId, $kind, $lineage);
    }

    /** @return array<string,mixed>|null */
    private function loadSession(int $userId, int $sessionId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id_, user_id_, project_id_, meta FROM ChatSessions WHERE id_=? AND user_id_=? LIMIT 1"
        );
        if (!$stmt) return null;
        $stmt->bind_param('ii', $sessionId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function branchMeta($rawMeta): array
    {
        if (!is_string($rawMeta) || trim($rawMeta) === '') return [];
        $meta = json_decode($rawMeta, true);
        if (!is_array($meta)) return [];
        $branch = $meta['branch'] ?? null;
        return is_array($branch) ? $branch : [];
    }

    private function messageBelongsToSession(int $userId, int $sessionId, int $messageId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT cm.id_ FROM ChatMessages cm
             INNER JOIN ChatSessions cs ON cs.id_=cm.session_id_
             WHERE cm.id_=? AND cm.session_id_=? AND cm.user_id_=? AND cs.user_id_=? LIMIT 1"
        );
        if (!$stmt) return false;
        $stmt->bind_param('iiii', $messageId, $sessionId, $userId, $userId);
        $stmt->execute();
        $ok = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $ok;
    }
}
