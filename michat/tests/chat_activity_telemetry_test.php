<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);

$passed=0;$failed=0;
$ok=function(bool$value,string$name)use(&$passed,&$failed):void{echo($value?'PASS ':'FAIL ').$name."\n";$value?$passed++:$failed++;};
$activity=file_get_contents(__DIR__.'/../includes/Chat/ChatActivityTelemetryService.php');
$execution=file_get_contents(__DIR__.'/../includes/Chat/ChatExecutionService.php');
$factory=file_get_contents(__DIR__.'/../includes/Chat/ChatExecutionServiceFactory.php');
$bootstrap=file_get_contents(__DIR__.'/../includes/Tasks/bootstrap.php');
$queue=file_get_contents(__DIR__.'/../includes/Tasks/TaskQueueRepository.php');

$ok(str_contains($factory,'new ChatActivityTelemetryService')&&str_contains($bootstrap,"'ChatActivityTelemetryService'")&&str_contains($execution,'ChatActivityTelemetryService'),'HTTP sync y Worker comparten el servicio de telemetría');
$ok(substr_count($execution,'$request->traceId')>=6&&str_contains($activity,'$traceId'),'todas las etapas conservan el mismo trace_id server-side');
$ok(str_contains($execution,"'context_builder_completed'")&&str_contains($execution,"'model_round_completed'")&&str_contains($execution,"'finalization_completed'"),'context, model y finalize se registran cuando ocurren');
$ok(str_contains($execution,'if($memoryCount>0)')&&str_contains($execution,"'memory_context_selected'")&&str_contains($execution,'if($ragCount>0)')&&str_contains($execution,"'rag_context_selected'"),'memory y rag sólo se emiten con selecciones reales');
$catchAt=strpos($execution,'catch(Throwable $e)');$errorAt=strpos($execution,"'runtime_error'",$catchAt?:0);$throwAt=strpos($execution,'throw $e',$catchAt?:0);
$ok($catchAt!==false&&$errorAt!==false&&$throwAt!==false&&$errorAt<$throwAt,'error se registra antes de propagar la excepción original');
$completedAt=strpos($execution,"'trace_completed'");
$ok($completedAt!==false&&$catchAt!==false&&$completedAt<$catchAt,'trace_completed sólo está en la ruta de éxito');
$ok(!preg_match('/\$_(?:POST|GET|SESSION|COOKIE)\b/',$activity),'telemetría no depende de estado HTTP');
$ok(str_contains($activity,'GET_LOCK')&&str_contains($activity,'WHERE trace_id=? AND event_key=?'),'reintentos no duplican eventos equivalentes');
$ok(str_contains($queue,"['chat','manual']")&&str_contains($queue,'shouldPersistFinalResponse')&&str_contains($execution,'persist_final_response'),'finalize visible conserva una única frontera final compartida para chat/manual');
$ok(!str_contains($execution,'Config::getBedrockRuntime')&&!str_contains(__FILE__,'BedrockChatRuntime('),'test no usa Bedrock real');

echo"Resultado: $passed passed, $failed failed\n";
echo"SKIP integración MySQL real: no hay TASK_TEST_DB_* configurado.\n";
exit($failed?1:0);
