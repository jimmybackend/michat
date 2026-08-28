<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__.'/../includes/Tasks/bootstrap.php';

$service=(new ReflectionClass(TaskApplicationService::class))
    ->newInstanceWithoutConstructor();

$method=new ReflectionMethod(TaskApplicationService::class,'finalResult');

$baseTask=[
    'session_id_'=>1,
    'status'=>'completed',
    'result_message_id_'=>null,
    'result_summary'=>'Resultado histórico almacenado en Tasks.',
    'completed_at'=>'2026-08-27 13:46:25.000000',
    'updated_at'=>'2026-08-27 13:46:25.000000',
];

$steps=[
    [
        'position'=>1,
        'step_type'=>'model',
        'status'=>'completed',
        'output_summary'=>'Resultado histórico almacenado en Step.',
        'model_id'=>'legacy-model',
        'completed_at'=>'2026-08-27 13:46:25.000000',
    ],
];

$checks=[];

$result=$method->invoke($service,$baseTask,$steps,1);

$checks[]=
    $result!==null &&
    $result['source']==='task_summary' &&
    $result['complete']===false &&
    $result['preview']==='Resultado histórico almacenado en Tasks.';

$taskWithoutSummary=$baseTask;
$taskWithoutSummary['result_summary']=null;

$result=$method->invoke($service,$taskWithoutSummary,$steps,1);

$checks[]=
    $result!==null &&
    $result['source']==='step_summary' &&
    $result['complete']===false &&
    $result['preview']==='Resultado histórico almacenado en Step.' &&
    $result['model_id']==='legacy-model';

$stepsWithoutResult=$steps;
$stepsWithoutResult[0]['output_summary']=null;

$result=$method->invoke(
    $service,
    $taskWithoutSummary,
    $stepsWithoutResult,
    1
);

$checks[]=$result===null;

$running=$baseTask;
$running['status']='running';
$running['result_summary']='Resultado todavía no final';

$result=$method->invoke($service,$running,$steps,1);

$checks[]=$result===null;

$js=file_get_contents(__DIR__.'/../js/task-center.js');

$checks[]=
    str_contains($js,"source==='chat_message'") &&
    str_contains($js,'Resultado histórico registrado') &&
    str_contains($js,'Sin resultado final persistido.');

foreach($checks as$i=>$ok){
    echo ($ok?'PASS ':'FAIL ')
        .'Legacy final result '.($i+1).PHP_EOL;
}

exit(in_array(false,$checks,true)?1:0);
