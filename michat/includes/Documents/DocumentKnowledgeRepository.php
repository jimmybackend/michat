<?php
declare(strict_types=1);

final class DocumentKnowledgeLeaseLost extends RuntimeException {}

final class DocumentKnowledgeRepository
{
    public function __construct(private mysqli $db) {}

    public function available(): bool
    {
        try {
            foreach (['Sources','Chunks','Jobs','Events'] as $suffix) $this->write('SELECT id_ FROM DocumentKnowledge'.$suffix.' LIMIT 0');
            return true;
        } catch (mysqli_sql_exception $e) {
            if ((int)$e->getCode() === 1146) return false;
            throw $e;
        }
    }

    public function run(string $sql, string $types = '', mixed ...$values): mysqli_stmt
    {
        $s=$this->db->prepare($sql);
        if (!$s) throw new mysqli_sql_exception('Document knowledge SQL preparation failed',(int)$this->db->errno);
        if ($types!=='' && !$s->bind_param($types,...$values)) { $s->close();throw new RuntimeException('Document knowledge SQL binding failed'); }
        if (!$s->execute()) { $code=(int)$s->errno;$s->close();throw new mysqli_sql_exception('Document knowledge SQL execution failed',$code); }
        return $s;
    }

    public function all(string $sql,string $types='',mixed ...$values): array
    {
        $s=$this->run($sql,$types,...$values);$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $rows;
    }

    public function one(string $sql,string $types='',mixed ...$values): ?array { return $this->all($sql,$types,...$values)[0]??null; }
    public function write(string $sql,string $types='',mixed ...$values): int { $s=$this->run($sql,$types,...$values);$n=$s->affected_rows;$s->close();return $n; }

    public function transaction(callable $work): mixed
    {
        if (!$this->db->begin_transaction()) throw new RuntimeException('Document transaction could not start');
        try { $value=$work();if(!$this->db->commit())throw new RuntimeException('Document transaction commit failed');return $value; }
        catch (Throwable $e) { try{$this->db->rollback();}catch(Throwable $ignored){} throw $e; }
    }

    public function event(array $job,string $type,array $details=[]): void
    {
        $this->write('INSERT INTO DocumentKnowledgeEvents(source_id,job_id,actor_user_id,event_type,details_json) VALUES (?,?,?,?,?)','iiiss',(int)$job['source_id'],(int)$job['id_'],(int)$job['user_id_'],$type,json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    }

    public function job(int $id): ?array
    {
        return $this->one('SELECT j.*,s.user_id_,s.files3_id,s.source_etag,s.s3_key,s.format,s.coverage,s.embedding_model,s.state,s.chunks_total,s.chunks_done FROM DocumentKnowledgeJobs j JOIN DocumentKnowledgeSources s ON s.id_=j.source_id WHERE j.id_=?','i',$id);
    }

    /** Compare-and-set claim works on MySQL 8 and MariaDB without SKIP LOCKED. */
    public function claim(?int $requestedId=null): ?array
    {
        for ($tries=0;$tries<5;$tries++) {
            $where=$requestedId===null?'':' AND id_='.(int)$requestedId;
            $candidate=$this->one("SELECT id_ FROM DocumentKnowledgeJobs WHERE status IN ('pending','retry','running') AND available_at<=UTC_TIMESTAMP() AND (lease_until IS NULL OR lease_until<UTC_TIMESTAMP()) AND failure_count<5{$where} ORDER BY available_at,id_ LIMIT 1");
            if (!$candidate) return null;
            $token=bin2hex(random_bytes(24));$id=(int)$candidate['id_'];
            $n=$this->write("UPDATE DocumentKnowledgeJobs SET failure_count=failure_count+IF(status='running',1,0),status='running',lease_owner=?,lease_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 300 SECOND),claim_count=claim_count+1 WHERE id_=? AND status IN ('pending','retry','running') AND (lease_until IS NULL OR lease_until<UTC_TIMESTAMP()) AND available_at<=UTC_TIMESTAMP() AND failure_count<5",'si',$token,$id);
            if ($n===1) {
                $job=$this->job($id);
                if ((int)$job['failure_count']>=5) {
                    $this->fenced($job,function() use($job) {
                        $this->write("UPDATE DocumentKnowledgeSources SET state='failed' WHERE id_=?",'i',(int)$job['source_id']);
                        $this->event($job,'interruption_budget_exhausted');
                        $this->release($job,$job['phase'],'failed','El worker se interrumpió repetidamente; requiere revisión y reintento explícito');
                    });
                    return null;
                }
                return $job;
            }
        }
        return null;
    }

    public function renew(array $job): void
    {
        // claim_count also changes so affected_rows remains reliable within the same second.
        $n=$this->write("UPDATE DocumentKnowledgeJobs SET lease_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 300 SECOND),claim_count=claim_count+1 WHERE id_=? AND lease_owner=? AND status='running' AND lease_until>=UTC_TIMESTAMP()",'is',(int)$job['id_'],$job['lease_owner']);
        if ($n!==1) throw new DocumentKnowledgeLeaseLost('Document worker lease expired');
    }

    /** All progress/chunk mutations happen inside a transaction fenced by the live lease. */
    public function fenced(array $job,callable $work): mixed
    {
        return $this->transaction(function() use($job,$work) {
            // Same source -> job lock order as enqueue avoids a requeue/worker deadlock.
            $this->one('SELECT id_ FROM DocumentKnowledgeSources WHERE id_=? FOR UPDATE','i',(int)$job['source_id']);
            $lease=$this->one("SELECT lease_owner,lease_until>=UTC_TIMESTAMP() AS valid FROM DocumentKnowledgeJobs WHERE id_=? AND status='running' FOR UPDATE",'i',(int)$job['id_']);
            if (!$lease || !$lease['valid'] || !hash_equals((string)$lease['lease_owner'],(string)$job['lease_owner'])) throw new DocumentKnowledgeLeaseLost('Stale document worker cannot publish');
            return $work();
        });
    }

    public function release(array $job,string $phase,string $status='pending',?string $error=null,int $delay=0): void
    {
        $this->write('UPDATE DocumentKnowledgeJobs SET phase=?,status=?,lease_owner=NULL,lease_until=NULL,error_message=?,available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND) WHERE id_=? AND lease_owner=?','sssiis',$phase,$status,$error,$delay,(int)$job['id_'],$job['lease_owner']);
    }
}
