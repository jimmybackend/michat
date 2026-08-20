<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/Tasks/TaskExceptions.php';
require_once __DIR__.'/../includes/Tools/ToolExecutionResult.php';
require_once __DIR__.'/../includes/Tools/CodeEditService.php';

$passed=0;$failed=0;$ok=function(bool$v,string$n)use(&$passed,&$failed):void{echo($v?'PASS ':'FAIL ').$n."\n";$v?$passed++:$failed++;};
$root=sys_get_temp_dir().'/michat_code_edit_'.bin2hex(random_bytes(6));$outside=$root.'_outside.php';mkdir($root,0700,true);mkdir($root.'/src',0700,true);
file_put_contents($root.'/src/sample.php',"<?php\nfunction value(): string { return 'old'; }\n");file_put_contents($outside,"<?php return 'outside';\n");
try{
    $result=CodeEditService::editLocalFile($root,'src/sample.php',static fn(string$content):string=>str_replace("'old'","'new'",$content));
    $ok($result['file']==='sample.php'&&str_contains((string)file_get_contents($root.'/src/sample.php'),"'new'"),'CodeEditService ejecuta edición real sobre fixture permitido');
    foreach(['../'.basename($outside),'/'.ltrim($outside,'/')]as$path){$rejected=false;try{CodeEditService::editLocalFile($root,$path,static fn(string$c):string=>$c);}catch(Throwable$e){$rejected=true;}$ok($rejected,'rechaza ruta fuera del proyecto: '.$path);}
    $invalid=false;try{CodeEditService::editLocalFile($root,'src/sample.php',static fn(string$c):string=>'<?php function {');}catch(Throwable$e){$invalid=true;}$ok($invalid,'rechaza contenido PHP inválido');
    $factory=file_get_contents(__DIR__.'/../includes/Tools/ToolRegistryFactory.php');$service=file_get_contents(__DIR__.'/../includes/Tools/CodeEditService.php');
    $ok(str_contains($factory,"register('code_edit'")&&str_contains($factory,'new CodeEditService')&&!str_contains($factory,"write(\$i,'code_edit')"),'ToolRegistry ya no usa placeholder para code_edit');
    $ok(str_contains($service,'ChatSessions cs JOIN Projects')&&str_contains($service,'cs.project_id_=p.id_'),'ownership exige sesión y proyecto persistidos del usuario');
    $ok(!preg_match('/\$_(?:POST|GET|SESSION|COOKIE)\b/',$service)&&!preg_match('/\b(?:curl|https?:\/\/|code_edit\.php)\b/i',$service),'servicio no usa superglobals ni HTTP interno');
    $stepFactory=file_get_contents(__DIR__.'/../includes/Tasks/TaskStepExecutionServiceFactory.php');$chatFactory=file_get_contents(__DIR__.'/../includes/Chat/ChatExecutionServiceFactory.php');
    $ok(str_contains($stepFactory,'ToolRegistryFactory')&&str_contains($chatFactory,'ToolRegistryFactory'),'HTTP Task y Worker comparten ToolRegistryFactory');
    $ok(str_contains($factory,"register('str_replace'")&&str_contains($factory,'StrReplaceService'),'str_replace sigue productivo');
    $ok(!str_contains($service,'TaskArtifacts')&&str_contains($service,'FileVersions'),'TaskArtifacts queda pendiente y FileVersions usa el flujo productivo');
}finally{@unlink($root.'/src/sample.php');@rmdir($root.'/src');@rmdir($root);@unlink($outside);}
echo"Resultado: $passed passed, $failed failed\n";exit($failed?1:0);
