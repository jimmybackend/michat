<?php
declare(strict_types=1);
final class TaskPlanStep {
 public function __construct(public readonly string $stepKey,public readonly string $title,public readonly string $description,public readonly string $stepType,public readonly string $agentKey) {}
 public function persistenceData(int $position):array{return['position'=>$position,'step_key'=>$this->stepKey,'title'=>$this->title,'description'=>$this->description,'step_type'=>$this->stepType,'agent_key'=>$this->agentKey];}
}
