<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$read=(string)file_get_contents($root.'/michat/includes/Tasks/TaskCenterAutonomyReadService.php');
$policy=(string)file_get_contents($root.'/michat/includes/Tasks/AutonomyPolicyRepository.php');
$planner=(string)file_get_contents($root.'/michat/includes/Tasks/AiTaskPlanner.php');
$validator=(string)file_get_contents($root.'/michat/includes/Tasks/TaskPlanValidator.php');
$queue=(string)file_get_contents($root.'/michat/includes/Tasks/TaskQueueRepository.php');
$activity=(string)file_get_contents($root.'/michat/includes/Chat/ChatActivityTelemetryService.php');
$memory=(string)file_get_contents($root.'/michat/includes/Chat/ChatMemoryFinalizationService.php');
$codeEdit=(string)file_get_contents($root.'/michat/includes/Tools/CodeEditService.php');
$js=(string)file_get_contents($root.'/michat/js/task-center.js');
$passed=0;$failed=0;$check=static function(bool$ok,string$label)use(&$passed,&$failed){echo($ok?'PASS ':'FAIL ').$label."\n";$ok?$passed++:$failed++;};

$check(!str_contains($read,'x.lock_version'),'Task Center detail never selects nonexistent continuation lock_version');
$check(str_contains($policy,'LAST_INSERT_ID(ProjectAutonomyPolicies.id_)'),'autonomy policy upsert qualifies id_ in duplicate-key path');
$check(str_contains($planner,'model, tool, approval, wait, validation, finalize')&&!str_contains($planner,'plan, model, tool, approval, wait, validation, finalize'),'AI planner advertises only executable Step types');
$check(str_contains($validator,"['model','tool','approval','wait','validation','finalize']")&&!str_contains($validator,"['plan','model'"),'validator rejects planner-generated plan Steps');
$check(str_contains($queue,'$affected>0')&&str_contains($queue,'ownedRunning'),'heartbeat accepts multi-table affected rows while revalidating ownership on zero');
$check(str_contains($activity,", 0, 50")&&str_contains($memory,", 0, 52")&&(str_contains($codeEdit,",0,46")||str_contains($codeEdit,", 0, 46")),'all dynamic MySQL advisory-lock names stay within 64 characters');
$check(str_contains($js,"p.mode==='disabled'")&&str_contains($js,'Modo disabled: selecciona Supervised o Automatic'),'Task Center does not offer cycle start while autonomy mode is disabled');

echo"Result: {$passed} passed, {$failed} failed\n";exit($failed?1:0);
