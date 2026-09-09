<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$models = (string) file_get_contents($root . '/includes/preferences/models.php');
$chat = (string) file_get_contents($root . '/chat.php');
$dump = (string) file_get_contents(dirname($root) . '/adbbmis1_Cloud.sql');

$passed = 0;
$failed = 0;
$check = function (bool $ok, string $message) use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
        echo "PASS {$message}\n";
    } else {
        $failed++;
        echo "FAIL {$message}\n";
    }
};

$check(str_contains($models, 'aiAttachmentVisionModel'), 'Preferencias muestra selector de modelo de visión');
$check(str_contains($models, 'aiAttachmentVisionActive'), 'Preferencias muestra interruptor Activo de visión');
$check(str_contains($models, 'amazon.nova-lite-v1:0'), 'Visión ofrece Nova Lite');
$check(str_contains($models, 'amazon.nova-pro-v1:0'), 'Visión ofrece Nova Pro');
$check(str_contains($models, 'amazon.nova-premier-v1:0'), 'Visión ofrece Nova Premier');
$check(str_contains($models, 'attachment_vision'), 'Preferencias identifica agent_key attachment_vision');
$check(str_contains($chat, "'attachment_vision' => ["), 'Runtime permite guardar attachment_vision');
$check(str_contains($chat, "key: 'attachment_vision'"), 'Binding JS carga y guarda attachment_vision');
$check(str_contains($chat, "'attachment_vision' => [\n        'model_id' => 'amazon.nova-lite-v1:0'"), 'Runtime tiene fallback visual Nova Lite');
$check(str_contains($dump, "'attachment_vision'"), 'Dump limpio incluye configuración global attachment_vision');

printf("Result: %d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
