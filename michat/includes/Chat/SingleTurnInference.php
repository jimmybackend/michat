<?php
declare(strict_types=1);
final class SingleTurnInferenceException extends RuntimeException{}
final class SingleTurnInferenceRequest{
 public const MAX_INPUT_CHARS=32000,MAX_SYSTEM_CHARS=16000,MAX_OUTPUT_TOKENS=8192;
 public function __construct(public readonly string$model,public readonly string$systemInstruction,public readonly string$userPrompt,public readonly float$temperature=0.0,public readonly int$maxTokens=2048,public readonly float$topP=0.9){if(trim($model)===''||mb_strlen($model)>255)throw new InvalidArgumentException('single_turn_model_invalid');if(trim($userPrompt)===''||mb_strlen($userPrompt)>self::MAX_INPUT_CHARS)throw new InvalidArgumentException('single_turn_prompt_invalid');if(mb_strlen($systemInstruction)>self::MAX_SYSTEM_CHARS)throw new InvalidArgumentException('single_turn_system_invalid');if(!is_finite($temperature)||$temperature<0||$temperature>1)throw new InvalidArgumentException('single_turn_temperature_invalid');if($maxTokens<1||$maxTokens>self::MAX_OUTPUT_TOKENS)throw new InvalidArgumentException('single_turn_max_tokens_invalid');if(!is_finite($topP)||$topP<=0||$topP>1)throw new InvalidArgumentException('single_turn_top_p_invalid');}
}
final class SingleTurnInferenceResult{/** @param array<string,int> $usage */public function __construct(public readonly string$text,public readonly string$model,public readonly string$stopReason,public readonly array$usage){}}
interface SingleTurnInferenceInterface{public function infer(SingleTurnInferenceRequest$request):SingleTurnInferenceResult;}
/** One textual inference, without Tool definitions, loops, persistence or retries. */
final class BedrockSingleTurnInference implements SingleTurnInferenceInterface{
 public function __construct(private BedrockConverseClientInterface$converse){}
 public function infer(SingleTurnInferenceRequest$request):SingleTurnInferenceResult{$params=['modelId'=>$request->model,'messages'=>[['role'=>'user','content'=>[['text'=>$request->userPrompt]]]],'inferenceConfig'=>['temperature'=>$request->temperature,'maxTokens'=>$request->maxTokens,'topP'=>$request->topP]];if(trim($request->systemInstruction)!=='')$params['system']=[['text'=>$request->systemInstruction]];$result=$this->converse->converse($params);if($result->toolUses!==[]||$result->stopReason==='tool_use')throw new SingleTurnInferenceException('single_turn_tool_use_rejected');return new SingleTurnInferenceResult(trim($result->text),$request->model,$result->stopReason,$result->usage);}
}
