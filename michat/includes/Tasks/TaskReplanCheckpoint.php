<?php
declare(strict_types=1);
final class TaskReplanCheckpoint{
 public const ACTIVE=['checkpointed','processing','proposed','pending_approval','approved'];
 public function __construct(public readonly string$publicId,public readonly string$taskPublicId,public readonly string$cyclePublicId,public readonly string$triggerCode,public readonly string$status,public readonly int$sourceTaskLockVersion,public readonly string$publicReason,public readonly int$lockVersion,public readonly string$createdAt){}
 public static function fromRow(array$r):self{return new self((string)$r['public_id'],(string)$r['task_public_id'],(string)$r['cycle_public_id'],(string)$r['trigger_code'],(string)$r['status'],(int)$r['source_task_lock_version'],(string)$r['public_reason'],(int)$r['lock_version'],(string)$r['created_at']);}
 public function toArray():array{return['public_id'=>$this->publicId,'task_public_id'=>$this->taskPublicId,'cycle_public_id'=>$this->cyclePublicId,'trigger_code'=>$this->triggerCode,'status'=>$this->status,'source_task_lock_version'=>$this->sourceTaskLockVersion,'public_reason'=>$this->publicReason,'lock_version'=>$this->lockVersion,'created_at'=>$this->createdAt];}
}
interface TaskReplanCheckpointStoreInterface{public function findOwned(int$userId,string$publicId):?array;public function safeCheckpoint(int$userId,string$publicId):array;public function transition(int$userId,string$publicId,int$lockVersion,string$to):array;}
