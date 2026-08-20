<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/Tasks/TaskExceptions.php';
require_once __DIR__.'/../includes/Tasks/TaskCancellationGuard.php';
require_once __DIR__.'/../includes/Tools/ToolExecutionResult.php';
require_once __DIR__.'/../includes/Tools/ToolCallRepository.php';
require_once __DIR__.'/../includes/Tools/ToolRegistry.php';

$passed=0;$failed=0;$ok=function(bool$value,string$name)use(&$passed,&$failed):void{echo($value?'PASS ':'FAIL ').$name."\n";$value?$passed++:$failed++;};

$legacy=new ToolExecutionResult('legacy',[['type'=>'file_version','id'=>7]],['changed'=>true],false,'error');
$ok($legacy->toolCallId===null,'constructor existente conserva toolCallId nullable');
$registry=new ToolRegistry();
$registry->register('view',static fn(array$input):ToolExecutionResult=>new ToolExecutionResult('summary',[['type'=>'project_source','id'=>9]],['value'=>42],true,'ok'),'read_only');
$withoutPersistence=$registry->execute('view',['arguments'=>[],'context'=>[]]);
$ok($withoutPersistence->summary==='summary','registry sin persistencia conserva summary');
$ok($withoutPersistence->data===['value'=>42],'registry sin persistencia conserva data');
$ok($withoutPersistence->artifacts===[['type'=>'project_source','id'=>9]],'registry sin persistencia conserva artifacts');
$ok($withoutPersistence->success===true&&$withoutPersistence->status==='ok','registry sin persistencia conserva success/status');
$ok($withoutPersistence->toolCallId===null,'registry sin Repository conserva toolCallId null');
$registry->register('search',static function(array$input):ToolExecutionResult{throw new RuntimeException('handler_failure');},'read_only');
try{$registry->execute('search',['arguments'=>[],'context'=>[]]);$ok(false,'errores del handler conservan excepción');}catch(RuntimeException$e){$ok($e->getMessage()==='handler_failure','errores del handler conservan excepción');}

$required=['TASK_TEST_DB_HOST','TASK_TEST_DB_PORT','TASK_TEST_DB_USER','TASK_TEST_DB_PASSWORD','TASK_TEST_DB_NAME'];
foreach($required as$key){if(getenv($key)===false||getenv($key)===''){echo"SKIP — integración MySQL toolCallId: TASK_TEST_DB_* no configurado.\n";echo"Resultado: {$passed} PASS, {$failed} FAIL.\n";exit($failed?1:0);}}
$database=(string)getenv('TASK_TEST_DB_NAME');
if(!preg_match('/(^|[_-])(test|testing)([_-]|$)/i',$database)){fwrite(STDERR,"FAIL — TASK_TEST_DB_NAME no parece una base aislada de tests.\n");exit(1);}
if(!extension_loaded('mysqli')){fwrite(STDERR,"FAIL — mysqli no disponible.\n");exit(1);}

mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli((string)getenv('TASK_TEST_DB_HOST'),(string)getenv('TASK_TEST_DB_USER'),(string)getenv('TASK_TEST_DB_PASSWORD'),$database,(int)getenv('TASK_TEST_DB_PORT'));
$db->set_charset('utf8mb4');$sessionId=0;$userId=0;
try{
    foreach(['Users','ChatSessions','ToolCalls']as$table){$safe=$db->real_escape_string($table);if($db->query("SHOW TABLES LIKE '{$safe}'")->num_rows!==1)throw new RuntimeException("missing_{$table}");}
    $suffix=bin2hex(random_bytes(6));$email="tool-call-id-{$suffix}@example.test";$curp=strtoupper(substr(hash('sha256',$suffix),0,18));
    $stmt=$db->prepare("INSERT INTO Users(firstname,lastname,curp,gender,email,password,role,chat,userstatus) VALUES('ToolCall','Test',?,'Otro',?,'test-only','Otros',1,'Activo')");$stmt->bind_param('ss',$curp,$email);$stmt->execute();$userId=(int)$db->insert_id;$stmt->close();
    $title="tool-call-id-{$suffix}";$model='test-model';$stmt=$db->prepare("INSERT INTO ChatSessions(user_id_,project_id_,title,model_id,provider,status) VALUES(?,NULL,?,?,'test','open')");$stmt->bind_param('iss',$userId,$title,$model);$stmt->execute();$sessionId=(int)$db->insert_id;$stmt->close();
    $calls=new ToolCallRepository($db);$persistedRegistry=new ToolRegistry($calls);
    $persistedRegistry->register('view',static fn(array$input):ToolExecutionResult=>new ToolExecutionResult('persisted-summary',[['type'=>'project_source','id'=>11]],['value'=>84],true,'ok'),'read_only');
    $result=$persistedRegistry->execute('view',['arguments'=>['chunk_id'=>11],'context'=>['session_id'=>$sessionId]]);
    $ok($result->toolCallId!==null&&$result->toolCallId>0,'ToolRegistry devuelve insert_id real positivo');
    $row=$db->query('SELECT id_ FROM ToolCalls WHERE id_='.(int)$result->toolCallId)->fetch_assoc();
    $ok((int)($row['id_']??0)===$result->toolCallId,'toolCallId corresponde a la fila MySQL persistida');
    $ok($result->summary==='persisted-summary'&&$result->data===['value'=>84]&&$result->artifacts===[['type'=>'project_source','id'=>11]]&&$result->success&&$result->status==='ok','propagación conserva todos los campos funcionales');
}catch(Throwable$e){$failed++;fwrite(STDERR,'FAIL integración toolCallId: '.$e->getMessage()."\n");}
finally{
    try{if($sessionId>0)$db->query("DELETE FROM ChatSessions WHERE id_={$sessionId}");if($userId>0)$db->query("DELETE FROM Users WHERE id={$userId}");}catch(Throwable$e){$failed++;fwrite(STDERR,'FAIL limpieza: '.$e->getMessage()."\n");}
    $db->close();
}
echo"Resultado: {$passed} PASS, {$failed} FAIL.\n";exit($failed?1:0);
