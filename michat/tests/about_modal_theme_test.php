<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$chat = file_get_contents($root . '/chat.php');
$css = file_get_contents($root . '/css/michat-about.css');

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};

$check(str_contains($chat, 'css/michat-about.css'), 'chat.php loads themed About stylesheet');
$check(str_contains($chat, 'class="michat-about-dialog"'), 'About dialog uses themed class');
$check(str_contains($chat, 'class="michat-about-password"'), 'password input uses themed class');
$check(!str_contains($chat, 'background:#20242b'), 'About dialog has no fixed dark background');
$check(!str_contains($chat, 'color:#111'), 'About password has no fixed dark text');
$check(str_contains($css, 'background: var(--bg2)'), 'dialog surface follows active theme');
$check(str_contains($css, 'color: var(--text)'), 'dialog text follows active theme');
$check(str_contains($css, 'border: 1px solid var(--border)'), 'dialog border follows active theme');
$check(str_contains($css, 'var(--accent)'), 'interactive state follows active accent');

if ($failures) {
    fwrite(STDERR, "About modal theme contract failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "About modal theme contract: OK\n";
