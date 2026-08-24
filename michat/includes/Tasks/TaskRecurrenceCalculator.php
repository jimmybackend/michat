<?php
declare(strict_types=1);
final class TaskRecurrenceCalculator{
 public function next(array$definition,DateTimeImmutable$afterUtc):DateTimeImmutable{
  $zone=new DateTimeZone((string)$definition['timezone']);$after=$afterUtc->setTimezone(new DateTimeZone('UTC'));$local=$after->setTimezone($zone);$date=$local->setTime(0,0);
  for($i=0;$i<370;$i++,$date=$date->modify('+1 day')){
   if($definition['frequency']==='weekly'&&(int)$date->format('N')!==(int)$definition['weekday'])continue;
   [$h,$m,$s]=array_map('intval',explode(':',(string)$definition['local_time']));
   // PHP rolls a nonexistent spring-forward wall time forward while preserving wall-clock minutes;
   // an ambiguous fall-back wall time maps to its first occurrence, hence one slot.
   $candidate=$date->setTime($h,$m,$s)->setTimezone(new DateTimeZone('UTC'));
   if($candidate>$after)return$candidate;
  }
  throw new RuntimeException('recurrence_next_not_found');
 }
 public function storage(DateTimeImmutable$instant):string{return$instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');}
}
