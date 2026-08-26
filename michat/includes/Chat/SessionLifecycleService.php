<?php

declare(strict_types=1);

final class SessionLifecycleService
{
    private mysqli $db;
    public function __construct(mysqli $db) { $this->db = $db; }

    /** @return array<string,mixed> */
    public function create(int $userId, string $title, string $modelId, ?int $projectId = null, ?int $parentMessageId = null): array
    {
        if ($userId <= 0) throw new InvalidArgumentException('user_id inválido');
        $title = trim($title) !== '' ? mb_substr(trim($title), 0, 255) : 'Nueva conversación';
        $modelId = trim($modelId);
        if ($modelId === '') throw new InvalidArgumentException('Falta parámetro model');

        $provider = $this->inferProvider($modelId);
        $meta = null;
        $branch = null;

        if ($parentMessageId !== null && $parentMessageId > 0) {
            $parent = $this->loadParentMessage($userId, $parentMessageId);
            if (!$parent) throw new RuntimeException('El mensaje padre no existe o no pertenece al usuario.');
            $projectId = $parent['project_id_'] !== null ? (int)$parent['project_id_'] : null;
            $rootSessionId = $this->resolveRootSessionId($userId, (int)$parent['session_id_']);
            $branch = [
                'parent_session_id' => (int)$parent['session_id_'],
                'parent_message_id' => $parentMessageId,
                'root_session_id' => $rootSessionId,
                'created_at' => gmdate('c'),
            ];
            $meta = json_encode(['branch' => $branch], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif ($projectId !== null && $projectId > 0) {
            if (!$this->ownsProject($userId, $projectId)) $projectId = null;
        } else {
            $projectId = null;
        }

        $createdId = null; $lastError = '';
        for ($attempt=0; $attempt<4; $attempt++) {
            $id = $this->nextId('ChatSessions','id_');
            $stmt = $this->db->prepare("INSERT INTO ChatSessions (id_,user_id_,project_id_,title,model_id,provider,status,meta) VALUES (?,?,?,?,?,?,'open',?)");
            if (!$stmt) throw new RuntimeException($this->db->error);
            $stmt->bind_param('iiissss',$id,$userId,$projectId,$title,$modelId,$provider,$meta);
            if ($stmt->execute()) { $createdId=$id; $stmt->close(); break; }
            $lastError=$stmt->error; $stmt->close();
            if (stripos($lastError,'duplicate')===false && stripos($lastError,'duplic')===false) break;
            usleep(50000);
        }
        if (!$createdId) throw new RuntimeException($lastError ?: 'No se pudo crear la sesión');

        $row = $this->loadSession($userId,$createdId) ?: [];
        $out = [
            'id'=>$createdId,
            'project_id'=>isset($row['project_id_']) && $row['project_id_']!==null ? (int)$row['project_id_'] : $projectId,
            'title'=>(string)($row['title']??$title),
            'status'=>(string)($row['status']??'open'),
            'model_id'=>(string)($row['model_id']??$modelId),
            'provider'=>$row['provider']!==null ? (string)$row['provider'] : $provider,
            'created_at'=>(string)($row['created_at']??''),
            'updated_at'=>(string)($row['updated_at']??''),
        ];
        if ($branch) {
            $out['branch'] = $branch + ['inherited_message_count'=>$this->countMessagesThrough((int)$branch['parent_session_id'], (int)$branch['parent_message_id'], $userId)];
        }
        return $out;
    }

    public function discardIfEmpty(int $userId, int $sessionId): bool
    {
        if ($userId<=0 || $sessionId<=0) return false;
        $row=$this->loadSession($userId,$sessionId); if(!$row) return false;
        if ($this->isBranchMeta($row['meta']??null)) return false;
        if (!$this->isEmpty($userId,$sessionId)) return false;
        $stmt=$this->db->prepare("DELETE FROM ChatSessions WHERE id_=? AND user_id_=?");
        if(!$stmt) return false; $stmt->bind_param('ii',$sessionId,$userId); $stmt->execute();
        $deleted=$stmt->affected_rows>0; $stmt->close(); return $deleted;
    }

    public function cleanupStaleEmpty(int $userId, int $graceSeconds = 900, int $limit = 50): int
    {
        $graceSeconds=max(60,min(86400,$graceSeconds)); $limit=max(1,min(200,$limit));
        $sql="SELECT id_,meta FROM ChatSessions WHERE user_id_=? AND status='open' AND updated_at < (NOW() - INTERVAL {$graceSeconds} SECOND) ORDER BY updated_at ASC LIMIT {$limit}";
        $stmt=$this->db->prepare($sql); if(!$stmt)return 0; $stmt->bind_param('i',$userId); $stmt->execute();
        $rows=[]; $res=$stmt->get_result(); while($r=$res->fetch_assoc())$rows[]=$r; $stmt->close();
        $deleted=0; foreach($rows as $row){ if($this->isBranchMeta($row['meta']??null))continue; if($this->discardIfEmpty($userId,(int)$row['id_']))$deleted++; }
        return $deleted;
    }

    /** @return array<string,mixed> */
    public function rename(int $userId,int $sessionId,string $title):array
    {
        $title=trim($title);if($title==='')$title='Conversación';$title=mb_substr($title,0,255);
        $stmt=$this->db->prepare('UPDATE ChatSessions SET title=? WHERE id_=? AND user_id_=?');
        if(!$stmt)throw new RuntimeException('database_error');$stmt->bind_param('sii',$title,$sessionId,$userId);if(!$stmt->execute())throw new RuntimeException('database_error');$affected=$stmt->affected_rows;$stmt->close();
        $row=$this->loadSession($userId,$sessionId);if(!$row)throw new OutOfBoundsException('session_not_found');
        if($affected>1)throw new RuntimeException('session_update_invalid');return$this->publicSession($row);
    }

    /** @return array<string,mixed> */
    public function archive(int$userId,int$sessionId):array{return$this->setStatus($userId,$sessionId,'archived');}
    /** @return array<string,mixed> */
    public function restore(int$userId,int$sessionId):array{return$this->setStatus($userId,$sessionId,'open');}

    public function isEmpty(int $userId, int $sessionId): bool
    {
        $stmt=$this->db->prepare("SELECT cs.id_,
            EXISTS(SELECT 1 FROM ChatMessages cm WHERE cm.session_id_=cs.id_ LIMIT 1) AS has_messages,
            EXISTS(SELECT 1 FROM SessionContextBlocks scb WHERE scb.session_id_=cs.id_ LIMIT 1) AS has_blocks
            FROM ChatSessions cs WHERE cs.id_=? AND cs.user_id_=? LIMIT 1");
        if(!$stmt)return false;
        $stmt->bind_param('ii',$sessionId,$userId);
        $stmt->execute();
        $row=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if(!$row || (int)$row['has_messages'] || (int)$row['has_blocks']) return false;

        // Los adjuntos de sesión no tienen FK directa: se relacionan por FileS3.Ruta,
        // Data/Chat/Uploads/{user}/{YYYY}/{MM}/{DD}/{session_id}/. Una sesión sin
        // mensajes pero con archivos NO es descartable.
        $pathPattern = 'Data/Chat/Uploads/' . $userId . '/%/' . $sessionId . '/%';
        $fileStmt = $this->db->prepare("SELECT id_ FROM FileS3 WHERE user_id_=? AND Found=1 AND Ruta LIKE ? LIMIT 1");
        if(!$fileStmt) return false;
        $fileStmt->bind_param('is',$userId,$pathPattern);
        $fileStmt->execute();
        $hasFiles=(bool)$fileStmt->get_result()->fetch_assoc();
        $fileStmt->close();

        return !$hasFiles;
    }

    private function ownsProject(int $userId,int $projectId):bool
    {
        $stmt=$this->db->prepare("SELECT id_ FROM Projects WHERE id_=? AND user_id_=? AND status='active' LIMIT 1"); if(!$stmt)return false;
        $stmt->bind_param('ii',$projectId,$userId);$stmt->execute();$ok=(bool)$stmt->get_result()->fetch_assoc();$stmt->close();return $ok;
    }

    /** @return array<string,mixed>|null */
    private function loadParentMessage(int $userId,int $messageId):?array
    {
        $stmt=$this->db->prepare("SELECT cm.id_,cm.session_id_,cm.role,cs.project_id_,cs.meta FROM ChatMessages cm INNER JOIN ChatSessions cs ON cs.id_=cm.session_id_ WHERE cm.id_=? AND cm.user_id_=? AND cs.user_id_=? LIMIT 1");
        if(!$stmt)return null;$stmt->bind_param('iii',$messageId,$userId,$userId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();return $row?:null;
    }

    /** @return array<string,mixed>|null */
    private function loadSession(int $userId,int $sessionId):?array
    {
        $stmt=$this->db->prepare("SELECT id_,project_id_,title,model_id,provider,status,meta,created_at,updated_at FROM ChatSessions WHERE id_=? AND user_id_=? LIMIT 1");
        if(!$stmt)return null;$stmt->bind_param('ii',$sessionId,$userId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();return $row?:null;
    }

    /** @return array<string,mixed> */
    private function setStatus(int$userId,int$sessionId,string$status):array
    {
        if(!in_array($status,['open','archived'],true))throw new InvalidArgumentException('session_status_invalid');
        $stmt=$this->db->prepare('UPDATE ChatSessions SET status=? WHERE id_=? AND user_id_=?');if(!$stmt)throw new RuntimeException('database_error');
        $stmt->bind_param('sii',$status,$sessionId,$userId);if(!$stmt->execute())throw new RuntimeException('database_error');$affected=$stmt->affected_rows;$stmt->close();
        $row=$this->loadSession($userId,$sessionId);if(!$row)throw new OutOfBoundsException('session_not_found');
        if($affected>1)throw new RuntimeException('session_update_invalid');return$this->publicSession($row);
    }

    /** @return array<string,mixed> */
    private function publicSession(array$row):array{return['id'=>(int)$row['id_'],'title'=>(string)$row['title'],'status'=>(string)$row['status'],'model_id'=>(string)$row['model_id'],'provider'=>$row['provider']!==null?(string)$row['provider']:null,'updated_at'=>(string)$row['updated_at']];}

    private function resolveRootSessionId(int $userId,int $sessionId):int
    {
        $seen=[];$cursor=$sessionId;$root=$sessionId;
        for($i=0;$i<12;$i++){
            if(isset($seen[$cursor]))break;$seen[$cursor]=true;$row=$this->loadSession($userId,$cursor);if(!$row)break;$root=$cursor;
            $meta=$this->decodeMeta($row['meta']??null);$parent=(int)($meta['branch']['parent_session_id']??0);if($parent<=0)break;$cursor=$parent;
        }
        return $root;
    }

    private function countMessagesThrough(int $sessionId,int $messageId,int $userId):int
    {
        $stmt=$this->db->prepare("SELECT COUNT(*) AS c FROM ChatMessages WHERE session_id_=? AND user_id_=? AND id_<=? AND role IN ('user','assistant')"); if(!$stmt)return 0;
        $stmt->bind_param('iii',$sessionId,$userId,$messageId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();return (int)($row['c']??0);
    }

    /** @return array<string,mixed> */
    private function decodeMeta($raw):array { if(!is_string($raw)||trim($raw)==='')return[];$d=json_decode($raw,true);return is_array($d)?$d:[]; }
    private function isBranchMeta($raw):bool { $m=$this->decodeMeta($raw); return !empty($m['branch']['parent_session_id']) && !empty($m['branch']['parent_message_id']); }
    private function inferProvider(string $m):?string { if(str_starts_with($m,'amazon.'))return'amazon';if(str_starts_with($m,'us.anthropic.')||str_starts_with($m,'anthropic.')||str_contains($m,'anthropic'))return'anthropic';if(str_starts_with($m,'openai:')||str_contains($m,'openai'))return'openai';return null; }
    private function nextId(string $table,string $col):int { $table=preg_replace('/[^A-Za-z0-9_]+/','',$table);$col=preg_replace('/[^A-Za-z0-9_]+/','',$col);$res=$this->db->query("SELECT COALESCE(MAX({$col}),0)+1 AS nxt FROM {$table}");$row=$res?$res->fetch_assoc():null;return max(1,(int)($row['nxt']??1)); }
}
