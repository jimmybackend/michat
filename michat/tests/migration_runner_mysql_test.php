<?php
declare(strict_types=1);
$required=['TASK_TEST_DB_HOST','TASK_TEST_DB_PORT','TASK_TEST_DB_USER','TASK_TEST_DB_PASSWORD'];$missing=[];
foreach($required as$key)if(getenv($key)===false||(string)getenv($key)==='')$missing[]=$key;
if($missing){echo'SKIP MYSQL MIGRATION RUNNER — missing '.implode(', ',$missing)."\n";exit(0);}
require_once __DIR__.'/support/ExternalMysqlTestSafety.php';
try{requireExternalMysqlDestructiveAuthorization();}catch(RuntimeException$e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
if(!extension_loaded('mysqli')){fwrite(STDERR,"FAIL mysqli extension unavailable\n");exit(1);}
$root=dirname(__DIR__,2);foreach(['MigrationCatalog','SchemaMigrationRepository','SqlMigrationExecutor','MigrationRunner']as$class)require_once $root.'/michat/includes/Migrations/'.$class.'.php';
$host=(string)getenv('TASK_TEST_DB_HOST');$port=filter_var(getenv('TASK_TEST_DB_PORT'),FILTER_VALIDATE_INT);$user=(string)getenv('TASK_TEST_DB_USER');$password=(string)getenv('TASK_TEST_DB_PASSWORD');
if($port===false||$port<1||$port>65535){fwrite(STDERR,"FAIL invalid TASK_TEST_DB_PORT\n");exit(1);}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$admin=new mysqli($host,$user,$password,'',(int)$port);$admin->set_charset('utf8mb4');
$database=externalMysqlTemporaryDatabaseName('runner');$failureDb=externalMysqlTemporaryDatabaseName('runner_failure');$created=[];$quoted=fn($v)=>'`'.str_replace('`','``',$v).'`';$passed=0;$failed=0;
$check=function(bool$ok,string$label)use(&$passed,&$failed){echo($ok?'PASS ':'FAIL ').$label."\n";$ok?$passed++:$failed++;};
try{
 $admin->query('CREATE DATABASE '.$quoted($database).' CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
 $created[]=$database;
 $db=new mysqli($host,$user,$password,$database,(int)$port);$db->set_charset('utf8mb4');
 $catalog=new MigrationCatalog();$repository=new SchemaMigrationRepository($db,$database);$executor=new SqlMigrationExecutor($db);$runner=new MigrationRunner($catalog,$repository,$executor);
 $initial=$runner->status();$check($initial['global']==='UNINITIALIZED'&&!$repository->historyTableExists(),'status is non-mutating and reports UNINITIALIZED');
 $dump=(string)file_get_contents($root.'/adbbmis1_Cloud.sql');$db->multi_query($dump);do{if($result=$db->store_result())$result->free();}while($db->more_results()&&$db->next_result());
 $executor->executeFile($root.'/michat/sql/fase12b_5_mysql_generated_column_compatibility.sql');$check(true,'12B.5 is a safe structural no-op on the final clean dump');
 $executor->executeFile($root.'/michat/sql/fase12b_6_system_role_authorization.sql');$check(true,'12B.6 is a safe authorization/planner reconciliation on the final clean dump');
 $runner->baseline('current-dump');$history=$repository->fetchHistory();$check(count($history)===14&&count(array_filter($history,fn($r)=>$r['application_mode']==='clean_baseline'))===14,'current dump baseline atomically records 14 current checksums');
 $status=$runner->status();$check($status['global']==='APPLIED'&&count(array_filter($status['rows'],fn($r)=>$r['state']==='APPLIED'))===14,'baseline status is fully APPLIED');
 try{$runner->baseline('current-dump');$check(false,'non-empty baseline rejected');}catch(RuntimeException){$check(true,'non-empty baseline rejected');}
 $db->query("DELETE FROM SchemaMigrations WHERE migration_id='fase12b_6_system_role_authorization'");$pending=$runner->status();$check($pending['global']==='PENDING'&&count(array_filter($pending['rows'],fn($r)=>$r['state']==='PENDING'))===1,'final authorization migration can be reconciled independently when its history row is absent');$applied=$runner->apply();$check($applied===['fase12b_6_system_role_authorization'],'apply records the exact final reconciliation migration');
 $first=$catalog->all()[0];$db->query("UPDATE SchemaMigrations SET checksum_sha256='".str_repeat('0',64)."' WHERE migration_id='".$db->real_escape_string($first['migration_id'])."'");$check($runner->status()['global']==='DRIFT','checksum drift detected');
 $firstChecksum=$first['checksum_sha256'];$firstId=$first['migration_id'];$stmt=$db->prepare('UPDATE SchemaMigrations SET checksum_sha256=? WHERE migration_id=?');$stmt->bind_param('ss',$firstChecksum,$firstId);$stmt->execute();$stmt->close();
 $db->query("UPDATE SchemaMigrations SET filename='renamed.sql' WHERE migration_id='".$db->real_escape_string($first['migration_id'])."'");$check($runner->status()['global']==='DRIFT','known migration filename rename is DRIFT');
 $firstFilename=$first['filename'];$stmt=$db->prepare('UPDATE SchemaMigrations SET filename=? WHERE migration_id=?');$stmt->bind_param('ss',$firstFilename,$firstId);$stmt->execute();$stmt->close();
 $db->query("INSERT INTO SchemaMigrations VALUES ('unknown_migration','unknown.sql','".str_repeat('a',64)."',CURRENT_TIMESTAMP(6),0,'adopted','test')");$check($runner->status()['global']==='UNKNOWN','unknown history row detected');$db->query("DELETE FROM SchemaMigrations WHERE migration_id='unknown_migration'");
 $repository->acquireLock(10);$second=new mysqli($host,$user,$password,$database,(int)$port);$secondRepo=new SchemaMigrationRepository($second,$database);try{$secondRepo->acquireLock(0);$check(false,'lock contention rejected');}catch(RuntimeException$e){$check(str_contains($e->getMessage(),'LOCK CONTENTION'),'lock contention rejected');}finally{$repository->releaseLock();$second->close();}
 $db->close();

 $admin->query('CREATE DATABASE '.$quoted($failureDb).' CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');$failure=new mysqli($host,$user,$password,$failureDb,(int)$port);$failureRunner=new MigrationRunner(new MigrationCatalog(),new SchemaMigrationRepository($failure,$failureDb),new SqlMigrationExecutor($failure));
 $created[]=$failureDb;
 try{$failureRunner->apply();$check(false,'failed migration stops apply');}catch(RuntimeException$e){$check(str_contains($e->getMessage(),'MIGRATION FAILED'),'failed migration stops apply');}
 $count=(int)$failure->query('SELECT COUNT(*) c FROM SchemaMigrations')->fetch_assoc()['c'];$check($count===0,'failed first migration writes no history and no later migration runs');$failure->close();
}catch(Throwable$e){$check(false,'isolated migration runner harness: '.$e->getMessage());}
finally{foreach(array_reverse($created)as$name)try{assertExternalMysqlDatabaseOwned($name,$created);$admin->query('DROP DATABASE IF EXISTS '.$quoted($name));}catch(Throwable$e){$check(false,'temporary database cleanup: '.$e->getMessage());}$admin->close();}
echo"MYSQL MIGRATION RUNNER: ".($failed?'FAIL':'PASS')."\nResult: {$passed} passed, {$failed} failed\n";exit($failed?1:0);
