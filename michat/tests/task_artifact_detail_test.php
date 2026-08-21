<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__.'/../includes/Tasks/bootstrap.php';

$passed=0;$failed=0;
$ok=static function(bool $condition,string $name)use(&$passed,&$failed):void{echo($condition?'PASS ':'FAIL ').$name."\n";$condition?$passed++:$failed++;};
$service=file_get_contents(__DIR__.'/../includes/Tasks/TaskApplicationService.php');
$adapter=file_get_contents(__DIR__.'/../task_api.php');
$controller=file_get_contents(__DIR__.'/../includes/Tasks/TaskApiController.php');
$repository=file_get_contents(__DIR__.'/../includes/Tasks/TaskArtifactRepository.php');

$ownedPosition=strpos($service,'$t=$this->owned(');
$artifactPosition=strpos($service,'$this->artifacts?->listByTask($id)');
$ok($ownedPosition!==false&&$artifactPosition!==false&&$ownedPosition<$artifactPosition,'ownership ocurre antes de leer artifacts');
$ok(str_contains($service,"'artifacts'=>array_map")&&str_contains($service,'??[]'),'detail siempre contiene artifacts y usa lista vacía sin repository');
$ok(str_contains($service,'private ?TaskArtifactRepository$artifacts=null')&&str_contains($service,'private ?TaskArtifactResourceResolver$artifactResources=null'),'TaskApplicationService recibe repositories/resolver explícitamente y conserva compatibilidad');
$ok(str_contains($adapter,'new TaskArtifactRepository($db_connection)')&&str_contains($adapter,'new TaskArtifactResourceResolver($db_connection)'),'adapter productivo usa la misma conexión mysqli');
$ok(!str_contains($controller,'TaskArtifactRepository')&&!str_contains($controller,'listByTask'),'controller permanece como adapter delgado');

$application=(new ReflectionClass(TaskApplicationService::class))->newInstanceWithoutConstructor();
$artifactDto=(new ReflectionClass(TaskApplicationService::class))->getMethod('artifactDto');
$artifact=$artifactDto->invoke($application,[
    'id_'=>'41','execution_id_'=>'17','tool_call_id_'=>null,'relation'=>'read',
    'resource_type'=>'source_chunk','resource_id'=>'99','created_at'=>'2026-08-20 12:00:00.000000',
    'tool_call_identity'=>0,'params'=>'secret','result'=>'secret','Ruta'=>'private/path','content'=>'secret',
]);
$ok(array_keys($artifact)===['relation','resource_type','resource','created_at']&&$artifact['resource']===null,'artifact público contiene whitelist estable y resource nullable');
$ok(!isset($artifact['tool_call_id'],$artifact['resource_id']),'IDs históricos permanecen privados');
$ok($artifact['resource_type']==='source_chunk','tipo de referencia histórica se expone sin resolver recurso');
$ok(!str_contains(json_encode($artifact),'secret')&&!array_key_exists('tool_call_identity',$artifact)&&!array_key_exists('Ruta',$artifact),'artifact no filtra identidad, params, result, rutas o contenido');

$executionDto=(new ReflectionClass(TaskApplicationService::class))->getMethod('executionDto');
$execution=$executionDto->invoke($application,['id_'=>'17','trace_id'=>'trace','attempt_number'=>'1','agent_key'=>'agent','model_id'=>'model','status'=>'completed','started_at'=>null,'heartbeat_at'=>null,'finished_at'=>null,'error_message'=>null,'created_at'=>'now','lease_token'=>'secret','worker_id'=>'secret']);
$ok(!isset($execution['id'],$artifact['execution_id']),'execution y artifact no exponen IDs internos');
$ok(!array_key_exists('lease_token',$execution)&&!array_key_exists('worker_id',$execution),'execution no expone lease ni datos internos del worker');

$listByTaskStart=strpos($repository,'public function listByTask');
$listByTaskEnd=strpos($repository,'private function validateInput',$listByTaskStart);
$listByTask=substr($repository,$listByTaskStart,$listByTaskEnd-$listByTaskStart);
$ok(!preg_match('/ProjectSources|SourceChunks|FileVersions|FileS3|ToolCalls|params|result|Ruta|content/',$listByTask),'lectura histórica no resuelve recursos ni ToolCalls');
$ok(str_contains($listByTask,'ORDER BY a.execution_id_,a.id_'),'se conserva el orden determinista del repository');

echo"Resultado: {$passed} passed, {$failed} failed\n";
exit($failed?1:0);
