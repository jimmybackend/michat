<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/Tasks/TaskExceptions.php';
require_once __DIR__.'/../includes/Tools/ToolExecutionResult.php';
require_once __DIR__.'/../includes/Tools/ToolRegistry.php';
foreach(['TaskToolRiskDecision','TaskToolRiskPolicy','TaskToolApprovalProposal','TaskToolApprovalFingerprint','TaskToolApprovalIdentity','TaskToolApprovalProposalFactory']as$file)require_once __DIR__.'/../includes/Tasks/'.$file.'.php';

$passed=0;$failed=0;$ok=function(bool$value,string$name)use(&$passed,&$failed):void{echo($value?'PASS ':'FAIL ').$name."\n";$value?$passed++:$failed++;};
$executions=0;$handler=static function(array$input)use(&$executions):ToolExecutionResult{$executions++;return new ToolExecutionResult('unexpected');};
$tools=new ToolRegistry();foreach(['grep','view','search']as$key)$tools->register($key,$handler,'read_only');foreach(['str_replace','code_edit']as$key)$tools->register($key,$handler,'non_idempotent');
$fingerprints=new TaskToolApprovalFingerprint();$factory=new TaskToolApprovalProposalFactory(new TaskToolRiskPolicy($tools),$fingerprints);
$scope=['task_id'=>11,'step_id'=>12,'execution_id'=>13,'user_id'=>14,'project_id'=>15,'session_id'=>16];
$code=['action'=>'write','target_filename'=>'App.php','instruction'=>'Change return value to A'];
$codeReordered=['instruction'=>'Change return value to A','target_filename'=>'App.php','action'=>'write'];

$first=$factory->create('code_edit',$code,$scope);$same=$factory->create('code_edit',$code,$scope);$reordered=$factory->create('code_edit',$codeReordered,array_reverse($scope,true));
$ok($first->fingerprint===$same->fingerprint,'mismos datos producen el mismo fingerprint');
$ok($first->fingerprint===$reordered->fingerprint,'orden de claves de mapas no altera fingerprint');
$continuationScope=$scope;$continuationScope['execution_id']=14;$continued=$factory->recreateForContinuation('code_edit',$code,$continuationScope,new TaskToolApprovalIdentity(13));
$ok($continued->fingerprint===$first->fingerprint,'continuación usa Execution proponente para recrear el fingerprint aprobado');
$ok($fingerprints->create('code_edit','non_idempotent',$code,$continuationScope)!==$first->fingerprint,'usar Execution continuadora directamente produciría otro fingerprint');
$listA=$fingerprints->create('code_edit','non_idempotent',['items'=>['a','b']],$scope);$listB=$fingerprints->create('code_edit','non_idempotent',['items'=>['b','a']],$scope);
$ok($listA!==$listB,'orden de listas altera fingerprint');
$ok($fingerprints->create('tool_a','non_idempotent',$code,$scope)!==$fingerprints->create('tool_b','non_idempotent',$code,$scope),'Tool distinta altera fingerprint');
$ok($fingerprints->create('code_edit','non_idempotent',$code,$scope)!==$fingerprints->create('code_edit','idempotent_write',$code,$scope),'effect distinto altera fingerprint');
foreach(['execution_id','step_id','task_id','project_id','user_id','session_id']as$key){$changed=$scope;$changed[$key]++;$ok($fingerprints->create('code_edit','non_idempotent',$code,$scope)!==$fingerprints->create('code_edit','non_idempotent',$code,$changed),"{$key} distinto altera fingerprint");}
$changedCode=$code;$changedCode['instruction']='Change return value to B';
$ok($first->fingerprint!==$factory->create('code_edit',$changedCode,$scope)->fingerprint,'un byte distinto en instrucción altera fingerprint');
foreach([[['value'=>null],['value'=>''],'null y string vacío'],[['value'=>false],['value'=>0],'false e int cero'],[['value'=>1],['value'=>'1'],'int y string']]as[$left,$right,$name])$ok($fingerprints->create('code_edit','non_idempotent',$left,$scope)!==$fingerprints->create('code_edit','non_idempotent',$right,$scope),$name.' son distintos');

foreach([['object'=>new stdClass()],['closure'=>static fn()=>null],['nan'=>NAN],['inf'=>INF]]as$invalid){try{$fingerprints->create('code_edit','non_idempotent',$invalid,$scope);$ok(false,'tipo inválido falla cerrado');}catch(TaskValidationException$e){$ok($e->getMessage()==='tool_approval_identity_invalid','tipo inválido falla cerrado');}}
try{$fingerprints->create('code_edit','non_idempotent',$code,array_diff_key($scope,['step_id'=>true]));$ok(false,'scope incompleto falla');}catch(TaskValidationException$e){$ok($e->getMessage()==='tool_approval_scope_invalid','scope incompleto falla cerrado');}

$safeCode=$first->toArray();$encodedCode=json_encode($safeCode,JSON_THROW_ON_ERROR);
$ok($safeCode['safe_summary']==='Modificar archivo'&&$safeCode['safe_target']==='App.php','code_edit tiene display humano seguro');
$ok(!str_contains($encodedCode,'Change return value')&&!str_contains($encodedCode,'instruction')&&!array_key_exists('arguments',$safeCode),'code_edit no expone instrucción ni argumentos');
$replaceA=['source_id'=>91,'old_text'=>'private old secret','new_text'=>'private new secret A'];$replaceB=$replaceA;$replaceB['new_text']='private new secret B';
$proposalA=$factory->create('str_replace',$replaceA,$scope);$proposalB=$factory->create('str_replace',$replaceB,$scope);$safeReplace=$proposalA->toArray();$encodedReplace=json_encode($safeReplace,JSON_THROW_ON_ERROR);
$ok($safeReplace['safe_summary']==='Reemplazar contenido en archivo'&&$safeReplace['safe_target']===null,'str_replace no inventa target sin filename seguro');
$ok($proposalA->fingerprint!==$proposalB->fingerprint,'replacements distintos con mismo display producen fingerprints distintos');
$ok(!str_contains($encodedReplace,'private')&&!str_contains($encodedReplace,'old_text')&&!str_contains($encodedReplace,'new_text'),'str_replace no expone replacement ni params');
$ok(array_keys($safeCode)===['format_version','tool_key','effect','reason_code','safe_summary','safe_target','fingerprint']&&$safeCode['format_version']===1,'proposal expone únicamente contrato seguro versionado');
$ok($safeCode['reason_code']==='write_requires_approval'&&strlen($safeCode['fingerprint'])===64&&ctype_xdigit($safeCode['fingerprint']),'reason y SHA-256 tienen formato seguro');
$restored=TaskToolApprovalProposal::fromArray(array_reverse($safeCode,true));$ok($restored->toArray()===$safeCode,'proposal persistida valida sin depender del orden JSON de MySQL');
$invalidProposal=$safeCode;$invalidProposal['effect']='read_only';try{TaskToolApprovalProposal::fromArray($invalidProposal);$ok(false,'proposal read_only corrupta falla');}catch(TaskValidationException$e){$ok($e->getMessage()==='tool_approval_checkpoint_invalid','proposal persistida inválida falla cerrada');}
foreach(['grep','view','search']as$key){try{$factory->create($key,[],$scope);$ok(false,"{$key} no debe generar proposal");}catch(TaskValidationException$e){$ok($e->getMessage()==='tool_approval_not_required',"{$key} ALLOWED no genera proposal");}}
try{$factory->create('code_edit',['target_filename'=>'/srv/private/App.php','instruction'=>'secret'],$scope);$ok(false,'ruta absoluta no se muestra');}catch(TaskValidationException$e){$ok($e->getMessage()==='tool_approval_target_invalid','ruta absoluta se rechaza');}
$ok($executions===0,'crear proposals no ejecuta handlers');
$sources='';foreach(['TaskToolApprovalProposal.php','TaskToolApprovalFingerprint.php','TaskToolApprovalProposalFactory.php']as$file)$sources.=file_get_contents(__DIR__.'/../includes/Tasks/'.$file);
$ok(!preg_match('/\b(?:mysqli|Config::getS3|ToolCalls|TaskEvents|TaskArtifacts|->execute\s*\()/',$sources),'proposal no usa SQL, S3, persistencia ni handlers');
$toolSources='';foreach(glob(__DIR__.'/../includes/Tools/*.php')as$file)$toolSources.=file_get_contents($file);
$ok(!str_contains($toolSources,'TaskToolApproval'),'Tools no depende de Tasks HITL');

echo"Resultado: {$passed} passed, {$failed} failed\n";exit($failed?1:0);
