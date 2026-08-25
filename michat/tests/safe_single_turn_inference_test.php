<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/Chat/BedrockConverseResult.php';
require_once __DIR__.'/../includes/Chat/BedrockConverseClient.php';
require_once __DIR__.'/../includes/Chat/SingleTurnInference.php';

final class SingleTurnFakeBedrock{
 public int$calls=0;public array$requests=[];public function __construct(private array$responses){}
 public function converse(array$params):array{$this->calls++;$this->requests[]=$params;return array_shift($this->responses);}
}
function singleText(string$text='ok',string$stop='end_turn'):array{return['stopReason'=>$stop,'usage'=>['inputTokens'=>3,'outputTokens'=>2,'totalTokens'=>5],'output'=>['message'=>['role'=>'assistant','content'=>[['text'=>$text]]]]];}
function singleTool():array{return['stopReason'=>'tool_use','usage'=>['inputTokens'=>1,'outputTokens'=>1,'totalTokens'=>2],'output'=>['message'=>['role'=>'assistant','content'=>[['toolUse'=>['name'=>'view','toolUseId'=>'u1','input'=>[]]]]]]];}
$passed=0;$failed=0;$ok=function(bool$v,string$n)use(&$passed,&$failed):void{echo($v?'PASS ':'FAIL ').$n."\n";$v?$passed++:$failed++;};

$fake=new SingleTurnFakeBedrock([singleText('hello')]);$primitive=new BedrockConverseClient($fake);$raw=$primitive->converse(['modelId'=>'m','messages'=>[]]);
$ok($fake->calls===1,'primitive realiza exactamente una llamada');
$ok($raw->text==='hello'&&$raw->toolUses===[],'primitive normaliza texto');
$ok($raw->usage===['prompt_tokens'=>3,'completion_tokens'=>2,'total_tokens'=>5],'primitive normaliza usage');
$ok($raw->stopReason==='end_turn'&&($raw->outputMessage['role']??null)==='assistant','primitive conserva stopReason y output message');
$toolFake=new SingleTurnFakeBedrock([singleTool()]);$toolRaw=(new BedrockConverseClient($toolFake))->converse([]);$ok(count($toolRaw->toolUses)===1&&$toolRaw->toolUses[0]['name']==='view','primitive normaliza toolUse sin ejecutarlo');

$safeFake=new SingleTurnFakeBedrock([singleText(' safe ')]);$safe=new BedrockSingleTurnInference(new BedrockConverseClient($safeFake));$result=$safe->infer(new SingleTurnInferenceRequest('model','system','prompt',0.2,321,0.8));$request=$safeFake->requests[0];
$ok($result->text==='safe'&&$result->usage['total_tokens']===5,'single-turn retorna texto y usage');
$ok($safeFake->calls===1,'single-turn realiza exactamente una llamada');
$ok(!array_key_exists('toolConfig',$request)&&!array_key_exists('tools',$request),'single-turn no envía Tools ni toolConfig');
$ok(($request['inferenceConfig']['maxTokens']??null)===321,'single-turn respeta output limit validado');

$unexpected=new SingleTurnFakeBedrock([singleTool(),singleText('must not run')]);try{(new BedrockSingleTurnInference(new BedrockConverseClient($unexpected)))->infer(new SingleTurnInferenceRequest('m','','p'));$ok(false,'toolUse debe fallar');}catch(SingleTurnInferenceException$e){$ok($e->getMessage()==='single_turn_tool_use_rejected'&&$unexpected->calls===1,'toolUse falla cerrado sin segunda llamada');}
$stopOnly=new SingleTurnFakeBedrock([singleText('bad','tool_use')]);try{(new BedrockSingleTurnInference(new BedrockConverseClient($stopOnly)))->infer(new SingleTurnInferenceRequest('m','','p'));$ok(false,'stopReason tool_use debe fallar');}catch(SingleTurnInferenceException$e){$ok($stopOnly->calls===1,'stopReason tool_use falla cerrado');}

$invalid=[fn()=>new SingleTurnInferenceRequest('','','p'),fn()=>new SingleTurnInferenceRequest('m','',str_repeat('x',SingleTurnInferenceRequest::MAX_INPUT_CHARS+1)),fn()=>new SingleTurnInferenceRequest('m','','p',-0.1),fn()=>new SingleTurnInferenceRequest('m','','p',0,SingleTurnInferenceRequest::MAX_OUTPUT_TOKENS+1),fn()=>new SingleTurnInferenceRequest('m','','p',0,1,0)];foreach($invalid as$i=>$make){try{$make();$ok(false,'parametro invalido '.$i);}catch(InvalidArgumentException){$ok(true,'parametro invalido '.$i.' rechazado');}}
$clientSource=file_get_contents(__DIR__.'/../includes/Chat/BedrockConverseClient.php');$safeSource=file_get_contents(__DIR__.'/../includes/Chat/SingleTurnInference.php');$runtimeSource=file_get_contents(__DIR__.'/../includes/Chat/BedrockChatRuntime.php');
$ok(substr_count($clientSource,'->converse(')===1&&!str_contains($clientSource,'mysqli')&&!str_contains($clientSource,'INSERT ')&&!str_contains($clientSource,'UPDATE '),'primitive posee una llamada y ninguna persistencia/DB');
$ok(!str_contains($safeSource,'ToolRegistry')&&!str_contains($safeSource,'while(')&&!str_contains($safeSource,'for ('),'single-turn no conoce ToolRegistry ni contiene loop');
$ok(!str_contains($runtimeSource,'Config::getBedrockRuntime')&&!preg_match('/\$[A-Za-z_][A-Za-z0-9_]*->converse\(/',$runtimeSource)&&str_contains($runtimeSource,'$this->converse->converse($params)'),'BedrockChatRuntime usa exclusivamente primitive compartida');
echo"Resultado: $passed PASS, $failed FAIL.\n";exit($failed?1:0);
