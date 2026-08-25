<?php
declare(strict_types=1);
final class AutonomyPolicy{
 public const MODES=['disabled','supervised','automatic'],STATUSES=['active','paused','stopped'];
 public const DEFAULTS=['max_tasks_per_cycle'=>3,'max_decisions_per_cycle'=>6,'max_descendant_depth'=>2,'max_replans_per_cycle'=>1,'max_runtime_seconds'=>1800,'max_input_tokens'=>120000,'max_output_tokens'=>24000,'max_tool_calls'=>20,'max_write_tool_calls'=>5];
 public const CEILINGS=['max_tasks_per_cycle'=>50,'max_decisions_per_cycle'=>100,'max_descendant_depth'=>10,'max_replans_per_cycle'=>20,'max_runtime_seconds'=>86400,'max_input_tokens'=>5000000,'max_output_tokens'=>1000000,'max_tool_calls'=>1000,'max_write_tool_calls'=>100];
 public function __construct(public readonly string$publicId,public readonly int$userId,public readonly int$projectId,public readonly string$mode,public readonly string$status,public readonly ?string$stopReason,public readonly array$limits,public readonly int$lockVersion){}
 public static function fromRow(array$row):self{$mode=(string)($row['mode']??'');$status=(string)($row['status']??'');if(!in_array($mode,self::MODES,true)||!in_array($status,self::STATUSES,true))throw new TaskValidationException('autonomy_policy_invalid');$limits=[];foreach(self::DEFAULTS as$key=>$default){$raw=$row[$key]??null;$value=$raw===null?$default:(int)$raw;if($value<1)$value=$default;$limits[$key]=min($value,self::CEILINGS[$key]);}return new self((string)$row['public_id'],(int)$row['user_id_'],(int)$row['project_id_'],$mode,$status,$row['stop_reason']===null?null:(string)$row['stop_reason'],$limits,(int)$row['lock_version']);}
 public function toArray():array{return['public_id'=>$this->publicId,'project_id'=>$this->projectId,'mode'=>$this->mode,'status'=>$this->status,'stop_reason'=>$this->stopReason,'limits'=>$this->limits,'lock_version'=>$this->lockVersion,'cost_budget'=>['enforceable'=>false,'reason'=>'pricing_not_authoritative']];}
}
interface AutonomyPolicyStoreInterface{public function findOwned(int$userId,int$projectId):?array;public function createDefault(int$userId,int$projectId,string$publicId):array;public function updateOwned(int$userId,int$projectId,int$lockVersion,array$changes):array;}
