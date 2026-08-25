<?php
declare(strict_types=1);
final class NextWorkDecision{
 public const STOP='stop',ASK_USER='ask_user',PROPOSE_TASK='propose_task';
 /** @param list<array{source:string,reference:string,summary:string}> $evidence */
 public function __construct(public readonly string$decision,public readonly string$publicReason,public readonly array$evidence,public readonly ?string$question=null,public readonly ?string$proposedTitle=null,public readonly ?string$proposedObjective=null){}
 public static function safeAsk(string$reason='No fue posible validar una recomendación segura.'):self{return new self(self::ASK_USER,$reason,[],'¿Qué resultado deseas priorizar como siguiente paso del proyecto?');}
 public function toArray():array{$out=['decision'=>$this->decision,'public_reason'=>$this->publicReason,'evidence'=>$this->evidence];if($this->question!==null)$out['question']=$this->question;if($this->proposedTitle!==null)$out['proposed_title']=$this->proposedTitle;if($this->proposedObjective!==null)$out['proposed_objective']=$this->proposedObjective;return$out;}
}
