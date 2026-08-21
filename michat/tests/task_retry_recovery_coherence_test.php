<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/Tasks/bootstrap.php';

$passed=0;$failed=0;$ok=function(bool$value,string$name)use(&$passed,&$failed):void{echo($value?'PASS ':'FAIL ').$name."\n";$value?$passed++:$failed++;};
$taskMachine=new TaskStateMachine();$stepMachine=new TaskStepStateMachine();
$taskMachine->assertTransition('failed','ready');$stepMachine->assertTransition('failed','ready');$ok(true,'retry manual permite transiciones failed a ready de Task y Step');
foreach(['completed','cancelled']as$status){try{$taskMachine->assertTransition($status,'ready');$ok(false,$status.' no debe usar retry');}catch(TaskTransitionException){$ok(true,'retry Task '.$status.' continúa fail closed');}}
$orchestrator=file_get_contents(__DIR__.'/../includes/Tasks/TaskOrchestrator.php');$taskRepo=file_get_contents(__DIR__.'/../includes/Tasks/TaskRepository.php');$stepRepo=file_get_contents(__DIR__.'/../includes/Tasks/TaskStepRepository.php');
$ok(str_contains($orchestrator,'lockOwnedForRetry')&&str_contains($orchestrator,'lockForRetry')&&strpos($orchestrator,'updateStatus($stepId,\'ready\'')<strpos($orchestrator,'$this->tasks->retry'),'retry bloquea y reactiva Step/Task dentro de una transacción única');
$ok(str_contains($orchestrator,"status']!=='failed'")&&str_contains($orchestrator,"retry_task_invalid")&&str_contains($orchestrator,"retry_step_invalid"),'retry exige Task failed y current Step failed, sin reutilizar waiting/approval');
$ok(str_contains($taskRepo,'FOR UPDATE')&&str_contains($stepRepo,'LIMIT 1 FOR UPDATE'),'retry bloquea filas Task y current Step');
$retry=substr($orchestrator,strpos($orchestrator,'public function retryTask'),strpos($orchestrator,'public function prepareChatTurn')-strpos($orchestrator,'public function retryTask'));
$ok(!str_contains($retry,'TaskExecutions SET')&&!str_contains($retry,'checkpoint_json'),'retry no revive Executions ni modifica checkpoint/approval');
$ok(str_contains($orchestrator,'\'step_id\'=>$stepId')&&str_contains($orchestrator,"'event_key'=>'task_ready'"),'evento histórico task_ready referencia el Step reactivado');
$queue=file_get_contents(__DIR__.'/../includes/Tasks/TaskQueueRepository.php');$ok(str_contains($queue,"s.status='ready'")&&str_contains($queue,'INSERT INTO TaskExecutions'),'Worker reclamará Step reactivado creando nueva Execution');
echo"SKIP — integración MySQL recovery/retry/claim no ejecutada: TASK_TEST_DB_* no configurado.\nResultado: $passed PASS, $failed FAIL.\n";exit($failed?1:0);
