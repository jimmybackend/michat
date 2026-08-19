<?php
declare(strict_types=1);
final class TaskExecutionRepository
{
    public function __construct(private mysqli $db) {}
    public function create(int$taskId,int$stepId,string$traceId,string$modelId):array{$s=$this->p("INSERT INTO TaskExecutions(task_id_,step_id_,trace_id,attempt_number,agent_key,model_id,status) VALUES(?,?,?,1,'chat_main',?,'queued')");$s->bind_param('iiss',$taskId,$stepId,$traceId,$modelId);$this->x($s);$id=(int)$this->db->insert_id;$s->close();return$this->findById($id)??throw new RuntimeException('execution_create_failed');}
    public function findById(int$id):?array{return$this->one('SELECT * FROM TaskExecutions WHERE id_=?','i',[$id]);}
    public function findByTrace(string$trace):?array{return$this->one('SELECT * FROM TaskExecutions WHERE trace_id=?','s',[$trace]);}
    public function findAttempt(int$step,int$attempt):?array{return$this->one('SELECT * FROM TaskExecutions WHERE step_id_=? AND attempt_number=?','ii',[$step,$attempt]);}
    public function updateStatus(int$id,string$status,?string$error=null,?string$modelId=null):array{$now=gmdate('Y-m-d H:i:s.u');$started=$status==='running'?$now:null;$finished=in_array($status,['completed','failed','cancelled'],true)?$now:null;$s=$this->p('UPDATE TaskExecutions SET status=?,started_at=COALESCE(?,started_at),finished_at=COALESCE(?,finished_at),error_message=?,model_id=COALESCE(?,model_id) WHERE id_=?');$s->bind_param('sssssi',$status,$started,$finished,$error,$modelId,$id);$this->x($s);$s->close();return$this->findById($id)??throw new RuntimeException('execution_not_found');}
    public function listOwned(int$task,int$u):array{$s=$this->p('SELECT e.id_,e.step_id_,e.trace_id,e.attempt_number,e.agent_key,e.model_id,e.status,e.started_at,e.heartbeat_at,e.finished_at,e.error_message,e.created_at FROM TaskExecutions e JOIN Tasks t ON t.id_=e.task_id_ WHERE e.task_id_=? AND t.user_id_=? ORDER BY e.id_');$s->bind_param('ii',$task,$u);$this->x($s);$o=[];$r=$s->get_result();while($x=$r->fetch_assoc())$o[]=$x;$s->close();return$o;}
    private function one(string$q,string$t,array$v):?array{$s=$this->p($q.' LIMIT 1');$s->bind_param($t,...$v);$this->x($s);$r=$s->get_result()->fetch_assoc();$s->close();return$r?:null;}private function p(string$q):mysqli_stmt{$s=$this->db->prepare($q);if(!$s)throw new RuntimeException('database_error');return$s;}private function x(mysqli_stmt$s):void{if(!$s->execute())throw new RuntimeException('database_error');}
}
