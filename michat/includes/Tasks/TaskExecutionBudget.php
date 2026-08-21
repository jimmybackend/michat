<?php
declare(strict_types=1);

/** In-memory guard for one Execution; durable retry/step limits remain in MySQL. */
final class TaskExecutionBudget
{
    private int $rounds=0,$tools=0,$writes=0,$inputTokens=0,$outputTokens=0;
    private float $startedAt;
    public function __construct(
        private int $maxRounds=5,private int $maxTools=20,private int $maxWrites=5,
        private int $maxInputTokens=100000,private int $maxOutputTokens=20000,
        private int $maxTotalTokens=120000,private int $maxDurationSeconds=600
    ){$this->startedAt=microtime(true);foreach([$maxRounds,$maxTools,$maxWrites,$maxInputTokens,$maxOutputTokens,$maxTotalTokens,$maxDurationSeconds]as$v)if($v<1)throw new InvalidArgumentException('task_budget_invalid');}

    public static function serverDefaults():self{return new self(
        self::env('TASK_BUDGET_MODEL_ROUNDS',5),self::env('TASK_BUDGET_TOOL_CALLS',20),self::env('TASK_BUDGET_WRITES',5),
        self::env('TASK_BUDGET_INPUT_TOKENS',100000),self::env('TASK_BUDGET_OUTPUT_TOKENS',20000),
        self::env('TASK_BUDGET_TOTAL_TOKENS',120000),self::env('TASK_BUDGET_DURATION_SECONDS',600));}
    public function beforeModelRound():void{$this->time();if($this->rounds >= $this->maxRounds)throw new TaskTransitionException('task_budget_model_rounds_exceeded');$this->rounds++;}
    public function recordUsage(int$input,int$output):void{$this->time();$this->inputTokens+=max(0,$input);$this->outputTokens+=max(0,$output);if($this->inputTokens>$this->maxInputTokens)throw new TaskTransitionException('task_budget_input_tokens_exceeded');if($this->outputTokens>$this->maxOutputTokens)throw new TaskTransitionException('task_budget_output_tokens_exceeded');if($this->inputTokens+$this->outputTokens>$this->maxTotalTokens)throw new TaskTransitionException('task_budget_total_tokens_exceeded');}
    public function beforeTool(string$effect):void{$this->time();if($this->tools >= $this->maxTools)throw new TaskTransitionException('task_budget_tool_calls_exceeded');if($effect!=='read_only'&&$this->writes >= $this->maxWrites)throw new TaskTransitionException('task_budget_writes_exceeded');$this->tools++;if($effect!=='read_only')$this->writes++;}
    private function time():void{if(microtime(true)-$this->startedAt>$this->maxDurationSeconds)throw new TaskTransitionException('task_budget_duration_exceeded');}
    private static function env(string$key,int$default):int{$value=getenv($key);return$value!==false&&ctype_digit($value)&&(int)$value>0?(int)$value:$default;}
}
