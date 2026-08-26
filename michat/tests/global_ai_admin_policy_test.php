<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/Chat/ChatIdentity.php';

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "PASS {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL {$label}\n";
};

$cases = [
    'Administración' => true,
    'ADMIN' => true,
    'administrator' => true,
    'Soporte' => false,
    'support' => false,
    'user' => false,
    '' => false,
];

foreach ($cases as $role => $expected) {
    $_SESSION = ['role' => $role];
    $check(
        ChatIdentity::canManageGlobalAiConfiguration() === $expected,
        ($expected ? 'allows ' : 'denies ') . ($role === '' ? 'empty role' : $role)
    );
}

$_SESSION = ['user_id' => 1, 'role' => 'Soporte'];
$check(!ChatIdentity::canManageGlobalAiConfiguration(), 'user_id 1 does not grant global AI administration');

$_SESSION = ['user_id' => 999, 'rol' => ' Administración '];
$check(ChatIdentity::canManageGlobalAiConfiguration(), 'server-side rol alias is normalized independently of user id');

echo "Result: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
