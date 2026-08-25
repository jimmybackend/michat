<?php
declare(strict_types=1);
interface BedrockConverseClientInterface{/** @param array<string,mixed> $params */public function converse(array$params):BedrockConverseResult;}
/** Persistence-free primitive: one SDK Converse call and shared response normalization. */
final class BedrockConverseClient implements BedrockConverseClientInterface{
 public function __construct(private ?object$runtime=null){}
 public function converse(array$params):BedrockConverseResult{$runtime=$this->runtime??Config::getBedrockRuntime();$response=$runtime->converse($params);$message=$response['output']['message']??[];if(!is_array($message))$message=[];$blocks=$message['content']??[];if(!is_array($blocks))$blocks=[];$text='';$uses=[];foreach($blocks as$block){if(!is_array($block))continue;if(isset($block['text'])&&is_string($block['text']))$text.=$block['text'];if(isset($block['toolUse'])&&is_array($block['toolUse']))$uses[]=$block['toolUse'];}$raw=is_array($response['usage']??null)?$response['usage']:[];$usage=['prompt_tokens'=>(int)($raw['inputTokens']??0),'completion_tokens'=>(int)($raw['outputTokens']??0),'total_tokens'=>(int)($raw['totalTokens']??0)];return new BedrockConverseResult($text,$uses,(string)($response['stopReason']??''),$usage,$message);}
}
