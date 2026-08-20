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
$readStart=strpos($service,'$readArtifacts=[');$readEnd=strpos($service,'];',$readStart);$readContract=$readStart===false||$readEnd===false?'':substr($service,$readStart,$readEnd-$readStart);
$ok(str_contains($readContract,"['relation'=>'read','resource_type'=>'project_source','resource_id'=>(int)\$source['id_']]")&&!preg_match("/'(?:source_id|filename|file|project_id|session_id|user_id|s3_key|s3_path|Ruta|content|size|mime_type|files3_id)'\\s*=>/",$readContract),'read emite únicamente ProjectSource read con ID resuelto');
$ok(str_contains($service,'$readArtifacts,[\'action\'=>\'read\',\'file\'=>$filename,\'source_id\'=>(int)$source[\'id_\'],\'size_bytes\'=>strlen($current),\'content\'=>$current]'),'read conserva data funcional separada del artifact');
$ok(strpos($service,'getObject(')<strpos($service,'$readArtifacts=[')&&str_contains($service,"[],['error'=>\$e->getMessage()],false,'error'"),'artifact read sólo se construye tras recuperar S3 y errores mantienen artifacts vacíos');
$ok(str_contains($service,"['relation'=>'modified','resource_type'=>'project_source','resource_id'=>(int)\$source['id_']]")&&str_contains($service,"['relation'=>'generated','resource_type'=>'file_version','resource_id'=>\$version['id']]")&&str_contains($result,'public readonly array $artifacts'),'write normaliza ProjectSource modified y FileVersion generated con IDs reales');
$artifactStart=strpos($service,'$artifacts=[');$artifactEnd=strpos($service,'];',$artifactStart);$artifactContract=$artifactStart===false||$artifactEnd===false?'':substr($service,$artifactStart,$artifactEnd-$artifactStart);
$ok(!preg_match("/'(?:filename|s3_key|s3_path|project_id|content|file_version_id|source_id)'\\s*=>/",$artifactContract),'artifacts no duplican metadata ni usan claves legacy');
$ok(substr_count($artifactContract,"'resource_id'")===2&&str_contains($artifactContract,"\$version['id']")&&str_contains($artifactContract,"\$source['id_']"),'ambos artifacts conservan los IDs persistidos correspondientes');
$ok(str_contains($service,"\$data=['action'=>'write','file'=>\$filename,'source_id'=>(int)\$source['id_'],'file_version_id'=>\$version['id'],'version'=>\$version['version']"),'summary/data conservan campos funcionales legacy fuera de artifacts');
$ok(str_contains($executor,'$result->artifacts')&&str_contains($executor,'TaskStepExecutionResult::completed'),'Task Step runtime propaga artifacts sin sustituir el ID');
$noChangeAt=strpos($service,"'changed'=>false");$versionAt=strpos($service,'createFileVersion(');
$ok($noChangeAt!==false&&$versionAt!==false&&$noChangeAt<$versionAt,'una operación sin cambios no crea FileVersion falsa');
$ok(str_contains($service,'"code_edit no produjo cambios en {$filename}.",[]')&&str_contains($service,"'changed'=>false"),'write sin cambios conserva summary/data y no emite artifacts');
$ok(str_contains($service,'"code_edit eliminó {$filename}.",[]'),'delete continúa sin artifacts');
$ok(str_contains($service,'GET_LOCK')&&str_contains($schema,'uq_file_version'),'serializa versiones y conserva la idempotencia/unique existente');
$ok(!str_contains($service,'TaskArtifacts')&&!str_contains($executor,'INSERT INTO TaskArtifacts'),'no crea TaskArtifacts');
$ok(!str_contains($schema,'fase8_6D_3C'),'no requiere cambios de esquema');

echo"Resultado: $passed passed, $failed failed\n";
echo"SKIP integración MySQL real: no hay TASK_TEST_DB_* configurado.\n";
exit($failed?1:0);
