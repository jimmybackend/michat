<?php
declare(strict_types=1);
final class NextWorkEvaluationResult{
 public function __construct(public readonly NextWorkDecision$decision,public readonly array$usage=[],public readonly bool$modelCalled=false,public readonly ?string$failureCode=null){}
 public function inputTokens():int{return max(0,(int)($this->usage['inputTokens']??$this->usage['input_tokens']??$this->usage['prompt_tokens']??0));}
 public function outputTokens():int{return max(0,(int)($this->usage['outputTokens']??$this->usage['output_tokens']??$this->usage['completion_tokens']??0));}
}
