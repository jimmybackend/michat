<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

$root = dirname(__DIR__, 2);
$dumpPath = $root.'/adbbmis1_Cloud.sql';
$readmePath = $root.'/README.md';
$envPath = $root.'/.env.example';
$bootstrapPath = $root.'/michat/app_bootstrap.php';
$dbConfigPath = $root.'/db-s3.php';
$passed = 0;
$failed = 0;
$issues = [];
$check = static function (bool $condition, string $name) use (&$passed, &$failed): void {
    echo ($condition ? 'PASS ' : 'FAIL ').$name."\n";
    $condition ? $passed++ : $failed++;
};
$issue = static function (bool $condition, string $message) use (&$issues): void {
    if ($condition) {
        $issues[] = $message;
        echo 'ISSUE '.$message."\n";
    }
};

$check(is_file($dumpPath), 'consolidated schema dump exists');
$check(is_file($readmePath), 'README installation contract exists');
$check(is_file($envPath), '.env.example database contract exists');
$check(is_file($bootstrapPath), 'application bootstrap exists');
$check(is_file($dbConfigPath), 'database bootstrap configuration exists');
if ($failed > 0) {
    echo "STATIC PREFLIGHT FAIL\nResult: {$passed} passed, {$failed} failed\n";
    exit(1);
}

$dump = (string)file_get_contents($dumpPath);
$readme = (string)file_get_contents($readmePath);
$envExample = (string)file_get_contents($envPath);
$bootstrap = (string)file_get_contents($bootstrapPath);
$dbConfig = (string)file_get_contents($dbConfigPath);

preg_match('/CREATE\s+DATABASE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $dump, $dumpCreateMatch);
preg_match('/\bUSE\s+`?([A-Za-z0-9_]+)`?\s*;/i', $dump, $dumpUseMatch);
preg_match('/^DB_NAME=(.*)$/m', $envExample, $envDbMatch);
$dumpCreateDb = (string)($dumpCreateMatch[1] ?? '');
$dumpUseDb = (string)($dumpUseMatch[1] ?? '');
$envDb = trim((string)($envDbMatch[1] ?? ''));

$check($dumpCreateDb === '', 'dump does not create a hardcoded database');
$check($dumpUseDb === '', 'dump does not select a hardcoded database');
$check(str_contains($readme, 'DB_NAME=michat'), 'README declares a configurable example DB_NAME');
$check($envDb !== '', '.env.example declares a non-empty DB_NAME');
$check(str_contains($bootstrap, "'/db-s3.php'")&&str_contains($bootstrap, 'require_once $dbPath'), 'application bootstrap loads the existing database configuration');
$check(str_contains($dbConfig, "getenv('DB_NAME')"), 'database connection selects deployment DB_NAME from the environment');
$check(str_contains($readme, 'DB_NAME=michat')&&str_contains($readme, '"$DB_NAME" < adbbmis1_Cloud.sql'), 'README imports the dump into the deployment-selected DB_NAME');
$check(str_contains($readme, 'does not create or select a database'), 'README states that the dump inherits the selected deployment database');
$check(substr_count(strtoupper($dump), 'CREATE TABLE') >= 1, 'dump contains CREATE TABLE statements');
$check(substr_count(strtoupper($dump), 'DROP TABLE') >= 1, 'dump contains DROP TABLE statements');
$check(substr_count(strtoupper($dump), 'INSERT INTO') >= 1, 'dump contains seed INSERT statements');
$check(substr_count(strtoupper($dump), 'ALTER TABLE') >= 1&&str_contains(strtoupper($dump), 'FOREIGN KEY'), 'dump contains ALTER TABLE foreign keys');

$criticalTables = [
    'Users','Projects','ChatSessions','ChatMessages','Tasks','TaskSteps','TaskExecutions','TaskArtifacts',
    'TaskRecurrenceRules','TaskRecurrenceOccurrences','ProjectAutonomyPolicies','ProjectAutonomyCycles',
    'ProjectAutonomyReservations','NextWorkProposals','PostTaskContinuations','TaskReplanRequests',
    'TaskPlanRevisions','TaskPlanRevisionSteps',
];
foreach ($criticalTables as $table) {
    $check(preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`'.preg_quote($table, '/').'`/i', $dump)===1, 'dump declares critical table '.$table);
}

$seedTables = ['UserAIAgentConfigs'];
foreach ($seedTables as $table) {
    $check(preg_match('/INSERT\s+INTO\s+`'.preg_quote($table, '/').'`/i', $dump)===1, 'dump contains seed block '.$table);
}
$usersSeeded = preg_match('/INSERT\s+INTO\s+`Users`/i', $dump)===1;
$check(!$usersSeeded, 'dump does not invent or seed Users during preflight');

$check(preg_match('/INSERT\s+INTO\s+`UserAIAgentConfigs`[\s\S]*?VALUES\s*\(\'global\',\s*NULL,/i', $dump)===1, 'AI catalog seeds ownerless GLOBAL rows');
$check(preg_match('/INSERT\s+INTO\s+`(?:UserPipelineFeatures|UserPreferences)`/i', $dump)!==1, 'dump omits historical user-scoped defaults');

echo $issues===[] ? "STATIC PREFLIGHT PASS\n" : 'STATIC PREFLIGHT ISSUES FOUND ('.count($issues).")\n";

$required = ['TASK_TEST_DB_HOST','TASK_TEST_DB_PORT','TASK_TEST_DB_USER','TASK_TEST_DB_PASSWORD'];
$missing = [];
foreach ($required as $key) {
    if (getenv($key)===false || (string)getenv($key)==='') $missing[] = $key;
}
if ($missing !== []) {
    echo 'STATICALLY SUSPECTED / MYSQL NOT VERIFIED: '.implode('; ', $issues)."\n";
    echo 'SKIP MYSQL CLEAN INSTALL — missing '.implode(', ', $missing)."\n";
    echo "Result: {$passed} passed, {$failed} failed\n";
    exit($failed===0 ? 0 : 1);
}

$host = (string)getenv('TASK_TEST_DB_HOST');
$portRaw = (string)getenv('TASK_TEST_DB_PORT');
$user = (string)getenv('TASK_TEST_DB_USER');
$password = (string)getenv('TASK_TEST_DB_PASSWORD');
$port = filter_var($portRaw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>65535]]);
if ($port===false) {
    $check(false, 'TASK_TEST_DB_PORT is valid');
    echo "MYSQL CLEAN INSTALL FAIL\nResult: {$passed} passed, {$failed} failed\n";
    exit(1);
}

$temporaryDb = 'michat_clean_'.bin2hex(random_bytes(6));
$created = [];
$db = null;
$runtime = sys_get_temp_dir().'/michat-schema-import-'.bin2hex(random_bytes(6));
$importStdout = '';
$importStderr = '';
$importExit = -1;
$identifier = static function (string $name): string {
    if (preg_match('/^[A-Za-z0-9_]+$/D', $name)!==1) throw new RuntimeException('unsafe_database_identifier');
    return '`'.$name.'`';
};

try {
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $db = new mysqli($host, $user, $password, '', (int)$port);
    $db->set_charset('utf8mb4');
    $check($db->query("SELECT DATABASE() current_db")->fetch_assoc()['current_db']===null, 'test connection does not inherit a database');
    $db->query('CREATE DATABASE '.$identifier($temporaryDb).' CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    $created[] = $temporaryDb;
    mkdir($runtime, 0700, true);
    $importer = $runtime.'/strict_import.php';
    file_put_contents($importer, <<<'PHP'
<?php
declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
try {
    $db=new mysqli((string)getenv('TASK_TEST_DB_HOST'),(string)getenv('TASK_TEST_DB_USER'),(string)getenv('TASK_TEST_DB_PASSWORD'),(string)getenv('SCHEMA_TEST_TEMP_DB'),(int)getenv('TASK_TEST_DB_PORT'));
    $sql=file_get_contents((string)getenv('SCHEMA_TEST_DUMP'));
    if($sql===false)throw new RuntimeException('dump_read_failed');
    $db->multi_query($sql);
    do { if($result=$db->store_result())$result->free(); } while($db->more_results()&&$db->next_result());
    $current=$db->query('SELECT DATABASE() current_db')->fetch_assoc()['current_db']??null;
    echo json_encode(['ok'=>true,'database'=>$current],JSON_THROW_ON_ERROR)."\n";
    exit(0);
} catch(Throwable $e) {
    fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
PHP);
    $environment = array_merge($_ENV, [
        'TASK_TEST_DB_HOST'=>$host,
        'TASK_TEST_DB_PORT'=>(string)$port,
        'TASK_TEST_DB_USER'=>$user,
        'TASK_TEST_DB_PASSWORD'=>$password,
        'SCHEMA_TEST_TEMP_DB'=>$temporaryDb,
        'SCHEMA_TEST_DUMP'=>$dumpPath,
    ]);
    $pipes = [];
    $process = proc_open([PHP_BINARY,$importer], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes, $runtime, $environment);
    if (!is_resource($process)) throw new RuntimeException('strict_import_process_failed');
    fclose($pipes[0]);
    $importStdout = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $importStderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $importExit = proc_close($process);

    $check($importExit===0, 'strict dump import exits zero'.($importStderr!==''?' — '.$importStderr:''));
    $tablesInTemporary = (int)$db->query("SELECT COUNT(*) count_ FROM information_schema.TABLES WHERE TABLE_SCHEMA='".$db->real_escape_string($temporaryDb)."'")->fetch_assoc()['count_'];
    $check($tablesInTemporary>0, 'temporary deployment database receives schema tables');

    $importResult = json_decode(trim($importStdout), true);
    $check(is_array($importResult)&&($importResult['database']??null)===$temporaryDb, 'strict importer remains in the deployment-selected temporary database');

    if ($importExit===0 && is_array($importResult) && ($importResult['database']??null)===$temporaryDb && $tablesInTemporary>0) {
        $db->select_db($temporaryDb);
        $actualDb = (string)$db->query('SELECT DATABASE() current_db')->fetch_assoc()['current_db'];
        $check($actualDb===$temporaryDb, 'application-style connection selects the same temporary database');
        foreach ($criticalTables as $table) {
            $stmt=$db->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
            $stmt->bind_param('ss',$temporaryDb,$table);$stmt->execute();
            $check($stmt->get_result()->fetch_assoc()!==null, 'clean import creates critical table '.$table);$stmt->close();
        }
        $criticalFks = [
            'fk_tasks_user','fk_tasks_session','fk_tasks_project','fk_task_recurrence_rules_user',
            'fk_task_recurrence_occurrence_rule','fk_project_autonomy_cycle_policy',
            'fk_project_autonomy_reservation_cycle','fk_next_work_proposal_cycle',
            'fk_post_task_continuation_cycle','fk_task_replan_cycle','fk_task_plan_revision_request',
            'fk_task_plan_revision_steps_revision','fk_post_task_continuation_answered_by',
        ];
        foreach ($criticalFks as $fk) {
            $stmt=$db->prepare('SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=? AND CONSTRAINT_NAME=?');
            $stmt->bind_param('ss',$temporaryDb,$fk);$stmt->execute();
            $check($stmt->get_result()->fetch_assoc()!==null, 'clean import creates critical FK '.$fk);$stmt->close();
        }
        echo "MYSQL CLEAN INSTALL PASS\n";
    } else {
        echo 'MYSQL CLEAN INSTALL FAIL — exit='.$importExit.' stdout='.trim($importStdout).' stderr='.trim($importStderr)."\n";
    }
} catch (Throwable $e) {
    $check(false, 'isolated MySQL clean-install harness: '.$e->getMessage());
    echo "MYSQL CLEAN INSTALL FAIL\n";
} finally {
    if ($db instanceof mysqli) {
        foreach (array_reverse(array_unique($created)) as $database) {
            try { $db->query('DROP DATABASE IF EXISTS '.$identifier($database)); }
            catch (Throwable $cleanupError) { $check(false, 'cleanup temporary database '.$database); }
        }
        $db->close();
    }
    if (is_dir($runtime)) {
        foreach (glob($runtime.'/*') ?: [] as $file) @unlink($file);
        @rmdir($runtime);
    }
}

echo "Result: {$passed} passed, {$failed} failed\n";
exit($failed===0 ? 0 : 1);
