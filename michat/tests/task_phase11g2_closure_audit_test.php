<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dump=file_get_contents($root.'/../adbbmis1_Cloud.sql');$bootstrap=file_get_contents($root.'/includes/Tasks/bootstrap.php');$worker=file_get_contents($root.'/includes/Tasks/TaskWorker.php');$taskCenter=file_get_contents($root.'/task_center.php');$api=file_get_contents($root.'/includes/Tasks/TaskApiController.php');$read=file_get_contents($root.'/includes/Tasks/TaskCenterAutonomyReadService.php');$control=file_get_contents($root.'/includes/Tasks/TaskCenterAutonomyControlService.php');$policy=file_get_contents($root.'/includes/Tasks/AutonomyPolicy.php');$budget=file_get_contents($root.'/includes/Tasks/AutonomyBudgetRepository.php');$continuation=file_get_contents($root.'/includes/Tasks/PostTaskContinuationRepository.php');$proposal=file_get_contents($root.'/includes/Tasks/NextWorkProposal.php');$replan=file_get_contents($root.'/includes/Tasks/TaskReplanRepository.php');$docs=file_get_contents($root.'/doc/estado-actual.md').file_get_contents($root.'/doc/fase11-safe-single-turn-inference.md').file_get_contents($root.'/doc/fase11f1-task-center-observability.md');
$migrations=[];foreach(['fase11b_project_autonomy.sql','fase11c_next_work_proposals.sql','fase11d_post_task_continuations.sql','fase11e0_replan_checkpoint.sql','fase11e1_versioned_replanning.sql','fase11f2_hitl_controls.sql']as$file)$migrations[$file]=file_get_contents($root.'/sql/'.$file);
$checks=[
'single TaskWorker class'=>count(glob($root.'/includes/Tasks/TaskWorker.php'))===1&&substr_count($worker,'final class TaskWorker')===1,
'single Orchestrator class'=>substr_count(file_get_contents($root.'/includes/Tasks/TaskOrchestrator.php'),'class TaskOrchestrator')===1,
'single planning architecture'=>substr_count(file_get_contents($root.'/includes/Tasks/TaskPlanningService.php'),'class TaskPlanningService')===1&&substr_count(file_get_contents($root.'/includes/Tasks/AiTaskPlanner.php'),'class AiTaskPlanner')===1,
'shared inference'=>str_contains(file_get_contents($root.'/includes/Tasks/NextWorkEvaluator.php'),'SingleTurnInferenceInterface')&&str_contains(file_get_contents($root.'/includes/Chat/BedrockChatRuntime.php'),'BedrockConverseClientInterface'),
'official Task Center'=>str_contains($taskCenter,'js/task-center.js')&&!file_exists($root.'/autonomy.php')&&!file_exists($root.'/agent_center.php'),
'policy modes/statuses'=>str_contains($policy,"MODES=['disabled','supervised','automatic']")&&str_contains($policy,"STATUSES=['active','paused','stopped']"),
'all enforceable budgets'=>array_reduce(['decisions','tasks','replans','input_tokens','output_tokens','tool_calls','write_tool_calls','runtime_seconds'],fn($ok,$x)=>$ok&&str_contains($budget,"'$x'"),true)&&str_contains($policy,'max_descendant_depth'),
'cost remains non-enforceable'=>str_contains($policy,"'enforceable'=>false")&&str_contains($docs,'NOT YET ENFORCEABLE'),
'continuation states'=>array_reduce(['pending','processing','completed','waiting_user','waiting_approval','failed'],fn($ok,$x)=>$ok&&str_contains($dump,"'$x'"),true),
'proposal states'=>array_reduce(['pending_approval','authorized','spawning','spawned','rejected','failed'],fn($ok,$x)=>$ok&&str_contains($proposal,"='$x'"),true),
'replan states'=>str_contains($dump,"enum('checkpointed','processing','proposed','pending_approval','approved','applied','rejected','failed')"),
'replan excludes continuation'=>str_contains($continuation,"rr.status IN ('checkpointed','processing','proposed','pending_approval','approved')"),
'replan apply atomic and historical'=>str_contains($replan,'begin_transaction')&&str_contains($replan,'TaskPlanRevisionSteps')&&!preg_match('/DELETE FROM (?:TaskSteps|TaskPlanRevisions)/i',$replan),
'bounded worker then normal claim'=>str_contains($worker,'$this->replans->processTick')&&str_contains($worker,'$this->continuations->processTick')&&str_contains($worker,'$this->claims->claim()'),
'Task Center read/write boundaries'=>str_contains($read,'side-effect-free')&&str_contains($control,'Task-public-id command boundary'),
'all autonomy writes POST CSRF'=>strpos($api,'CsrfGuard::assertSessionToken')<strpos($api,"\$action==='autonomy_policy_update'"),
'all migrations present'=>!in_array(false,$migrations,true),
'11B schema parity'=>str_contains($migrations['fase11b_project_autonomy.sql'],'ProjectAutonomyPolicies')&&str_contains($dump,'ProjectAutonomyPolicies')&&str_contains($dump,'uq_project_autonomy_cycle_active'),
'11C schema parity'=>str_contains($migrations['fase11c_next_work_proposals.sql'],'NextWorkProposals')&&str_contains($dump,'uq_next_work_proposal_dedupe'),
'11D schema parity'=>str_contains($migrations['fase11d_post_task_continuations.sql'],'PostTaskContinuations')&&str_contains($dump,'uq_post_task_continuation_logical'),
'11E schema parity'=>str_contains($migrations['fase11e0_replan_checkpoint.sql'],'TaskReplanRequests')&&str_contains($migrations['fase11e1_versioned_replanning.sql'],'TaskPlanRevisionSteps')&&str_contains($dump,'uq_task_plan_revision_number'),
'11F schema parity'=>array_reduce(['`answer`','`answered_at`','`answered_by_user_id_`'],fn($ok,$x)=>$ok&&str_contains($migrations['fase11f2_hitl_controls.sql'],$x)&&str_contains($dump,$x),true),
'no sensitive autonomy output'=>!str_contains($read,'system_instruction')&&!str_contains($read,'user_prompt_template')&&!str_contains($read,'lease_token'),
'closure status honest'=>str_contains($docs,'FASE 11 — PRE-MERGE PASS / READY TO MERGE')&&str_contains($docs,'11G.2 closure audit')&&!str_contains($docs,'FASE 11 — MERGED'),
];foreach($checks as$name=>$ok){if(!$ok){fwrite(STDERR,"FAIL: $name\n");exit(1);}}echo'task_phase11g2_closure_audit_test: PASS ('.count($checks)." checks)\n";
