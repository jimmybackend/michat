<?php
declare(strict_types=1);
final class TaskPlanValidator {
 private const TYPES=['model','tool','approval','wait','validation','finalize']; private const FIELDS=['step_key','title','description','step_type','agent_key'];
 /** @param callable(string):bool|null $agentExists */ public function __construct(private $agentExists=null,private string $fallbackAgent='chat_main'){}
 public function validate(array$payload):TaskPlan{
  if(array_keys($payload)!==['steps']||!is_array($payload['steps']))throw new TaskValidationException('plan_structure_invalid');$count=count($payload['steps']);if($count<1||$count>8)throw new TaskValidationException('plan_steps_invalid');$seen=[];$steps=[];
  foreach($payload['steps']as$raw){if(!is_array($raw)||array_diff(array_keys($raw),self::FIELDS)!==[])throw new TaskValidationException('plan_step_fields_invalid');foreach(self::FIELDS as$field)if(!array_key_exists($field,$raw)||!is_string($raw[$field]))throw new TaskValidationException('plan_step_fields_invalid');$key=$raw['step_key'];$title=trim($raw['title']);$description=trim($raw['description']);$type=$raw['step_type'];$agent=trim($raw['agent_key']);if(!preg_match('/^[a-z][a-z0-9_]{0,79}$/D',$key)||isset($seen[$key]))throw new TaskValidationException('plan_step_key_invalid');if($title===''||mb_strlen($title)>255||mb_strlen($description)>4000)throw new TaskValidationException('plan_step_length_invalid');if(!in_array($type,self::TYPES,true))throw new TaskValidationException('plan_step_type_invalid');if($agent===''||!preg_match('/^[a-z][a-z0-9_]{0,79}$/D',$agent)||($this->agentExists!==null&&!($this->agentExists)($agent)))$agent=$this->fallbackAgent;$seen[$key]=true;$steps[]=new TaskPlanStep($key,$title,$description,$type,$agent);}
  return new TaskPlan($steps);
 }
}
