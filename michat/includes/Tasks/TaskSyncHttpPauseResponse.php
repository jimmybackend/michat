<?php
declare(strict_types=1);

/** Safe HTTP representation of a durable pause that was already persisted. */
final class TaskSyncHttpPauseResponse
{
    private function __construct(private string $publicId,private string $safeSummary){}

    public static function fromResult(string $publicId,TaskStepExecutionResult $result): ?self
    {
        if(!$result->isDurablePauseAlreadyPersisted())return null;
        if(trim($publicId)==='')throw new TaskValidationException('task_public_id_invalid');
        return new self($publicId,$result->summary);
    }

    /** @return array{ok:true,approval_required:true,status:string,approval_summary:string,task:array{public_id:string,status:string}} */
    public function toArray(): array
    {
        return['ok'=>true,'approval_required'=>true,'status'=>'waiting_user','approval_summary'=>$this->safeSummary,'task'=>['public_id'=>$this->publicId,'status'=>'waiting_user']];
    }
}
