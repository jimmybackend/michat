<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/Tasks/bootstrap.php';

$executor = new WaitTaskStepExecutor();
$beat = 0;
$future = $executor->execute(
    ['input'=>['wait_until'=>'2030-08-20T10:00:00+02:00'], 'now'=>'2030-08-20T07:59:59Z'],
    static function () use (&$beat): void { $beat++; },
    static fn(): bool => false
);
$due = $executor->execute(
    ['input'=>['wait_until'=>'2030-08-20T10:00:00+02:00'], 'now'=>'2030-08-20T08:00:00Z'],
    static function (): void {},
    static fn(): bool => false
);

$failed = 0;
foreach ([
    [$future->status === 'waiting_dependency', 'wait futuro usa waiting_dependency'],
    [($future->checkpoint['wait_until'] ?? null) === '2030-08-20 08:00:00.000000', 'wait_until se normaliza a UTC'],
    [$due->status === 'completed', 'wait vencido se completa sin sleep'],
    [$beat === 1, 'heartbeat antes de persistir espera'],
] as [$ok, $name]) {
    echo ($ok ? 'PASS ' : 'FAIL ').$name."\n";
    if (!$ok) $failed++;
}

if (!getenv('TASK_TEST_DB_HOST')) {
    echo "SKIP — no se ejecutó integración MySQL real.\n";
}
exit($failed ? 1 : 0);
