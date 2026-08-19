<?php
declare(strict_types=1);
require_once __DIR__.'/CsrfGuard.php';
final class MaintenanceAccess{public static function authorizeCli(array$o):void{$e=trim((string)(getenv('MICHAT_MAINTENANCE_SECRET')?:''));$p=trim((string)($o['secret']??''));if($e===''||$p===''||!hash_equals($e,$p))throw new RuntimeException('Clave de mantenimiento inválida.');}public static function authorizeWeb():void{if(session_status()===PHP_SESSION_NONE)session_start();if((int)($_SESSION['user_id']??0)<=0)throw new RuntimeException('Sesión no autenticada.');CsrfGuard::assertSessionToken((string)($_SERVER['HTTP_X_CSRF_TOKEN']??''));}}
