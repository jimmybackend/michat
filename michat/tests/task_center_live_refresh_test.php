<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);

$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/task_center.php');
$live=(string)file_get_contents($root.'/js/task-center-live.js');

$passed=0;$failed=0;
$check=static function(bool $ok,string $label)use(&$passed,&$failed):void{
    echo($ok?'PASS ':'FAIL ').$label."\n";
    $ok?$passed++:$failed++;
};

$check(str_contains($page,'js/task-center-live.js'),'Task Center carga la capa de refresco en vivo');
$check(str_contains($live,'REFRESH_MS=5000'),'polling acotado a cinco segundos');
$check(str_contains($live,'document.hidden'),'polling se pausa cuando la pestaña no está visible');
$check(str_contains($live,'interactiveFocused'),'polling no interrumpe edición del usuario');
$check(str_contains($live,'refresh.click()'),'refresco reutiliza la lectura autoritativa existente');
$check(str_contains($live,"'pending','ready','running','waiting_user','waiting_dependency'"),'solo estados no terminales mantienen seguimiento activo');
$check(str_contains($live,"michat:open-task"),'detalle seleccionado se vuelve a consultar cuando cambia su estado/progreso');
$check(str_contains($live,'lastSelectedFingerprint'),'detalle solo se refresca cuando cambia el fingerprint operativo');
$check(str_contains($live,"aria-live','off"),'polling evita anunciar cada tick como interacción del usuario');

echo"Result: {$passed} passed, {$failed} failed\n";
exit($failed?1:0);
