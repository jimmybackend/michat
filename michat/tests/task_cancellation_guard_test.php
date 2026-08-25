<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/Tasks/bootstrap.php';
$passed=0;$failed=0;$ok=function(bool$v,string$n)use(&$passed,&$failed):void{echo($v?'PASS ':'FAIL ').$n."\n";$v?$passed++:$failed++;};

$modelCalls=0;$chat=new ChatExecutionService(new CallableChatRuntime(static function(ChatExecutionRequest$r)use(&$modelCalls):ChatExecutionResult{$modelCalls++;return new ChatExecutionResult('unexpected',null,'model',$r->traceId);}));
$model=new ModelTaskStepExecutor($chat);try{$model->execute(['user_id'=>1,'session_id'=>2,'trace_id'=>'cancel-test-trace','step_type'=>'model','input'=>['prompt'=>'x']],static function():void{},static fn():bool=>true);$ok(false,'cancelada antes de Bedrock');}catch(TaskTransitionException$e){$ok($modelCalls===0&&$e->getMessage()==='cancel_requested','Task cancelada antes de Bedrock no llama runtime');}

$toolCalls=0;$guard=new TaskCancellationGuard(null,static fn(array$c):bool=>true);$tools=new ToolRegistry(null,$guard);$tools->register('view',static function(array$i)use(&$toolCalls):ToolExecutionResult{$toolCalls++;return new ToolExecutionResult('unexpected');},'read_only');
try{$tools->execute('view',['arguments'=>['chunk_id'=>1],'context'=>['task_id'=>9,'user_id'=>1,'session_id'=>2,'project_id'=>3]]);$ok(false,'cancelada antes de tool');}catch(TaskTransitionException$e){$ok($toolCalls===0,'Task cancelada antes de tool no ejecuta handler ni crea ToolCall');}

$bedrock=file_get_contents(__DIR__.'/../includes/Chat/BedrockChatRuntime.php');$str=file_get_contents(__DIR__.'/../includes/Tools/StrReplaceService.php');$code=file_get_contents(__DIR__.'/../includes/Tools/CodeEditService.php');$registry=file_get_contents(__DIR__.'/../includes/Tools/ToolRegistry.php');
$converseAt=strpos($bedrock,'$this->converse->converse($params)');$ok(substr_count($bedrock,'$this->checkpoint($request)')>=3&&strpos($bedrock,'$this->checkpoint($request)')<$converseAt&&strpos($bedrock,'$this->checkpoint($request)',$converseAt)<strpos($bedrock,"\$usage['prompt_tokens']"),'checkpoint precede Converse compartido y vuelve a validar al terminar la llamada');
$ok(strpos($bedrock,'$this->checkpoint($request)',strpos($bedrock,'foreach ($uses'))<strpos($bedrock,'$this->tools->execute'),'cancelación entre ronda modelo y ToolResult impide tool/segunda ronda');
$ok(substr_count($str,'cancellations?->assertActive')>=2&&strpos($str,'cancellations?->assertActive',strpos($str,'replaceContent'))<strpos($str,'putObject'),'str_replace comprueba cancelación antes de persistir');
$ok(substr_count($code,'cancellations?->assertActive')>=4&&strpos($code,'cancellations?->assertActive',strpos($code,"\$action==='delete'"))<strpos($code,'deleteObject')&&strpos($code,'cancellations?->assertActive',strpos($code,'generateEdit'))<strpos($code,'createFileVersion'),'code_edit comprueba cancelación antes de delete y FileVersion/escritura');
$ok(strpos($registry,'cancellations?->assertActive')<strpos($registry,'microtime(true)')&&strpos($registry,'cancellations?->assertActive')<strpos($registry,'calls?->record'),'tool cancelada antes de comenzar no genera ToolCalls');
$runner=file_get_contents(__DIR__.'/../includes/Tasks/TaskExecutionRunner.php');$progress=file_get_contents(__DIR__.'/../includes/Tasks/TaskStepProgressionService.php');
$ok(str_contains($runner,"progression->cancel")&&str_contains($progress,"finish(\$c,'cancelled'")&&!preg_match('/cancel_requested[^}]+progression->apply/s',$runner),'cancelación usa estados existentes, no avanza Step ni completa Task');
$chatFactory=file_get_contents(__DIR__.'/../includes/Chat/ChatExecutionServiceFactory.php');$toolFactory=file_get_contents(__DIR__.'/../includes/Tools/ToolRegistryFactory.php');
$ok(str_contains($chatFactory,'new TaskCancellationGuard')&&str_contains($toolFactory,'TaskCancellationGuard'),'HTTP sync y Worker comparten TaskCancellationGuard');
$guardSource=file_get_contents(__DIR__.'/../includes/Tasks/TaskCancellationGuard.php');$ok(!preg_match('/\$_(?:POST|GET|SESSION|COOKIE)\b/',$guardSource)&&str_contains($guardSource,'FROM Tasks'),'guard consulta estado persistido sin superglobals');
$ok(!str_contains($guardSource,'TaskArtifacts')&&!str_contains($code,'TaskArtifacts'),'no crea TaskArtifacts');

echo"Resultado: $passed passed, $failed failed\n";exit($failed?1:0);
