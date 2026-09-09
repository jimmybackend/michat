<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);

$root=dirname(__DIR__);
require_once $root.'/includes/Tasks/bootstrap.php';

$passed=0;$failed=0;
$check=static function(bool $ok,string $label)use(&$passed,&$failed):void{
    echo($ok?'PASS ':'FAIL ').$label."\n";
    $ok?$passed++:$failed++;
};

$config=['is_active'=>1,'model_id'=>'test-model','system_instruction'=>''];

$responsePlan=new AiTaskPlanner(
    new TaskPlanValidator(),
    static fn(array $cfg,string $prompt,string $system):string=>json_encode([
        'steps'=>[
            ['step_key'=>'generate','title'=>'Generar clase','description'=>'Generar codigo como respuesta.','step_type'=>'model','agent_key'=>'code_generator'],
            ['step_key'=>'validate','title'=>'Validar clase','description'=>'Validar la respuesta generada.','step_type'=>'validation','agent_key'=>'code_validator'],
            ['step_key'=>'finish','title'=>'Finalizar','description'=>'Confirmar la tarea.','step_type'=>'finalize','agent_key'=>'chat_main'],
        ],
    ],JSON_THROW_ON_ERROR),
    $config
);
$plan=$responsePlan->plan('Dame una clase que clasifique enteros y flotantes.');
$check($plan->count()===1,'plan de respuesta model/validation/finalize se colapsa a un Step ejecutable');
$step=$plan->steps()[0];
$check($step->stepType==='model','Step resultante sigue siendo model');
$check($step->agentKey==='code_generator','se conserva el agente que genera la respuesta');
$check($step->stepKey==='generate','se conserva la identidad lógica del primer model Step');

$invalidControlOnly=new AiTaskPlanner(
    new TaskPlanValidator(),
    static fn(array $cfg,string $prompt,string $system):string=>json_encode([
        'steps'=>[
            ['step_key'=>'validate','title'=>'Validar','description'=>'Validar algo sin input técnico.','step_type'=>'validation','agent_key'=>'code_validator'],
            ['step_key'=>'finish','title'=>'Finalizar','description'=>'Finalizar.','step_type'=>'finalize','agent_key'=>'chat_main'],
        ],
    ],JSON_THROW_ON_ERROR),
    $config
);
$fallback=$invalidControlOnly->plan('Objetivo de prueba');
$check($fallback->count()===1&&$fallback->steps()[0]->stepType==='model','plan solo control sin model cae a fallback seguro');

$mixedPlan=new AiTaskPlanner(
    new TaskPlanValidator(),
    static fn(array $cfg,string $prompt,string $system):string=>json_encode([
        'steps'=>[
            ['step_key'=>'generate','title'=>'Generar','description'=>'Preparar trabajo.','step_type'=>'model','agent_key'=>'chat_main'],
            ['step_key'=>'approval','title'=>'Aprobar','description'=>'Esperar aprobación explícita.','step_type'=>'approval','agent_key'=>'chat_main'],
        ],
    ],JSON_THROW_ON_ERROR),
    $config
);
$mixed=$mixedPlan->plan('Objetivo con aprobación');
$check($mixed->count()===2,'planes con controles ejecutables distintos de validation/finalize no se colapsan');

$source=(string)file_get_contents($root.'/includes/Tasks/AiTaskPlanner.php');
$check(str_contains($source,'usa exactamente un step model'),'instrucción del Planner evita validación genérica en respuestas');
$check(str_contains($source,'normalizeExecutableContract'),'normalización queda protegida por contrato explícito');

echo"Result: {$passed} passed, {$failed} failed\n";
exit($failed?1:0);
