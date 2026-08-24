<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/Tasks/bootstrap.php';
$service=(new ReflectionClass(TaskApplicationService::class))->newInstanceWithoutConstructor();
$method=new ReflectionMethod(TaskApplicationService::class,'taskDto');
$base=[
    'public_id'=>'00000000-0000-4000-8000-000000000001','project_id_'=>null,'session_id_'=>7,
    'origin_message_id_'=>null,'result_message_id_'=>null,'origin_type'=>'manual','title'=>'Operativa','objective'=>'Probar',
    'status'=>'waiting_dependency','priority'=>'high','progress_percent'=>25,'max_attempts'=>3,'attempt_count'=>1,'lock_version'=>2,
    'scheduled_at'=>null,'started_at'=>null,'due_at'=>'2030-01-01 00:00:00.000000','completed_at'=>null,
    'cancel_requested_at'=>null,'cancelled_at'=>null,'result_summary'=>null,'error_code'=>null,'error_message'=>null,
    'created_at'=>'2029-01-01 00:00:00','updated_at'=>'2029-01-02 00:00:00',
];
$wait=$method->invoke($service,$base+[
    'current_step_key'=>'wait_release','current_step_title'=>'Esperar ventana','current_step_type'=>'wait',
    'current_step_status'=>'waiting_dependency','current_step_agent_key'=>'ops_agent','current_step_model_id'=>null,
    'current_step_checkpoint_json'=>'{"wait_until":"2030-02-03 04:05:06.000000","private":"not-public"}',
]);
$checks=[
    $wait['current_step']['step_key']==='wait_release'&&$wait['current_step']['step_type']==='wait',
    $wait['current_step']['wait_until']==='2030-02-03 04:05:06.000000',
    !str_contains(json_encode($wait,JSON_THROW_ON_ERROR),'not-public')&&!array_key_exists('checkpoint_json',$wait['current_step']),
    $wait['scheduled_at']===null&&$wait['due_at']==='2030-01-01 00:00:00.000000',
];
$model=$method->invoke($service,$base+[
    'current_step_key'=>'respond','current_step_title'=>'Responder','current_step_type'=>'model','current_step_status'=>'running',
    'current_step_agent_key'=>'chat_main','current_step_model_id'=>'model-real','current_step_checkpoint_json'=>'{"wait_until":"2030-02-03 04:05:06.000000"}',
]);
$checks[]=$model['current_step']['wait_until']===null&&$model['current_step']['agent_key']==='chat_main'&&$model['current_step']['model_id']==='model-real';
$none=$method->invoke($service,$base+['current_step_key'=>null,'current_step_title'=>null,'current_step_type'=>null,'current_step_status'=>null,'current_step_agent_key'=>null,'current_step_model_id'=>null,'current_step_checkpoint_json'=>null]);
$checks[]=$none['current_step']===null;
foreach($checks as$i=>$ok)echo($ok?'PASS ':'FAIL ').'Task operational DTO '.($i+1)."\n";
exit(in_array(false,$checks,true)?1:0);
