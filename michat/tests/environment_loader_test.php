<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__.'/../includes/Config/EnvironmentLoader.php';

$passed=0;$failed=0;
$ok=function(bool $value,string $name)use(&$passed,&$failed):void{echo($value?'PASS ':'FAIL ').$name."\n";$value?$passed++:$failed++;};
$path=tempnam(sys_get_temp_dir(),'michat-env-');
if($path===false)throw new RuntimeException('temp_file_failed');
$prefix='MICHAT_ENV_TEST_'.bin2hex(random_bytes(4));
$existing=$prefix.'_EXISTING';$plain=$prefix.'_PLAIN';$quoted=$prefix.'_QUOTED';$empty=$prefix.'_EMPTY';
putenv($existing.'=runtime');
file_put_contents($path,"# comment\n{$existing}=file\n{$plain}=value # inline comment\nexport {$quoted}=\"two words\"\n{$empty}=\n");
try{
 (new EnvironmentLoader())->loadIfPresent($path);
 $ok(getenv($existing)==='runtime','no sobrescribe variables inyectadas');
 $ok(getenv($plain)==='value','carga valor y elimina comentario inline');
 $ok(getenv($quoted)==='two words','acepta export y valor entre comillas');
 $ok(getenv($empty)==='','carga valor vacío explícito');
 (new EnvironmentLoader())->loadIfPresent($path.'.missing');
 $ok(true,'archivo ausente es opcional');
}finally{
 @unlink($path);
 foreach([$existing,$plain,$quoted,$empty]as$key)putenv($key);
}
echo"Resultado: {$passed} passed, {$failed} failed\n";
exit($failed?1:0);
