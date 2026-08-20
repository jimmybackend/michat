<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/Tasks/bootstrap.php';

$passed=0;$failed=0;
$ok=static function(bool$condition,string$name)use(&$passed,&$failed):void{echo($condition?'PASS ':'FAIL ').$name."\n";$condition?$passed++:$failed++;};
$resolver=file_get_contents(__DIR__.'/../includes/Tasks/TaskArtifactResourceResolver.php');
$service=file_get_contents(__DIR__.'/../includes/Tasks/TaskApplicationService.php');
$adapter=file_get_contents(__DIR__.'/../task_api.php');

$ok(str_contains($resolver,'$ids[$type][$id]=true')&&substr_count($resolver,'array_keys($ids[')===4,'resolver deduplica IDs y agrupa una vez por resource_type');
$ok(substr_count($resolver,' IN (')===4,'resolver usa una consulta batch por cada tipo con IDs presentes');
$ok(str_contains($resolver,'ps.project_id_=?')&&str_contains($resolver,'p.user_id_=?'),'project_source limita por proyecto y usuario autorizados');
$ok(str_contains($resolver,'sc.project_id_=?')&&str_contains($resolver,'ps.id_=sc.source_id_ AND ps.project_id_=sc.project_id_'),'source_chunk limita por proyecto y fuente coherente');
$ok(str_contains($resolver,'fv.project_id_=?')&&str_contains($resolver,'p.id_=fv.project_id_'),'file_version limita por proyecto autorizado');
$ok(str_contains($resolver,'FROM FileS3 WHERE user_id_=?'),'file_s3 limita por usuario autorizado');
$ok(!preg_match('/SELECT[^\n]*(?:s3_key|s3_path|Ruta|Encriptado|PasswordHash|SecureHint|content|params|result)/i',$resolver),'queries seleccionan únicamente metadata pública permitida');
$ok(str_contains($service,'$this->owned(')&&strpos($service,'$this->owned(')<strpos($service,'$this->artifactResources?->resolve('),'Task se autoriza antes de resolver metadata');
$ok(str_contains($adapter,'new TaskArtifactResourceResolver($db_connection)'),'resolver productivo comparte mysqli existente');

$application=(new ReflectionClass(TaskApplicationService::class))->newInstanceWithoutConstructor();
$dto=(new ReflectionClass(TaskApplicationService::class))->getMethod('artifactDto');
$base=['id_'=>'41','execution_id_'=>'17','tool_call_id_'=>'90','relation'=>'read','resource_id'=>'12','created_at'=>'now'];
$cases=[
 'project_source'=>[['filename'=>'App.php','s3_key'=>'secret','content'=>'secret'],['filename'=>'App.php']],
 'source_chunk'=>[['filename'=>'App.php','name'=>'run','start_line'=>'10','end_line'=>'20','content'=>'secret'],['filename'=>'App.php','name'=>'run','start_line'=>10,'end_line'=>20]],
 'file_version'=>[['filename'=>'App.php','version'=>'1.2','s3_path'=>'secret'],['filename'=>'App.php','version'=>'1.2']],
 'file_s3'=>[['filename'=>'notes.txt','Ruta'=>'secret','Encriptado'=>'secret','PasswordHash'=>'secret','SecureHint'=>'secret'],['filename'=>'notes.txt']],
];
foreach($cases as$type=>[$input,$expected]){$artifact=$dto->invoke($application,$base+['resource_type'=>$type],$input);$ok($artifact['resource']===$expected,"{$type} expone DTO público exacto");$ok($artifact['resource_type']===$type&&$artifact['resource_id']===12&&$artifact['execution_id']===17&&$artifact['tool_call_id']===90,"{$type} conserva identidad y correlaciones");$ok(!str_contains(json_encode($artifact),'secret'),"{$type} no expone metadata privada");}
$missing=$dto->invoke($application,$base+['resource_type'=>'project_source'],null);
$ok($missing['resource']===null&&$missing['resource_id']===12,'recurso eliminado conserva artifact con resource null');

echo"Resultado: {$passed} passed, {$failed} failed\n";
exit($failed?1:0);
