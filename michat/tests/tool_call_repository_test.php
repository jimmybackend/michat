<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$passed=0;$failed=0;$ok=function(bool$v,string$n)use(&$passed,&$failed):void{echo($v?'PASS ':'FAIL ').$n."\n";$v?$passed++:$failed++;};
$repo=file_get_contents(__DIR__.'/../includes/Tools/ToolCallRepository.php');$registry=file_get_contents(__DIR__.'/../includes/Tools/ToolRegistry.php');$factory=file_get_contents(__DIR__.'/../includes/Tools/ToolRegistryFactory.php');
$code=file_get_contents(__DIR__.'/../includes/Tools/CodeEditService.php');$str=file_get_contents(__DIR__.'/../includes/Tools/StrReplaceService.php');$executor=file_get_contents(__DIR__.'/../includes/Tasks/ToolTaskStepExecutor.php');$schema=file_get_contents(__DIR__.'/../../adbbmis1_Cloud.sql');

$ok(class_exists('ToolCallRepository')||str_contains($repo,'final class ToolCallRepository'),'existe ToolCallRepository POO');
$ok(substr_count($repo,'INSERT INTO ToolCalls')===1&&substr_count($factory,'INSERT INTO ToolCalls')===0&&substr_count($code,'INSERT INTO ToolCalls')===0&&substr_count($str,'INSERT INTO ToolCalls')===0,'el único INSERT compartido no está disperso en handlers');
foreach(['grep','view','search','str_replace','code_edit']as$tool)$ok(str_contains($factory,"register('$tool'")&&str_contains($repo,"'$tool'"),"$tool usa registry y persistencia común");
$ok(substr_count($registry,'calls?->record(')===1&&substr_count($registry,'calls?->recordError(')===1,'cada ejecución física registra una vez éxito o excepción');
$ok(str_contains($repo,"'ok','error','timeout'")&&str_contains($repo,"?'timeout':'error'"),'éxito y errores conservan status real');
$ok(str_contains($registry,'microtime(true)')&&str_contains($repo,'duration_ms')&&str_contains($repo,'max(0,$durationMs)'),'duration_ms se mide y conserva');
$ok(str_contains($repo,'$this->sanitize($arguments)')&&str_contains($repo,'targetPath($params,$data)')&&str_contains($repo,"'target_filename','path'"),'params normalizados y target_path corresponden a la ejecución');
$ok(str_contains($repo,"'artifacts'=>\$this->sanitize(\$artifacts)")&&str_contains($code,"'file_version_id'=>\$version['id']")&&str_contains($executor,'$result->artifacts'),'code_edit conserva file_version_id hasta el runtime');
$ok(!preg_match('/\$_(?:POST|GET|SESSION|COOKIE)\b/',$repo)&&str_contains($repo,"'[server-owned]'"),'repository no usa HTTP ni confía en IDs dentro de params');
$ok(str_contains($schema,"'str_replace','code_edit'")&&str_contains(file_get_contents(__DIR__.'/../sql/fase8_6d_3d_toolcalls_code_edit.sql'),"'code_edit'"),'enum ToolCalls acepta code_edit mediante migración mínima');
$ok(!str_contains($repo,'TaskArtifacts')&&!str_contains($registry,'TaskArtifacts'),'no crea TaskArtifacts');

echo"Resultado: $passed passed, $failed failed\n";echo"SKIP integración MySQL real: no hay TASK_TEST_DB_* configurado.\n";exit($failed?1:0);
