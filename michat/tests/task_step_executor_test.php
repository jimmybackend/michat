<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/Tasks/bootstrap.php';
final class RecordingTaskArtifactRepository extends TaskArtifactRepository{
 public array$records=[];public bool$fail=false;public function __construct(){}
 public function record(int$executionId,?int$toolCallId,string$relation,string$resourceType,int$resourceId):array{$this->records[]=[$executionId,$toolCallId,$relation,$resourceType,$resourceId];if($this->fail)throw new RuntimeException('artifact_persist_failed');return['id_'=>count($this->records)];}
}
$passed=0;$failed=0;$ok=function(bool$v,string$n)use(&$passed,&$failed){echo($v?'PASS ':'FAIL ').$n."\n";$v?$passed++:$failed++;};
$chat=new ChatExecutionService(new CallableChatRuntime(static fn(ChatExecutionRequest$r)=>new ChatExecutionResult('real reply',42,$r->model??'configured',$r->traceId)));
$tools=new ToolRegistry();$calls=0;$artifactRepo=new RecordingTaskArtifactRepository();
$normalized=[['relation'=>'modified','resource_type'=>'project_source','resource_id'=>7],['relation'=>'generated','resource_type'=>'file_version','resource_id'=>9]];
$viewArtifact=[['relation'=>'read','resource_type'=>'source_chunk','resource_id'=>13]];
$tools->register('view',static function(array$i)use(&$calls,$viewArtifact){$calls++;return new ToolExecutionResult('viewed',$viewArtifact,['results'=>[['chunk_id'=>999]]],true,'ok',77);},'read_only');
$grepArtifacts=[['relation'=>'read','resource_type'=>'source_chunk','resource_id'=>21],['relation'=>'read','resource_type'=>'source_chunk','resource_id'=>22]];
$searchArtifacts=[['relation'=>'read','resource_type'=>'source_chunk','resource_id'=>31],['relation'=>'read','resource_type'=>'source_chunk','resource_id'=>32]];
$tools->register('grep',static fn(array$i)=>new ToolExecutionResult('grep',$grepArtifacts,['results'=>[['chunk_id'=>901],['chunk_id'=>902]]],true,'ok',78),'read_only');
$tools->register('search',static fn(array$i)=>new ToolExecutionResult('search',$searchArtifacts,['results'=>[['chunk_id'=>903],['chunk_id'=>904]]],true,'ok',81),'read_only');
$tools->register('empty_search',static fn(array$i)=>new ToolExecutionResult('empty',[],['results'=>[]],true,'ok',82),'read_only');
$tools->register('malformed',static fn(array$i)=>new ToolExecutionResult('bad',[['relation'=>'read']],[],true,'ok',83),'read_only');
$tools->register('code_edit',static fn(array$i)=>new ToolExecutionResult('edited',$normalized,[],true,'ok',80),'non_idempotent');
$codeEditReadArtifact=[['relation'=>'read','resource_type'=>'project_source','resource_id'=>14]];
$tools->register('code_edit_read',static fn(array$i)=>new ToolExecutionResult('read',$codeEditReadArtifact,['source_id'=>999,'content'=>'functional'],true,'ok',84),'read_only');
$tools->register('missing_tool_call',static fn(array$i)=>new ToolExecutionResult('missing-id',[['relation'=>'read','resource_type'=>'source_chunk','resource_id'=>13]]),'read_only');
$tools->register('str_replace',static fn(array$i)=>new ToolExecutionResult('persist-error',[['relation'=>'modified','resource_type'=>'project_source','resource_id'=>7]],[],true,'ok',79),'non_idempotent');
$registry=new TaskStepExecutorRegistry();
$registry->register('model',new ModelTaskStepExecutor($chat));$registry->register('tool',new ToolTaskStepExecutor($tools,$artifactRepo));$registry->register('validation',new ValidationTaskStepExecutor());$registry->register('finalize',new FinalizeTaskStepExecutor());$registry->register('approval',new ApprovalTaskStepExecutor());$registry->register('wait',new WaitTaskStepExecutor());$registry->register('plan',new PlanTaskStepExecutor());
$service=new TaskStepExecutionService($registry);$heartbeat=0;$beat=function()use(&$heartbeat){$heartbeat++;};$notCancelled=static fn()=>false;
try{$registry->get('Some\\InjectedClass');$ok(false,'registry rechaza tipo desconocido');}catch(TaskValidationException$e){$ok($e->getMessage()==='step_executor_not_supported','registry rechaza tipo desconocido');}
$base=['user_id'=>1,'session_id'=>2,'trace_id'=>'trace-a','step_type'=>'model','input'=>['prompt'=>'hello','model_id'=>'runtime-model']];
$model=$service->execute($base,$beat,$notCancelled);$ok($model->status==='completed'&&$model->messageId===42,'model usa ChatExecutionService');
try{$service->execute(['step_type'=>'tool','input'=>['tool_key'=>'search']],$beat,$notCancelled);$ok(false,'tool exige execution server-side');}catch(TaskValidationException$e){$ok($e->getMessage()==='execution_id_invalid','tool exige execution server-side positiva');}
$tool=$service->execute(['execution_id'=>55,'step_type'=>'tool','input'=>['tool_key'=>'view','arguments'=>[]]],$beat,$notCancelled);$ok($calls===1&&$tool->artifacts===$viewArtifact,'view conserva el artifact SourceChunk read en TaskStepExecutionResult');
$ok($artifactRepo->records===[[55,77,'read','source_chunk',13]],'view persiste chunk real con execution/toolCall sin reconstruir ID desde data');
$codeEdit=$service->execute(['execution_id'=>55,'step_type'=>'tool','input'=>['tool_key'=>'code_edit']],$beat,$notCancelled);$ok($codeEdit->artifacts===$normalized&&array_slice($artifactRepo->records,-2)===[[55,80,'modified','project_source',7],[55,80,'generated','file_version',9]],'code_edit conserva sus dos artifacts normalizados');
$codeEditRead=$service->execute(['execution_id'=>59,'step_type'=>'tool','input'=>['tool_key'=>'code_edit_read']],$beat,$notCancelled);$ok($codeEditRead->artifacts===$codeEditReadArtifact&&end($artifactRepo->records)===[59,84,'read','project_source',14],'code_edit read persiste artifact con execution/toolCall sin usar source_id de data');
$grep=$service->execute(['execution_id'=>57,'step_type'=>'tool','input'=>['tool_key'=>'grep']],$beat,$notCancelled);$ok($grep->artifacts===$grepArtifacts&&array_slice($artifactRepo->records,-2)===[[57,78,'read','source_chunk',21],[57,78,'read','source_chunk',22]],'grep persiste múltiples chunks con execution/toolCall comunes sin usar data');
$search=$service->execute(['execution_id'=>58,'step_type'=>'tool','input'=>['tool_key'=>'search']],$beat,$notCancelled);$ok($search->artifacts===$searchArtifacts&&array_slice($artifactRepo->records,-2)===[[58,81,'read','source_chunk',31],[58,81,'read','source_chunk',32]],'search persiste múltiples chunks con execution/toolCall comunes sin usar data');
$before=count($artifactRepo->records);$empty=$service->execute(['execution_id'=>55,'step_type'=>'tool','input'=>['tool_key'=>'empty_search']],$beat,$notCancelled);$ok($empty->status==='completed'&&count($artifactRepo->records)===$before,'artifacts vacíos no intentan persistencia');
try{$service->execute(['execution_id'=>55,'step_type'=>'tool','input'=>['tool_key'=>'malformed']],$beat,$notCancelled);$ok(false,'artifact malformado falla');}catch(TaskValidationException$e){$ok($e->getMessage()==='tool_artifact_invalid','artifact malformado falla explícitamente');}
try{$service->execute(['execution_id'=>55,'step_type'=>'tool','input'=>['tool_key'=>'missing_tool_call']],$beat,$notCancelled);$ok(false,'artifact sin ToolCall falla');}catch(TaskValidationException$e){$ok($e->getMessage()==='tool_call_id_missing','artifact sin ToolCall falla explícitamente');}
$strResult=$service->execute(['execution_id'=>56,'step_type'=>'tool','input'=>['tool_key'=>'str_replace']],$beat,$notCancelled);$ok($strResult->status==='completed'&&end($artifactRepo->records)===[56,79,'modified','project_source',7],'str_replace persiste un artifact con execution/toolCall del runtime');
$artifactRepo->fail=true;try{$service->execute(['execution_id'=>56,'step_type'=>'tool','input'=>['tool_key'=>'str_replace']],$beat,$notCancelled);$ok(false,'error Repository impide completed');}catch(RuntimeException$e){$ok($e->getMessage()==='artifact_persist_failed','error Repository impide completed');}$artifactRepo->fail=false;
try{$service->execute(['execution_id'=>55,'step_type'=>'tool','input'=>['tool_key'=>'exec']],$beat,$notCancelled);$ok(false,'tool desconocida rechazada');}catch(TaskValidationException$e){$ok(true,'tool desconocida rechazada');}
$ok($tools->effect('view')==='read_only','metadata read_only');
$factorySource=file_get_contents(__DIR__.'/../includes/Tasks/TaskStepExecutionServiceFactory.php');$ok(str_contains($factorySource,'new ToolTaskStepExecutor')&&str_contains($factorySource,'new TaskArtifactRepository($this->db)'),'factory productiva inyecta Repository con mysqli compartido');
$approval=$service->execute(['step_type'=>'approval'],$beat,$notCancelled);$wait=$service->execute(['step_type'=>'wait','input'=>['wait_until'=>'2030-01-01T00:00:00Z'],'now'=>'2029-01-01T00:00:00Z'],$beat,$notCancelled);$due=$service->execute(['step_type'=>'wait','input'=>['wait_until'=>'2028-01-01T00:00:00Z'],'now'=>'2029-01-01T00:00:00Z'],$beat,$notCancelled);$ok($approval->status==='waiting_user','approval pausa sin dormir');$ok($wait->status==='waiting_dependency'&&($wait->checkpoint['wait_until']??'')==='2030-01-01 00:00:00.000000','wait futuro persiste fecha UTC');$ok($due->status==='completed','wait vencido completa sin dormir');
$machine=new TaskStepStateMachine();try{$machine->assertTransition('pending','ready');$machine->assertTransition('ready','running');$machine->assertTransition('running','completed');$ok(true,'flujo de estados completo');}catch(Throwable$e){$ok(false,'flujo de estados completo');}
$sources='';foreach(glob(__DIR__.'/../includes/{Tasks,Tools,Chat}/*.php',GLOB_BRACE)as$f)$sources.=file_get_contents($f);
$ok(!preg_match('/\b(?:eval|shell_exec|passthru|system)\s*\(/',$sources),'sin ejecución dinámica o shell arbitrario');
$ok(!str_contains(file_get_contents(__DIR__.'/../includes/Tasks/TaskWorker.php'),'http'),'Worker no llama HTTP');
$ok($heartbeat>=4,'heartbeat alrededor de modelo/tool');
echo"Resultado: $passed passed, $failed failed\n";exit($failed?1:0);
