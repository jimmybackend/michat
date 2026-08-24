<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);

final class TaskPhase9fHardeningTest{
 private int$passed=0,$failed=0;
 public function run():int{
  $page=file_get_contents(__DIR__.'/../task_center.php');
  $js=file_get_contents(__DIR__.'/../js/task-center.js');
  $css=file_get_contents(__DIR__.'/../css/task-center.css');

  foreach(['search','status','priority','project','session']as$id)$this->ok(str_contains($page,'for="'.$id.'"')&&str_contains($page,'id="'.$id.'"'),'label explícito para '.$id);
  $this->ok(str_contains($page,'id="task-feedback"')&&str_contains($page,'role="status"')&&str_contains($page,'aria-atomic="true"'),'región de estado accesible');
  $this->ok(str_contains($page,'id="detail"')&&str_contains($page,'tabindex="-1"')&&str_contains($page,'aria-busy="false"'),'detalle recibe foco y comunica carga');
  $this->ok(substr_count($page,'<template id="task-template"><button')===1&&substr_count($page,'<template id="board-card-template"><button')===1,'Lista y Tablero usan botones nativos de teclado');
  $this->ok(str_contains($js,"setAttribute('aria-pressed','true')")&&str_contains($js,"setAttribute('aria-pressed','false')"),'selector de dependencia comunica selección');
  $this->ok(str_contains($css,':focus-visible')&&str_contains($css,'prefers-reduced-motion'),'focus visible y movimiento reducido');

  $this->ok(str_contains($js,"controls.tasks.setAttribute('aria-busy','true')")&&str_contains($js,"controls.detail.setAttribute('aria-busy','true')")&&str_contains($js,"results.setAttribute('aria-busy','true')"),'listado, detalle y candidatos comunican loading');
  $this->ok(str_contains($js,'const busyActions=new Set()')&&substr_count($js,'if(busyActions.has(key))return')===2,'acciones y dependencias bloquean doble ejecución');
  $this->ok(str_contains($js,'if(sequence!==loadSequence)return')&&str_contains($js,'const sequence=++loadSequence'),'respuestas antiguas del listado no pisan estado nuevo');
  $this->ok(str_contains($js,"announce(success)")&&str_contains($js,"announce(e.message,'error')"),'acciones anuncian éxito y error');
  $this->ok(!str_contains($js,'alert('),'errores no dependen de alert bloqueante');
  $this->ok(str_contains($js,'controls.detail.focus')&&str_contains($js,'markSelected()'),'detalle recupera foco y selección sin reconstruir listado');

  preg_match('/const esc=(v=>String\(v\?\?\'\'\)\.replace\(\/\[&<>"\'\]\/g,c=>\(\{.*?\}\[c\]\)\));/',$js,$match);
  $hostile='<script data-x="1">&\'boom\'</script>';$expected='&lt;script data-x=&quot;1&quot;&gt;&amp;&#39;boom&#39;&lt;/script&gt;';
  $actual=$match?$this->runEscape($match[1],$hostile):null;
  $this->same($expected,$actual,'escape real neutraliza < > & comillas dobles y simples');
  foreach(['t.title','t.objective','e.summary','eventLabel[e.event_key]||e.event_key',"actorLabel[e.actor_type]||e.actor_type||'Origen no disponible'",'e.error_message','name','currentStep.agent_key','currentStep.model_id']as$value)$this->ok(str_contains($js,'esc('.$value.')'),'render dinámico escapa '.$value);
  $this->ok(str_contains($js,"button.innerHTML=`<b>\${esc(candidate.title)}")&&str_contains($js,"results.innerHTML=`<p class=\"error\" role=\"alert\">\${esc(e.message)}"),'candidatos y errores usan escape');

  $this->ok(str_contains($css,'@media(max-width:900px)')&&str_contains($css,'@media(max-width:560px)'),'breakpoints finales cubren tablet y móvil');
  $this->ok(str_contains($css,'.actions{flex-wrap:wrap}')&&str_contains($css,'overflow-wrap:anywhere'),'acciones y contenido largo evitan overflow');
  $this->ok(str_contains($js,"node.querySelector('time').textContent=formatDate(task.updated_at)"),'fechas de Lista usan presentación UTC común');

  echo"Resultado: {$this->passed} passed, {$this->failed} failed\n";
  echo"SKIP E2E navegador: no hay navegador instalado ni sesión autenticada utilizable.\n";
  echo"SKIP integración MySQL: no hay TASK_TEST_DB_* configurado.\n";
  return$this->failed?1:0;
 }
 private function runEscape(string$expression,string$value):?string{
  $command=['node','-e','const esc='.$expression.';process.stdout.write(esc(process.argv[1]));',$value];
  $pipes=[];$process=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes);
  if(!is_resource($process))return null;$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
  return$status===0&&$error===''?$output:null;
 }
 private function same(mixed$expected,mixed$actual,string$name):void{$this->ok($expected===$actual,$name);}
 private function ok(bool$value,string$name):void{echo($value?'PASS ':'FAIL ').$name."\n";$value?$this->passed++:$this->failed++;}
}
exit((new TaskPhase9fHardeningTest())->run());
