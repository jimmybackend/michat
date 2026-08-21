<?php
declare(strict_types=1);if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/Tasks/TaskExceptions.php';require_once __DIR__.'/../includes/Tasks/TaskExecutionBudget.php';
$pass=0;$fail=0;$ok=function($v,$n)use(&$pass,&$fail){echo($v?'PASS ':'FAIL ').$n."\n";$v?$pass++:$fail++;};$throws=function(callable$f,string$code)use($ok){try{$f();$ok(false,$code);}catch(TaskTransitionException$e){$ok($e->getMessage()===$code,$code);}};
$b=new TaskExecutionBudget(2,2,1,10,10,20,60);$b->beforeModelRound();$b->beforeModelRound();$b->recordUsage(5,5);$b->recordUsage(5,5);$b->beforeTool('read_only');$b->beforeTool('idempotent_write');$ok(true,'límites exactos permitidos');$throws(fn()=>$b->beforeModelRound(),'task_budget_model_rounds_exceeded');$throws(fn()=>$b->beforeTool('read_only'),'task_budget_tool_calls_exceeded');
$throws(function(){($b=new TaskExecutionBudget(1,2,1,10,10,20,60))->recordUsage(11,0);},'task_budget_input_tokens_exceeded');$throws(function(){($b=new TaskExecutionBudget(1,2,1,10,10,15,60))->recordUsage(10,6);},'task_budget_total_tokens_exceeded');$throws(function(){$b=new TaskExecutionBudget(1,2,1,10,10,20,60);$b->beforeTool('non_idempotent');$b->beforeTool('idempotent_write');},'task_budget_writes_exceeded');
exit($fail?1:0);
