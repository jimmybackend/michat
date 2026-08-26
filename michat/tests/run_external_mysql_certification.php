<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }

$notRunnable = static function (string $reason): void {
    fwrite(STDERR, "NOT RUNNABLE: {$reason}\n");
    echo "EXTERNAL MYSQL CERTIFICATION = NOT RUNNABLE\n";
    exit(2);
};
if (!extension_loaded('mysqli')) $notRunnable('mysqli extension is unavailable');
$required=['TASK_TEST_DB_HOST','TASK_TEST_DB_PORT','TASK_TEST_DB_USER','TASK_TEST_DB_PASSWORD'];
foreach($required as$key)if(getenv($key)===false||(string)getenv($key)==='')$notRunnable("missing {$key}");
if(getenv('TASK_TEST_DB_ALLOW_DESTRUCTIVE')!=='1')$notRunnable('TASK_TEST_DB_ALLOW_DESTRUCTIVE=1 is required for an isolated TEST server');
$port=filter_var(getenv('TASK_TEST_DB_PORT'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
if($port===false)$notRunnable('TASK_TEST_DB_PORT is invalid');
try{
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $probe=new mysqli((string)getenv('TASK_TEST_DB_HOST'),(string)getenv('TASK_TEST_DB_USER'),(string)getenv('TASK_TEST_DB_PASSWORD'),'',(int)$port);
    $version=(string)$probe->query('SELECT VERSION() version_')->fetch_assoc()['version_'];$probe->close();
}catch(Throwable$e){$notRunnable('MySQL connection/version probe failed: '.$e->getMessage());}
if(str_contains(strtolower($version),'mariadb'))$notRunnable('MariaDB is not an accepted MySQL substitute');
if(preg_match('/^(\d+\.\d+\.\d+)/',$version,$match)!==1||version_compare($match[1],'8.0.16','<'))$notRunnable('MySQL 8.0.16 or newer is required; server reported '.$version);
echo "MYSQL VERSION = PASS ({$version})\n";

$mysqlTests=['migration_runner_mysql_test.php','supported_upgrade_mysql_test.php','schema_clean_install_test.php','mysql_compatibility_contract_test.php','ai_agent_global_scope_contract_test.php','multiuser_owned_boundaries_test.php'];
$staticTests=['migration_runner_static_contract_test.php','sql_migration_executor_test.php','supported_upgrade_static_contract_test.php','global_ai_admin_policy_test.php','multiuser_identity_static_contract_test.php','task_phase11g2_closure_audit_test.php'];
$results=[];
foreach(array_merge($mysqlTests,$staticTests)as$file){
    $process=proc_open([PHP_BINARY,__DIR__.'/'.$file],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,__DIR__,null);
    if(!is_resource($process)){echo "FAIL {$file}: process could not start\n";$results[$file]='FAIL';continue;}
    fclose($pipes[0]);
    $stdout=(string)stream_get_contents($pipes[1]);fclose($pipes[1]);$stderr=(string)stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($process);$combined=$stdout.$stderr;
    $state=$exit!==0?'FAIL':(preg_match('/\bSKIP\b/',$combined)===1?'SKIP':'PASS');$results[$file]=$state;
    echo "\n=== {$file}: {$state} ===\n{$combined}";
}
$supported=$results['supported_upgrade_mysql_test.php']??'FAIL';
$summary=['SUPPORTED UPGRADE'=>$supported,'CLEAN INSTALL'=>$results['schema_clean_install_test.php']??'FAIL','SCHEMA PARITY'=>$supported,'SECOND APPLY'=>$supported,'DRIFT'=>$supported,'UNKNOWN HISTORY'=>$supported,'PARTIAL STATES'=>$supported,'NEGATIVE FIXTURES'=>$supported,'LOCK CONTENTION'=>$supported];
echo "\n=== REQUIRED GATES ===\n";foreach($summary as$gate=>$state)echo "{$gate} = {$state}\n";
$hasFail=in_array('FAIL',$results,true)||in_array('FAIL',$summary,true);$hasSkip=in_array('SKIP',$results,true)||in_array('SKIP',$summary,true);
$final=$hasFail||$hasSkip?'FAIL':'PASS';echo "EXTERNAL MYSQL CERTIFICATION = {$final}\n";exit($final==='PASS'?0:1);
