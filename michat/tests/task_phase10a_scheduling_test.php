<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/Tasks/bootstrap.php';
$passed=0;$failed=0;
$ok=function(bool $value,string $name)use(&$passed,&$failed):void{echo($value?'PASS ':'FAIL ').$name."\n";$value?$passed++:$failed++;};
$validator=new TaskInputValidator();
$ok($validator->optionalUtcDateTime([],'scheduled_at')===null,'scheduled_at ausente es NULL');
$ok($validator->optionalUtcDateTime(['scheduled_at'=>null],'scheduled_at')===null,'scheduled_at nullable');
$ok($validator->optionalUtcDateTime(['scheduled_at'=>'2030-08-20T10:00:00.123+02:00'],'scheduled_at')==='2030-08-20 08:00:00.123000','offset se normaliza a UTC datetime(6)');
foreach(['2030-08-20 10:00:00','not-a-date','2030-02-30T10:00:00Z'] as $invalid){try{$validator->optionalUtcDateTime(['scheduled_at'=>$invalid],'scheduled_at');$ok(false,'fecha inválida rechazada');}catch(TaskValidationException $e){$ok($e->getMessage()==='scheduled_at_invalid','fecha inválida rechazada');}}
$now=new DateTimeImmutable('2030-08-20T08:00:00Z');
$ok(TaskScheduleEligibility::isEligible(['scheduled_at'=>null],$now),'NULL es elegible');
$ok(TaskScheduleEligibility::isEligible(['scheduled_at'=>'2030-08-20 07:59:59.999999'],$now),'pasado es elegible');
$ok(TaskScheduleEligibility::isEligible(['scheduled_at'=>'2030-08-20 08:00:00.000000'],$now),'presente es elegible');
$ok(!TaskScheduleEligibility::isEligible(['scheduled_at'=>'2030-08-20 08:00:00.000001'],$now),'futuro no es elegible');
try{TaskScheduleEligibility::assertEligible(['scheduled_at'=>'2030-08-20 08:00:00.000001'],$now);$ok(false,'guard sync rechaza futuro');}catch(TaskTransitionException $e){$ok($e->getMessage()==='task_not_yet_scheduled','guard sync rechaza futuro');}
$repo=file_get_contents(__DIR__.'/../includes/Tasks/TaskRepository.php');
$app=file_get_contents(__DIR__.'/../includes/Tasks/TaskApplicationService.php');
$orchestrator=file_get_contents(__DIR__.'/../includes/Tasks/TaskOrchestrator.php');
$queue=file_get_contents(__DIR__.'/../includes/Tasks/TaskQueueRepository.php');
$ok(str_contains($repo,'max_attempts,scheduled_at')&&str_contains($repo,"$"."d['scheduled_at']??null"),'repository persiste scheduled_at sin romper callers');
$ok(str_contains($app,"optionalUtcDateTime($"."d,'scheduled_at')")&&str_contains($app,"'scheduled_at'=>$"."scheduled"),'application valida y propaga scheduled_at');
$ok(substr_count($orchestrator,'TaskScheduleEligibility::assertEligible($task)')===2,'todos los inicios sync tienen guard antes de Execution');
$beginPos=strpos($orchestrator,'public function beginChatExecution');$guardPos=strpos($orchestrator,'TaskScheduleEligibility::assertEligible($task)',$beginPos);$executionPos=strpos($orchestrator,'$this->executions->create(',$beginPos);
$ok($guardPos!==false&&$executionPos!==false&&$guardPos<$executionPos,'sync futuro se bloquea antes de crear Execution o cambiar estados');
$condition='COALESCE(t.scheduled_at,UTC_TIMESTAMP(6))<=UTC_TIMESTAMP(6)';
$ok(str_contains($queue,$condition),'claim async usa reloj UTC independiente de timezone de conexión');
$wherePos=strpos($queue,$condition);$orderPos=strpos($queue,'ORDER BY CASE t.priority');
$ok($wherePos!==false&&$orderPos!==false&&$wherePos<$orderPos,'fecha filtra antes que prioridad');
$ok(!str_contains($repo,'due_at')&&!str_contains($queue,'due_at'),'scheduled_at no altera semántica de due_at');
$ok(str_contains($orchestrator,"'event_key'=>'task_scheduled'")&&str_contains($orchestrator,"'summary'=>'Inicio one-shot programado en UTC.'"),'creación programada emite Event mínimo');
$ok(!str_contains($orchestrator,"'scheduled'")&&!str_contains($queue,'WaitTaskStepExecutor'),'no crea estado scheduled ni Wait artificial');
echo"Resultado: {$passed} passed, {$failed} failed\n";exit($failed?1:0);
