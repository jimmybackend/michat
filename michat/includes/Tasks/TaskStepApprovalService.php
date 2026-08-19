<?php
declare(strict_types=1);

/** Repository-backed human decision service for intermediate approval steps. */
final class TaskStepApprovalService
{
    public function __construct(private mysqli $db) {}

    public function decide(int $userId, string $publicId, string $stepKey, int $stepLock, bool $approved): array
    {
        $this->db->begin_transaction();
        try {
            $task=$this->one("SELECT * FROM Tasks WHERE public_id=? AND user_id_=? FOR UPDATE",'si',[$publicId,$userId]);
            if(!$task)throw new TaskNotFoundException('not_found');
            if($task['status']!=='waiting_user')throw new TaskTransitionException('task_not_waiting_user');
            $step=$this->one("SELECT * FROM TaskSteps WHERE task_id_=? AND step_key=? FOR UPDATE",'is',[(int)$task['id_'],$stepKey]);
            if(!$step)throw new TaskNotFoundException('step_not_found');
            if($step['step_type']!=='approval'||$step['status']!=='waiting_user')throw new TaskTransitionException('approval_step_not_waiting');
            if((int)$step['lock_version']!==$stepLock)throw new TaskConcurrencyException('step_concurrency_conflict');
            $stepTo=$approved?'completed':'cancelled';
            $s=$this->p("UPDATE TaskSteps SET status=?,progress_percent=IF(?='completed',100,progress_percent),completed_at=IF(?='completed',NOW(6),completed_at),lock_version=lock_version+1 WHERE id_=? AND status='waiting_user' AND lock_version=?");
            $s->bind_param('sssii',$stepTo,$stepTo,$stepTo,$step['id_'],$stepLock);$this->x($s);if($s->affected_rows!==1)throw new TaskConcurrencyException('step_concurrency_conflict');$s->close();
            $executionTo=$approved?'completed':'cancelled';$s=$this->p("UPDATE TaskExecutions SET status=?,finished_at=NOW(6),worker_id=NULL,lease_token=NULL,lease_expires_at=NULL WHERE step_id_=? AND status='waiting'");$s->bind_param('si',$executionTo,$step['id_']);$this->x($s);$s->close();
            $next=null;$taskTo='cancelled';$progress=(int)$task['progress_percent'];
            if($approved){$next=$this->one("SELECT * FROM TaskSteps WHERE task_id_=? AND status IN ('pending','ready') ORDER BY position,id_ FOR UPDATE",'i',[(int)$task['id_']]);$counts=$this->one("SELECT COUNT(*) total,SUM(status IN ('completed','skipped')) done FROM TaskSteps WHERE task_id_=?",'i',[(int)$task['id_']]);$progress=(int)floor(100*((int)$counts['done']/max(1,(int)$counts['total'])));if($next){$s=$this->p("UPDATE TaskSteps SET status='ready',lock_version=lock_version+1 WHERE id_=? AND status='pending'");$s->bind_param('i',$next['id_']);$this->x($s);$s->close();$taskTo='ready';}else{$taskTo='completed';$progress=100;}}
            $current=$next?(int)$next['id_']:null;$s=$this->p("UPDATE Tasks SET status=?,progress_percent=?,current_step_id_=?,completed_at=IF(?='completed',NOW(6),completed_at),cancelled_at=IF(?='cancelled',NOW(6),cancelled_at),lock_version=lock_version+1 WHERE id_=? AND status='waiting_user'");$s->bind_param('siissi',$taskTo,$progress,$current,$taskTo,$taskTo,$task['id_']);$this->x($s);if($s->affected_rows!==1)throw new TaskConcurrencyException('concurrency_conflict');$s->close();
            $details=json_encode(['decision'=>$approved?'approved':'rejected','step_key'=>$stepKey],JSON_UNESCAPED_SLASHES);$key=$approved?'approval_step_approved':'approval_step_rejected';$summary=$approved?'Step aprobado por el usuario.':'Step rechazado por el usuario.';
            $s=$this->p("INSERT INTO TaskEvents(task_id_,step_id_,actor_type,actor_user_id_,event_key,from_status,to_status,summary,details_json) VALUES(?,?,'user',?,?,?,?,?,?)");$s->bind_param('iiisssss',$task['id_'],$step['id_'],$userId,$key,$step['status'],$stepTo,$summary,$details);$this->x($s);$s->close();
            $this->db->commit();return['task_status'=>$taskTo,'step_status'=>$stepTo,'next_step_key'=>$next['step_key']??null,'progress_percent'=>$progress,'execution_mode'=>$this->mode($next)];
        }catch(Throwable$e){$this->db->rollback();throw$e;}
    }
    private function mode(?array$s):string{if(!$s)return'none';$i=json_decode((string)($s['input_json']??''),true);return is_array($i)&&($i['execution_mode']??'sync')==='async'?'async':'sync';}
    private function one(string$q,string$t,array$v):?array{$s=$this->p($q.' LIMIT 1');$s->bind_param($t,...$v);$this->x($s);$r=$s->get_result()->fetch_assoc();$s->close();return$r?:null;}
    private function p(string$q):mysqli_stmt{$s=$this->db->prepare($q);if(!$s)throw new RuntimeException('database_error');return$s;}
    private function x(mysqli_stmt$s):void{if(!$s->execute())throw new RuntimeException('database_error');}
}
