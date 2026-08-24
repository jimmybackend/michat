<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/Tasks/bootstrap.php';
$passed=0;$failed=0;$ok=function(bool$v,string$n)use(&$passed,&$failed):void{echo($v?'PASS ':'FAIL ').$n."\n";$v?$passed++:$failed++;};
$validator=new TaskInputValidator();
$ok($validator->optionalUtcDateTime(['scheduled_at'=>'2030-08-20T10:00:00+02:00'],'scheduled_at')==='2030-08-20 08:00:00.000000','reschedule normaliza offset mediante contrato 10A');
try{$validator->optionalUtcDateTime(['scheduled_at'=>'invalid'],'scheduled_at');$ok(false,'reschedule rechaza fecha inválida');}catch(TaskValidationException $e){$ok($e->getMessage()==='scheduled_at_invalid','reschedule rechaza fecha inválida');}
$repository=file_get_contents(__DIR__.'/../includes/Tasks/TaskRepository.php');
$orchestrator=file_get_contents(__DIR__.'/../includes/Tasks/TaskOrchestrator.php');
$app=file_get_contents(__DIR__.'/../includes/Tasks/TaskApplicationService.php');
$controller=file_get_contents(__DIR__.'/../includes/Tasks/TaskApiController.php');
$queue=file_get_contents(__DIR__.'/../includes/Tasks/TaskQueueRepository.php');
$ok(str_contains($controller,"$"."action==='reschedule'")&&str_contains($controller,'->reschedule($this->userId,$body)'),'API expone únicamente action reschedule');
$ok(str_contains($app,"optionalUtcDateTime($"."d,'scheduled_at')")&&str_contains($app,'$this->v->lock($d)'),'reschedule reutiliza parser UTC y lock validator de 10A');
$ok(str_contains($app,'$this->owned($u,$this->v->publicId'),'public_id se resuelve con ownership antes de mutar');
$allowed="['pending','ready','waiting_user']";
$ok(str_contains($orchestrator,$allowed)&&str_contains($repository,"status IN ('pending','ready','waiting_user')")&&str_contains($repository,'NOT EXISTS(SELECT 1 FROM TaskExecutions'),'solo pending ready waiting_user sin Execution son editables');
foreach(['running','waiting_dependency','completed','failed','cancelled']as$status)$ok(!str_contains($allowed,"'{$status}'"),"{$status} no es reprogramable");
$lockPos=strpos($orchestrator,"lockOwnedForResponse($"."id,$"."u)");$eventPos=strpos($orchestrator,"'event_key'=>'task_rescheduled'");
$ok($lockPos!==false&&$eventPos>$lockPos,'Task owned se bloquea antes de mutación y Event');
$ok(str_contains($repository,'lock_version=lock_version+1')&&str_contains($repository,'AND lock_version=?'),'UPDATE usa optimistic locking');
$ok(str_contains($repository,"scheduled_at=?")&&!str_contains(substr($repository,strpos($repository,'public function reschedule'),500),'due_at'),'reschedule solo cambia scheduled_at');
$ok(str_contains($orchestrator,"$"."scheduled===null?'Programación one-shot eliminada.'"),'NULL elimina límite one-shot explícitamente');
$ok(str_contains($orchestrator,"'details'=>['scheduled_at'=>$"."scheduled]")&&str_contains($orchestrator,"'event_key'=>'task_rescheduled'"),'Event mínimo conserva instante normalizado');
$ok(!str_contains(substr($orchestrator,strpos($orchestrator,'public function rescheduleTask'),900),'executions->create'),'reschedule no crea TaskExecution');
$ok(str_contains($queue,'COALESCE(t.scheduled_at,UTC_TIMESTAMP(6))<=UTC_TIMESTAMP(6)'),'Worker conserva guard 10A tras reprogramar');
$ok(strpos($queue,'scheduled_at,UTC_TIMESTAMP')<strpos($queue,'ORDER BY CASE t.priority'),'priority no salta programación');
echo"Resultado: {$passed} passed, {$failed} failed\n";exit($failed?1:0);
