<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);

$root=dirname(__DIR__);
require_once $root.'/includes/Pipeline/PipelineFeatureFlags.php';

$passed=0;$failed=0;
$check=static function(bool$ok,string$label)use(&$passed,&$failed):void{
    echo($ok?'PASS ':'FAIL ').$label."\n";
    $ok?$passed++:$failed++;
};

$reflection=new ReflectionClass(PipelineFeatureFlags::class);
/** @var PipelineFeatureFlags $flags */
$flags=$reflection->newInstanceWithoutConstructor();
$flagsProperty=$reflection->getProperty('flags');
$flagsProperty->setValue($flags,[
    'prompt_compiler'=>true,
    'memory_router'=>true,
    'procedural_memory_read'=>true,
    'project_memory_read'=>true,
    'session_memory_read'=>true,
    'question_memory_read'=>true,
    'project_rag'=>true,
    'attachment_rag'=>true,
    'context_ranking'=>true,
    'memory_backfill'=>true,
    'project_tools'=>true,
    'memory_writer'=>true,
    'task_orchestrator'=>true,
    'task_auto_execute'=>false,
    'task_async_execute'=>true,
    'task_planner'=>true,
]);

$_POST=[];
$normal=$flags->all();
$check($normal['task_orchestrator']===false,'chat normal no entra al Task Orchestrator');
$check($normal['task_auto_execute']===false&&$normal['task_async_execute']===false&&$normal['task_planner']===false,'chat normal enmascara toda la superficie Task');
$check($normal['memory_router']===true&&$normal['project_tools']===true,'chat normal conserva memoria y herramientas conversacionales');

$_POST=['action'=>'send_message'];
$normalAction=$flags->all();
$check($normalAction['task_orchestrator']===false,'una acción normal tampoco materializa Tasks');

$_POST=['action'=>'execute_approved_task'];
$resume=$flags->all();
$check($resume['task_orchestrator']===true&&$resume['task_async_execute']===true&&$resume['task_planner']===true,'execute_approved_task recupera las flags persistidas para reanudar una Task existente');

$_POST=[];
$full=$flags->all(true);
$check($full['task_orchestrator']===true&&$full['task_planner']===true,'snapshot completo conserva la configuración Task persistida');
$check($flags->enabled('task_orchestrator')===true,'enabled() sigue exponiendo Task Center sin depender del chat');

$chat=(string)file_get_contents($root.'/bedrock_chat2.php');
$taskApi=(string)file_get_contents($root.'/task_api.php');
$check(str_contains($chat,"execute_approved_task")&&str_contains($chat,'approvedChatTurn('),'reanudación explícita mantiene validación persistida y ownership');
$check(str_contains($taskApi,"enabled('task_orchestrator')"),'Task API sigue separado y usa el flag persistido');

$_POST=[];
echo"Result: {$passed} passed, {$failed} failed\n";
exit($failed?1:0);
