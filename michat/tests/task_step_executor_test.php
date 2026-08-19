<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/Tasks/bootstrap.php';
$passed=0;$failed=0;$ok=function(bool$v,string$n)use(&$passed,&$failed){echo($v?'PASS ':'FAIL ').$n."\n";$v?$passed++:$failed++;};
$chat=new ChatExecutionService(new CallableChatRuntime(static fn(ChatExecutionRequest$r)=>new ChatExecutionResult('real reply',42,$r->model??'configured',$r->traceId)));
$tools=new ToolRegistry();$calls=0;
$tools->register('view',static function(array$i)use(&$calls){$calls++;return new ToolExecutionResult('viewed',[['type'=>'file_version','id'=>7,'relation'=>'read']]);},'read_only');
$registry=new TaskStepExecutorRegistry();
$registry->register('model',new ModelTaskStepExecutor($chat));$registry->register('tool',new ToolTaskStepExecutor($tools));$registry->register('validation',new ValidationTaskStepExecutor());$registry->register('finalize',new FinalizeTaskStepExecutor());$registry->register('approval',new ApprovalTaskStepExecutor());$registry->register('wait',new WaitTaskStepExecutor());$registry->register('plan',new PlanTaskStepExecutor());
$service=new TaskStepExecutionService($registry);$heartbeat=0;$beat=function()use(&$heartbeat){$heartbeat++;};$notCancelled=static fn()=>false;
try{$registry->get('Some\\InjectedClass');$ok(false,'registry rechaza tipo desconocido');}catch(TaskValidationException$e){$ok($e->getMessage()==='step_executor_not_supported','registry rechaza tipo desconocido');}
$base=['user_id'=>1,'session_id'=>2,'trace_id'=>'trace-a','step_type'=>'model','input'=>['prompt'=>'hello','model_id'=>'runtime-model']];
$model=$service->execute($base,$beat,$notCancelled);$ok($model->status==='completed'&&$model->messageId===42,'model usa ChatExecutionService');
$tool=$service->execute(['step_type'=>'tool','input'=>['tool_key'=>'view','arguments'=>[]]],$beat,$notCancelled);$ok($calls===1&&$tool->artifacts[0]['id']===7,'tool permitido y referencia artefacto');
try{$service->execute(['step_type'=>'tool','input'=>['tool_key'=>'exec']],$beat,$notCancelled);$ok(false,'tool desconocida rechazada');}catch(TaskValidationException$e){$ok(true,'tool desconocida rechazada');}
$ok($tools->effect('view')==='read_only','metadata read_only');
$approval=$service->execute(['step_type'=>'approval'],$beat,$notCancelled);$wait=$service->execute(['step_type'=>'wait','input'=>['wait_until'=>'2030-01-01T00:00:00Z'],'now'=>'2029-01-01T00:00:00Z'],$beat,$notCancelled);$due=$service->execute(['step_type'=>'wait','input'=>['wait_until'=>'2028-01-01T00:00:00Z'],'now'=>'2029-01-01T00:00:00Z'],$beat,$notCancelled);$ok($approval->status==='waiting_user','approval pausa sin dormir');$ok($wait->status==='waiting_dependency'&&($wait->checkpoint['wait_until']??'')==='2030-01-01 00:00:00.000000','wait futuro persiste fecha UTC');$ok($due->status==='completed','wait vencido completa sin dormir');
$machine=new TaskStepStateMachine();try{$machine->assertTransition('pending','ready');$machine->assertTransition('ready','running');$machine->assertTransition('running','completed');$ok(true,'flujo de estados completo');}catch(Throwable$e){$ok(false,'flujo de estados completo');}
$sources='';foreach(glob(__DIR__.'/../includes/{Tasks,Tools,Chat}/*.php',GLOB_BRACE)as$f)$sources.=file_get_contents($f);
$ok(!preg_match('/\b(?:eval|shell_exec|passthru|system)\s*\(/',$sources),'sin ejecución dinámica o shell arbitrario');
$ok(!str_contains(file_get_contents(__DIR__.'/../includes/Tasks/TaskWorker.php'),'http'),'Worker no llama HTTP');
$ok($heartbeat>=4,'heartbeat alrededor de modelo/tool');
echo"Resultado: $passed passed, $failed failed\n";exit($failed?1:0);
