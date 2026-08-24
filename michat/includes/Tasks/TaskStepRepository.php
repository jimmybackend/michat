<?php
declare(strict_types=1);
final class TaskStepRepository
{
    public function __construct(private mysqli $db) {}
    public function createRespond(int $taskId, array $input = []): array
    {
        $json=$input ? json_encode($input,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;
        $s=$this->prepare("INSERT INTO TaskSteps(task_id_,position,step_key,title,step_type,status,agent_key,max_attempts,input_json) VALUES(?,1,'respond','Generar respuesta','model','pending','chat_main',1,?)");
        $s->bind_param('is',$taskId,$json);$this->execute($s);$id=(int)$this->db->insert_id;$s->close();return $this->findById($id)??throw new RuntimeException('step_create_failed');
    }
    public function createPlanned(int $taskId, TaskPlanStep $step, int $position, string $status='ready', array $input=[]): array
    {
        $s=$this->prepare('INSERT INTO TaskSteps(task_id_,position,step_key,title,description,step_type,status,agent_key,max_attempts,input_json) VALUES(?,?,?,?,?,?,?,?,1,?)');
        $data=$step->persistenceData($position);$json=$input?json_encode($input,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null;$s->bind_param('iisssssss',$taskId,$data['position'],$data['step_key'],$data['title'],$data['description'],$data['step_type'],$status,$data['agent_key'],$json);$this->execute($s);$id=(int)$this->db->insert_id;$s->close();return $this->findById($id)??throw new RuntimeException('step_create_failed');
    }
    public function countByTask(int $taskId):int{$s=$this->prepare('SELECT COUNT(*) c FROM TaskSteps WHERE task_id_=?');$s->bind_param('i',$taskId);$this->execute($s);$n=(int)$s->get_result()->fetch_assoc()['c'];$s->close();return$n;}
    public function hasExecutions(int $taskId):bool{$s=$this->prepare('SELECT 1 FROM TaskExecutions WHERE task_id_=? LIMIT 1');$s->bind_param('i',$taskId);$this->execute($s);$found=(bool)$s->get_result()->fetch_assoc();$s->close();return$found;}
    public function deleteUnexecutedPlaceholder(int $taskId):void{$s=$this->prepare("DELETE s FROM TaskSteps s LEFT JOIN TaskExecutions e ON e.step_id_=s.id_ WHERE s.task_id_=? AND s.step_key='respond' AND e.id_ IS NULL");$s->bind_param('i',$taskId);$this->execute($s);$s->close();}
    public function findById(int$id):?array{return $this->one('SELECT * FROM TaskSteps WHERE id_=?','i',[$id]);}
    public function lockForRetry(int$id,int$taskId):?array{$s=$this->prepare('SELECT * FROM TaskSteps WHERE id_=? AND task_id_=? LIMIT 1 FOR UPDATE');$s->bind_param('ii',$id,$taskId);$this->execute($s);$row=$s->get_result()->fetch_assoc();$s->close();return$row?:null;}
    public function lockCurrentWaitingUser(int$id,int$taskId):?array{$s=$this->prepare("SELECT * FROM TaskSteps WHERE id_=? AND task_id_=? AND status='waiting_user' LIMIT 1 FOR UPDATE");$s->bind_param('ii',$id,$taskId);$this->execute($s);$row=$s->get_result()->fetch_assoc();$s->close();return$row?:null;}
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
