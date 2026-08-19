<?php

declare(strict_types=1);

/**
 * Frontera efectiva de memoria para una conversación.
 *
 * - project: memoria compartida únicamente entre sesiones del mismo proyecto.
 * - free:    memoria automática únicamente de la sesión actual.
 * - branch:  chat libre que puede heredar sólo el linaje explícito de su rama.
 */
final class ConversationScope
{
    private int $userId;
    private int $sessionId;
    private int $projectId;
    private string $kind;

    /** @var array<int,array{session_id:int,max_message_id:?int}> */
    private array $lineage;

    /**
     * @param array<int,array{session_id:int,max_message_id:?int}> $lineage
     */
    public function __construct(int $userId, int $sessionId, int $projectId, string $kind, array $lineage = [])
    {
        $this->userId = $userId;
        $this->sessionId = $sessionId;
        $this->projectId = max(0, $projectId);
        $this->kind = in_array($kind, ['project','free','branch'], true) ? $kind : 'free';
        $this->lineage = $lineage ?: [['session_id' => $sessionId, 'max_message_id' => null]];
    }

    public function userId(): int { return $this->userId; }
    public function sessionId(): int { return $this->sessionId; }
    public function projectId(): int { return $this->projectId; }
    public function kind(): string { return $this->kind; }
    public function isProject(): bool { return $this->kind === 'project' && $this->projectId > 0; }
    public function isBranch(): bool { return $this->kind === 'branch'; }
    public function isFree(): bool { return !$this->isProject(); }
    public function hasLineage(): bool { return count($this->lineage) > 1; }

    /** @return array<int,array{session_id:int,max_message_id:?int}> */
    public function lineage(): array { return $this->lineage; }

    /** @return int[] */
    public function allowedSessionIds(): array
    {
        $ids = [];
        foreach ($this->lineage as $entry) {
            $sid = (int)($entry['session_id'] ?? 0);
            if ($sid > 0) $ids[$sid] = true;
        }
        return array_map('intval', array_keys($ids));
    }

    /**
     * El scope semántico se deriva del contenedor real, nunca de un selector
     * frontend que pudiera mezclar chats libres entre sí.
     */
    public function semanticScope(): string
    {
        return $this->isProject() ? 'project' : 'session';
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'user_id' => $this->userId,
            'session_id' => $this->sessionId,
            'project_id' => $this->projectId > 0 ? $this->projectId : null,
            'has_lineage' => $this->hasLineage(),
            'lineage_session_ids' => $this->allowedSessionIds(),
            'lineage' => $this->lineage,
            'semantic_scope' => $this->semanticScope(),
        ];
    }
}
