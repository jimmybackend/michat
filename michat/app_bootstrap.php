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
(new EnvironmentLoader())->loadIfPresent($APP_ROOT . '/.env');

// =====================================================================
// ✅ GUARD: Evitar doble carga del autoloader de Composer
// Si la clase del autoloader ya existe, no incluirlo de nuevo.
// =====================================================================
$autoload = '/var/www/vendor/autoload.php';
if (!is_file($autoload)) {
    error_log('MiChat bootstrap: falta vendor/autoload.php.');
    http_response_code(500);
    exit('Error de configuración del servidor.');
}

if (!class_exists('ComposerAutoloaderInitbd9357ed7e4e67fe1f5490cbadb5b6f1', false)) {
    require_once $autoload;
}

// 3) Archivos privados
$configPath = '/var/www/Config-s3.php';
$dbPath     = '/var/www/db-s3.php';

if (!is_file($configPath) || !is_file($dbPath)) {
    error_log('MiChat bootstrap: faltan archivos privados de configuración.');
    http_response_code(500);
    exit('Error de configuración del servidor.');
}

require_once $configPath;
require_once $dbPath;
