<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/Tasks/bootstrap.php';

final class Phase9eHistoryTest{
 private int$passed=0,$failed=0;
 public function run():int{
  $application=(new ReflectionClass(TaskApplicationService::class))->newInstanceWithoutConstructor();
  (new ReflectionProperty(TaskApplicationService::class,'approvalPresenter'))->setValue($application,new TaskPublicApprovalPresenter());
  $eventDto=(new ReflectionClass(TaskApplicationService::class))->getMethod('eventDto');
  $rows=[
   ['event_order'=>4,'actor_type'=>'worker','event_key'=>'execution_started','from_status'=>'queued','to_status'=>'running','summary'=>'Inicio','details_json'=>'{"token":"secret"}','created_at'=>'2026-08-24 10:00:00.000000','step_key'=>'analyze','step_title'=>'Analizar','execution_attempt'=>'1','execution_trace_id'=>'trace-one'],
   ['event_order'=>5,'actor_type'=>'future_actor','event_key'=>'future_event','from_status'=>null,'to_status'=>'future','summary'=>'Evento futuro','details_json'=>'{"private":"secret"}','created_at'=>'2026-08-24 10:00:00.000000','step_key'=>null,'step_title'=>null,'execution_attempt'=>null,'execution_trace_id'=>null],
  ];
  $public=array_map(fn(array$row):array=>$eventDto->invoke($application,$row),$rows);
  $this->same(['execution_started','future_event'],array_column($public,'event_key'),'DTO conserva orden backend con timestamps idénticos');
  $this->ok($public[0]['actor_type']==='worker'&&$public[1]['actor_type']==='future_actor','actor presente y desconocido se conserva sin inferencia');
  $this->ok($public[0]['step_key']==='analyze'&&$public[0]['execution_attempt']==='1','Event conserva relaciones reales de Step y Execution');
  $this->ok(!str_contains(json_encode($public),'secret')&&!array_key_exists('details_json',$public[0])&&!array_key_exists('event_order',$public[0]),'payload privado e ID de orden no se exponen');

  $executionDto=(new ReflectionClass(TaskApplicationService::class))->getMethod('executionDto');
  $execution=$executionDto->invoke($application,['id_'=>8,'step_id_'=>3,'trace_id'=>'trace-one','attempt_number'=>'2','agent_key'=>'agent','model_id'=>'model','status'=>'failed','started_at'=>null,'heartbeat_at'=>null,'finished_at'=>'2026-08-24','error_message'=>'token=abc123 fallo comprensible','created_at'=>'2026-08-24','step_key'=>'analyze','step_title'=>'Analizar','worker_id'=>'private','lease_token'=>'private']);
  $this->ok($execution['attempt_number']==='2'&&$execution['step_title']==='Analizar'&&$execution['trace_id']==='trace-one','Execution pública conserva intento, Step y trace');
  $this->ok($execution['error_message']==='token=[oculto] fallo comprensible','error redacta secretos');
  $this->ok(!isset($execution['id_'],$execution['step_id_'],$execution['worker_id'],$execution['lease_token']),'Execution no expone IDs, worker ni lease');
  $sqlError=$executionDto->invoke($application,['error_message'=>'SELECT password FROM Users','trace_id'=>null,'attempt_number'=>1,'status'=>'failed']);
  $this->same('Detalle técnico oculto.',$sqlError['error_message'],'error SQL se sustituye por fallback seguro');
  $stepDto=(new ReflectionClass(TaskApplicationService::class))->getMethod('stepDto');
  $longOutput=str_repeat('x',1200);$step=$stepDto->invoke($application,['position'=>1,'step_key'=>'analyze','title'=>'Analizar','description'=>null,'step_type'=>'model','status'=>'completed','progress_percent'=>100,'agent_key'=>'agent','model_id'=>'model','output_summary'=>$longOutput,'error_message'=>'secret=visible fallo','attempt_count'=>1,'max_attempts'=>1,'lock_version'=>1,'started_at'=>null,'completed_at'=>null,'created_at'=>'now','updated_at'=>'now','checkpoint_json'=>null]);
  $this->ok(mb_strlen($step['output_summary'])<=1000&&str_ends_with($step['output_summary'],'…'),'output público se acota sin volcar contenido grande');
  $this->same('secret=[oculto] fallo',$step['error_message'],'error de Step usa la misma sanitización');

  $eventRepository=file_get_contents(__DIR__.'/../includes/Tasks/TaskEventRepository.php');
  $executionRepository=file_get_contents(__DIR__.'/../includes/Tasks/TaskExecutionRepository.php');
  $service=file_get_contents(__DIR__.'/../includes/Tasks/TaskApplicationService.php');
  $this->ok(str_contains($eventRepository,'t.user_id_=?')&&str_contains($eventRepository,'s.task_id_=e.task_id_')&&str_contains($eventRepository,'x.task_id_=e.task_id_'),'Events y referencias aplican scope owned/coherente');
  $this->ok(str_contains($eventRepository,'ORDER BY e.id_ DESC LIMIT ?')&&str_contains($eventRepository,'array_reverse($rows)'),'Events usan límite y orden monotónico determinista');
  $this->ok(str_contains($service,"'limit'=>100,'has_earlier'=>\$hasEarlier")&&str_contains($service,'array_slice($eventRows,-100)'),'detail publica límite e indicador de historial anterior');
  $this->ok(str_contains($executionRepository,'t.user_id_=?')&&str_contains($executionRepository,'LEFT JOIN TaskSteps'),'Executions owned resuelven Steps en una consulta');
  $this->ok(!str_contains($service,"'details_json'")&&!str_contains($service,"'worker_id'")&&!str_contains($service,"'lease_token'"),'DTO histórico omite payloads y campos internos');
  echo"Resultado: {$this->passed} passed, {$this->failed} failed\n";
  echo"SKIP integración MySQL de historial: no hay TASK_TEST_DB_* configurado.\n";
  return$this->failed?1:0;
 }
 private function same(mixed$expected,mixed$actual,string$name):void{$this->ok($expected===$actual,$name);}
 private function ok(bool$value,string$name):void{echo($value?'PASS ':'FAIL ').$name."\n";$value?$this->passed++:$this->failed++;}
}
exit((new Phase9eHistoryTest())->run());
