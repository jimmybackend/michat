<?php
declare(strict_types=1);
$required=['TASK_TEST_DB_HOST','TASK_TEST_DB_PORT','TASK_TEST_DB_USER','TASK_TEST_DB_PASSWORD'];$missing=[];
foreach($required as$key)if(getenv($key)===false||(string)getenv($key)==='')$missing[]=$key;
if($missing){echo'SKIP MYSQL MIGRATION RUNNER — missing '.implode(', ',$missing)."\n";exit(0);}
if(!extension_loaded('mysqli')){fwrite(STDERR,"FAIL mysqli extension unavailable\n");exit(1);}
$root=dirname(__DIR__,2);foreach(['MigrationCatalog','SchemaMigrationRepository','SqlMigrationExecutor','MigrationRunner']as$class)require_once $root.'/michat/includes/Migrations/'.$class.'.php';
$host=(string)getenv('TASK_TEST_DB_HOST');$port=filter_var(getenv('TASK_TEST_DB_PORT'),FILTER_VALIDATE_INT);$user=(string)getenv('TASK_TEST_DB_USER');$password=(string)getenv('TASK_TEST_DB_PASSWORD');
if($port===false||$port<1||$port>65535){fwrite(STDERR,"FAIL invalid TASK_TEST_DB_PORT\n");exit(1);}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$admin=new mysqli($host,$user,$password,'',(int)$port);$admin->set_charset('utf8mb4');
$database='michat_migration_test_'.bin2hex(random_bytes(6));$failureDb=$database.'_failure';$quoted=fn($v)=>'`'.str_replace('`','``',$v).'`';$passed=0;$failed=0;
$check=function(bool$ok,string$label)use(&$passed,&$failed){echo($ok?'PASS ':'FAIL ').$label."\n";$ok?$passed++:$failed++;};
try{
 $admin->query('CREATE DATABASE '.$quoted($database).' CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
 $db=new mysqli($host,$user,$password,$database,(int)$port);$db->set_charset('utf8mb4');
 $catalog=new MigrationCatalog();$repository=new SchemaMigrationRepository($db,$database);$executor=new SqlMigrationExecutor($db);$runner=new MigrationRunner($catalog,$repository,$executor);
 $initial=$runner->status();$check($initial['global']==='UNINITIALIZED'&&!$repository->historyTableExists(),'status is non-mutating and reports UNINITIALIZED');
 $dump=(string)file_get_contents($root.'/adbbmis1_Cloud.sql');$db->multi_query($dump);do{if($result=$db->store_result())$result->free();}while($db->more_results()&&$db->next_result());
 $executor->executeFile($root.'/michat/sql/fase12b_2c_global_ai_configuration_scope.sql');$check(true,'delimiter executor runs Fase 12B.2C against complete target schema');
 $runner->baseline('current-dump');$history=$repository->fetchHistory();$check(count($history)===11&&count(array_filter($history,fn($r)=>$r['application_mode']==='clean_baseline'))===11,'current dump baseline atomically records 11 current checksums');
 $status=$runner->status();$check($status['global']==='APPLIED'&&count(array_filter($status['rows'],fn($r)=>$r['state']==='APPLIED'))===11,'baseline status is fully APPLIED');
 try{$runner->baseline('current-dump');$check(false,'non-empty baseline rejected');}catch(RuntimeException){$check(true,'non-empty baseline rejected');}
 $first=$catalog->all()[0];$db->query("UPDATE SchemaMigrations SET checksum_sha256='".str_repeat('0',64)."' WHERE migration_id='".$db->real_escape_string($first['migration_id'])."'");$check($runner->status()['global']==='DRIFT','checksum drift detected');
 $firstChecksum=$first['checksum_sha256'];$firstId=$first['migration_id'];$stmt=$db->prepare('UPDATE SchemaMigrations SET checksum_sha256=? WHERE migration_id=?');$stmt->bind_param('ss',$firstChecksum,$firstId);$stmt->execute();$stmt->close();
 $db->query("UPDATE SchemaMigrations SET filename='renamed.sql' WHERE migration_id='".$db->real_escape_string($first['migration_id'])."'");$check($runner->status()['global']==='DRIFT','known migration filename rename is DRIFT');
 $firstFilename=$first['filename'];$stmt=$db->prepare('UPDATE SchemaMigrations SET filename=? WHERE migration_id=?');$stmt->bind_param('ss',$firstFilename,$firstId);$stmt->execute();$stmt->close();
 $db->query("INSERT INTO SchemaMigrations VALUES ('unknown_migration','unknown.sql','".str_repeat('a',64)."',CURRENT_TIMESTAMP(6),0,'adopted','test')");$check($runner->status()['global']==='UNKNOWN','unknown history row detected');$db->query("DELETE FROM SchemaMigrations WHERE migration_id='unknown_migration'");
 $repository->acquireLock(10);$second=new mysqli($host,$user,$password,$database,(int)$port);$secondRepo=new SchemaMigrationRepository($second,$database);try{$secondRepo->acquireLock(0);$check(false,'lock contention rejected');}catch(RuntimeException$e){$check(str_contains($e->getMessage(),'LOCK CONTENTION'),'lock contention rejected');}finally{$repository->releaseLock();$second->close();}
 $db->close();

 $admin->query('CREATE DATABASE '.$quoted($failureDb).' CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');$failure=new mysqli($host,$user,$password,$failureDb,(int)$port);$failureRunner=new MigrationRunner(new MigrationCatalog(),new SchemaMigrationRepository($failure,$failureDb),new SqlMigrationExecutor($failure));
 try{$failureRunner->apply();$check(false,'failed migration stops apply');}catch(RuntimeException$e){$check(str_contains($e->getMessage(),'MIGRATION FAILED'),'failed migration stops apply');}
 $count=(int)$failure->query('SELECT COUNT(*) c FROM SchemaMigrations')->fetch_assoc()['c'];$check($count===0,'failed first migration writes no history and no later migration runs');$failure->close();
}catch(Throwable$e){$check(false,'isolated migration runner harness: '.$e->getMessage());}
finally{foreach([$failureDb,$database]as$name)try{$admin->query('DROP DATABASE IF EXISTS '.$quoted($name));}catch(Throwable$e){$check(false,'temporary database cleanup: '.$e->getMessage());}$admin->close();}
echo"MYSQL MIGRATION RUNNER: ".($failed?'FAIL':'PASS')."\nResult: {$passed} passed, {$failed} failed\n";exit($failed?1:0);
