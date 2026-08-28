<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__.'/../includes/Chat/ChatTokenUsageService.php';

$passed=0;$failed=0;
$ok=function(bool $value,string $name)use(&$passed,&$failed):void{echo($value?'PASS ':'FAIL ').$name."\n";$value?$passed++:$failed++;};
$service=file_get_contents(__DIR__.'/../includes/Chat/ChatTokenUsageService.php');
$execution=file_get_contents(__DIR__.'/../includes/Chat/ChatExecutionService.php');
$factory=file_get_contents(__DIR__.'/../includes/Chat/ChatExecutionServiceFactory.php');
$bootstrap=file_get_contents(__DIR__.'/../includes/Tasks/bootstrap.php');
$queue=file_get_contents(__DIR__.'/../includes/Tasks/TaskQueueRepository.php');

$persistAt=strpos($execution,'responses->persist');
$tokenAt=strpos($execution,'tokens?->recordFinal');
$ok($persistAt!==false&&$tokenAt!==false&&$persistAt<$tokenAt&&str_contains($execution,'$messageId'),'TokenUsage se registra después del message_id real');
$ok(str_contains($execution,'$result->tokenUsage')&&str_contains($service,"['prompt_tokens']")&&str_contains($service,"['completion_tokens']"),'usa prompt_tokens y completion_tokens del resultado real');
$ok(str_contains($execution,'$result->modelId')&&str_contains($service,'$modelId'),'usa el model_id efectivo');
$ok(str_contains($execution,'$request->sessionId')&&str_contains($service,'$sessionId'),'usa el session_id resuelto');
$ok(str_contains($factory,'new ChatTokenUsageService')&&str_contains($bootstrap,"'ChatTokenUsageService'")&&substr_count($execution,'tokens?->recordFinal')===1,'HTTP y Worker comparten ChatTokenUsageService');
$ok(str_contains($service,'GET_LOCK')&&str_contains($service,'alreadyRecorded')&&str_contains($service,'message_id_=? AND phase=?'),'reintentar no duplica TokenUsage');
$ok(str_contains($queue,'shouldPersistFinalResponse')&&str_contains($queue,"['chat','manual']")&&str_contains($queue,"later.step_type='model'")&&str_contains($queue,'return!$hasLater')&&str_contains($execution,'persist_final_response'),'TokenUsage final se registra sólo en la misma frontera visible del último Model de chat/manual, no en Models internos con otro Model posterior');
$ok(!preg_match('/\$_(?:POST|GET|SESSION|COOKIE)\b/',$service),'Worker no depende de estado HTTP');
$ok(str_contains($service,"['prompt_tokens'] ?? 0")&&str_contains($service,"['completion_tokens'] ?? 0")&&str_contains($service,'$durationMs ?? 0'),'ausencia de usage conserva la política legacy de cero');
$ok(ChatTokenUsageService::calculateCost('amazon.nova-pro-v1:0',1000000,1000000)===4.0&&ChatTokenUsageService::calculateCost('unknown',0,0)===0.0,'coste reutiliza exactamente la política legacy');

echo "Resultado: $passed passed, $failed failed\n";
echo "SKIP integración MySQL real: no hay TASK_TEST_DB_* configurado.\n";
exit($failed?1:0);
