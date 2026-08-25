<?php
declare(strict_types=1);

final class TaskPlanningResult
{
    public function __construct(
        public readonly TaskPlan $plan,
        public readonly array $usage = [],
        public readonly bool $modelInvoked = false,
        public readonly ?string $model = null
    ) {}

    public function inputTokens(): int { return max(0, (int)($this->usage['inputTokens'] ?? $this->usage['input_tokens'] ?? 0)); }
    public function outputTokens(): int { return max(0, (int)($this->usage['outputTokens'] ?? $this->usage['output_tokens'] ?? 0)); }
}

interface UsageAwareTaskPlanner extends TaskPlanner
{
    public function planWithUsage(string $objective, array $context = []): TaskPlanningResult;
}
