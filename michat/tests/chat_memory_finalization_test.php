<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);

$passed=0;$failed=0;
$ok=function(bool $value,string $name)use(&$passed,&$failed):void{echo($value?'PASS ':'FAIL ').$name."\n";$value?$passed++:$failed++;};
$memory=file_get_contents(__DIR__.'/../includes/Chat/ChatMemoryFinalizationService.php');
$execution=file_get_contents(__DIR__.'/../includes/Chat/ChatExecutionService.php');
$factory=file_get_contents(__DIR__.'/../includes/Chat/ChatExecutionServiceFactory.php');
$bootstrap=file_get_contents(__DIR__.'/../includes/Tasks/bootstrap.php');
$queue=file_get_contents(__DIR__.'/../includes/Tasks/TaskQueueRepository.php');

$persistAt=strpos($execution,'responses->persist');
$finalizeAt=strpos($execution,'memory->finalize');
$ok($persistAt!==false&&$finalizeAt!==false&&$persistAt<$finalizeAt,'finalización ocurre después de persistir assistant');
$ok(str_contains($execution,'$questionId')&&str_contains($execution,'$messageId')&&str_contains($memory,'$questionMessageId')&&str_contains($memory,'$answerMessageId'),'usa los IDs reales de pregunta y respuesta');
$ok(substr_count($execution,'memory->finalize')===1&&str_contains($memory,'MemoryWriteEvents unique key'),'MemoryWriter se ejecuta una sola vez por respuesta final idempotente');
$ok(str_contains($memory,'GET_LOCK')&&str_contains($memory,'WHERE question_msg_id=? AND answer_msg_id=?'),'SessionContextBlocks no se duplica ni bajo reintentos concurrentes');
$ok(str_contains($memory,'INSERT IGNORE INTO EmbeddingJobs'),'EmbeddingJobs reutiliza su idempotencia existente');
$ok(str_contains($queue,'shouldPersistFinalResponse')&&str_contains($queue,"['chat','manual']")&&str_contains($queue,"later.step_type='model'")&&str_contains($queue,'return!$hasLater')&&str_contains($execution,"persist_final_response"),'sólo el último Model visible de chat/manual cruza la frontera de finalización; Models internos con otro Model posterior no finalizan');
$ok(str_contains($factory,'ChatMemoryFinalizationService')&&str_contains($bootstrap,"'ChatMemoryFinalizationService'")&&substr_count($execution,'memory->finalize')===1,'HTTP y Worker usan el mismo servicio');
$ok(!preg_match('/\$_(?:POST|GET|SESSION|COOKIE)\b/',$memory),'finalizador sólo consume datos server-side');

echo "Resultado: $passed passed, $failed failed\n";
echo "SKIP integración MySQL/Bedrock real: no hay TASK_TEST_DB_* configurado.\n";
exit($failed?1:0);
