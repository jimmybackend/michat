<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$repository=file_get_contents(__DIR__.'/../includes/Tasks/TaskRepository.php');
$service=file_get_contents(__DIR__.'/../includes/Tasks/TaskApplicationService.php');
$dependencies=file_get_contents(__DIR__.'/../includes/Tasks/TaskDependencyService.php');
$dependencyRepository=file_get_contents(__DIR__.'/../includes/Tasks/TaskDependencyRepository.php');
$eventRepository=file_get_contents(__DIR__.'/../includes/Tasks/TaskEventRepository.php');
$executionRepository=file_get_contents(__DIR__.'/../includes/Tasks/TaskExecutionRepository.php');
$checks=[
    str_contains($repository,'LEFT JOIN TaskSteps current')&&str_contains($repository,'current.id_=Tasks.current_step_id_')&&str_contains($repository,"ownedFilters(\$u,\$f,'Tasks.')"),
    str_contains($service,"\$dto['current_step']")&&str_contains($service,"'wait_until'=>\$this->waitUntil")&&!str_contains($service,"'checkpoint_json'=>"),
    str_contains($service,"\$type!=='wait'")&&str_contains($service,"preg_match('/^\\d{4}-\\d{2}-\\d{2}"),
    str_contains($dependencyRepository,'owner.user_id_=? AND required.user_id_=?'),
    str_contains($dependencyRepository,'function listOwnedDependents')&&str_contains($dependencyRepository,'required.user_id_=? AND dependent.user_id_=?')&&str_contains($dependencyRepository,'dependent.public_id dependent_public_id'),
    str_contains($service,"'dependents'=>\$this->dependencies->listOwnedDependents(\$id,\$u)"),
    str_contains($dependencies,"dependency_self")&&str_contains($dependencies,"dependency_duplicate")&&str_contains($dependencies,"dependency_cycle")&&str_contains($dependencies,"dependency_scope_invalid"),
    str_contains($dependencies,"findOwnedByPublicId(\$public,\$user)")&&str_contains($dependencies,"terminal_any"),
    str_contains($eventRepository,'ORDER BY e.id_ DESC LIMIT ?')&&str_contains($eventRepository,'t.user_id_=?'),
    str_contains($executionRepository,'LEFT JOIN TaskSteps')&&str_contains($executionRepository,'t.user_id_=?'),
    !str_contains($service,"'details_json'")&&str_contains($service,"'history'=>['limit'=>100"),
];
foreach($checks as$i=>$ok)echo($ok?'PASS ':'FAIL ').'Task operational contract '.($i+1)."\n";
exit(in_array(false,$checks,true)?1:0);
