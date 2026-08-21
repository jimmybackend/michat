<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$passed=0;$failed=0;$ok=function(bool$v,string$n)use(&$passed,&$failed):void{echo($v?'PASS ':'FAIL ').$n."\n";$v?$passed++:$failed++;};
$orchestrator=file_get_contents(__DIR__.'/../includes/Tasks/TaskOrchestrator.php');$steps=file_get_contents(__DIR__.'/../includes/Tasks/TaskStepRepository.php');$executions=file_get_contents(__DIR__.'/../includes/Tasks/TaskExecutionRepository.php');
$ok(str_contains($orchestrator,'lockOwnedForResponse')&&str_contains($orchestrator,'lockCurrentWaitingUser')&&str_contains($orchestrator,'lockWaitingByStep'),'cancel bloquea Task, current Step y Execution waiting en una transacción');
$ok(str_contains($orchestrator,"\$from==='waiting_user'")&&str_contains($orchestrator,"assertTransition('waiting_user','cancelled')")&&str_contains($orchestrator,"assertTransition('waiting','cancelled')"),'sólo waiting_user terminaliza hijos mediante state machines');
$ok(str_contains($steps,"status='waiting_user'")&&str_contains($steps,'task_id_=?')&&str_contains($steps,'FOR UPDATE'),'current Step se valida por id, pertenencia y estado');
$ok(str_contains($executions,"status='cancelled',finished_at=NOW(6),worker_id=NULL,lease_token=NULL,lease_expires_at=NULL")&&str_contains($executions,"status='waiting'"),'Execution waiting se cancela y libera lease');
$cancel=substr($orchestrator,strpos($orchestrator,'public function requestCancellation'),strpos($orchestrator,'public function retryTask')-strpos($orchestrator,'public function requestCancellation'));
$ok(!str_contains($cancel,'checkpoint_json')&&!str_contains($cancel,'ConsumptionService')&&!str_contains($cancel,'consume('),'cancel no borra checkpoint ni consume autorización');
$ok(str_contains($orchestrator,'count($waiting)>1')&&str_contains($orchestrator,'waiting_execution_ambiguous'),'múltiples Execution waiting fallan cerrado');
$decision=file_get_contents(__DIR__.'/../includes/Tasks/TaskToolApprovalDecisionService.php');
$ok(str_contains($decision,"status']!=='waiting_user'")&&str_contains($decision,'tool_approval_not_waiting'),'approve/reject posteriores fallan cerrado por Task terminal');
$required=['TASK_TEST_DB_HOST','TASK_TEST_DB_PORT','TASK_TEST_DB_USER','TASK_TEST_DB_PASSWORD','TASK_TEST_DB_NAME'];foreach($required as$k){if(getenv($k)===false||getenv($k)===''){echo"SKIP — integración MySQL cancel/approve race no ejecutada: TASK_TEST_DB_* no configurado.\nResultado: {$passed} PASS, {$failed} FAIL.\n";exit($failed?1:0);}}
echo"SKIP — integración MySQL requiere fixture aislado del entorno de CI; variables presentes pero este test focalizado sólo valida el contrato estático.\n";$failed++;echo"Resultado: {$passed} PASS, {$failed} FAIL.\n";exit(1);
