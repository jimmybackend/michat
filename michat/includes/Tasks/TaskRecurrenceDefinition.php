<?php
declare(strict_types=1);
final class TaskRecurrenceDefinition{
 public function validate(array$d):array{
  $frequency=(string)($d['frequency']??'');if(!in_array($frequency,['daily','weekly'],true))throw new TaskValidationException('recurrence_frequency_invalid');
  $time=trim((string)($d['local_time']??''));if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D',$time))throw new TaskValidationException('recurrence_local_time_invalid');
  $timezone=trim((string)($d['timezone']??''));try{$zone=new DateTimeZone($timezone);}catch(Throwable){throw new TaskValidationException('recurrence_timezone_invalid');}if(!in_array($zone->getName(),DateTimeZone::listIdentifiers(),true))throw new TaskValidationException('recurrence_timezone_invalid');
  $weekday=$d['weekday']??null;if($frequency==='weekly'){if(filter_var($weekday,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>7]])===false)throw new TaskValidationException('recurrence_weekday_invalid');$weekday=(int)$weekday;}elseif($weekday!==null&&$weekday!=='')throw new TaskValidationException('recurrence_weekday_invalid');else $weekday=null;
  $misfire=(string)($d['misfire_policy']??'run_once');if(!in_array($misfire,['skip','run_once','catch_up'],true))throw new TaskValidationException('recurrence_misfire_invalid');
  return['frequency'=>$frequency,'weekday'=>$weekday,'local_time'=>$time,'timezone'=>$zone->getName(),'misfire_policy'=>$misfire];
 }
}
