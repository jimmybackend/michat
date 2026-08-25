<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/ai_agent_runtime.php';

final class TaskPlannerFactory
{
    public function __construct(private mysqli $db, private ?SingleTurnInferenceInterface $inference = null) {}

    public function create(TaskPlanValidator $validator): AiTaskPlanner
    {
        $resolver = function(int $userId): array {
            if ($userId < 1) throw new TaskValidationException('planner_user_invalid');
            $configs = loadDynamicAIAgentConfigs($this->db, $userId);
            return is_array($configs['task_planner'] ?? null) ? $configs['task_planner'] : [];
        };
        $inference = $this->inference ?? new BedrockSingleTurnInference(new BedrockConverseClient());
        $invoke = static function(array $cfg, string $prompt, string $system) use ($inference): SingleTurnInferenceResult {
            $system = mb_substr($system, 0, SingleTurnInferenceRequest::MAX_SYSTEM_CHARS);
            $model = trim((string)($cfg['model_id'] ?? ''));
            return $inference->infer(new SingleTurnInferenceRequest(
                $model,
                $system,
                mb_substr($prompt, 0, SingleTurnInferenceRequest::MAX_INPUT_CHARS),
                max(0.0, min(1.0, (float)($cfg['temperature'] ?? 0.0))),
                max(1, min(3000, (int)($cfg['max_tokens_output'] ?? 1200))),
                max(0.01, min(1.0, (float)($cfg['top_p'] ?? 0.9)))
            ));
        };
        return new AiTaskPlanner($validator, $invoke, $resolver);
    }
}
