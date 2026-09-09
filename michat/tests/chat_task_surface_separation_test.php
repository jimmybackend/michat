<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);

$root=dirname(__DIR__);
$flags=(string)file_get_contents($root.'/includes/Pipeline/PipelineFeatureFlags.php');
$chat=(string)file_get_contents($root.'/bedrock_chat2.php');
$taskApi=(string)file_get_contents($root.'/task_api.php');

$passed=0;$failed=0;
$check=static function(bool$ok,string$label)use(&$passed,&$failed):void{
    echo($ok?'PASS ':'FAIL ').$label."\n";
    $ok?$passed++:$failed++;
};

$check(str_contains($chat,'$pipelineConfigured = $pipelineFlags->all();'),'chat obtains its feature snapshot through all()');
$check(str_contains($flags,'TASK_SURFACE_KEYS')&&str_contains($flags,"'task_orchestrator'")&&str_contains($flags,"'task_auto_execute'")&&str_contains($flags,"'task_async_execute'")&&str_contains($flags,"'task_planner'"),'chat snapshot explicitly identifies all task-surface flags');
$check(str_contains($flags,'public function all(bool $includeTaskSurface = false)')&&str_contains($flags,'$flags[$key] = false'),'normal chat snapshot masks task orchestration by default');
$check(str_contains($flags,'if ($includeTaskSurface) return $flags;'),'full configured task snapshot remains available explicitly');
$check(str_contains($taskApi,"\$flags->enabled('task_orchestrator')")&&str_contains($taskApi,"\$flags->enabled('task_planner')"),'Task API continues using persisted task flags directly');
$check(str_contains($flags,'public function enabled(string $featureKey): bool'),'enabled() remains the independent Task Center configuration path');

// Regression contract: bedrock_chat2.php still contains the historical bridge,
// but it cannot be entered by an ordinary chat turn because its all() snapshot
// forces task_orchestrator=false. Task Center remains active through enabled().
$check(str_contains($chat,"pipelineEffective['task_orchestrator']")&&str_contains($chat,'ChatTaskBridge'),'legacy bridge remains available for historical compatibility without being the normal chat path');

echo"Result: {$passed} passed, {$failed} failed\n";
exit($failed?1:0);
