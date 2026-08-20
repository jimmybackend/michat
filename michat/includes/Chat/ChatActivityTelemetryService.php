<?php
declare(strict_types=1);

/** Emits Phase 7 activity events from server-owned Task execution data. */
final class ChatActivityTelemetryService
{
    private const STATUSES = ['started','completed','info','waiting','error'];

    public function __construct(private mysqli $db) {}

    /** @param mixed $details */
    public function emit(
        string $traceId,
        int $userId,
        int $sessionId,
        string $phase,
        string $eventKey,
        string $status,
        string $title,
        ?string $summary = null,
        $details = null,
        ?string $modelId = null,
        ?int $durationMs = null
    ): void {
        if (!preg_match('/^[A-Za-z0-9_-]{16,36}$/', $traceId) || $userId < 1 || $sessionId < 1) return;
        if (!in_array($status, self::STATUSES, true)) $status = 'info';
        $lockName = 'chat_activity:' . hash('sha256', $traceId . ':' . $eventKey);

        try {
            if (!$this->acquireLock($lockName)) return;
            try {
                if ($this->exists($traceId, $eventKey)) return;
                $detailsJson = $details === null ? null : json_encode($this->normalize($details), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($detailsJson === false) $detailsJson = json_encode(['error'=>'No se pudo serializar details_json']);
                $durationMs = $durationMs === null ? null : max(0, $durationMs);
                $stmt = $this->db->prepare('INSERT INTO ChatActivityEvents (trace_id,session_id_,user_id_,phase,event_key,status,title,summary,details_json,model_id,duration_ms) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                if (!$stmt) return;
                $stmt->bind_param('siisssssssi', $traceId, $sessionId, $userId, $phase, $eventKey, $status, $title, $summary, $detailsJson, $modelId, $durationMs);
                $stmt->execute();
                $stmt->close();
            } finally {
                $this->releaseLock($lockName);
            }
        } catch (Throwable $e) {
            // Activity telemetry is fail-open in bedrock_chat2.php as well.
            error_log('ChatActivityTelemetryService: ' . $e->getMessage());
        }
    }

    private function exists(string $traceId, string $eventKey): bool
    {
        $stmt = $this->db->prepare('SELECT id_ FROM ChatActivityEvents WHERE trace_id=? AND event_key=? LIMIT 1');
        if (!$stmt) return false;
        $stmt->bind_param('ss', $traceId, $eventKey);
        if (!$stmt->execute()) { $stmt->close(); return false; }
        $exists = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $exists;
    }

    /** @param mixed $value @return mixed */
    private function normalize($value, int $depth = 0)
    {
        if ($depth > 8) return '[profundidad omitida]';
        if (is_array($value)) {
            $out=[];$count=0;
            foreach ($value as $key=>$item) {
                if ($count++ >= 200) { $out['_truncated_items']=true; break; }
                $out[$key]=$this->normalize($item,$depth+1);
            }
            return $out;
        }
        if (is_object($value)) return $this->normalize((array)$value,$depth+1);
        if (is_string($value)) {
            $value=(string)preg_replace('/<thinking>[\s\S]*?<\/thinking>/i','[razonamiento privado omitido]',$value);
            return mb_strlen($value)>120000?mb_substr($value,0,120000)."\n[truncado para telemetría]":$value;
        }
        return is_bool($value)||is_int($value)||is_float($value)||$value===null?$value:(string)$value;
    }

    private function acquireLock(string $name): bool
    {
        $stmt=$this->db->prepare('SELECT GET_LOCK(?,10) acquired');
        if(!$stmt)return false;$stmt->bind_param('s',$name);
        if(!$stmt->execute()){ $stmt->close(); return false; }
        $ok=(int)($stmt->get_result()->fetch_assoc()['acquired']??0)===1;$stmt->close();return$ok;
    }

    private function releaseLock(string $name): void
    {
        $stmt=$this->db->prepare('SELECT RELEASE_LOCK(?)');if(!$stmt)return;
        $stmt->bind_param('s',$name);$stmt->execute();$stmt->close();
    }
}
