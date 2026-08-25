<?php
declare(strict_types=1);

final class TaskPlanRevision
{
    public function __construct(
        public readonly string $publicId,
        public readonly string $requestPublicId,
        public readonly int $revisionNumber,
        public readonly int $sourceRevision,
        public readonly string $status,
        public readonly array $proposedSteps,
        public readonly int $lockVersion
    ) {}

    public static function fromRow(array $row): self
    {
        $steps = json_decode((string)$row['proposed_plan_json'], true);
        return new self((string)$row['public_id'], (string)$row['request_public_id'], (int)$row['revision_number'], (int)$row['source_revision'], (string)$row['status'], is_array($steps['steps'] ?? null) ? $steps['steps'] : [], (int)$row['lock_version']);
    }
}
