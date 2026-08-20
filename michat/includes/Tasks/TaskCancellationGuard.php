<?php
declare(strict_types=1);

/** Persistent cancellation checkpoint shared by HTTP and worker execution paths. */
final class TaskCancellationGuard
{
    private ?Closure $checker;
    public function __construct(private ?mysqli $db=null,?callable$checker=null){$this->checker=$checker===null?null:Closure::fromCallable($checker);}

    /** @param array<string,mixed> $context */
    public function assertActive(array$context):void
    {
        $taskId=(int)($context['task_id']??0);$userId=(int)($context['user_id']??0);
        if($taskId<1||$userId<1)return;
        if($this->checker!==null){if(($this->checker)($context))throw new TaskTransitionException('cancel_requested');return;}
        if(!$this->db)throw new RuntimeException('task_cancellation_guard_unconfigured');
        $stmt=$this->db->prepare('SELECT status,cancel_requested_at FROM Tasks WHERE id_=? AND user_id_=? LIMIT 1');if(!$stmt)throw new RuntimeException('database_error');
        $stmt->bind_param('ii',$taskId,$userId);if(!$stmt->execute())throw new RuntimeException('database_error');$row=$stmt->get_result()->fetch_assoc();$stmt->close();
        if(!$row)throw new TaskConcurrencyException('task_not_found');
        if($row['cancel_requested_at']!==null||$row['status']==='cancelled')throw new TaskTransitionException('cancel_requested');
    }
}
