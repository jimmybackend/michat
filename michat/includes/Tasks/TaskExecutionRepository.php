<?php
declare(strict_types=1);
final class TaskExecutionRepository
{
    public function __construct(private mysqli $db) {}
    public function create(int$taskId,int$stepId,string$traceId,string$modelId,int$attempt=1):array{$s=$this->p("INSERT INTO TaskExecutions(task_id_,step_id_,trace_id,attempt_number,agent_key,model_id,status) VALUES(?,?,?,?,'chat_main',?,'queued')");$s->bind_param('iisis',$taskId,$stepId,$traceId,$attempt,$modelId);$this->x($s);$id=(int)$this->db->insert_id;$s->close();return$this->findById($id)??throw new RuntimeException('execution_create_failed');}
    public function findById(int$id):?array{return$this->one('SELECT * FROM TaskExecutions WHERE id_=?','i',[$id]);}
    public function findByTrace(string$trace):?array{return$this->one('SELECT * FROM TaskExecutions WHERE trace_id=?','s',[$trace]);}
    public function findAttempt(int$step,int$attempt):?array{return$this->one('SELECT * FROM TaskExecutions WHERE step_id_=? AND attempt_number=?','ii',[$step,$attempt]);}
    public function historyByStep(int$step):array{$s=$this->p('SELECT id_,task_id_,step_id_,trace_id,status,attempt_number,worker_id,lease_token,lease_expires_at FROM TaskExecutions WHERE step_id_=? ORDER BY attempt_number,id_ FOR UPDATE');$s->bind_param('i',$step);$this->x($s);$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return$rows;}
    public function continuationAfter(array$history,int$proposalExecutionId):?array
    {
        $origin=null;$continuations=[];
        foreach($history as$row){if((int)$row['id_']===$proposalExecutionId)$origin=$row;}
        if($origin===null)throw new TaskTransitionException('tool_resume_origin_invalid');
        foreach($history as$row)if((int)$row['attempt_number']>(int)$origin['attempt_number'])$continuations[]=$row;
        if(count($continuations)>1)throw new TaskConcurrencyException('tool_resume_continuation_ambiguous');
        return$continuations[0]??null;
    }
    public function lockWaitingByStep(int$taskId,int$stepId):array{$s=$this->p("SELECT * FROM TaskExecutions WHERE task_id_=? AND step_id_=? AND status='waiting' ORDER BY id_ FOR UPDATE");$s->bind_param('ii',$taskId,$stepId);$this->x($s);$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return$rows;}
    public function claimHttpContinuation(int$id,string$owner,string$token,int$leaseSeconds):bool
    {
        if($id<1||$owner===''||$token===''||$leaseSeconds<1)throw new TaskValidationException('tool_resume_lease_invalid');
        $s=$this->p("UPDATE TaskExecutions SET worker_id=?,lease_token=?,lease_expires_at=DATE_ADD(NOW(6),INTERVAL ? SECOND),heartbeat_at=NOW(6) WHERE id_=? AND status='running' AND (lease_expires_at IS NULL OR lease_expires_at<NOW(6))");
        $s->bind_param('ssii',$owner,$token,$leaseSeconds,$id);$this->x($s);$claimed=$s->affected_rows===1;$s->close();return$claimed;
    }
    public function updateStatus(int$id,string$status,?string$error=null,?string$modelId=null):array{$now=gmdate('Y-m-d H:i:s.u');$started=$status==='running'?$now:null;$finished=in_array($status,['completed','failed','cancelled'],true)?$now:null;$s=$this->p('UPDATE TaskExecutions SET status=?,started_at=COALESCE(?,started_at),finished_at=COALESCE(?,finished_at),error_message=?,model_id=COALESCE(?,model_id) WHERE id_=?');$s->bind_param('sssssi',$status,$started,$finished,$error,$modelId,$id);$this->x($s);$s->close();return$this->findById($id)??throw new RuntimeException('execution_not_found');}
    public function cancelWaiting(int$id):array{$s=$this->p("UPDATE TaskExecutions SET status='cancelled',finished_at=NOW(6),worker_id=NULL,lease_token=NULL,lease_expires_at=NULL WHERE id_=? AND status='waiting'");$s->bind_param('i',$id);$this->x($s);$affected=$s->affected_rows;$s->close();if($affected!==1)throw new TaskConcurrencyException('execution_concurrency_conflict');return$this->findById($id)??throw new RuntimeException('execution_not_found');}
    public function listOwned(int$task,int$u):array{$s=$this->p('SELECT e.id_,e.step_id_,e.trace_id,e.attempt_number,e.agent_key,e.model_id,e.status,e.started_at,e.heartbeat_at,e.finished_at,e.error_message,e.created_at,s.step_key,s.title step_title FROM TaskExecutions e JOIN Tasks t ON t.id_=e.task_id_ LEFT JOIN TaskSteps s ON s.id_=e.step_id_ AND s.task_id_=e.task_id_ WHERE e.task_id_=? AND t.user_id_=? ORDER BY e.id_');$s->bind_param('ii',$task,$u);$this->x($s);$o=[];$r=$s->get_result();while($x=$r->fetch_assoc())$o[]=$x;$s->close();return$o;}
    private function one(string$q,string$t,array$v):?array{$s=$this->p($q.' LIMIT 1');$s->bind_param($t,...$v);$this->x($s);$r=$s->get_result()->fetch_assoc();$s->close();return$r?:null;}private function p(string$q):mysqli_stmt{$s=$this->db->prepare($q);if(!$s)throw new RuntimeException('database_error');return$s;}private function x(mysqli_stmt$s):void{if(!$s->execute())throw new RuntimeException('database_error');}
}
