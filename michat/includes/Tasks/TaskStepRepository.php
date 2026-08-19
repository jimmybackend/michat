<?php
declare(strict_types=1);
final class TaskStepRepository
{
    public function __construct(private mysqli $db) {}
    public function createRespond(int $taskId): array
    {
        $s=$this->prepare("INSERT INTO TaskSteps(task_id_,position,step_key,title,step_type,status,agent_key,max_attempts) VALUES(?,1,'respond','Generar respuesta','model','pending','chat_main',1)");
        $s->bind_param('i',$taskId);$this->execute($s);$id=(int)$this->db->insert_id;$s->close();return $this->findById($id)??throw new RuntimeException('step_create_failed');
    }
    public function findById(int$id):?array{return $this->one('SELECT * FROM TaskSteps WHERE id_=?','i',[$id]);}
    public function findByKey(int$taskId,string$key):?array{return $this->one('SELECT * FROM TaskSteps WHERE task_id_=? AND step_key=?','is',[$taskId,$key]);}
    public function updateStatus(int$id,string$status,int$lock,array$fields=[]):array
    {
        $now=gmdate('Y-m-d H:i:s.u');$started=$status==='running'?$now:null;$completed=$status==='completed'?$now:null;$error=$fields['error_message']??null;
        $s=$this->prepare("UPDATE TaskSteps SET status=?,started_at=COALESCE(?,started_at),completed_at=COALESCE(?,completed_at),error_message=?,progress_percent=IF(?='completed',100,progress_percent),lock_version=lock_version+1 WHERE id_=? AND lock_version=?");
        $s->bind_param('sssssii',$status,$started,$completed,$error,$status,$id,$lock);$this->execute($s);$a=$s->affected_rows;$s->close();if($a!==1)throw new TaskConcurrencyException('step_concurrency_conflict');return$this->findById($id)??throw new RuntimeException('step_not_found');
    }
    public function listOwned(int$task,int$u):array{$s=$this->prepare('SELECT s.* FROM TaskSteps s JOIN Tasks t ON t.id_=s.task_id_ WHERE s.task_id_=? AND t.user_id_=? ORDER BY s.position');$s->bind_param('ii',$task,$u);$this->execute($s);$o=[];$r=$s->get_result();while($x=$r->fetch_assoc())$o[]=$x;$s->close();return$o;}
    private function one(string$q,string$t,array$v):?array{$s=$this->prepare($q.' LIMIT 1');$s->bind_param($t,...$v);$this->execute($s);$r=$s->get_result()->fetch_assoc();$s->close();return$r?:null;}
    private function prepare(string$q):mysqli_stmt{$s=$this->db->prepare($q);if(!$s)throw new RuntimeException('database_error');return$s;}
    private function execute(mysqli_stmt$s):void{if(!$s->execute())throw new RuntimeException('database_error');}
}
