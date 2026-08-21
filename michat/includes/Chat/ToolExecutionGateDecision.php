<?php
declare(strict_types=1);

/** Immutable control decision made before a Chat Tool is physically executed. */
final class ToolExecutionGateDecision
{
    private const ALLOW = 'allow';
    private const PAUSE_ALREADY_PERSISTED = 'pause_already_persisted';

    private function __construct(
        private readonly string $disposition,
        public readonly string $safeSummary = ''
    ) {}

    public static function allow(): self
    {
        return new self(self::ALLOW);
    }

    public static function pauseAlreadyPersisted(string $safeSummary): self
    {
        return new self(self::PAUSE_ALREADY_PERSISTED, mb_substr(trim($safeSummary), 0, 1000));
    }

    public function isAllowed(): bool
    {
        return $this->disposition === self::ALLOW;
    }

    public function isPauseAlreadyPersisted(): bool
    {
        return $this->disposition === self::PAUSE_ALREADY_PERSISTED;
    }
}
