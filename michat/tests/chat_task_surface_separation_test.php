<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);

$root=dirname(__DIR__);
require_once $root.'/includes/Pipeline/PipelineFeatureFlags.php';

$flagsSource=(string)file_get_contents($root.'/includes/Pipeline/PipelineFeatureFlags.php');
$chat=(string)file_get_contents($root.'/bedrock_chat2.php');
$taskApi=(string)file_get_contents($root.'/task_api.php');

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
    'task_auto_execute'=>true,
    'task_async_execute'=>true,
    'task_planner'=>true,
]);

$chatSnapshot=$flags->all();
$fullSnapshot=$flags->all(true);

$check($chatSnapshot['memory_router']===true&&$chatSnapshot['project_tools']===true,'normal chat keeps its non-Task pipeline features');
$check($chatSnapshot['task_orchestrator']===false&&$chatSnapshot['task_auto_execute']===false&&$chatSnapshot['task_async_execute']===false&&$chatSnapshot['task_planner']===false,'normal chat masks every Task-surface flag');
$check($fullSnapshot['task_orchestrator']===true&&$fullSnapshot['task_auto_execute']===true&&$fullSnapshot['task_async_execute']===true&&$fullSnapshot['task_planner']===true,'full persisted Task configuration remains available');
$check($flags->enabled('task_orchestrator')===true&&$flags->enabled('task_planner')===true,'enabled() still exposes persisted Task flags to Task Center');

$check(str_contains($chat,'$pipelineConfigured = $pipelineFlags->all();'),'bedrock chat obtains the chat-only snapshot');
$check(str_contains($flagsSource,'TASK_SURFACE_KEYS')&&str_contains($flagsSource,'public function all(bool $includeTaskSurface = false)'),'PipelineFeatureFlags declares the chat/Task surface boundary');
$check(str_contains($taskApi,"\$flags->enabled('task_orchestrator')")&&str_contains($taskApi,"\$flags->enabled('task_planner')"),'Task API continues reading persisted Task flags directly');
$check(str_contains($chat,"pipelineEffective['task_orchestrator']")&&str_contains($chat,'ChatTaskBridge'),'legacy bridge remains present but unreachable from an ordinary chat snapshot');

echo"Result: {$passed} passed, {$failed} failed\n";
exit($failed?1:0);
