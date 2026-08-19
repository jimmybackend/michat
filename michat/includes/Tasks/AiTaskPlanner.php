<?php
declare(strict_types=1);
/** Adapter for the existing agent runtime; it never executes a business step. */
final class AiTaskPlanner implements TaskPlanner {
 public const INSTRUCTION='Convierte el objetivo en el menor numero de pasos necesarios (maximo 8). Devuelve solo JSON con steps y los campos step_key, title, description, step_type y agent_key. Tipos: plan, model, tool, approval, wait, validation, finalize. No ejecutes, no generes SQL, credenciales, tareas hijas, cambios de ownership, afirmaciones de ejecucion ni razonamiento privado.';
 /** @param callable(array,string):string $invokeModel */ public function __construct(private TaskPlanValidator$validator,private$invokeModel,private array$agentConfig){}
 public function plan(string$objective,array$context=[]):TaskPlan{if(empty($this->agentConfig)||!(bool)($this->agentConfig['is_active']??false)||trim((string)($this->agentConfig['model_id']??''))==='')throw new RuntimeException('planner_agent_unavailable');$instruction=trim((string)($this->agentConfig['system_instruction']??''))?:self::INSTRUCTION;$raw=($this->invokeModel)($this->agentConfig,$instruction."\nObjetivo: ".$objective);$decoded=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($decoded))throw new TaskValidationException('plan_json_invalid');return$this->validator->validate($decoded);}
}
