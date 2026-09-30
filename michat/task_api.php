<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');if(session_status()===PHP_SESSION_NONE)session_start();
require_once __DIR__.'/app_bootstrap.php';require_once __DIR__.'/includes/Chat/ChatIdentity.php';require_once __DIR__.'/includes/Pipeline/PipelineFeatureFlags.php';require_once __DIR__.'/includes/Security/CsrfGuard.php';require_once __DIR__.'/includes/Tasks/bootstrap.php';
$userId=ChatIdentity::resolveUserId($db_connection);$flags=new PipelineFeatureFlags($db_connection,$userId);$tasks=new TaskRepository($db_connection);$ruleRepository=new TaskRecurrenceRuleRepository($db_connection);$occurrenceRepository=new TaskRecurrenceOccurrenceRepository($db_connection);$recurrenceDomain=new TaskRecurrenceService($db_connection,$tasks,$ruleRepository,$occurrenceRepository,new TaskRecurrenceDefinition(),new TaskRecurrenceCalculator());$recurrence=new TaskRecurrenceApplicationService($ruleRepository,$occurrenceRepository,$recurrenceDomain,new TaskInputValidator());$app=(new TaskApplicationServiceFactory($db_connection))->create($flags->enabled('task_planner'));$controller=new TaskApiController($app,$userId,$flags->enabled('task_orchestrator'),$recurrence);
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));$body=$_POST;if(str_contains(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json')){$decoded=json_decode((string)file_get_contents('php://input'),true);if(is_array($decoded))$body=$decoded;}
$response=$controller->handle($method,$_GET,$body,['x-csrf-token'=>(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'')]);
// Arranque bajo demanda: tras una mutación de tareas confirmada en BD (2xx),
// asegurar que el worker esté activo para procesarla de inmediato.
if($response->status>=200&&$response->status<300&&in_array((string)($body['action']??''),['create','reschedule','retry','approve','approve_step','recurrence_create','recurrence_resume'],true)){if(class_exists('TaskWorkerServiceManager')){TaskWorkerServiceManager::ensureRunning();}}
http_response_code($response->status);echo json_encode($response->body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
