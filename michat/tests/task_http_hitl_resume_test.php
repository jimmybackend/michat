<?php
declare(strict_types=1);if(PHP_SAPI!=='cli')exit(1);
$orchestrator=file_get_contents(__DIR__.'/../includes/Tasks/TaskOrchestrator.php');$executions=file_get_contents(__DIR__.'/../includes/Tasks/TaskExecutionRepository.php');$chat=file_get_contents(__DIR__.'/../bedrock_chat2.php');
$checks=[
 str_contains($orchestrator,'TaskClaimAttemptBudget')&&str_contains($orchestrator,'historyByStep'),
 str_contains($orchestrator,'lockOwnedForResponse')&&str_contains($orchestrator,'lockForRetry'),
 str_contains($executions,'attempt_number,agent_key')&&str_contains($executions,'$attempt'),
 str_contains($chat,'resumeApproved(')&&str_contains($chat,'TaskSyncHttpPauseResponse::fromResult'),
 substr_count($chat,'resumeApproved(')===1,
];foreach($checks as$i=>$ok)echo($ok?'PASS ':'FAIL ').'HTTP HITL resume '.($i+1)."\n";exit(in_array(false,$checks,true)?1:0);
