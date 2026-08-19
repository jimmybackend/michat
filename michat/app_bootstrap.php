<?php
declare(strict_types=1);

// Desactiva IMDS (evita que el SDK intente 169.254.169.254)
putenv('AWS_EC2_METADATA_DISABLED=true');

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

// =====================================================================
// ✅ GUARD: Evitar doble carga del autoloader de Composer
// Si la clase del autoloader ya existe, no incluirlo de nuevo.
// =====================================================================
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    die("No existe: {$autoload}");
}

if (!class_exists('ComposerAutoloaderInitbd9357ed7e4e67fe1f5490cbadb5b6f1', false)) {
    require_once $autoload;
}

// 2) Ruta privada (1 nivel arriba de public_html/s3v2)
$APP_ROOT = realpath(__DIR__ . '/../');
if ($APP_ROOT === false) {
    die('No se pudo resolver APP_ROOT con realpath(). Revisa la ruta /../');
}

// 3) Archivos privados
$configPath = $APP_ROOT . '/Config-s3.php';
$dbPath     = $APP_ROOT . '/db-s3.php';

if (!is_file($configPath)) die("No existe: {$configPath}");
if (!is_file($dbPath))     die("No existe: {$dbPath}");

require_once $configPath;
require_once $dbPath;