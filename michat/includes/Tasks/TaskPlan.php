<?php
declare(strict_types=1);
final class TaskPlan {
 /** @param TaskPlanStep[] $steps */
 public function __construct(private array $steps,private bool $fallback=false){if($steps===[])throw new InvalidArgumentException('plan_empty');}
 /** @return TaskPlanStep[] */ public function steps():array{return$this->steps;}
 public function count():int{return count($this->steps);} public function isFallback():bool{return$this->fallback;}
 public static function fallback():self{return new self([new TaskPlanStep('respond','Generar respuesta','Resolver el objetivo mediante el pipeline de chat.','model','chat_main')],true);}
}
