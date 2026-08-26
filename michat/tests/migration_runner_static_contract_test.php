<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require_once $root.'/michat/includes/Migrations/MigrationCatalog.php';
$files=[
 'catalog'=>$root.'/michat/includes/Migrations/MigrationCatalog.php','repository'=>$root.'/michat/includes/Migrations/SchemaMigrationRepository.php',
 'executor'=>$root.'/michat/includes/Migrations/SqlMigrationExecutor.php','runner'=>$root.'/michat/includes/Migrations/MigrationRunner.php','cli'=>$root.'/michat/bin/migrations.php'];
$source=[];foreach($files as$key=>$file)$source[$key]=(string)file_get_contents($file);
$passed=0;$failed=0;$check=function(bool $ok,string $label)use(&$passed,&$failed){echo($ok?'PASS ':'FAIL ').$label."\n";$ok?$passed++:$failed++;};
$catalog=(new MigrationCatalog())->all();
$expected=['fase8_1_task_orchestrator','fase8_6d_3d_toolcalls_code_edit','fase8_7b_task_artifacts','fase10d_task_recurrence','fase11b_project_autonomy','fase11c_next_work_proposals','fase11d_post_task_continuations','fase11e0_replan_checkpoint','fase11e1_versioned_replanning','fase11f2_hitl_controls','fase12b_2c_global_ai_configuration_scope'];
$ids=array_column($catalog,'migration_id');$names=array_column($catalog,'filename');
$check($ids===$expected&&count($catalog)===11,'closed catalog has approved 11-migration order');
$check(count(array_unique($ids))===11&&count(array_unique($names))===11,'migration IDs and filenames are unique');
$check(array_reduce($catalog,fn($ok,$m)=>$ok&&$m['migration_id']===basename($m['filename'],'.sql'),true),'migration IDs follow basename contract');
$sqlRoot=realpath($root.'/michat/sql').DIRECTORY_SEPARATOR;
$check(array_reduce($catalog,fn($ok,$m)=>$ok&&str_starts_with($m['path'],$sqlRoot)&&is_file($m['path'])&&!is_link($m['path']),true),'catalog paths remain regular files under SQL root');
$check(array_reduce($catalog,fn($ok,$m)=>$ok&&$m['checksum_sha256']===hash('sha256',(string)file_get_contents($m['path']))&&preg_match('/^[0-9a-f]{64}$/',$m['checksum_sha256']),true),'checksums hash exact file bytes as lowercase SHA-256');
$check(str_contains($source['catalog'],'glob(')&&!str_contains($source['catalog'],'sort(self::FILES'),'glob detects uncataloged SQL but does not determine execution order');
$check(str_contains($source['repository'],'SchemaMigrations')&&str_contains($source['repository'],'ascii_bin')&&str_contains($source['repository'],"enum('applied','adopted','clean_baseline')")&&!str_contains($source['repository'],'AUTO_INCREMENT'),'history table follows approved functional identity schema');
$check(str_contains($source['repository'],'GET_LOCK(?,?)')&&str_contains($source['repository'],'RELEASE_LOCK(?)')&&str_contains($source['repository'],"substr(hash('sha256', \$this->databaseName), 0, 40)"),'repository implements scoped advisory lock contract');
$check(str_contains($source['executor'],'splitStatements')&&str_contains($source['executor'],'DELIMITER')&&!str_contains($source['executor'],"explode(';'")&&str_contains($source['executor'],'more_results'),'executor is delimiter-aware and drains results');
$executePos=strpos($source['runner'],'executeFile(');$historyPos=strpos($source['runner'],'insertHistory(',$executePos);
$check($executePos!==false&&$historyPos!==false&&$executePos<$historyPos,'runner writes history only after executor success');
$check(str_contains($source['runner'],'DRIFT DETECTED')&&str_contains($source['runner'],'UNKNOWN HISTORY')&&str_contains($source['runner'],'POST-STATE WITHOUT HISTORY'),'runner fails closed for drift unknown and post-state without history');
$check(str_contains($source['runner'],'assertPendingPreState')&&str_contains($source['runner'],'OPERATOR RECONCILIATION REQUIRED'),'pending target structures prevent dangerous automatic re-execution');
$check(str_contains($source['runner'],"acquireLock(0)")&&str_contains($source['runner'],"acquireLock(10)"),'status and write commands use approved lock timeouts');
$check(str_contains($source['cli'],"PHP_SAPI !== 'cli'")&&str_contains($source['cli'],"MIGRATION_COMMANDS=['status','apply','adopt-existing','baseline']"),'CLI-only adapter has a closed command allowlist');
$check(!preg_match('/--(?:sql|file|path)\b/',$source['cli'])&&!str_contains($source['cli'],'$_GET')&&!str_contains($source['cli'],'$_POST'),'CLI accepts no arbitrary SQL path or HTTP input');
$runnerFiles=glob($root.'/michat/includes/**/MigrationRunner.php',GLOB_BRACE)?:[];
$check(count($runnerFiles)===1&&!file_exists($root.'/michat/migrations.php'),'one MigrationRunner and no HTTP migration endpoint');
echo"Result: {$passed} passed, {$failed} failed\n";exit($failed?1:0);
