<?php
declare(strict_types=1);
$root=dirname(__DIR__);$service=file_get_contents($root.'/includes/Tasks/TaskCenterAutonomyReadService.php');$app=file_get_contents($root.'/includes/Tasks/TaskApplicationService.php');$js=file_get_contents($root.'/js/task-center.js');$css=file_get_contents($root.'/css/task-center.css');
$checks=[
 'owned project boundary'=>str_contains($service,"Projects WHERE id_=? AND user_id_=?"),
 'owned task boundary'=>str_contains($service,"t.id_=? AND t.user_id_=?"),
 'pure selects'=>!preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE)\b/i',$service),
 'bounded continuations'=>str_contains($service,'LIMIT 10'),
 'bounded replans'=>str_contains($service,'LIMIT 20'),
 'bounded revision steps'=>str_contains($service,'LIMIT 64'),
 'public DTO strips revision id'=>str_contains($service,"unset(\$revision['id_']"),
 'no fake cost'=>str_contains($service,"'cost_enforceable'=>false"),
 'policy absence is disabled'=>str_contains($service,"'configured'=>false,'mode'=>'disabled'"),
 'detail composition'=>str_contains($app,"['autonomy']")&&str_contains($app,"['project_autonomy']"),
 'safe renderer'=>str_contains($js,'${esc(c.question')&&str_contains($js,'${esc(x.proposed_objective)')&&str_contains($js,'${esc(step.output_summary)'),
 'no autonomy writes'=>!preg_match('/data-action="(?:approve_proposal|reject_proposal|approve_replan|start_cycle)"/',$js),
 'ask user read only'=>str_contains($js,'Esperando respuesta')&&!str_contains($js,'name="ask_user_response"'),
 'pending approval observable'=>str_contains($js,'Esperando aprobación'),
 'plan separation'=>str_contains($js,'PLAN ACTUAL')&&str_contains($js,'HISTORIAL DE PLANES'),
 'agent key only'=>str_contains($js,'Agente:')&&!str_contains($js,'system_instruction'),
 'events integrated'=>str_contains($js,"continuation_started:'Continuación iniciada'")&&str_contains($js,"replan_applied:'Replan aplicado'"),
 'responsive autonomy CSS'=>str_contains($css,'.budget-grid')&&str_contains($css,'@media(max-width:560px)'),
];
foreach($checks as$name=>$ok){if(!$ok){fwrite(STDERR,"FAIL: $name\n");exit(1);}}echo "task_phase11f1_observability_test: PASS (".count($checks)." checks)\n";
