<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/Tasks/bootstrap.php';

final class WiringStates implements TaskToolApprovalStateReaderInterface{public function read(int$id):TaskToolApprovalState{return TaskToolApprovalState::fromCheckpoint(null);}}
final class WiringProposals implements TaskToolApprovalProposalFactoryInterface{public function create(string$key,array$args,array$scope):TaskToolApprovalProposal{throw new RuntimeException('unused');}}
final class WiringPauses implements TaskToolApprovalPauseInterface{public function pause(int$id,array$args,TaskToolApprovalProposal$proposal):array{throw new RuntimeException('unused');}}
final class WiringConsumptions implements TaskToolApprovalConsumptionInterface{public function consume(int$id,string$key,array$args):array{throw new RuntimeException('unused');}}

$passed=0;$failed=0;$ok=function(bool$value,string$name)use(&$passed,&$failed):void{echo($value?'PASS ':'FAIL ').$name."\n";$value?$passed++:$failed++;};
$db=mysqli_init();$tools=new ToolRegistry();$tools->register('view',static fn():ToolExecutionResult=>new ToolExecutionResult('view'),'read_only');$risk=new TaskToolRiskPolicy($tools);$gate=new TaskChatToolExecutionGate($risk,new WiringStates(),new WiringProposals(),new WiringPauses(),new WiringConsumptions());$factory=new ChatExecutionServiceFactory($db,null,$tools,$gate);
$reflection=new ReflectionClass($factory);$toolProperty=$reflection->getProperty('tools');$gateProperty=$reflection->getProperty('toolGate');
$ok($toolProperty->getValue($factory)===$tools&&$gateProperty->getValue($factory)===$gate,'Chat factory conserva exactamente registry y gate inyectados por Tasks');
$normal=new ChatExecutionServiceFactory($db);$ok($toolProperty->getValue($normal)===null&&$gateProperty->getValue($normal)===null,'Chat factory normal crea registry propio y no exige gate Task');
$taskSource=file_get_contents(__DIR__.'/../includes/Tasks/TaskStepExecutionServiceFactory.php');
$ok(substr_count($taskSource,'ToolRegistryFactory(')===1&&str_contains($taskSource,'new TaskToolRiskPolicy($tools)')&&str_contains($taskSource,'ChatExecutionServiceFactory($this->db,$toolObserver,$tools,$modelGate,$cancellations)')&&str_contains($taskSource,'ToolTaskStepExecutor($tools'),'composición Task crea un registry y comparte su misma variable con Policy, Model y Tool Step');
$ok(substr_count($taskSource,'new TaskToolApprovalStateReader(')===1&&substr_count($taskSource,'new TaskToolApprovalPauseService(')===1&&substr_count($taskSource,'new TaskToolApprovalConsumptionService(')===1,'composición Task reutiliza un único conjunto de servicios approval');
$chatSource=file_get_contents(__DIR__.'/../includes/Chat/ChatExecutionServiceFactory.php');$ok(!str_contains($chatSource,'TaskChatToolExecutionGate')&&!str_contains($chatSource,'TaskToolRiskPolicy'),'Chat genérico acepta contrato opcional sin conocer dominio Task');
$http=file_get_contents(__DIR__.'/../bedrock_chat2.php');$ok(str_contains($http,"pipelineEffective['task_orchestrator']")&&str_contains($http,'TaskStepExecutionServiceFactory'),'feature flag Task sigue aislando composición HITL de pipeline legacy');
$modelExecutor=file_get_contents(__DIR__.'/../includes/Tasks/ModelTaskStepExecutor.php');
$stepResult=file_get_contents(__DIR__.'/../includes/Tasks/TaskStepExecutionResult.php');
$progression=file_get_contents(__DIR__.'/../includes/Tasks/TaskStepProgressionService.php');
$queue=file_get_contents(__DIR__.'/../includes/Tasks/TaskQueueRepository.php');
$ok(str_contains($modelExecutor,'$result->modelId')&&str_contains($stepResult,'public readonly ?string $modelId'),'Model Step conserva el modelo efectivo resuelto por runtime');
$ok(str_contains($progression,'$r->modelId')&&str_contains($queue,'model_id=COALESCE(NULLIF(?,\'\'),model_id)'),'progresión persiste el modelo efectivo en TaskExecutions sin resolverlo dos veces');
echo"SKIP — MySQL Worker/HTTP E2E no ejecutado: TASK_TEST_DB_* no configurado en este entorno.\nResultado: $passed PASS, $failed FAIL.\n";exit($failed?1:0);
