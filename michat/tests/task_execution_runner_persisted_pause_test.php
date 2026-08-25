<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/Tasks/bootstrap.php';

final class RunnerTestProgression implements TaskStepProgressionInterface
{
    public int$applied=0,$failed=0,$cancelled=0;
    public function apply(array$context,TaskStepExecutionResult$result):bool{$this->applied++;return true;}
    public function fail(array$context,string$error,?TaskFailureDisposition$disposition=null):bool{$this->failed++;return true;}
    public function cancel(array$context):bool{$this->cancelled++;return true;}
}
final class RunnerTestLeases implements TaskLeaseInterface
{
    public int$heartbeats=0,$assertions=0;
    public function heartbeat(array$context):bool{$this->heartbeats++;return true;}
    public function assertActive(array$context):void{$this->assertions++;}
}
final class RunnerTestSteps implements TaskStepExecutionInterface
{
    public int$calls=0;public function __construct(private$callback){}
    public function execute(array$context,callable$heartbeat,callable$isCancelled):TaskStepExecutionResult{$this->calls++;return($this->callback)($context,$heartbeat,$isCancelled);}
}

$passed=0;$failed=0;$ok=function(bool$value,string$name)use(&$passed,&$failed):void{echo($value?'PASS ':'FAIL ').$name."\n";$value?$passed++:$failed++;};
$completed=TaskStepExecutionResult::completed('done');$waiting=TaskStepExecutionResult::waiting('approval');$dependency=TaskStepExecutionResult::waitingDependency('wait',['until'=>'later']);$persisted=TaskStepExecutionResult::persistedWaitingUser('persisted');
$ok(!$completed->isDurablePauseAlreadyPersisted()&&!$waiting->isDurablePauseAlreadyPersisted()&&!$dependency->isDurablePauseAlreadyPersisted(),'resultados ordinarios requieren persistencia normal');
$ok($persisted->status==='waiting_user'&&$persisted->isDurablePauseAlreadyPersisted(),'factory identifica pausa durable ya persistida');
try{new TaskStepExecutionResult('completed','',[],null,null,true);$ok(false,'completed no puede omitir persistencia');}catch(Throwable$e){$ok($e instanceof InvalidArgumentException&&$e->getMessage()==='persisted_pause_status_invalid','combinación persisted completed falla cerrada');}

$context=['execution_id'=>1];$normalProgression=new RunnerTestProgression();$normalLeases=new RunnerTestLeases();$normalSteps=new RunnerTestSteps(static fn():TaskStepExecutionResult=>TaskStepExecutionResult::completed('done'));
$normal=(new TaskExecutionRunner($normalProgression,$normalLeases,$normalSteps))->run($context);
$ok($normal&&$normalSteps->calls===1&&$normalLeases->heartbeats===2&&$normalProgression->applied===1,'resultado normal ejecuta heartbeat inicial/posterior y apply una vez');

$persistedProgression=new RunnerTestProgression();$persistedLeases=new RunnerTestLeases();$persistedSteps=new RunnerTestSteps(static fn():TaskStepExecutionResult=>TaskStepExecutionResult::persistedWaitingUser('paused'));
$paused=(new TaskExecutionRunner($persistedProgression,$persistedLeases,$persistedSteps))->run($context);
$ok($paused&&$persistedSteps->calls===1&&$persistedLeases->heartbeats===1,'pausa persistida conserva heartbeat inicial y omite el posterior');
$ok($persistedProgression->applied===0&&$persistedProgression->failed===0&&$persistedProgression->cancelled===0,'pausa persistida no alcanza progression ni finish indirecto');

$approvalProgression=new RunnerTestProgression();$approvalLeases=new RunnerTestLeases();$approvalExecutor=new ApprovalTaskStepExecutor();$approvalSteps=new RunnerTestSteps(static fn(array$c,callable$h,callable$x):TaskStepExecutionResult=>$approvalExecutor->execute($c,$h,$x));
$ok((new TaskExecutionRunner($approvalProgression,$approvalLeases,$approvalSteps))->run($context)&&$approvalProgression->applied===1&&$approvalLeases->heartbeats===2,'Approval Step conserva waiting_user y progresión ordinarios');
$waitProgression=new RunnerTestProgression();$waitLeases=new RunnerTestLeases();$waitExecutor=new WaitTaskStepExecutor();$waitSteps=new RunnerTestSteps(static fn(array$c,callable$h,callable$x):TaskStepExecutionResult=>$waitExecutor->execute($c,$h,$x));
$waitContext=$context+['input'=>['wait_until'=>'2030-01-01T00:00:00Z'],'now'=>'2029-01-01T00:00:00Z'];$ok((new TaskExecutionRunner($waitProgression,$waitLeases,$waitSteps))->run($waitContext)&&$waitProgression->applied===1,'Wait Step conserva waiting_dependency y progresión ordinarios');

$errorProgression=new RunnerTestProgression();$errorLeases=new RunnerTestLeases();$errorSteps=new RunnerTestSteps(static function():TaskStepExecutionResult{throw new RuntimeException('controlled failure');});
$ok(!(new TaskExecutionRunner($errorProgression,$errorLeases,$errorSteps))->run($context)&&$errorSteps->calls===1&&$errorProgression->failed===1,'excepción conserva sanitización y progresión de fallo');
$toolSource=file_get_contents(__DIR__.'/../includes/Tasks/ToolTaskStepExecutor.php');$ok(str_contains($toolSource,'persistedWaitingUser'),'ToolTaskStepExecutor conecta el contrato únicamente para su pausa HITL');
echo"Resultado: {$passed} passed, {$failed} failed\n";exit($failed?1:0);
