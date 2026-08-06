<?php
/**
 * run.php — ejecuta todos los tests/test_*.php
 *
 *     php tests/run.php
 *
 * Sale con código 0 si todo pasa, 1 si algo falla (apto para CI).
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$files = glob(__DIR__ . '/test_*.php');
sort($files);

if (empty($files)) {
    echo "No se encontró ningún archivo tests/test_*.php\n";
    exit(1);
}

$start = hrtime(true);

foreach ($files as $file) {
    TestState::$currentFile = basename($file);
    echo "\n\033[1;36m═══ " . basename($file) . " ═══\033[0m\n";
    require $file;
}

$elapsedMs = (hrtime(true) - $start) / 1e6;
$failed = count(TestState::$failures);

echo "\n" . str_repeat('─', 60) . "\n";
if ($failed === 0) {
    printf("\033[32mOK\033[0m — %d aserciones en %.1f ms\n", TestState::$passed, $elapsedMs);
    exit(0);
}

printf("\033[31mFALLARON %d\033[0m de %d aserciones en %.1f ms\n", $failed, TestState::$passed + $failed, $elapsedMs);
foreach (TestState::$failures as $f) {
    echo "  - {$f}\n";
}
exit(1);
