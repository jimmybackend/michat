<?php
declare(strict_types=1);
// CI installs the actual Composer dependency. Offline checks retain a compatible
// object shape, never the arrays that hid the production type mismatch.
if (!class_exists('Aws\\Result') && is_file(dirname(__DIR__,3).'/vendor/autoload.php')) {
    require_once dirname(__DIR__,3).'/vendor/autoload.php';
}
if (getenv('CI') && !class_exists('Aws\\Result')) {
    throw new RuntimeException('AWS SDK Result is required for CI document extraction tests');
}
function documentSdkResult(array $data): ArrayAccess
{
    return class_exists('Aws\\Result') ? new Aws\Result($data) : new ArrayObject($data);
}
