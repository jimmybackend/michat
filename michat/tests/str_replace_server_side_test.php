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
        try{$a=$input['arguments'];$data=StrReplaceService::replaceLocalFile($root,(string)$a['path'],(string)$a['old_string'],(string)$a['new_string']);return new ToolExecutionResult('modified',[],$data);}
        catch(Throwable$e){return new ToolExecutionResult($e->getMessage(),[],['error'=>$e->getMessage()],false,'error');}
    },'non_idempotent');
    foreach(['grep','view','search']as$key)$registry->register($key,static fn(array$i):ToolExecutionResult=>new ToolExecutionResult($key), 'read_only');
    $result=$registry->execute('str_replace',['arguments'=>['path'=>'src/sample.php','old_string'=>'old','new_string'=>'new']]);
    $ok($result->success&&str_contains((string)file_get_contents($root.'/src/sample.php'),"'new'"),'handler productivo modifica un archivo temporal permitido');
    $traversal=$registry->execute('str_replace',['arguments'=>['path'=>'../'.basename($outside),'old_string'=>'old','new_string'=>'bad']]);
    $ok(!$traversal->success&&file_get_contents($outside)==="old\n",'rechaza path traversal y archivo fuera del proyecto');
    $missing=$registry->execute('str_replace',['arguments'=>['path'=>'src/sample.php','old_string'=>'not-present','new_string'=>'x']]);
    $ok(!$missing->success&&str_contains($missing->summary,'old_string_not_found'),'rechaza old_string inexistente');
    $factory=file_get_contents(__DIR__.'/../includes/Tools/ToolRegistryFactory.php');$service=file_get_contents(__DIR__.'/../includes/Tools/StrReplaceService.php');
    $ok(str_contains($factory,"register('str_replace'")&&str_contains($factory,'new StrReplaceService')&&!str_contains($factory,"write(\$i,'str_replace')"),'ToolRegistryFactory usa StrReplaceService y no el placeholder');
    $ok(str_contains($factory,"register('grep'")&&str_contains($factory,"register('view'")&&str_contains($factory,"register('search'"),'grep, view y search siguen registrados');
    $ok(!preg_match('/\$_(?:POST|GET|SESSION|COOKIE)\b/',$service),'servicio no depende de superglobals ni navegador');
    $ok(!preg_match('/\b(?:curl|https?:\/\/|tools\.php)\b/i',$service),'servicio no usa HTTP interno');
    $ok(!str_contains($service,'code_edit')&&!str_contains($service,'FileVersions')&&!str_contains($service,'TaskArtifacts'),'no ejecuta code_edit ni crea persistencias de fases posteriores');
}finally{
    @unlink($root.'/src/sample.php');@rmdir($root.'/src');@rmdir($root);@unlink($outside);
}
echo"Resultado: $passed passed, $failed failed\n";exit($failed?1:0);
