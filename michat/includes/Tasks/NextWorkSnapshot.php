<?php
declare(strict_types=1);
final class NextWorkSnapshot{
 public const MAX_TOTAL_BYTES=24000;
 /** @param list<string> $references */
 public function __construct(public readonly array$data,public readonly array$references,public readonly bool$hasGoalSignal){$json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if(strlen($json)>self::MAX_TOTAL_BYTES)throw new TaskValidationException('next_work_snapshot_too_large');}
 public function json():string{return json_encode($this->data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
 public function taskStatus():string{return(string)$this->data['task']['status'];}
}
interface NextWorkSnapshotSourceInterface{public function load(int$userId,int$projectId,string$taskPublicId):array;}
