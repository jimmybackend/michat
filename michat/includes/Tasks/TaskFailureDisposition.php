<?php
declare(strict_types=1);
final class TaskFailureDisposition{
 public const TECHNICAL='technical_failure',REPLAN='logical_replan_candidate';
 public const TRIGGERS=['step_failed','validation_failed','dependency_invalidated','plan_no_longer_executable','explicit_replan_request'],REPLAN_TRIGGERS=['validation_failed','dependency_invalidated','plan_no_longer_executable','explicit_replan_request'];
 public function __construct(public readonly string$type,public readonly string$triggerCode='step_failed',public readonly string$publicReason='La ejecución falló.'){if(!in_array($type,[self::TECHNICAL,self::REPLAN],true)||!in_array($triggerCode,self::TRIGGERS,true)||($type===self::REPLAN&&!in_array($triggerCode,self::REPLAN_TRIGGERS,true))||trim($publicReason)===''||mb_strlen($publicReason)>500)throw new InvalidArgumentException('failure_disposition_invalid');}
 public static function technical(string$reason='La ejecución falló por un error técnico.'):self{return new self(self::TECHNICAL,'step_failed',$reason);}
 public static function replan(string$trigger,string$reason):self{return new self(self::REPLAN,$trigger,$reason);}
 public function isReplanCandidate():bool{return$this->type===self::REPLAN;}
}
final class TaskClassifiedFailureException extends RuntimeException{
 public function __construct(public readonly TaskFailureDisposition$disposition,string$technicalMessage='task_step_failed',?Throwable$previous=null){parent::__construct($technicalMessage,0,$previous);}
}
