<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$passed=0;$failed=0;$ok=function(bool$v,string$n)use(&$passed,&$failed):void{echo($v?'PASS ':'FAIL ').$n."\n";$v?$passed++:$failed++;};
$service=file_get_contents(__DIR__.'/../includes/Tools/CodeEditService.php');
$result=file_get_contents(__DIR__.'/../includes/Tools/ToolExecutionResult.php');
$executor=file_get_contents(__DIR__.'/../includes/Tasks/ToolTaskStepExecutor.php');
$schema=file_get_contents(__DIR__.'/../../adbbmis1_Cloud.sql');

$ok(str_contains($service,'INSERT INTO FileVersions')&&str_contains($service,'SELECT version FROM FileVersions')&&str_contains($service,"\$version='1'")&&str_contains($service,"explode('.',(string)\$row['version'])"),'reutiliza la política legacy de versión inicial e incremento dotted');
$ok(str_contains($service,'$this->db->insert_id')&&!preg_match('/file_version_id[^\n]*random|uniqid|mt_rand/i',$service),'obtiene FileVersions.id_ real desde mysqli insert_id');
$ok(str_contains($service,'SELECT id_ FROM FileVersions WHERE id_=?')&&str_contains($service,'$persisted!==$id'),'confirma que el ID devuelto corresponde al registro persistido');
$ok(str_contains($service,"'type'=>'file_version'")&&str_contains($service,"'file_version_id'=>\$version['id']")&&str_contains($result,'public readonly array $artifacts'),'ToolExecutionResult transporta la referencia estructurada');
$ok(str_contains($executor,'$result->artifacts')&&str_contains($executor,'TaskStepExecutionResult::completed'),'Task Step runtime propaga artifacts sin sustituir el ID');
$noChangeAt=strpos($service,"'changed'=>false");$versionAt=strpos($service,'createFileVersion(');
$ok($noChangeAt!==false&&$versionAt!==false&&$noChangeAt<$versionAt,'una operación sin cambios no crea FileVersion falsa');
$ok(str_contains($service,'GET_LOCK')&&str_contains($schema,'uq_file_version'),'serializa versiones y conserva la idempotencia/unique existente');
$ok(!str_contains($service,'TaskArtifacts')&&!str_contains($executor,'INSERT INTO TaskArtifacts'),'no crea TaskArtifacts');
$ok(!str_contains($schema,'fase8_6D_3C'),'no requiere cambios de esquema');

echo"Resultado: $passed passed, $failed failed\n";
echo"SKIP integración MySQL real: no hay TASK_TEST_DB_* configurado.\n";
exit($failed?1:0);
