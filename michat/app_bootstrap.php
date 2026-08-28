<?php
declare(strict_types=1);

/**
 * app_bootstrap.php (dentro de public_html/s3v2)
 * Carga:
 * - vendor/autoload.php (AWS SDK / Composer)
 * - Config-s3.php y db.php desde fuera de public_html (ruta protegida)
 */

// =====================================================================
// ✅ GUARD: Evitar doble carga del bootstrap completo
// Si este archivo ya se ejecutó una vez, no volver a ejecutarlo.
// =====================================================================
if (defined('APP_BOOTSTRAP_LOADED')) {
    return;
}
define('APP_BOOTSTRAP_LOADED', true);

$APP_ROOT = realpath(__DIR__ . '/../');
if ($APP_ROOT === false) {
    error_log('MiChat bootstrap: no se pudo resolver APP_ROOT.');
    http_response_code(500);
    exit('Error de configuración del servidor.');
}

require_once __DIR__ . '/includes/Config/EnvironmentLoader.php';
$environmentLoader = new EnvironmentLoader();
$explicitEnvFile = trim((string)(getenv('MICHAT_ENV_FILE') ?: ''));
if ($explicitEnvFile !== '') {
    $environmentLoader->loadIfPresent($explicitEnvFile);
}
$environmentLoader->loadIfPresent($APP_ROOT . '/.env');

// =====================================================================
// ✅ GUARD: Evitar doble carga del autoloader de Composer
// Si la clase del autoloader ya existe, no incluirlo de nuevo.
// =====================================================================
$firstReadableFile = static function (array $candidates): ?string {
    foreach ($candidates as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== '' && is_file($candidate) && is_readable($candidate)) {
            return $candidate;
        }
    }
    return null;
};

$autoload = $firstReadableFile([
    getenv('MICHAT_VENDOR_AUTOLOAD') ?: '',
    $APP_ROOT . '/vendor/autoload.php',
    '/var/www/vendor/autoload.php',
]);
if ($autoload === null) {
    error_log('MiChat bootstrap: falta vendor/autoload.php.');
    http_response_code(500);
    exit('Error de configuración del servidor.');
}

if (!class_exists('ComposerAutoloaderInitbd9357ed7e4e67fe1f5490cbadb5b6f1', false)) {
    require_once $autoload;
}

// 3) Bootstrap/configuración. El checkout portable se intenta primero y el
// layout EC2 validado queda como fallback, nunca como requisito universal.
$configPath = $firstReadableFile([
    getenv('MICHAT_CONFIG_FILE') ?: '',
    $APP_ROOT . '/Config-s3.php',
    '/var/www/Config-s3.php',
]);
$dbPath = $firstReadableFile([
    getenv('MICHAT_DB_BOOTSTRAP') ?: '',
    $APP_ROOT . '/db-s3.php',
    '/var/www/db-s3.php',
]);

if ($configPath === null || $dbPath === null) {
    error_log('MiChat bootstrap: faltan archivos de configuración requeridos.');
    http_response_code(500);
    exit('Error de configuración del servidor.');
}

require_once $configPath;
require_once $dbPath;
