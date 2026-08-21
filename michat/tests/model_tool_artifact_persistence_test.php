<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/Tasks/bootstrap.php';

final class ModelArtifactRecordingRepository extends TaskArtifactRepository
{
    public array $records=[];public bool $fail=false;public function __construct(){}
    public function record(int$executionId,?int$toolCallId,string$relation,string$resourceType,int$resourceId):array
    {
        if($this->fail)throw new RuntimeException('model_artifact_persist_failed');
        $this->records[]=[$executionId,$toolCallId,$relation,$resourceType,$resourceId];return['id_'=>count($this->records)];
    }
}
final class ObservingModelRuntime implements ChatRuntimeInterface
{
    /** @param list<ToolExecutionResult> $results */
    public function __construct(private ToolExecutionObserverInterface$observer,private array$results,private bool$failAfter=false){}
    public function execute(ChatExecutionRequest$request,?callable$heartbeat=null):ChatExecutionResult
    {
        foreach($this->results as$result)$this->observer->observe($request->taskContext,$result);
        if($this->failAfter)throw new RuntimeException('later_model_round_failed');
        return new ChatExecutionResult('model complete',null,'test-model',$request->traceId);
    }
}

$passed=0;$failed=0;$ok=function(bool$v,string$n)use(&$passed,&$failed):void{echo($v?'PASS ':'FAIL ').$n."\n";$v?$passed++:$failed++;};
$repo=new ModelArtifactRecordingRepository();$observer=new TaskToolExecutionArtifactObserver($repo);
$first=new ToolExecutionResult('first',[['relation'=>'read','resource_type'=>'source_chunk','resource_id'=>7],['relation'=>'read','resource_type'=>'source_chunk','resource_id'=>8]],['chunk_id'=>999],true,'ok',101);
$second=new ToolExecutionResult('second',[['relation'=>'modified','resource_type'=>'project_source','resource_id'=>3],['relation'=>'generated','resource_type'=>'file_version','resource_id'=>21]],['source_id'=>998,'file_version_id'=>997],true,'ok',102);
$chat=new ChatExecutionService(new ObservingModelRuntime($observer,[$first,$second]));$executor=new ModelTaskStepExecutor($chat);
$context=['execution_id'=>55,'task_id'=>44,'step_id'=>33,'user_id'=>2,'project_id'=>5,'session_id'=>6,'trace_id'=>'model-tool-trace','objective'=>'test','input'=>[]];
$step=$executor->execute($context,static function():void{},static fn():bool=>false);
$expected=[[55,101,'read','source_chunk',7],[55,101,'read','source_chunk',8],[55,102,'modified','project_source',3],[55,102,'generated','file_version',21]];
$ok($repo->records===$expected,'Model Step conserva execution común, ToolCall propia y múltiples artifacts sin usar data');
$ok($step->status==='completed'&&$step->artifacts===[]&&count($repo->records)===4,'persistencia ocurre durante ToolCall y no se duplica al terminar ModelTaskStepExecutor');
$before=count($repo->records);$observer->observe([],new ToolExecutionResult('empty',[],['resource_id'=>999],true,'ok',103));$ok(count($repo->records)===$before,'artifacts vacíos no requieren contexto Task ni producen inserts');
try{$observer->observe([],new ToolExecutionResult('missing-execution',[['relation'=>'read','resource_type'=>'source_chunk','resource_id'=>9]],[],true,'ok',103));$ok(false,'artifact sin Execution falla');}catch(TaskValidationException$e){$ok($e->getMessage()==='execution_id_invalid','execution_id se exige desde contexto server-side');}
try{$observer->observe(['execution_id'=>55],new ToolExecutionResult('missing',[['relation'=>'read','resource_type'=>'source_chunk','resource_id'=>9]]));$ok(false,'artifact sin ToolCall falla');}catch(TaskValidationException$e){$ok($e->getMessage()==='tool_call_id_missing','artifact sin ToolCall falla explícitamente');}
$repo->fail=true;try{$observer->observe(['execution_id'=>55],new ToolExecutionResult('failure',[['relation'=>'read','resource_type'=>'source_chunk','resource_id'=>9]],[],true,'ok',104));$ok(false,'error Repository detiene observer');}catch(RuntimeException$e){$ok($e->getMessage()==='model_artifact_persist_failed','error Repository detiene flujo');}$repo->fail=false;
$persistedBeforeFailure=new ToolExecutionResult('persisted',[['relation'=>'modified','resource_type'=>'project_source','resource_id'=>12]],['source_id'=>999],true,'ok',105);
try{(new ModelTaskStepExecutor(new ChatExecutionService(new ObservingModelRuntime($observer,[$persistedBeforeFailure],true))))->execute($context,static function():void{},static fn():bool=>false);$ok(false,'fallo posterior se propaga');}catch(RuntimeException$e){$ok($e->getMessage()==='later_model_round_failed'&&end($repo->records)===[55,105,'modified','project_source',12],'provenance persiste antes de fallo posterior del modelo');}
$outsideRequest=new ChatExecutionRequest(2,6,5,null,'outside',null,'hello',null,'test-model',0.1,10,0.9,'outside-trace',[]);
$outside=(new ChatExecutionService(new CallableChatRuntime(static fn(ChatExecutionRequest$r):ChatExecutionResult=>new ChatExecutionResult('outside ok',null,'test-model',$r->traceId))))->execute($outsideRequest);
$ok($outside->replyText==='outside ok','ChatExecutionService fuera de Tasks funciona sin observer ni Repository');
$bedrock=file_get_contents(__DIR__.'/../includes/Chat/BedrockChatRuntime.php');$executeAt=strpos($bedrock,'$result=$this->tools->execute');$observeAt=strpos($bedrock,'$this->toolObserver?->observe');$continueAt=strpos($bedrock,"\$results[]=['toolResult'",$observeAt);
$ok($executeAt!==false&&$executeAt<$observeAt&&$observeAt<$continueAt,'Bedrock notifica inmediatamente después de ToolRegistry y antes de continuar loop');
$ok(str_contains($bedrock,'?ToolExecutionObserverInterface $toolObserver=null')&&!str_contains($bedrock,'TaskArtifactRepository'),'Bedrock depende sólo del observer opcional de Chat');
$factory=file_get_contents(__DIR__.'/../includes/Tasks/TaskStepExecutionServiceFactory.php');$ok(str_contains($factory,'new TaskToolExecutionArtifactObserver($artifacts)')&&str_contains($factory,'new ChatExecutionServiceFactory($this->db,$toolObserver,$tools,$modelGate,$cancellations)'),'Factory Task conserva observer específico al activar gate Model');
echo"Resultado: $passed passed, $failed failed\n";exit($failed?1:0);
