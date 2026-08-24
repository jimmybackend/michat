<?php
declare(strict_types=1);
final class TaskRecurrenceMisfirePlanner{
 public function __construct(private TaskRecurrenceCalculator$calculator){}
 /** @return array{slots:list<string>,next:string,skipped:int} */
 public function plan(array$rule,DateTimeImmutable$nowUtc,int$catchUpLimit):array{
  if($catchUpLimit<1||$catchUpLimit>100)throw new InvalidArgumentException('recurrence_catchup_limit_invalid');$slot=(string)$rule['next_occurrence_at'];$now=$nowUtc->setTimezone(new DateTimeZone('UTC'));$slotDate=$this->utc($slot);if($slotDate>$now)return['slots'=>[],'next'=>$slot,'skipped'=>0];
  if($rule['misfire_policy']==='skip')return['slots'=>[],'next'=>$this->calculator->storage($this->calculator->next($rule,$now)),'skipped'=>1];
  if($rule['misfire_policy']==='run_once')return['slots'=>[$slot],'next'=>$this->calculator->storage($this->calculator->next($rule,$now)),'skipped'=>0];
  if($rule['misfire_policy']!=='catch_up')throw new TaskValidationException('recurrence_misfire_invalid');$slots=[];
  while($slotDate<=$now&&count($slots)<$catchUpLimit){$slots[]=$this->calculator->storage($slotDate);$slotDate=$this->calculator->next($rule,$slotDate);}
  return['slots'=>$slots,'next'=>$this->calculator->storage($slotDate),'skipped'=>0];
 }
 private function utc(string$value):DateTimeImmutable{return new DateTimeImmutable($value,new DateTimeZone('UTC'));}
}
