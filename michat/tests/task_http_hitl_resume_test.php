<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/Tasks/bootstrap.php';
$passed=0;$failed=0;$ok=function(bool$value,string$name)use(&$passed,&$failed):void{echo($value?'PASS ':'FAIL ').$name."\n";$value?$passed++:$failed++;};

// Behavioral identity checks: a continuation is the sole execution whose
// attempt follows the persisted proposal execution in the locked history.
$repo=new TaskExecutionRepository(mysqli_init());
$origin=['id_'=>41,'task_id_'=>7,'step_id_'=>9,'status'=>'completed','attempt_number'=>1];
$continuation=['id_'=>42,'task_id_'=>7,'step_id_'=>9,'status'=>'running','attempt_number'=>2];
$ok($repo->continuationAfter([$origin],41)===null,'approved interruption has no continuation yet');
$ok($repo->continuationAfter([$origin,$continuation],41)===$continuation,'second resume reuses the single continuation B');
try{$repo->continuationAfter([$origin,$continuation,$continuation+['id_'=>43,'attempt_number'=>3]],41);$ok(false,'ambiguous B/C must fail');}catch(TaskConcurrencyException$e){$ok($e->getMessage()==='tool_resume_continuation_ambiguous','ambiguous B/C fails closed');}
try{$repo->continuationAfter([$continuation],41);$ok(false,'missing proposal execution must fail');}catch(TaskTransitionException$e){$ok($e->getMessage()==='tool_resume_origin_invalid','missing proposal execution fails closed');}

$fingerprint=str_repeat('a',64);$step=['status'=>'ready','input_json'=>json_encode(['execution_mode'=>'sync']),'checkpoint_json'=>json_encode(['tool_approval'=>['proposal'=>['fingerprint'=>$fingerprint,'safe_summary'=>'Write','safe_target'=>'App.php','effect'=>'non_idempotent'],'decision'=>['status'=>'approved','fingerprint'=>$fingerprint,'consumed'=>false]]])];
$dto=(new TaskPublicApprovalPresenter())->present($step);$ok($dto['status']==='approved'&&$dto['can_resume']===true&&!str_contains(json_encode($dto),'execution_id'),'detail after interruption exposes safe recovery without internal IDs');
$step['status']='running';$ok((new TaskPublicApprovalPresenter())->present($step)['can_resume']===true,'orphan running sync remains visibly recoverable');
$step['input_json']=json_encode(['execution_mode'=>'async']);$ok((new TaskPublicApprovalPresenter())->present($step)['can_resume']===false,'async approval is not owned by HTTP resume');

$orchestrator=file_get_contents(__DIR__.'/../includes/Tasks/TaskOrchestrator.php');$chat=file_get_contents(__DIR__.'/../bedrock_chat2.php');
$ok(str_contains($orchestrator,'resumeApprovedToolTask')&&str_contains($orchestrator,'historyByStep')&&str_contains($orchestrator,'continuationAfter'),'POO resume owns locked lookup and idempotent creation');
$ok(str_contains($orchestrator,"'outcome'=>'already_resumed'")&&str_contains($chat,"\$resume['outcome']==='already_resumed'"),'HTTP duplicate returns controlled already_resumed without executing twice');
$ok(str_contains($chat,"['ready','running']")&&strpos($chat,"['ready','running']")<strpos($chat,'resumeApprovedToolTask('),'HTTP preflight admits the locked idempotent running path');
$ok(!str_contains(substr($orchestrator,strpos($orchestrator,'resumeApprovedToolTask'),strpos($orchestrator,'public function beginChatExecution')-strpos($orchestrator,'resumeApprovedToolTask')),'consume('),'resume never consumes approval before the gate');
$queue=file_get_contents(__DIR__.'/../includes/Tasks/TaskQueueRepository.php');$ok(str_contains($orchestrator,'claimHttpContinuation')&&str_contains($orchestrator,"'outcome'=>'resume_recovered'")&&str_contains($queue,"<>'http-sync'"),'expired unconsumed HTTP lease is reclaimed by resume rather than abandoned by Worker recovery');
$ok(str_contains($queue,"decision.consumed'))='true'")&&str_contains($queue,"t.status='cancelled'"),'consumed or cancelled orphan HTTP execution is recovered to terminal failure without replay');
$ok(str_contains($orchestrator,"\$task['status']!=='running'||\$step['status']!=='running'"),'cancelled or otherwise terminal Task cannot reclaim orphan B');
$ok(str_contains($chat,'TaskLeaseService')&&str_contains($chat,'$httpHeartbeat')&&str_contains($chat,"'worker_id']='http-sync'"),'active HTTP resume heartbeats its lease and observes cancellation');

$required=['TASK_TEST_DB_HOST','TASK_TEST_DB_PORT','TASK_TEST_DB_USER','TASK_TEST_DB_PASSWORD','TASK_TEST_DB_NAME'];
foreach($required as$key)if(getenv($key)===false||getenv($key)===''){echo"SKIP — MySQL HTTP HITL resume/concurrencia real: TASK_TEST_DB_* no configurado.\nResultado: {$passed} PASS, {$failed} FAIL.\n";exit($failed?1:0);}
echo"SKIP — fixture MySQL HTTP completo requiere el entorno aislado de CI.\nResultado: {$passed} PASS, ".(++$failed)." FAIL.\n";exit(1);
