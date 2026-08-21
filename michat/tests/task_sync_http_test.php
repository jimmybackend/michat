<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__.'/../includes/Tasks/bootstrap.php';
require_once __DIR__.'/../includes/MemoryContextRouter.php';

$passed=0;$failed=0;
$ok=function(bool $value,string $name)use(&$passed,&$failed):void{echo($value?'PASS ':'FAIL ').$name."\n";$value?$passed++:$failed++;};

final class SyncHttpExecutorDouble implements TaskStepExecutorInterface
{
    public int $calls=0;
    public function execute(array $context,callable $heartbeat,callable $isCancelled):TaskStepExecutionResult
    {
        $this->calls++;
        return TaskStepExecutionResult::completed('double reply',[],null,91);
    }
}

$double=new SyncHttpExecutorDouble();
$registry=new TaskStepExecutorRegistry();
$registry->register('model',$double);
$result=(new TaskStepExecutionService($registry))->execute(['step_type'=>'model'],static function():void{},static fn():bool=>false);
$ok($double->calls===1&&$result->messageId===91,'TaskStepExecutionService ejecuta el double sin Bedrock');
$pause=TaskStepExecutionResult::persistedWaitingUser('Se requiere aprobación: Modificar archivo');
$pauseResponse=TaskSyncHttpPauseResponse::fromResult('00000000-0000-4000-8000-000000000000',$pause)?->toArray();
$ok($pauseResponse===['ok'=>true,'approval_required'=>true,'status'=>'waiting_user','approval_summary'=>'Se requiere aprobación: Modificar archivo','task'=>['public_id'=>'00000000-0000-4000-8000-000000000000','status'=>'waiting_user']],'pausa durable produce DTO HTTP 200 seguro waiting_user');
$encodedPause=json_encode($pauseResponse);$ok(!str_contains($encodedPause,'execution_id')&&!str_contains($encodedPause,'step_id')&&!str_contains($encodedPause,'task_id')&&!str_contains($encodedPause,'checkpoint_json')&&!str_contains($encodedPause,'input_json')&&!str_contains($encodedPause,'fingerprint')&&!str_contains($encodedPause,'consumer_execution_id'),'DTO de pausa no expone IDs internos, checkpoint, input ni fingerprint');
$ok(TaskSyncHttpPauseResponse::fromResult('public',TaskStepExecutionResult::waiting('ordinary'))===null&&TaskSyncHttpPauseResponse::fromResult('public',TaskStepExecutionResult::completed('done',[],null,91))===null,'waiting ordinario y completed no se confunden con pausa durable');

$chat=file_get_contents(__DIR__.'/../bedrock_chat2.php');
$worker=file_get_contents(__DIR__.'/../bin/task_worker.php');
$chatFactory=file_get_contents(__DIR__.'/../includes/Chat/ChatExecutionServiceFactory.php');
$contextService=file_get_contents(__DIR__.'/../includes/Chat/ChatContextPreparationService.php');
$chatService=file_get_contents(__DIR__.'/../includes/Chat/ChatExecutionService.php');
$taskStart=(int)strpos($chat,'// Fase 8.6D.1:');
$taskEnd=(int)strpos($chat,'// 1.7. MODO RESPUESTA FINAL',$taskStart);
$taskBlock=substr($chat,$taskStart,$taskEnd-$taskStart);
$servicePos=strpos($taskBlock,'TaskStepExecutionServiceFactory');
$exitPos=strpos($taskBlock,"jexit(['ok'=>true",$servicePos ?: 0);
$ok(str_contains($chat,"pipelineEffective['task_orchestrator']"),'Orchestrator OFF conserva el pipeline legacy');
$ok($servicePos!==false,'Orchestrator ON sync usa TaskStepExecutionService');
$ok(str_contains($taskBlock,'$executeApprovedTask')&&str_contains($taskBlock,'resumeApprovedToolTask('),'execute_approved_task usa el resume POO idempotente');
$ok(str_contains($taskBlock,'$taskAutoExecute')&&str_contains($taskBlock,'beginExecution('),'auto sync inicia una única Execution POO');
$ok(str_contains($taskBlock,"'async_queued'=>true")&&str_contains($worker,'TaskExecutionRunner'),'async continúa en Worker');
$ok(substr_count($taskBlock,'beginExecution(')===1&&substr_count($taskBlock,'resumeApprovedToolTask(')===1,'HTTP delega una sola creación/reutilización de TaskExecution');
$ok($servicePos!==false&&$exitPos!==false&&$exitPos>$servicePos,'Task sync sale antes del pipeline procedural');
$executePos=strpos($taskBlock,'->create()->execute(');$pausePos=strpos($taskBlock,'TaskSyncHttpPauseResponse::fromResult',$executePos?:0);$invalidPos=strpos($taskBlock,'sync_task_step_did_not_complete',$executePos?:0);$completePos=strpos($taskBlock,'completeTurn(',$executePos?:0);$failPos=strpos($taskBlock,'failTurn(',$executePos?:0);
$ok($executePos!==false&&$pausePos>$executePos&&$pausePos<$invalidPos&&$pausePos<$completePos&&$pausePos<$failPos,'HTTP detecta pausa tipada inmediatamente antes de failure y complete/fail');
$pauseBranch=substr($taskBlock,$pausePos,$invalidPos-$pausePos);$ok(str_contains($pauseBranch,'jexit($persistedPause->toArray())')&&!str_contains($pauseBranch,'completeTurn')&&!str_contains($pauseBranch,'failTurn')&&!str_contains($pauseBranch,'heartbeat')&&!str_contains($pauseBranch,'finish('),'rama de pausa termina request sin complete, fail, heartbeat ni double finish');
$bootstrap=file_get_contents(__DIR__.'/../includes/Tasks/bootstrap.php');
$application=file_get_contents(__DIR__.'/../includes/Tasks/TaskApplicationService.php');
$ok(str_contains($bootstrap,'TaskWaitService')&&str_contains($application,'approveStep('),'Approval Step y Wait permanecen disponibles');
$ok(str_contains($chatFactory,'ChatContextPreparationService')&&str_contains($chatService,'contexts->prepare'),'HTTP sync prepara Memory/RAG en ChatExecutionService compartido');
$ok(str_contains($contextService,'MemoryContextRouter')&&str_contains($contextService,'ContextBuilder'),'preparación reutiliza Router y Builder existentes');
$ok(str_contains($contextService,'ContextRanker')&&str_contains($contextService,"'project_rag_context_block'"),'ContextRanker y Project RAG alimentan el contexto');
$ok(str_contains($contextService,'$projectId=$scope->projectId()')&&str_contains($contextService,"'project_id'=>\$projectId"),'project_id procede del scope persistido');
$router=new MemoryContextRouter();
$ok(!empty($router->route('revisa el archivo src/app.php',['project_id'=>7])['use_project_rag']),'con proyecto una operación de código solicita RAG');
$ok(empty($router->route('revisa el archivo src/app.php',['project_id'=>null])['use_project_rag']),'sin proyecto no solicita RAG de proyecto');
$memoryService=file_get_contents(__DIR__.'/../includes/Chat/ChatMemoryFinalizationService.php');
$ok(!str_contains($contextService,'MemoryWriter')&&str_contains($memoryService,'MemoryWriter'),'MemoryWriter se limita a la finalización posterior a la respuesta');
$responseService=file_get_contents(__DIR__.'/../includes/Chat/ChatResponsePersistenceService.php');
$ok(str_contains($chatService,'responses->persist')&&str_contains($taskBlock,"'persist_final_response'=>true"),'Task sync persiste assistant con el servicio compartido');
$ok(str_contains($responseService,'assignResultIfEmpty'),'Task sync enlaza result_message_id_ idempotentemente');

echo "Resultado: $passed passed, $failed failed\n";
exit($failed?1:0);
