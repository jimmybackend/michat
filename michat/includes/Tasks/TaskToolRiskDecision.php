<?php
declare(strict_types=1);

/** Metadata-only HITL decision for a registered Task Tool. */
final class TaskToolRiskDecision
{
    public const ALLOWED = 'allowed';
    public const APPROVAL_REQUIRED = 'approval_required';

    private function __construct(
        public readonly string $toolKey,
        public readonly string $effect,
        public readonly string $decision
    ) {}

    public static function allowed(string $toolKey, string $effect): self
    {
        return new self($toolKey, $effect, self::ALLOWED);
    }

    public static function approvalRequired(string $toolKey, string $effect): self
    {
        return new self($toolKey, $effect, self::APPROVAL_REQUIRED);
    }

    public function isAllowed(): bool
    {
        return $this->decision === self::ALLOWED;
    }

    public function requiresApproval(): bool
    {
        return $this->decision === self::APPROVAL_REQUIRED;
    }
}
