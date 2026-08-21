<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/Tasks/bootstrap.php';

$passed=0;$failed=0;$ok=function(bool$value,string$name)use(&$passed,&$failed):void{echo($value?'PASS ':'FAIL ').$name."\n";$value?$passed++:$failed++;};
$budget=new TaskClaimAttemptBudget();$task=10;$step=20;
$row=static fn(int$id,string$status,int$attempt=1):array=>['id_'=>$id,'task_id_'=>10,'step_id_'=>20,'status'=>$status,'attempt_number'=>$attempt];
$proposal=new TaskToolApprovalProposal(1,'code_edit','non_idempotent','write_requires_approval','Modificar archivo','App.php',str_repeat('a',64));
$checkpoint=static function(int$origin,bool$consumed=false,int$consumer=0)use($proposal):string{$decision=['status'=>'approved','fingerprint'=>$proposal->fingerprint,'consumed'=>$consumed];if($consumed)$decision+=['consumer_execution_id'=>$consumer,'consumed_at'=>'2026-08-21 00:00:00.000000'];return json_encode(['tool_approval'=>['format_version'=>1,'identity'=>(new TaskToolApprovalIdentity($origin))->toArray(),'proposal'=>$proposal->toArray(),'decision'=>$decision]],JSON_THROW_ON_ERROR);};
$ok($budget->nextAttemptNumber($task,$step,1,[],null)===1,'primera Execution usa attempt_number 1');
$ok($budget->nextAttemptNumber($task,$step,1,[$row(101,'completed')],$checkpoint(101))===2,'proponente HITL approved permite continuation B con attempt_number 2 y max_attempts 1');
foreach(['completed','failed','abandoned']as$status){try{$budget->nextAttemptNumber($task,$step,1,[$row(101,$status)],null);$ok(false,$status.' normal no debe obtener bypass');}catch(TaskTransitionException$e){$ok($e->getMessage()==='attempt_limit',$status.' normal consume budget');}}
try{$budget->nextAttemptNumber($task,$step,1,[$row(101,'completed')],$checkpoint(101,true,101));$ok(false,'approval consumida no permite C');}catch(TaskTransitionException$e){$ok($e->getMessage()==='attempt_limit','consumed=true no obtiene bypass');}
foreach(['{"tool_approval":','{}',$checkpoint(999)]as$invalid){try{$budget->nextAttemptNumber($task,$step,1,[$row(101,'completed')],$invalid);$ok(false,'approval inválida no permite bypass');}catch(TaskTransitionException$e){$ok($e->getMessage()==='attempt_limit','checkpoint/identity inválida falla cerrada');}}
try{$budget->nextAttemptNumber($task,$step,1,[$row(101,'completed'),$row(102,'failed',2)],$checkpoint(101));$ok(false,'approval vieja no permite Execution posterior');}catch(TaskTransitionException$e){$ok($e->getMessage()==='attempt_limit','proposal_execution_id debe ser la Execution histórica más reciente');}
$queue=file_get_contents(__DIR__.'/../includes/Tasks/TaskQueueRepository.php');$ok(str_contains($queue,'s.checkpoint_json')&&str_contains($queue,'TaskClaimAttemptBudget')&&str_contains($queue,'INSERT INTO TaskExecutions')&&!str_contains(file_get_contents(__DIR__.'/../includes/Tasks/TaskClaimAttemptBudget.php'),'code_edit'),'claim usa checkpoint bloqueado sin depender del nombre de Tool');
$ok(str_contains($queue,'FOR UPDATE SKIP LOCKED')&&str_contains($queue,"NOT EXISTS(SELECT 1 FROM TaskExecutions e")&&str_contains($queue,"status='ready'"),'selección concurrente y estados ready permanecen intactos');
echo"SKIP — integración MySQL A/B y dos Workers: TASK_TEST_DB_* no configurado.\nResultado: $passed PASS, $failed FAIL.\n";exit($failed?1:0);
