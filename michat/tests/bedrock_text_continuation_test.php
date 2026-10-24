<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/Tasks/bootstrap.php';
require_once __DIR__.'/fixtures/aws_result.php';
final class ContinuationFixture
{
    public array $calls=[];
    public function __construct(public array $replies){}
    public function converse(array $params):ArrayAccess{$this->calls[]=$params;return documentSdkResult(array_shift($this->replies)??throw new RuntimeException('Unexpected model call'));}
}
function continuationReply(string $text,string $stop='end_turn',array $tools=[]):array{return ['stopReason'=>$stop,'usage'=>['inputTokens'=>7,'outputTokens'=>3,'totalTokens'=>10],'output'=>['message'=>['role'=>'assistant','content'=>array_merge([['text'=>$text]],$tools)]]];}
$count=0;$check=static function(bool $ok,string $label)use(&$count){$count++;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";};
$params=['modelId'=>'fixture','system'=>[['text'=>'Evidencia PDF, páginas 1 a 5']],'messages'=>[['role'=>'user','content'=>[['text'=>'Explica este PDF con citas']]]],'inferenceConfig'=>['maxTokens'=>1000],'toolConfig'=>['tools'=>[]]];
$sdk=new ContinuationFixture([continuationReply('Se declara que no se adquir','max_tokens'),continuationReply('ieron bienes. [SCB:7 · página 4]')]);$client=new BedrockConverseClient($sdk);$progress=[];
$r=BedrockTextContinuation::complete($params,$client->converse($params),[$client,'converse'],static function($part)use(&$progress){$progress[]=$part;});
$check($r->text==='Se declara que no se adquirieron bienes. [SCB:7 · página 4]'&&$r->stopReason==='end_turn','Truncated PDF sentence completes automatically with its real source reference');
$check($r->usage===['prompt_tokens'=>14,'completion_tokens'=>6,'total_tokens'=>20],'All continuation input/output tokens are included for billing');
$check(count($sdk->calls)===2&&$progress===[1]&&!isset($sdk->calls[1]['toolConfig']),'One extra call reports progress and cannot execute tools');
$check($sdk->calls[1]['messages'][0]===$params['messages'][0]&&$sdk->calls[1]['system']===$params['system']&&$sdk->calls[1]['messages'][1]['content'][0]['text']==='Se declara que no se adquir','Continuation retains original evidence and the unfinished assistant turn');
$sdk=new ContinuationFixture([continuationReply('Completo.')]);$client=new BedrockConverseClient($sdk);$r=BedrockTextContinuation::complete($params,$client->converse($params),[$client,'converse']);$check(count($sdk->calls)===1&&$r->text==='Completo.','Complete answers incur no additional model call');
$sdk=new ContinuationFixture(array_fill(0,4,continuationReply('texto','max_tokens')));$client=new BedrockConverseClient($sdk);$r=BedrockTextContinuation::complete($params,$client->converse($params),[$client,'converse']);$check(count($sdk->calls)===4&&$r->stopReason==='max_tokens'&&$r->usage['total_tokens']===40,'Repeated truncation is bounded and remains explicitly incomplete');
$sdk=new ContinuationFixture([continuationReply('prefix','max_tokens'),continuationReply('','tool_use',[['toolUse'=>['name'=>'delete','toolUseId'=>'x','input'=>[]]]])]);$client=new BedrockConverseClient($sdk);$r=BedrockTextContinuation::complete($params,$client->converse($params),[$client,'converse']);$check($r->text==='prefix'&&$r->toolUses===[]&&$r->stopReason==='max_tokens'&&$r->usage['total_tokens']===20,'Unexpected continuation tool request is never executed or mistaken for completion');
$runtimeFor=static function(array $replies)use(&$sdk){$sdk=new ContinuationFixture($replies);return new BedrockChatRuntime(mysqli_init(),new ToolRegistry(),configLoader:static fn()=>['chat_main'=>['is_active'=>1,'model_id'=>'fixture']],converse:new BedrockConverseClient($sdk));};
$request=new ChatExecutionRequest(1,1,null,null,'request',null,'PDF evidence',null,'fixture',0.2,1000,0.9,'continuation-test-0001');
$result=$runtimeFor([continuationReply('Introducción. ','max_tokens'),continuationReply('Conclusión verificada.')])->execute($request);
$check($result->replyText==='Introducción. Conclusión verificada.'&&$result->tokenUsage['total_tokens']===20&&$result->stopReason==='end_turn','Production chat runtime persists complete joined text and combined usage');
$result=$runtimeFor(array_fill(0,4,continuationReply('texto','max_tokens')))->execute($request);$check(str_contains($result->replyText,'quedó incompleta')&&$result->stopReason==='max_tokens','Runtime retains explicit warning if its bounded continuation still cannot finish');
$taskRequest=new ChatExecutionRequest(1,1,null,null,'request',null,'PDF evidence',null,'fixture',0.2,1000,0.9,'continuation-test-0002',['task_id'=>1]);
putenv('TASK_BUDGET_MODEL_ROUNDS=1');$denied=false;try{$runtimeFor([continuationReply('texto','max_tokens')])->execute($taskRequest);}catch(TaskTransitionException $e){$denied=$e->getMessage()==='task_budget_model_rounds_exceeded';}finally{putenv('TASK_BUDGET_MODEL_ROUNDS');}
$check($denied&&count($sdk->calls)===1,'Automatic continuation cannot bypass the Tasks model-round budget');
echo "OK $count text continuation checks\n";
