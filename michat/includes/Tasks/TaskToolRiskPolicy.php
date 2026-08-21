<?php
declare(strict_types=1);
require_once __DIR__.'/TaskToolApprovalGateContracts.php';

/** Conservative Task HITL policy backed exclusively by registered Tool metadata. */
final class TaskToolRiskPolicy implements TaskToolRiskPolicyInterface
{
    public function __construct(private ToolRegistry $tools) {}

    public function decide(string $toolKey): TaskToolRiskDecision
    {
        $effect = $this->tools->effect($toolKey);

        return match ($effect) {
            'read_only' => TaskToolRiskDecision::allowed($toolKey, $effect),
            'idempotent_write', 'non_idempotent' => TaskToolRiskDecision::approvalRequired($toolKey, $effect),
            default => throw new TaskValidationException('tool_effect_invalid'),
        };
    }
}
