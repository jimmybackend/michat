<?php
declare(strict_types=1);

final class ToolRegistry
{
    private array $tools = [];

    public function register(string $key, callable $handler, string $effect = 'non_idempotent'): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/D', $key) || !in_array($effect, ['read_only', 'idempotent_write', 'non_idempotent'], true)) {
            throw new InvalidArgumentException('tool_metadata_invalid');
        }
        $this->tools[$key] = ['handler' => Closure::fromCallable($handler), 'effect' => $effect];
    }

    public function effect(string $key): string { return $this->definition($key)['effect']; }
    public function execute(string $key, array $input): ToolExecutionResult
    {
        $result = ($this->definition($key)['handler'])($input);
        if (!$result instanceof ToolExecutionResult) throw new RuntimeException('tool_result_invalid');
        return $result;
    }
    private function definition(string $key): array
    {
        if (!isset($this->tools[$key])) throw new TaskValidationException('tool_not_supported');
        return $this->tools[$key];
    }
}
