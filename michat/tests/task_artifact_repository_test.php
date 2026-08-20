<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__.'/../includes/Tasks/TaskExceptions.php';
require_once __DIR__.'/../includes/Tasks/TaskArtifactRepository.php';

$passed=0;$failed=0;
$ok=function(bool$value,string$name)use(&$passed,&$failed):void{echo($value?'PASS ':'FAIL ').$name."\n";$value?$passed++:$failed++;};

// Input validation is testable without a database because it runs before mysqli access.
$repository=(new ReflectionClass(TaskArtifactRepository::class))->newInstanceWithoutConstructor();
$reject=function(callable$call,string$message)use($ok):void{try{$call();$ok(false,$message);}catch(TaskValidationException){$ok(true,$message);}};
$reject(fn()=>$repository->record(1,null,'invalid','project_source',1),'rechaza relation inválida');
$reject(fn()=>$repository->record(1,null,'read','invalid',1),'rechaza resource_type inválido');
$reject(fn()=>$repository->record(0,null,'read','project_source',1),'rechaza execution_id no positivo');
$reject(fn()=>$repository->record(1,null,'read','project_source',0),'rechaza resource_id no positivo');
$reject(fn()=>$repository->record(1,0,'read','project_source',1),'rechaza tool_call_id no positivo');

$source=file_get_contents(__DIR__.'/../includes/Tasks/TaskArtifactRepository.php');
$ok(str_contains($source,'ON DUPLICATE KEY UPDATE id_=LAST_INSERT_ID(id_)'),'upsert usa la UNIQUE de TaskArtifacts idempotentemente');
$ok(!preg_match('/\$_(?:GET|POST|SESSION|COOKIE)\b/',$source),'Repository no deriva ownership de HTTP');
$ok(!preg_match('/SELECT[^;]*(?:s3_key|s3_path|Ruta|params|result|content)/is',$source),'DTOs no consultan contenido, rutas ni payloads privados');

$required=['TASK_TEST_DB_HOST','TASK_TEST_DB_PORT','TASK_TEST_DB_USER','TASK_TEST_DB_PASSWORD','TASK_TEST_DB_NAME'];
foreach($required as$key){if(getenv($key)===false||getenv($key)===''){echo"SKIP — integración MySQL TaskArtifactRepository: TASK_TEST_DB_* no configurado.\n";echo"Resultado: {$passed} PASS, {$failed} FAIL.\n";exit($failed?1:0);}}
$database=(string)getenv('TASK_TEST_DB_NAME');
if(!preg_match('/(^|[_-])(test|testing)([_-]|$)/i',$database)){fwrite(STDERR,"FAIL — TASK_TEST_DB_NAME no parece una base aislada de tests.\n");exit(1);}
if(!extension_loaded('mysqli')){fwrite(STDERR,"FAIL — mysqli no disponible.\n");exit(1);}

mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli((string)getenv('TASK_TEST_DB_HOST'),(string)getenv('TASK_TEST_DB_USER'),(string)getenv('TASK_TEST_DB_PASSWORD'),$database,(int)getenv('TASK_TEST_DB_PORT'));
$db->set_charset('utf8mb4');
foreach(['Users','Projects','ChatSessions','Tasks','TaskSteps','TaskExecutions','TaskArtifacts','ProjectSources','SourceChunks','FileVersions','FileS3','ToolCalls']as$table){
    $safe=$db->real_escape_string($table);$result=$db->query("SHOW TABLES LIKE '{$safe}'");
    if($result->num_rows!==1){fwrite(STDERR,"FAIL — falta tabla {$table} en DB de test.\n");exit(1);}
}

$ids=['tasks'=>[],'sessions'=>[],'projects'=>[],'users'=>[],'files'=>[]];
$suffix=bin2hex(random_bytes(6));
$expectValidation=function(callable$call,string$name)use($ok):void{try{$call();$ok(false,$name);}catch(TaskValidationException){$ok(true,$name);}};
try{
    foreach([1,2]as$n){
        $email="artifact-{$suffix}-{$n}@example.test";$curp=strtoupper(substr(hash('sha256',"{$suffix}:{$n}"),0,18));
        $stmt=$db->prepare("INSERT INTO Users(firstname,lastname,curp,gender,email,password,role,chat,userstatus) VALUES('Artifact','Test',?,'Otro',?,'test-only','Otros',1,'Activo')");
        $stmt->bind_param('ss',$curp,$email);$stmt->execute();$ids['users'][]=(int)$db->insert_id;$stmt->close();
    }
    foreach([[$ids['users'][0],1],[$ids['users'][0],2]]as[$user,$n]){
        $name="artifact-{$suffix}-{$n}";$slug=$name;$root="Data/Tests/{$suffix}/{$n}/";
        $stmt=$db->prepare("INSERT INTO Projects(user_id_,name,slug,root_prefix,status) VALUES(?,?,?,?,'active')");
        $stmt->bind_param('isss',$user,$name,$slug,$root);$stmt->execute();$ids['projects'][]=(int)$db->insert_id;$stmt->close();
    }
    foreach($ids['projects']as$n=>$project){
        $title="artifact-session-{$suffix}-{$n}";$model='test-model';
        $stmt=$db->prepare("INSERT INTO ChatSessions(user_id_,project_id_,title,model_id,provider,status) VALUES(?,?,?,?,'test','open')");
        $stmt->bind_param('iiss',$ids['users'][0],$project,$title,$model);$stmt->execute();$ids['sessions'][]=(int)$db->insert_id;$stmt->close();
    }
    $public=sprintf('00000000-0000-4000-8000-%012d',random_int(1,999999999999));$title="artifact-task-{$suffix}";$objective='Repository integration test';
    $stmt=$db->prepare("INSERT INTO Tasks(public_id,user_id_,created_by_user_id_,project_id_,session_id_,origin_type,title,objective,status,priority,max_attempts) VALUES(?,?,?,?,?,'system',?,?,'running','normal',1)");
    $stmt->bind_param('siiiiss',$public,$ids['users'][0],$ids['users'][0],$ids['projects'][0],$ids['sessions'][0],$title,$objective);$stmt->execute();$taskId=(int)$db->insert_id;$ids['tasks'][]=$taskId;$stmt->close();
    $key='artifact';$stmt=$db->prepare("INSERT INTO TaskSteps(task_id_,position,step_key,title,step_type,status,max_attempts) VALUES(?,1,?,?,'tool','running',1)");$stmt->bind_param('iss',$taskId,$key,$title);$stmt->execute();$stepId=(int)$db->insert_id;$stmt->close();
    $trace=sprintf('10000000-0000-4000-8000-%012d',random_int(1,999999999999));$stmt=$db->prepare("INSERT INTO TaskExecutions(task_id_,step_id_,trace_id,attempt_number,status) VALUES(?,?,?,1,'running')");$stmt->bind_param('iis',$taskId,$stepId,$trace);$stmt->execute();$executionId=(int)$db->insert_id;$stmt->close();

    $makeSource=function(int$project,string$name)use($db,$suffix):int{$key="Data/Tests/{$suffix}/{$project}/{$name}";$stmt=$db->prepare("INSERT INTO ProjectSources(project_id_,s3_key,filename,status) VALUES(?,?,?,'pending')");$stmt->bind_param('iss',$project,$key,$name);$stmt->execute();$id=(int)$db->insert_id;$stmt->close();return$id;};
    $sourceId=$makeSource($ids['projects'][0],'owned.php');$otherSourceId=$makeSource($ids['projects'][1],'other.php');
    $stmt=$db->prepare("INSERT INTO SourceChunks(source_id_,project_id_,chunk_type,content,start_line,end_line) VALUES(?,?,'file','owned',1,1)");$stmt->bind_param('ii',$sourceId,$ids['projects'][0]);$stmt->execute();$chunkId=(int)$db->insert_id;$stmt->close();
    $stmt=$db->prepare("INSERT INTO SourceChunks(source_id_,project_id_,chunk_type,content,start_line,end_line) VALUES(?,?,'file','other',1,1)");$stmt->bind_param('ii',$otherSourceId,$ids['projects'][1]);$stmt->execute();$otherChunkId=(int)$db->insert_id;$stmt->close();
    $stmt=$db->prepare("INSERT INTO FileVersions(project_id_,original_filename,version,s3_path) VALUES(?,'owned.php','1','test/owned.v1')");$stmt->bind_param('i',$ids['projects'][0]);$stmt->execute();$versionId=(int)$db->insert_id;$stmt->close();
    $stmt=$db->prepare("INSERT INTO FileVersions(project_id_,original_filename,version,s3_path) VALUES(?,'other.php','1','test/other.v1')");$stmt->bind_param('i',$ids['projects'][1]);$stmt->execute();$otherVersionId=(int)$db->insert_id;$stmt->close();
    $stmt=$db->prepare("INSERT INTO FileS3(Nombre,Encriptado,Tamano,Ruta,user_id_) VALUES('owned.txt',?,1,'test/',?)");$encrypted="artifact-{$suffix}-owned";$stmt->bind_param('si',$encrypted,$ids['users'][0]);$stmt->execute();$fileId=(int)$db->insert_id;$ids['files'][]=$fileId;$stmt->close();
    $stmt=$db->prepare("INSERT INTO FileS3(Nombre,Encriptado,Tamano,Ruta,user_id_) VALUES('other.txt',?,1,'test/',?)");$encrypted="artifact-{$suffix}-other";$stmt->bind_param('si',$encrypted,$ids['users'][1]);$stmt->execute();$otherFileId=(int)$db->insert_id;$ids['files'][]=$otherFileId;$stmt->close();
    $params='{}';$stmt=$db->prepare("INSERT INTO ToolCalls(session_id_,project_id_,tool,params,status) VALUES(?,?,'view',?,'ok')");$stmt->bind_param('iis',$ids['sessions'][0],$ids['projects'][0],$params);$stmt->execute();$toolCallId=(int)$db->insert_id;$stmt->close();
    $stmt=$db->prepare("INSERT INTO ToolCalls(session_id_,project_id_,tool,params,status) VALUES(?,?,'view',?,'ok')");$stmt->bind_param('iis',$ids['sessions'][1],$ids['projects'][1],$params);$stmt->execute();$otherToolCallId=(int)$db->insert_id;$stmt->close();

    $repo=new TaskArtifactRepository($db);
    $first=$repo->record($executionId,$toolCallId,'read','project_source',$sourceId);
    $second=$repo->record($executionId,$toolCallId,'read','project_source',$sourceId);
    $ok($first['id_']===$second['id_'],'inserción válida y repetición idempotente conservan una fila');
    $expectValidation(fn()=>$repo->record($executionId,null,'read','project_source',PHP_INT_MAX),'rechaza recurso inexistente');
    $expectValidation(fn()=>$repo->record($executionId,null,'read','project_source',$otherSourceId),'rechaza ProjectSource de otro proyecto');
    $expectValidation(fn()=>$repo->record($executionId,null,'read','source_chunk',$otherChunkId),'rechaza SourceChunk de otro proyecto');
    $expectValidation(fn()=>$repo->record($executionId,null,'generated','file_version',$otherVersionId),'rechaza FileVersion de otro proyecto');
    $expectValidation(fn()=>$repo->record($executionId,null,'used','file_s3',$otherFileId),'rechaza FileS3 de otro usuario');
    $expectValidation(fn()=>$repo->record($executionId,$otherToolCallId,'read','project_source',$sourceId),'rechaza ToolCall incoherente');
    $repo->record($executionId,null,'read','source_chunk',$chunkId);$repo->record($executionId,null,'generated','file_version',$versionId);$repo->record($executionId,null,'used','file_s3',$fileId);
    $byExecution=$repo->listByExecution($executionId);$byTask=$repo->listByTask($taskId);
    $ok(count($byExecution)===4&&count($byTask)===4,'consulta por execution y Task devuelve artifacts controlados');
    $allowed=['id_','execution_id_','tool_call_id_','relation','resource_type','resource_id','created_at'];
    $ok(array_diff(array_keys($byTask[0]),$allowed)===[]&&array_diff($allowed,array_keys($byTask[0]))===[],'DTO no expone campos privados');
}catch(Throwable$e){$failed++;fwrite(STDERR,'FAIL integración TaskArtifactRepository: '.$e->getMessage()."\n");}
finally{
    try{
        foreach($ids['tasks']as$id)$db->query("DELETE FROM Tasks WHERE id_=".(int)$id);
        foreach($ids['sessions']as$id)$db->query("DELETE FROM ChatSessions WHERE id_=".(int)$id);
        foreach($ids['projects']as$id)$db->query("DELETE FROM Projects WHERE id_=".(int)$id);
        foreach($ids['files']as$id)$db->query("DELETE FROM FileS3 WHERE id_=".(int)$id);
        foreach(array_reverse($ids['users'])as$id)$db->query("DELETE FROM Users WHERE id=".(int)$id);
    }catch(Throwable$cleanup){$failed++;fwrite(STDERR,'FAIL limpieza: '.$cleanup->getMessage()."\n");}
    $db->close();
}
echo"Resultado: {$passed} PASS, {$failed} FAIL.\n";
exit($failed?1:0);
