<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$readme = file_get_contents($root . '/README.md');
$dump = file_get_contents($root . '/adbbmis1_Cloud.sql');
$migrationPath = $root . '/michat/sql/fase11d_post_task_continuations.sql';
$migration = file_get_contents($migrationPath);
$sqlFiles = glob($root . '/michat/sql/*.sql') ?: [];
$allIncremental = '';
foreach ($sqlFiles as $sqlFile) $allIncremental .= "\n" . file_get_contents($sqlFile);

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) { $passed++; echo "PASS {$label}\n"; return; }
    $failed++; echo "FAIL {$label}\n";
};

$unsupported = [
    '/\bADD\s+(?:COLUMN\s+)?IF\s+NOT\s+EXISTS\b/i',
    '/\bADD\s+(?:INDEX|KEY)\s+IF\s+NOT\s+EXISTS\b/i',
    '/\bDROP\s+COLUMN\s+IF\s+EXISTS\b/i',
    '/\bCREATE\s+(?:UNIQUE\s+)?INDEX\s+IF\s+NOT\s+EXISTS\b/i',
];

$check(str_contains($readme, 'MySQL 8.0.16 or newer'), 'README declares the MySQL 8.0.16 minimum contract');
foreach ($unsupported as $pattern) {
    $check(preg_match($pattern, $dump . "\n" . $allIncremental) !== 1, 'known unsupported conditional DDL syntax is absent: ' . $pattern);
}
$check(str_contains($migration, 'information_schema.COLUMNS'), 'fase11d checks information_schema.COLUMNS');
$check(str_contains($migration, 'TABLE_SCHEMA = DATABASE()'), 'fase11d scopes the guard to DATABASE()');
$check(str_contains($migration, 'PREPARE michat_fase11d_stmt'), 'fase11d uses controlled dynamic SQL without a stored procedure');
$definition = '`decision_accounted` tinyint(1) NOT NULL DEFAULT 0';
$check(str_contains($migration, $definition), 'fase11d preserves the decision_accounted definition');
$check(str_contains($dump, $definition), 'consolidated dump preserves the decision_accounted definition');

$required = ['TASK_TEST_DB_HOST', 'TASK_TEST_DB_PORT', 'TASK_TEST_DB_USER', 'TASK_TEST_DB_PASSWORD'];
$missing = array_values(array_filter($required, static fn(string $key): bool => getenv($key) === false || getenv($key) === ''));
if ($missing !== []) {
    echo 'SKIP MYSQL COMPATIBILITY E2E — missing ' . implode(', ', $missing) . "\n";
    echo "Result: {$passed} passed, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}

$host = (string)getenv('TASK_TEST_DB_HOST');
$port = filter_var(getenv('TASK_TEST_DB_PORT'), FILTER_VALIDATE_INT);
$user = (string)getenv('TASK_TEST_DB_USER');
$password = (string)getenv('TASK_TEST_DB_PASSWORD');
if ($port === false || $port < 1 || $port > 65535) {
    $check(false, 'TASK_TEST_DB_PORT is valid');
    echo "Result: {$passed} passed, {$failed} failed\n";
    exit(1);
}

$db = null;
$temporaryDb = 'michat_mysql_contract_' . bin2hex(random_bytes(6));
$identifier = static fn(string $value): string => '`' . str_replace('`', '``', $value) . '`';
$runMigration = static function (string $database) use ($host, $port, $user, $password, $migrationPath): array {
    $command = ['mysql', '--protocol=TCP', '--host=' . $host, '--port=' . $port, '--user=' . $user, '--database=' . $database, '--batch', '--raw'];
    $pipes = [];
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, array_replace($_ENV, ['MYSQL_PWD' => $password]));
    if (!is_resource($process)) throw new RuntimeException('mysql client could not start');
    fwrite($pipes[0], (string)file_get_contents($migrationPath));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
};

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($host, $user, $password, '', (int)$port);
    $db->set_charset('utf8mb4');
    $db->query('CREATE DATABASE ' . $identifier($temporaryDb) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    $db->select_db($temporaryDb);
    foreach ([
        'CREATE TABLE Users (id int NOT NULL PRIMARY KEY) ENGINE=InnoDB',
        'CREATE TABLE Projects (id_ int NOT NULL PRIMARY KEY) ENGINE=InnoDB',
        'CREATE TABLE Tasks (id_ bigint UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB',
        'CREATE TABLE ProjectAutonomyCycles (id_ bigint UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB',
        'CREATE TABLE NextWorkProposals (id_ bigint UNSIGNED NOT NULL PRIMARY KEY, authorization_reason varchar(80) NOT NULL) ENGINE=InnoDB',
    ] as $fixtureSql) $db->query($fixtureSql);

    $first = $runMigration($temporaryDb);
    $check($first['exit'] === 0, 'fase11d executes on MySQL without conditional DDL syntax' . ($first['stderr'] !== '' ? ' — ' . trim($first['stderr']) : ''));
    $second = $runMigration($temporaryDb);
    $check($second['exit'] === 0, 'fase11d second execution preserves the guarded column');
    $column = $db->query("SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='" . $db->real_escape_string($temporaryDb) . "' AND TABLE_NAME='NextWorkProposals' AND COLUMN_NAME='decision_accounted'")->fetch_assoc();
    $check($column !== null && $column['COLUMN_TYPE'] === 'tinyint(1)' && $column['IS_NULLABLE'] === 'NO' && (string)$column['COLUMN_DEFAULT'] === '0', 'real MySQL decision_accounted definition matches the consolidated schema');
    echo "MYSQL COMPATIBILITY E2E: " . ($failed === 0 ? 'PASS' : 'FAIL') . "\n";
} catch (Throwable $error) {
    $check(false, 'isolated MySQL compatibility harness: ' . $error->getMessage());
} finally {
    if ($db instanceof mysqli) {
        try { $db->query('DROP DATABASE IF EXISTS ' . $identifier($temporaryDb)); }
        catch (Throwable $cleanupError) { $check(false, 'cleanup temporary MySQL compatibility database'); }
        $db->close();
    }
}

echo "Result: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
