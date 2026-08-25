<?php
declare(strict_types=1);
$root=dirname(__DIR__);$repo=file_get_contents($root.'/includes/Tasks/PostTaskContinuationRepository.php');$service=file_get_contents($root.'/includes/Tasks/PostTaskContinuationService.php');$proposal=file_get_contents($root.'/includes/Tasks/NextWorkProposalService.php');$replan=file_get_contents($root.'/includes/Tasks/TaskReplanRepository.php');$control=file_get_contents($root.'/includes/Tasks/TaskCenterAutonomyControlService.php');$api=file_get_contents($root.'/includes/Tasks/TaskApiController.php');$js=file_get_contents($root.'/js/task-center.js');$sql=file_get_contents($root.'/sql/fase11f2_hitl_controls.sql');$dump=file_get_contents($root.'/../adbbmis1_Cloud.sql');
$emptyAnswer="\$answer===''";
$checks=[
'answer schema'=>str_contains($sql,'`answer` varchar(2000)')&&str_contains($dump,'`answered_at` datetime(6)')&&str_contains($dump,'answered_by_user_id_'),
'answer owned and locked'=>str_contains($repo,"pc.user_id_=? AND pc.project_id_=? AND t.public_id=? AND pc.public_id=? FOR UPDATE"),
'answer state'=>str_contains($repo,"status='pending'")&&str_contains($repo,"status='waiting_user'"),
'question not overwritten'=>!str_contains($repo,'SET answer=?,question='),
'answer bounded'=>str_contains($service,'mb_strlen($answer)>2000')&&str_contains($service,$emptyAnswer),
'answer idempotent'=>str_contains($repo,'hash_equals')&&str_contains($repo,"'idempotent'=>true"),
'different answer conflicts'=>str_contains($repo,'continuation_answer_conflict'),
'answer event'=>str_contains($repo,'continuation_user_answered'),
'answer reaches later evaluation'=>str_contains($service,"\$c['answer']")&&str_contains(file_get_contents($root.'/includes/Tasks/NextWorkEvaluator.php'),'HUMAN ANSWER (untrusted data)'),
'no chat persistence'=>!str_contains($repo,'ChatMessages')&&!str_contains($control,'ChatMessages'),
'proposal authoritative'=>str_contains($control,'$this->proposals->approve')&&str_contains($control,'$this->proposals->reject'),
'proposal HTTP defers spawn'=>str_contains($control,'$lock,true)')&&str_contains($proposal,'processApprovedBatch'),
'proposal worker path'=>str_contains($service,'processApprovedBatch($this->maxPerTick)'),
'replan authoritative'=>str_contains($control,'$this->replans->approveReplan')&&str_contains($control,'$this->replans->rejectReplan'),
'replan approval deferred'=>!str_contains(substr($replan,strpos($replan,'public function approve'),strpos($replan,'public function reject')-strpos($replan,'public function approve')),'return$this->apply'),
'replan idempotent approval'=>str_contains($replan,"['approved','applied']"),
'replan idempotent rejection'=>str_contains($replan,"status']==='rejected'"),
'csrf precedes HITL'=>strpos($api,'CsrfGuard::assertSessionToken')<strpos($api,'autonomy_continuation_answer'),
'five API operations'=>array_reduce(['autonomy_continuation_answer','autonomy_proposal_approve','autonomy_proposal_reject','autonomy_replan_approve','autonomy_replan_reject'],fn($ok,$x)=>$ok&&str_contains($api,$x),true),
'ask form conditional'=>str_contains($js,"c.status==='waiting_user'?`<form")&&str_contains($js,'maxlength="2000" required'),
'proposal buttons conditional'=>str_contains($js,"x.status==='pending_approval'?")&&str_contains($js,'autonomy_proposal_approve'),
'replan buttons conditional'=>str_contains($js,"r.status==='pending_approval'?")&&str_contains($js,'Aprobar nuevo plan'),
'tool separation copy'=>str_contains($js,'Aprobar el plan no aprueba Tools'),
'loading and double submit'=>str_contains($js,'if(busy.has(key))return')&&str_contains($js,"textContent='Guardando…'"),
'authoritative refresh'=>str_contains($js,'await detail(task.public_id)'),
'xss answer and proposed steps'=>str_contains($js,'${esc(c.answer)}')&&str_contains($js,'${esc(step.title||step.step_key)}'),
'no inline worker planner'=>!preg_match('/->(?:processTick|planRemaining|evaluateWithUsage)\(/',$control),
];foreach($checks as$name=>$ok){if(!$ok){fwrite(STDERR,"FAIL: $name\n");exit(1);}}echo'task_phase11f2b_hitl_test: PASS ('.count($checks)." checks)\n";
