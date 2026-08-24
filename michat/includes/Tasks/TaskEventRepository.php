<?php
declare(strict_types=1);

final class TaskEventRepository
{
    public function __construct(private mysqli $db) {}

    public function append(array $data): void
    {
        $stmt=$this->db->prepare('INSERT INTO TaskEvents(task_id_,step_id_,execution_id_,actor_type,actor_user_id_,event_key,from_status,to_status,summary,details_json) VALUES(?,?,?,?,?,?,?,?,?,?)');
        if(!$stmt)throw new RuntimeException('database_error');
        $step=$data['step_id']??null;$execution=$data['execution_id']??null;$actor=$data['actor_user_id']??null;$from=$data['from_status']??null;$to=$data['to_status']??null;
        $json=isset($data['details'])?json_encode($data['details'],JSON_THROW_ON_ERROR):null;
        $stmt->bind_param('iiisisssss',$data['task_id'],$step,$execution,$data['actor_type'],$actor,$data['event_key'],$from,$to,$data['summary'],$json);
        if(!$stmt->execute())throw new RuntimeException('database_error');
        $stmt->close();
    }

    /** @return list<array<string,mixed>> Most recent rows, restored to authoritative ascending id order. */
    public function listOwned(int $task,int $user,int $limit=101): array
    {
        if($limit<1||$limit>501)throw new TaskValidationException('history_limit_invalid');
        $sql='SELECT e.id_ event_order,e.actor_type,e.event_key,e.from_status,e.to_status,e.summary,e.created_at,'
            .'s.step_key,s.title step_title,x.attempt_number execution_attempt,x.trace_id execution_trace_id '
            .'FROM TaskEvents e JOIN Tasks t ON t.id_=e.task_id_ '
            .'LEFT JOIN TaskSteps s ON s.id_=e.step_id_ AND s.task_id_=e.task_id_ '
            .'LEFT JOIN TaskExecutions x ON x.id_=e.execution_id_ AND x.task_id_=e.task_id_ '
            .'WHERE e.task_id_=? AND t.user_id_=? ORDER BY e.id_ DESC LIMIT ?';
        $stmt=$this->db->prepare($sql);if(!$stmt)throw new RuntimeException('database_error');
        $stmt->bind_param('iii',$task,$user,$limit);if(!$stmt->execute())throw new RuntimeException('database_error');
        $rows=[];$result=$stmt->get_result();while($row=$result->fetch_assoc())$rows[]=$row;$stmt->close();
        return array_reverse($rows);
    }
}
