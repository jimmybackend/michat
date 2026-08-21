<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/Tasks/bootstrap.php';

final class PauseTestBedrock
{
    public int $calls=0;
    public array $requests=[];
    public function __construct(private array $responses){}
    public function converse(array $params):array{$this->calls++;$this->requests[]=$params;return array_shift($this->responses);}
}
final class PauseTestGate implements ToolExecutionGateInterface
{
    public array $calls=[];
    public function __construct(private array $pausingKeys=[]){ }
    public function beforeExecute(string$key,array$arguments,array$context):ToolExecutionGateDecision
    {
        $this->calls[]=[$key,$arguments,$context];
        return in_array($key,$this->pausingKeys,true)?ToolExecutionGateDecision::pauseAlreadyPersisted('Approval required for a write Tool.'):ToolExecutionGateDecision::allow();
    }
}
final class PauseTestObserver implements ToolExecutionObserverInterface
{
    public int$calls=0;public function observe(array$context,ToolExecutionResult$result):void{$this->calls++;}
}

function pauseResponse(array$uses):array{return['stopReason'=>'tool_use','usage'=>['inputTokens'=>1,'outputTokens'=>2,'totalTokens'=>3],'output'=>['message'=>['role'=>'assistant','content'=>array_map(static fn(array$u):array=>['toolUse'=>$u],$uses)]]];}
function textResponse(string$text='done'):array{return['stopReason'=>'end_turn','usage'=>['inputTokens'=>1,'outputTokens'=>1,'totalTokens'=>2],'output'=>['message'=>['role'=>'assistant','content'=>[['text'=>$text]]]]];}
function pauseRequest():ChatExecutionRequest{return new ChatExecutionRequest(2,3,4,null,'request',null,'prompt',null,'test-model',0.1,100,0.9,'trace-pause',['task_id'=>10,'step_id'=>20,'execution_id'=>30]);}
function pauseRuntime(ToolRegistry$registry,PauseTestBedrock$bedrock,?ToolExecutionGateInterface$gate=null,?ToolExecutionObserverInterface$observer=null):BedrockChatRuntime
{
    return new BedrockChatRuntime(mysqli_init(),$registry,null,$observer,$gate,static fn():array=>['chat_main'=>['is_active'=>1,'model_id'=>'test-model']],$bedrock);
}

$passed=0;$failed=0;$ok=function(bool$value,string$name)use(&$passed,&$failed):void{echo($value?'PASS ':'FAIL ').$name."\n";$value?$passed++:$failed++;};

$handled=0;$registry=new ToolRegistry();$registry->register('view',static function(array$input)use(&$handled):ToolExecutionResult{$handled++;return new ToolExecutionResult('viewed');},'read_only');
$allowGate=new PauseTestGate();$allowObserver=new PauseTestObserver();$allowBedrock=new PauseTestBedrock([pauseResponse([['name'=>'view','toolUseId'=>'u1','input'=>['path'=>'a']]]),textResponse()]);
$allow=pauseRuntime($registry,$allowBedrock,$allowGate,$allowObserver)->execute(pauseRequest());
$toolResult=$allowBedrock->requests[1]['messages'][2]['content'][0]['toolResult']??null;
$ok(!$allow->isPauseAlreadyPersisted()&&count($allowGate->calls)===1&&$handled===1&&$allowObserver->calls===1,'ALLOW conserva gate, ToolRegistry y observer una vez');
$ok($toolResult!==null&&$allowBedrock->calls===2,'ALLOW construye toolResult y permite la ronda Converse siguiente');
$ok(($allowGate->calls[0][2]['step_id']??null)===20,'step_id procede del taskContext server-side');

$pauseHandled=0;$pauseRegistry=new ToolRegistry();$pauseRegistry->register('code_edit',static function()use(&$pauseHandled):ToolExecutionResult{$pauseHandled++;return new ToolExecutionResult('unexpected');});$pauseObserver=new PauseTestObserver();$pauseGate=new PauseTestGate(['code_edit']);$pauseBedrock=new PauseTestBedrock([pauseResponse([['name'=>'code_edit','toolUseId'=>'u2','input'=>['content'=>'sensitive']]])]);
$pause=pauseRuntime($pauseRegistry,$pauseBedrock,$pauseGate,$pauseObserver)->execute(pauseRequest());
$ok($pause->isPauseAlreadyPersisted()&&$pause->controlDecision->safeSummary==='Approval required for a write Tool.','Runtime devuelve pausa tipada y sólo el summary seguro');
$ok($pauseHandled===0&&$pauseObserver->calls===0&&$pauseBedrock->calls===1,'PAUSE omite handler, observer, toolResult y siguiente Converse');

$multiHandled=[];$multiRegistry=new ToolRegistry();foreach(['view','code_edit','search']as$key)$multiRegistry->register($key,static function()use(&$multiHandled,$key):ToolExecutionResult{$multiHandled[]=$key;return new ToolExecutionResult($key);},$key==='view'||$key==='search'?'read_only':'non_idempotent');$multiGate=new PauseTestGate(['code_edit']);$multiObserver=new PauseTestObserver();$multiBedrock=new PauseTestBedrock([pauseResponse([['name'=>'view','toolUseId'=>'m1','input'=>[]],['name'=>'code_edit','toolUseId'=>'m2','input'=>[]],['name'=>'search','toolUseId'=>'m3','input'=>[]]])]);
$multi=pauseRuntime($multiRegistry,$multiBedrock,$multiGate,$multiObserver)->execute(pauseRequest());
$ok($multi->isPauseAlreadyPersisted()&&$multiHandled===['view']&&count($multiGate->calls)===2&&$multiObserver->calls===1,'pausa en segundo Tool corta el foreach antes del segundo efecto y del tercer gate');
$ok($multiBedrock->calls===1&&count($multiBedrock->requests[0]['messages'])===1,'pausa multi-Tool no añade mensaje toolResult ni llama otra ronda');

$heartbeats=0;$chatPause=(new ChatExecutionService(new CallableChatRuntime(static fn(ChatExecutionRequest$r):ChatExecutionResult=>ChatExecutionResult::pauseAlreadyPersisted('model',$r->traceId,'safe'))))->execute(pauseRequest(),static function()use(&$heartbeats):void{$heartbeats++;});
$ok($chatPause->isPauseAlreadyPersisted()&&$heartbeats===1,'ChatExecutionService retorna pausa antes del heartbeat y finalización posteriores');
$serviceSource=file_get_contents(__DIR__.'/../includes/Chat/ChatExecutionService.php');$pauseCheck=strpos($serviceSource,'if($result->isPauseAlreadyPersisted())return$result;');$finalization=strpos($serviceSource,'model_round_completed');
$ok($pauseCheck!==false&&$pauseCheck<$finalization,'Chat corta antes de telemetría de ronda, persistencia final, tokens, memoria y trace completed');

$cancelChecks=0;$executor=new ModelTaskStepExecutor(new ChatExecutionService(new CallableChatRuntime(static fn(ChatExecutionRequest$r):ChatExecutionResult=>ChatExecutionResult::pauseAlreadyPersisted('model',$r->traceId,'safe pause'))));$step=$executor->execute(['user_id'=>2,'session_id'=>3,'trace_id'=>'trace-model','task_id'=>10,'step_id'=>20,'execution_id'=>30,'objective'=>'x','input'=>[]],static function():void{},static function()use(&$cancelChecks):bool{$cancelChecks++;return $cancelChecks>1;});
$ok($step->isDurablePauseAlreadyPersisted()&&$step->summary==='safe pause'&&$cancelChecks===1,'Model mapea persistedWaitingUser antes del check de cancelación posterior');

$normalChecks=0;$normal=(new ModelTaskStepExecutor(new ChatExecutionService(new CallableChatRuntime(static fn(ChatExecutionRequest$r):ChatExecutionResult=>new ChatExecutionResult('normal',77,'model',$r->traceId,[],[],[['id'=>1]])))))->execute(['user_id'=>2,'session_id'=>3,'trace_id'=>'trace-normal','objective'=>'x','input'=>[]],static function():void{},static function()use(&$normalChecks):bool{$normalChecks++;return false;});
$ok($normal->status==='completed'&&$normal->messageId===77&&$normal->artifacts===[['id'=>1]]&&$normalChecks===2,'Model normal conserva completion, messageId, artifacts y checks');
$normalFactory=new ChatExecutionServiceFactory(mysqli_init());$property=(new ReflectionClass($normalFactory))->getProperty('toolGate');$ok($property->getValue($normalFactory)===null,'ChatExecutionServiceFactory normal conserva gate null por defecto');

echo"Resultado: $passed passed, $failed failed\n";exit($failed?1:0);
