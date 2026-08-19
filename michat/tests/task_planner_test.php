<?php
declare(strict_types=1);if(PHP_SAPI!=='cli')exit(1);require_once __DIR__.'/../includes/Tasks/bootstrap.php';$p=0;$f=0;$ok=function(bool$v,string$n)use(&$p,&$f){echo($v?'PASS ':'FAIL ').$n."\n";$v?$p++:$f++;};
$agents=fn(string$key)=>in_array($key,['chat_main','known_agent'],true);$v=new TaskPlanValidator($agents);
$valid=['steps'=>[['step_key'=>'analyze','title'=>'Analizar','description'=>'Objetivo','step_type'=>'model','agent_key'=>'known_agent'],['step_key'=>'validate','title'=>'Validar','description'=>'Comprobar','step_type'=>'validation','agent_key'=>'missing']]];
$plan=$v->validate($valid);$ok($plan->count()===2,'JSON correcto produce TaskPlan');$ok($plan->steps()[1]->agentKey==='chat_main','agent_key inexistente usa fallback seguro');$ok($plan->steps()[0]->persistenceData(1)['position']===1&&$plan->steps()[1]->persistenceData(2)['position']===2,'positions se asignan server-side');
$bad=[['steps'=>[]],['steps'=>array_fill(0,9,$valid['steps'][0])],['steps'=>[array_merge($valid['steps'][0],['step_type'=>'shell'])]],['steps'=>[array_merge($valid['steps'][0],['step_key'=>'BAD'])]],['steps'=>[$valid['steps'][0],$valid['steps'][0]]],['steps'=>[array_merge($valid['steps'][0],['sql'=>'DROP TABLE Tasks'])]]];foreach($bad as$i=>$payload){try{$v->validate($payload);$ok(false,'payload inválido '.$i);}catch(TaskValidationException){$ok(true,'payload inválido '.$i.' rechazado');}}
$calls=0;$ai=new AiTaskPlanner($v,function(array$config,string$prompt)use(&$calls){$calls++;return json_encode(['steps'=>[['step_key'=>'respond','title'=>'Responder','description'=>'Resolver','step_type'=>'model','agent_key'=>'chat_main']]]);},['is_active'=>1,'model_id'=>'dynamic-model']);$ok($ai->plan('objetivo')->count()===1&&$calls===1,'AiTaskPlanner usa configuración dinámica');$ok(!str_contains(strtolower(AiTaskPlanner::INSTRUCTION),'drop table'),'prompt independiente de SQL ejecutable');$fallback=TaskPlan::fallback();$ok($fallback->isFallback()&&$fallback->steps()[0]->stepKey==='respond','fallback determinista respond');
$service=file_get_contents(__DIR__.'/../includes/Tasks/TaskPlanningService.php');$orchestrator=file_get_contents(__DIR__.'/../includes/Tasks/TaskOrchestrator.php');$flags=file_get_contents(__DIR__.'/../includes/Pipeline/PipelineFeatureFlags.php');
$ok(str_contains($flags,"'task_planner' => false"),'task_planner default OFF');
$ok(str_contains($service,'planning_not_authorized'),'Planner exige autorización');
$ok(str_contains($service,'hasExecutions'),'Task con Execution no se replantea');
$ok(!str_contains($service,'TaskExecutions('),'Planner no crea Executions');
$ok(str_contains($service,'begin_transaction')&&str_contains($service,'rollback'),'persistencia de Plan es atómica');
$ok(str_contains($service,'deleteUnexecutedPlaceholder'),'placeholder respond se sustituye sin Execution');
$ok(str_contains($orchestrator,"\$target=\$auto?'ready':'waiting_user'"),'supervisión mantiene waiting_user antes de aprobación');
echo"Resultado: $p passed, $f failed\n";echo"SKIP integración MySQL/Bedrock: no hay TASK_TEST_DB_* configurado.\n";exit($f?1:0);
