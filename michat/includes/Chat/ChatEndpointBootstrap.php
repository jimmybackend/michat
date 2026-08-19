<?php

declare(strict_types=1);

final class ChatEndpointBootstrap
{
    public static function mysqli(string $endpointDir): mysqli
    {
        $bootstrap = self::findBootstrap($endpointDir);
        if ($bootstrap === null) throw new RuntimeException('app_bootstrap.php no encontrado.');
        require_once $bootstrap;
        if (isset($db_connection) && $db_connection instanceof mysqli) return $db_connection;
        if (isset($GLOBALS['db_connection']) && $GLOBALS['db_connection'] instanceof mysqli) return $GLOBALS['db_connection'];
        throw new RuntimeException('DB no disponible (mysqli).');
    }

    private static function findBootstrap(string $endpointDir): ?string
    {
        $direct=[rtrim($endpointDir,'/').'/app_bootstrap.php', rtrim($endpointDir,'/').'/../app_bootstrap.php'];
        foreach($direct as $f) if(is_file($f)) return $f;
        $docRoot=(string)($_SERVER['DOCUMENT_ROOT']??'');
        $rootFromDoc=$docRoot!==''?realpath($docRoot.'/..'):false;
        $bases=[];
        foreach([$rootFromDoc,realpath($endpointDir.'/../../'),realpath($endpointDir.'/../..'),realpath($endpointDir.'/../../../'),realpath($endpointDir.'/../'),realpath($endpointDir)] as $p){if($p&&is_dir($p))$bases[$p]=true;}
        foreach(array_keys($bases) as $base){foreach(['','public_html','api','app','www'] as $sub){$f=rtrim($base,'/').($sub!==''?'/'.$sub:'').'/app_bootstrap.php';if(is_file($f))return$f;}}
        return null;
    }
}
