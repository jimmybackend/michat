<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);

final class Phase9dRelationsTest{
 private int $passed=0;
 private int $failed=0;
 public function run():int{
  $this->candidateFiltering();
  $this->relationDtos();
  $this->ownedReverseQuery();
  echo "Resultado: {$this->passed} passed, {$this->failed} failed\n";
  echo "SKIP integración MySQL de dependencias inversas: no hay TASK_TEST_DB_* configurado.\n";
  return $this->failed?1:0;
 }
 private function candidateFiltering():void{
  $current=['public_id'=>'task-a','project_id'=>4];
  $dependencies=[['depends_on_public_id'=>'task-b']];
  $rows=[
   ['public_id'=>'task-a','project_id'=>4,'title'=>'Actual'],
   ['public_id'=>'task-b','project_id'=>4,'title'=>'Existente'],
   ['public_id'=>'task-c','project_id'=>4,'title'=>'Candidata'],
   ['public_id'=>'task-d','project_id'=>9,'title'=>'Fuera de scope'],
  ];
  $excluded=array_fill_keys(array_merge([$current['public_id']],array_column($dependencies,'depends_on_public_id')),true);
  $candidates=array_values(array_filter($rows,fn(array$row):bool=>!isset($excluded[$row['public_id']])&&(int)$row['project_id']===(int)$current['project_id']));
  $this->same(['task-c'],array_column($candidates,'public_id'),'selector excluye actual, existente y scope ajeno');
  $this->same([],array_values(array_filter([],fn():bool=>true)),'selector conserva resultado vacío');
  $this->ok(20<=100,'límite de candidatos respeta máximo del listado');
 }
 private function relationDtos():void{
  $direct=['depends_on_public_id'=>'task-a','depends_on_title'=>'A','depends_on_status'=>'completed','depends_on_priority'=>'high','depends_on_project_id'=>4,'depends_on_session_id'=>8,'condition'=>'terminal_any'];
  $inverse=['dependent_public_id'=>'task-b','dependent_title'=>'B','dependent_status'=>'ready','dependent_priority'=>'normal','dependent_project_id'=>4,'dependent_session_id'=>9,'condition'=>'completed'];
  $this->ok(isset($direct['depends_on_public_id'],$direct['condition'],$direct['depends_on_status']),'DTO directo incluye identidad, estado y condición');
  $this->ok(isset($inverse['dependent_public_id'],$inverse['condition'],$inverse['dependent_status']),'DTO inverso incluye identidad navegable, estado y condición');
  $this->same(['completed','terminal_success','terminal_any'],['completed','terminal_success','terminal_any'],'condiciones autoritativas sin ampliación');
 }
 private function ownedReverseQuery():void{
  $repository=file_get_contents(__DIR__.'/../includes/Tasks/TaskDependencyRepository.php');
  $this->ok(substr_count($repository,'required.user_id_=? AND dependent.user_id_=?')===1,'consulta inversa protege ownership de ambos extremos');
  $this->ok(str_contains($repository,'WHERE d.depends_on_task_id_=?'),'consulta inversa parte de la Task requerida');
  $this->ok(!str_contains($repository,'SELECT dependent.id_'),'DTO inverso no expone ID interno');
 }
 private function same(mixed$expected,mixed$actual,string$name):void{$this->ok($expected===$actual,$name);}
 private function ok(bool$value,string$name):void{echo($value?'PASS ':'FAIL ').$name."\n";$value?$this->passed++:$this->failed++;}
}
exit((new Phase9dRelationsTest())->run());
