<?php
declare(strict_types=1);

final class ToolRegistry
{
    private array $tools = [];

    public function __construct(private ?ToolCallRepository $calls=null,private ?TaskCancellationGuard $cancellations=null) {}

    public function register(string $key, callable $handler, string $effect = 'non_idempotent'): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/D', $key) || !in_array($effect, ['read_only', 'idempotent_write', 'non_idempotent'], true)) {
            throw new InvalidArgumentException('tool_metadata_invalid');
        }
        $this->tools[$key] = ['handler' => Closure::fromCallable($handler), 'effect' => $effect];
    }

    public function effect(string $key): string { return $this->definition($key)['effect']; }
    public function keys(): array { return array_keys($this->tools); }
    public function execute(string $key, array $input): ToolExecutionResult
    {
        $definition=$this->definition($key);$context=(array)($input['context']??[]);$arguments=(array)($input['arguments']??$input);
        $this->cancellations?->assertActive($context); // No ToolCall: physical execution has not begun.
        $started=microtime(true);
        try{
            $result=($definition['handler'])($input);
            if(!$result instanceof ToolExecutionResult)throw new RuntimeException('tool_result_invalid');
            if($this->calls===null)return$result;
            $toolCallId=$this->calls->record($context,$key,$arguments,$result,(int)round((microtime(true)-$started)*1000));
            return new ToolExecutionResult($result->summary,$result->artifacts,$result->data,$result->success,$result->status,$toolCallId);
        }catch(Throwable$e){
            $this->calls?->recordError($context,$key,$arguments,$e,(int)round((microtime(true)-$started)*1000));
            throw$e;
        }
    }
    private function definition(string $key): array
    {
        if (!isset($this->tools[$key])) throw new TaskValidationException('tool_not_supported');
        return $this->tools[$key];
    }
}
