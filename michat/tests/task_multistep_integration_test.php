<?php
declare(strict_types=1);

$required=['TASK_TEST_DB_HOST','TASK_TEST_DB_PORT','TASK_TEST_DB_USER','TASK_TEST_DB_PASSWORD','TASK_TEST_DB_NAME'];
foreach($required as$key){if(getenv($key)===false||getenv($key)===''){echo"SKIP — integración MySQL Multi-Step no ejecutada: TASK_TEST_DB_* no configurado.\n";exit(0);}}
$database=(string)getenv('TASK_TEST_DB_NAME');
if(!preg_match('/(^|[_-])(test|testing)([_-]|$)/i',$database)){fwrite(STDERR,"FAIL — TASK_TEST_DB_NAME no parece una base aislada de tests.\n");exit(1);}
if(!extension_loaded('mysqli')){fwrite(STDERR,"FAIL — mysqli no disponible.\n");exit(1);}

require_once __DIR__.'/../includes/Tasks/bootstrap.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli((string)getenv('TASK_TEST_DB_HOST'),(string)getenv('TASK_TEST_DB_USER'),(string)getenv('TASK_TEST_DB_PASSWORD'),$database,(int)getenv('TASK_TEST_DB_PORT'));
$db->set_charset('utf8mb4');
foreach(['Users','ChatSessions','Tasks','TaskSteps','TaskExecutions','TaskEvents']as$table){$safe=$db->real_escape_string($table);$result=$db->query("SHOW TABLES LIKE '{$safe}'");if($result->num_rows!==1){fwrite(STDERR,"FAIL — falta tabla {$table} en DB de test.\n");exit(1);}}

$passed=0;$failed=0;$ok=function(bool$value,string$name)use(&$passed,&$failed):void{echo($value?'PASS ':'FAIL ').$name."\n";$value?$passed++:$failed++;};
$taskId=0;$sessionId=0;$userId=0;$stepIds=[];$executionIds=[];$progress=[];$current=[];
$suffix=bin2hex(random_bytes(8));
try{
    $email="task-multistep-{$suffix}@example.test";$curp=strtoupper(substr(hash('sha256',$suffix),0,18));$password='integration-test-only';
    $stmt=$db->prepare("INSERT INTO Users(firstname,lastname,curp,gender,email,password,role,chat,userstatus) VALUES('Task','Integration',?,'Otro',?,?,'Otros',1,'Activo')");$stmt->bind_param('sss',$curp,$email,$password);$stmt->execute();$userId=(int)$db->insert_id;$stmt->close();
    $title="multistep-{$suffix}";$model='test-double';$stmt=$db->prepare("INSERT INTO ChatSessions(user_id_,project_id_,title,model_id,provider,status) VALUES(?,NULL,?,?,'test','open')");$stmt->bind_param('iss',$userId,$title,$model);$stmt->execute();$sessionId=(int)$db->insert_id;$stmt->close();

    $public=TaskPublicId::generate();$idempotency="multistep-{$suffix}";$objective='Persistent deterministic three-step integration test';
    $stmt=$db->prepare("INSERT INTO Tasks(public_id,user_id_,created_by_user_id_,project_id_,session_id_,idempotency_key,origin_type,title,objective,status,priority,max_attempts) VALUES(?,?,?,NULL,?,?,'system',?,?,'ready','normal',1)");
    $stmt->bind_param('siiisss',$public,$userId,$userId,$sessionId,$idempotency,$title,$objective);$stmt->execute();$taskId=(int)$db->insert_id;$stmt->close();
    for($position=1;$position<=3;$position++){
        $key="step_{$position}";$stepTitle="Deterministic step {$position}";$status=$position===1?'ready':'pending';$input=json_encode(['execution_mode'=>'async','summary'=>"step {$position} done"],JSON_UNESCAPED_SLASHES);
        $stmt=$db->prepare("INSERT INTO TaskSteps(task_id_,position,step_key,title,step_type,status,agent_key,max_attempts,input_json) VALUES(?,?,?,?,'finalize',?,'test_double',1,?)");
        $stmt->bind_param('iissss',$taskId,$position,$key,$stepTitle,$status,$input);$stmt->execute();$stepIds[]=(int)$db->insert_id;$stmt->close();
    }
    $stmt=$db->prepare("UPDATE Tasks SET current_step_id_=? WHERE id_=? AND status='ready'");$stmt->bind_param('ii',$stepIds[0],$taskId);$stmt->execute();$stmt->close();
    $ok(count($stepIds)===3,'escenario aislado crea exactamente 3 Steps');

    $queue=new TaskQueueRepository($db);$config=new TaskWorkerConfig("integration:{$suffix}",120,1,10);$leases=new TaskLeaseService($queue,120);
    $registry=new TaskStepExecutorRegistry();$registry->register('finalize',new FinalizeTaskStepExecutor());
    $runner=new TaskExecutionRunner(new TaskStepProgressionService($queue),$leases,new TaskStepExecutionService($registry));

    for($index=0;$index<3;$index++){
        $stmt=$db->prepare("SELECT t.*,s.id_ step_id,s.step_key,s.status step_status,s.step_type,s.agent_key,s.lock_version step_lock,s.input_json,s.model_id step_model FROM Tasks t JOIN TaskSteps s ON s.task_id_=t.id_ WHERE t.id_=? AND s.id_=? AND s.status='ready' AND t.current_step_id_=s.id_ LIMIT 1 FOR UPDATE");
        $stmt->bind_param('ii',$taskId,$stepIds[$index]);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();$ok((bool)$row,"Step ".($index+1).' es el único ready/current reclamable');
        $queue->begin();try{$context=$queue->createClaim($row,$config->workerId,TaskWorkerConfig::leaseToken(),$config->leaseSeconds,TaskPublicId::generate());$queue->commit();}catch(Throwable$e){$queue->rollback();throw$e;}
        $executionIds[]=(int)$context['execution_id'];
        $running=(int)$db->query("SELECT COUNT(*) c FROM TaskExecutions WHERE task_id_={$taskId} AND status='running'")->fetch_assoc()['c'];$ok($running===1,'no hay dos Steps simultáneamente en ronda '.($index+1));
        $taskBefore=$db->query("SELECT current_step_id_ FROM Tasks WHERE id_={$taskId}")->fetch_assoc();$ok((int)$taskBefore['current_step_id_']===$stepIds[$index],'current_step_id_ apunta al Step en ejecución '.($index+1));
        $ok($runner->run($context),'Execution '.($index+1).' finaliza mediante TaskExecutionRunner');
        $task=$db->query("SELECT status,current_step_id_,progress_percent FROM Tasks WHERE id_={$taskId}")->fetch_assoc();$progress[]=(int)$task['progress_percent'];$current[]=$task['current_step_id_']===null?null:(int)$task['current_step_id_'];
        $expectedCurrent=$index<2?$stepIds[$index+1]:null;$ok($current[$index]===$expectedCurrent,'progresión activa únicamente el siguiente Step tras ronda '.($index+1));
    }

    $task=$db->query("SELECT status,current_step_id_,progress_percent,result_message_id_ FROM Tasks WHERE id_={$taskId}")->fetch_assoc();
    $steps=$db->query("SELECT id_,status,progress_percent,attempt_count FROM TaskSteps WHERE task_id_={$taskId} ORDER BY position")->fetch_all(MYSQLI_ASSOC);
    $executions=$db->query("SELECT id_,step_id_,status,attempt_number FROM TaskExecutions WHERE task_id_={$taskId} ORDER BY id_")->fetch_all(MYSQLI_ASSOC);
    $events=$db->query("SELECT task_id_,step_id_,execution_id_,event_key FROM TaskEvents WHERE task_id_={$taskId} AND execution_id_ IS NOT NULL ORDER BY id_")->fetch_all(MYSQLI_ASSOC);
    $ok($task['status']==='completed'&&(int)$task['progress_percent']===100&&$task['current_step_id_']===null,'Task termina completed, progreso 100 y current_step_id_ NULL');
    $ok(count($steps)===3&&count(array_filter($steps,static fn(array$s):bool=>$s['status']==='completed'))===3,'exactamente 3 Steps terminan completed');
    $ok(count($executions)===3&&count(array_unique(array_column($executions,'id_')))===3,'existen exactamente 3 TaskExecutions distintas');
    $ok(array_map('intval',array_column($executions,'step_id_'))===$stepIds,'cada Execution pertenece al Step correcto y no se reutiliza');
    $ok(array_map('intval',array_column($executions,'attempt_number'))===[1,1,1]&&array_map('intval',array_column($steps,'attempt_count'))===[1,1,1],'attempts coherentes: uno por Step');
    $ok($progress===[33,66,100]&&$current===[$stepIds[1],$stepIds[2],null],'current_step_id_ y porcentaje progresan 33 → 66 → 100');
    $ok(count($events)===6&&count(array_filter($events,static fn(array$e):bool=>(int)$e['task_id_']>0&&(int)$e['step_id_']>0&&(int)$e['execution_id_']>0))===6,'TaskEvents mantienen referencias coherentes para start/completed');
    $ok($task['result_message_id_']===null,'no duplica resultado final ni inventa message_id');
    $ok(count($stepIds)===3&&count($executionIds)===3,'no crea cuarto Step ni cuarta Execution');
}catch(Throwable$e){$failed++;fwrite(STDERR,'FAIL escenario Multi-Step: '.$e->getMessage()."\n");}
finally{
    try{
        if($taskId>0){$db->query("UPDATE Tasks SET current_step_id_=NULL WHERE id_={$taskId}");$db->query("DELETE FROM Tasks WHERE id_={$taskId}");}
        if($sessionId>0)$db->query("DELETE FROM ChatSessions WHERE id_={$sessionId}");
        if($userId>0)$db->query("DELETE FROM Users WHERE id={$userId}");
    }catch(Throwable$cleanup){$failed++;fwrite(STDERR,'FAIL limpieza: '.$cleanup->getMessage()."\n");}
    $db->close();
}
echo"Resultado integración MySQL Multi-Step: {$passed} PASS, {$failed} FAIL.\n";
exit($failed?1:0);
