<?php
declare(strict_types=1);
final class CsrfGuard{public static function assertSessionToken(string$provided):void{$expected=(string)($_SESSION['csrf_token']??'');if($expected===''||$provided===''||!hash_equals($expected,$provided))throw new RuntimeException('csrf_invalid');}}
