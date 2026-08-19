<?php

declare(strict_types=1);

final class AttachmentContextRepository
{
    private mysqli $db;
    private $bedrock;

    public function __construct(mysqli $db, $bedrock)
    {
        $this->db = $db;
        $this->bedrock = $bedrock;
    }

    public function hasContext(int $userId, int $sessionId): bool
    {
        if ($userId <= 0 || $sessionId <= 0) return false;
        $stmt = $this->db->prepare(
            "SELECT scb.id_
             FROM SessionContextBlocks scb
             INNER JOIN ChatSessions cs ON cs.id_ = scb.session_id_
             WHERE scb.session_id_ = ?
               AND cs.user_id_ = ?
               AND scb.block_type IN ('file','file_chunk')
             LIMIT 1"
        );
        if (!$stmt) return false;
        $stmt->bind_param('ii', $sessionId, $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $found = $res && $res->num_rows > 0;
        $stmt->close();
        return $found;
    }

    /**
     * @param float[]|null $queryVector
     * @return array{context:string,items:array<int,ContextItem>,telemetry:array<string,mixed>}
     */
    public function retrieve(
        int $userId,
        int $sessionId,
        string $queryText,
        string $mode,
        ?array $queryVector,
        ?int $logMsgId
    ): array {
        $telemetry = [
            'mode' => $mode,
            'candidates' => 0,
            'selected' => [],
            'owner_verified' => false,
        ];
        if (!$this->ownsSession($userId, $sessionId)) {
            return ['context' => '', 'items' => [], 'telemetry' => $telemetry];
        }
        $telemetry['owner_verified'] = true;

        if (!function_exists('buildSessionAttachmentContext')) {
            $telemetry['error'] = 'buildSessionAttachmentContext no disponible';
            return ['context' => '', 'items' => [], 'telemetry' => $telemetry];
        }

        $context = buildSessionAttachmentContext(
            $this->db,
            $this->bedrock,
            $sessionId,
            $queryText,
            $mode,
            $queryVector,
            $logMsgId,
            $telemetry
        );

        $items = [];
        foreach ((array)($telemetry['selected'] ?? []) as $selected) {
            $content = trim((string)($selected['content'] ?? ''));
            if ($content === '') continue;
            $items[] = new ContextItem(
                'SessionContextBlocks',
                isset($selected['block_id']) ? (int)$selected['block_id'] : null,
                (string)($selected['block_type'] ?? 'file_chunk'),
                'session_attachment',
                $content,
                isset($selected['score']) ? (float)$selected['score'] : null,
                null,
                [
                    'session_id' => $sessionId,
                    'filename' => $selected['filename'] ?? null,
                    'mode' => $mode,
                ]
            );
        }

        return ['context' => trim((string)$context), 'items' => $items, 'telemetry' => $telemetry];
    }

    private function ownsSession(int $userId, int $sessionId): bool
    {
        $stmt = $this->db->prepare("SELECT id_ FROM ChatSessions WHERE id_ = ? AND user_id_ = ? LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('ii', $sessionId, $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $ok = $res && $res->num_rows > 0;
        $stmt->close();
        return $ok;
    }
}
