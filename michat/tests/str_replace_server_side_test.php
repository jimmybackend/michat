<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/Tasks/TaskExceptions.php';
require_once __DIR__.'/../includes/Tools/ToolExecutionResult.php';
require_once __DIR__.'/../includes/Tools/ToolRegistry.php';
require_once __DIR__.'/../includes/Tools/StrReplaceService.php';

$passed=0;$failed=0;
$ok=function(bool$value,string$name)use(&$passed,&$failed):void{echo($value?'PASS ':'FAIL ').$name."\n";$value?$passed++:$failed++;};
$root=sys_get_temp_dir().'/michat_str_replace_'.bin2hex(random_bytes(6));$outside=$root.'_outside.txt';
mkdir($root,0700,true);mkdir($root.'/src',0700,true);file_put_contents($root.'/src/sample.php',"<?php\nreturn 'old';\n");file_put_contents($outside,"old\n");
try{
    $registry=new ToolRegistry();
    $registry->register('str_replace',static function(array$input)use($root):ToolExecutionResult{
        try{$a=$input['arguments'];$data=StrReplaceService::replaceLocalFile($root,(string)$a['path'],(string)$a['old_string'],(string)$a['new_string']);$data['source_id']=17;return new ToolExecutionResult('modified',[['relation'=>'modified','resource_type'=>'project_source','resource_id'=>17]],$data);}
        catch(Throwable$e){return new ToolExecutionResult($e->getMessage(),[],['error'=>$e->getMessage()],false,'error');}
    },'non_idempotent');
    foreach(['grep','view','search']as$key)$registry->register($key,static fn(array$i):ToolExecutionResult=>new ToolExecutionResult($key), 'read_only');
    $result=$registry->execute('str_replace',['arguments'=>['path'=>'src/sample.php','old_string'=>'old','new_string'=>'new']]);
    $ok($result->success&&$result->data['source_id']===17&&$result->data['replacements']===1&&str_contains((string)file_get_contents($root.'/src/sample.php'),"'new'"),'handler productivo modifica contenido y conserva source_id/replacements en data');
    $ok($result->artifacts===[['relation'=>'modified','resource_type'=>'project_source','resource_id'=>17]],'ToolRegistry conserva exactamente ProjectSource modified');
    $traversal=$registry->execute('str_replace',['arguments'=>['path'=>'../'.basename($outside),'old_string'=>'old','new_string'=>'bad']]);
    $ok(!$traversal->success&&$traversal->artifacts===[]&&file_get_contents($outside)==="old\n",'rechazo mantiene artifacts vacíos y no modifica archivo externo');
    $missing=$registry->execute('str_replace',['arguments'=>['path'=>'src/sample.php','old_string'=>'not-present','new_string'=>'x']]);
    $ok(!$missing->success&&$missing->artifacts===[]&&str_contains($missing->summary,'old_string_not_found'),'old_string inexistente mantiene artifacts vacíos');
    $factory=file_get_contents(__DIR__.'/../includes/Tools/ToolRegistryFactory.php');$service=file_get_contents(__DIR__.'/../includes/Tools/StrReplaceService.php');
    $artifactStart=strpos($service,'$artifacts=[');$artifactEnd=strpos($service,'];',$artifactStart);$artifactContract=$artifactStart===false||$artifactEnd===false?'':substr($service,$artifactStart,$artifactEnd-$artifactStart);
    $ok(str_contains($artifactContract,"['relation'=>'modified','resource_type'=>'project_source','resource_id'=>\$sourceId]")&&!preg_match("/'(?:source_id|filename|file|project_id|user_id|s3_key|Ruta|content|replacements)'\\s*=>/",$artifactContract),'artifact productivo usa sourceId validado sin metadata adicional');
    $ok(str_contains($service,"'source_id'=>\$sourceId,'replacements'=>\$count")&&str_contains($service,"[],['error'=>\$e->getMessage()],false,'error'"),'data mantiene compatibilidad y rechazos mantienen artifacts vacíos');
    $ok(str_contains($factory,"register('str_replace'")&&str_contains($factory,'new StrReplaceService')&&!str_contains($factory,"write(\$i,'str_replace')"),'ToolRegistryFactory usa StrReplaceService y no el placeholder');
    $ok(str_contains($factory,"register('grep'")&&str_contains($factory,"register('view'")&&str_contains($factory,"register('search'"),'grep, view y search siguen registrados');
    $ok(!preg_match('/\$_(?:POST|GET|SESSION|COOKIE)\b/',$service),'servicio no depende de superglobals ni navegador');
    $ok(!preg_match('/\b(?:curl|https?:\/\/|tools\.php)\b/i',$service),'servicio no usa HTTP interno');
    $ok(!str_contains($service,'code_edit')&&!str_contains($service,'FileVersions')&&!str_contains($service,'TaskArtifacts'),'no ejecuta code_edit, crea FileVersion ni persiste TaskArtifacts directamente');
}finally{
    @unlink($root.'/src/sample.php');@rmdir($root.'/src');@rmdir($root);@unlink($outside);
}
echo"Resultado: $passed passed, $failed failed\n";exit($failed?1:0);
