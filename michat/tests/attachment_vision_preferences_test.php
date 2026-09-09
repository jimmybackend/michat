<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$modal = (string) file_get_contents($root . '/includes/preferences/modal.php');
$vision = (string) file_get_contents($root . '/includes/preferences/attachment_vision.php');
$endpoint = (string) file_get_contents($root . '/attachment_vision_preferences.php');

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

$check(str_contains($modal, "require __DIR__ . '/attachment_vision.php'"), 'Modelos IA incluye preferencias de visión');
$check(str_contains($vision, 'aiAttachmentVisionModel'), 'Preferencias muestra selector de modelo de visión');
$check(str_contains($vision, 'aiAttachmentVisionActive'), 'Preferencias muestra interruptor Activo de visión');
$check(str_contains($vision, 'amazon.nova-lite-v1:0'), 'Visión ofrece Nova Lite');
$check(str_contains($vision, 'amazon.nova-pro-v1:0'), 'Visión ofrece Nova Pro');
$check(str_contains($vision, 'amazon.nova-premier-v1:0'), 'Visión ofrece Nova Premier');
$check(str_contains($vision, 'attachment_vision_preferences.php'), 'UI persiste mediante endpoint dedicado');
$check(str_contains($vision, 'Nova Micro') && str_contains($vision, 'solo texto'), 'UI explica que Nova Micro no sirve para visión');
$check(str_contains($endpoint, "ATTACHMENT_VISION_DEFAULT_MODEL = 'amazon.nova-lite-v1:0'"), 'Endpoint usa Nova Lite como predeterminado');
$check(str_contains($endpoint, 'ensureAttachmentVisionGlobal'), 'Primera escritura provisiona configuración global fija');
$check(str_contains($endpoint, "upsertUserOverride($userId, 'attachment_vision'"), 'Guardado persiste override del usuario');
$check(str_contains($endpoint, "hash_equals((string)\$_SESSION['csrf_token'], \$csrf)"), 'Guardado conserva protección CSRF');
$check(str_contains($endpoint, 'ATTACHMENT_VISION_ALLOWED_MODELS'), 'Backend limita modelos visuales permitidos');

printf("Result: %d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
