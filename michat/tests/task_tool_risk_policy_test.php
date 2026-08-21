<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__.'/../includes/Tasks/TaskExceptions.php';
require_once __DIR__.'/../includes/Tools/ToolExecutionResult.php';
require_once __DIR__.'/../includes/Tools/ToolRegistry.php';
require_once __DIR__.'/../includes/Tasks/TaskToolRiskDecision.php';
require_once __DIR__.'/../includes/Tasks/TaskToolRiskPolicy.php';

$passed=0;$failed=0;
$ok=function(bool$value,string$name)use(&$passed,&$failed):void{echo($value?'PASS ':'FAIL ').$name."\n";$value?$passed++:$failed++;};
$executions=0;
$handler=static function(array$input)use(&$executions):ToolExecutionResult{$executions++;return new ToolExecutionResult('unexpected');};
$tools=new ToolRegistry();
foreach(['grep','view','search']as$key)$tools->register($key,$handler,'read_only');
foreach(['str_replace','code_edit']as$key)$tools->register($key,$handler,'non_idempotent');
$tools->register('controlled_write',$handler,'idempotent_write');
$policy=new TaskToolRiskPolicy($tools);

foreach(['grep','view','search']as$key){$decision=$policy->decide($key);$ok($decision->isAllowed()&&!$decision->requiresApproval()&&$decision->decision===TaskToolRiskDecision::ALLOWED,"{$key} read_only queda ALLOWED");}
foreach(['str_replace','code_edit']as$key){$decision=$policy->decide($key);$ok(!$decision->isAllowed()&&$decision->requiresApproval()&&$decision->decision===TaskToolRiskDecision::APPROVAL_REQUIRED,"{$key} non_idempotent exige aprobación");}
$write=$policy->decide('controlled_write');
$ok($write->effect==='idempotent_write'&&$write->requiresApproval(),'idempotent_write exige aprobación');

try{$policy->decide('unknown_tool');$ok(false,'Tool desconocida falla cerrada');}
catch(TaskValidationException$e){$ok($e->getMessage()==='tool_not_supported','Tool desconocida conserva excepción controlada');}

$property=new ReflectionProperty(ToolRegistry::class,'tools');$property->setAccessible(true);
$corrupt=$property->getValue($tools);$corrupt['corrupt_effect']=['handler'=>Closure::fromCallable($handler),'effect'=>'unexpected'];$property->setValue($tools,$corrupt);
try{$policy->decide('corrupt_effect');$ok(false,'effect inválido falla cerrado');}
catch(TaskValidationException$e){$ok($e->getMessage()==='tool_effect_invalid','effect inválido produce excepción controlada');}

$ok($executions===0,'decidir riesgo no ejecuta handlers ni crea ToolCalls');
$public=array_keys(get_object_vars($write));sort($public);
$ok($public===['decision','effect','toolKey'],'decisión solo contiene metadata no sensible');
$factory=file_get_contents(__DIR__.'/../includes/Tools/ToolRegistryFactory.php');
foreach(['grep','view','search']as$key)$ok(str_contains($factory,"register('{$key}'")&&str_contains($factory,"'read_only'"),"Factory productiva registra {$key} como lectura");
foreach(['str_replace','code_edit']as$key)$ok(str_contains($factory,"register('{$key}'")&&str_contains($factory,"'non_idempotent'"),"Factory productiva registra {$key} como escritura no idempotente");
$toolSources='';foreach(glob(__DIR__.'/../includes/Tools/*.php')as$file)$toolSources.=file_get_contents($file);
$ok(!str_contains($toolSources,'TaskToolRiskPolicy')&&!str_contains($toolSources,'TaskToolRiskDecision'),'Tools no depende de la política Tasks');
$policySource=file_get_contents(__DIR__.'/../includes/Tasks/TaskToolRiskPolicy.php');
$ok(str_contains($policySource,'$this->tools->effect($toolKey)')&&!str_contains($policySource,'->execute('),'política consulta metadata server-side sin ejecutar Tools');

echo"Resultado: {$passed} passed, {$failed} failed\n";
exit($failed?1:0);
