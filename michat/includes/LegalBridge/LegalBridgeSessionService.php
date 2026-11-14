<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/Chat/SessionLifecycleService.php';

/**
 * Every read/write must resolve the persisted Legal document binding first.
 * A signed session_id alone is never sufficient authorization.
 */
final class LegalBridgeSessionService
{
    public function __construct(private mysqli $db) {}

    /** One advisory lock for both chat turns and revision activation. */
    public static function chatLock(string $credential,int $user,int $session): string
    {
        return 'legal-chat:'.hash('sha256',$credential.':'.$user.':'.$session);
    }

    /** @return array{session_id:int,created:bool,status:string} */
    public function open(string $credentialId, int $userId, array $request): array
    {
        $system = (string)($request['external_system'] ?? '');
        $type = (string)($request['external_document_type'] ?? '');
        $id = (string)($request['external_document_id'] ?? '');
        $revision = (string)($request['external_revision'] ?? '');
        $title = (string)($request['title'] ?? 'Documento Legal');
        $instructions = (string)($request['session_instructions'] ?? '');
        if (mb_strlen($instructions)>3000) throw new LegalBridgeError('invalid_session_instructions',422);
        if ($system !== 'esforzados-legal'
            || !preg_match('/^[a-z0-9_-]{1,80}$/D', $type)
            || !preg_match('/^[A-Za-z0-9_.:-]{1,180}$/D', $id)
            || !preg_match('/^[a-f0-9]{64}$/D', $revision)
            || trim($title) === '' || mb_strlen($title) > 255) {
            throw new LegalBridgeError('invalid_document_reference', 422);
        }
        $this->db->begin_transaction();
        try {
            // Client row locking serializes concurrent opens for one credential.
            $q=$this->db->prepare('SELECT credential_id FROM LegalBridgeClients WHERE credential_id=? AND enabled=1 FOR UPDATE');
            $q->bind_param('s',$credentialId);
            $q->execute(); $client=$q->get_result()->fetch_assoc(); $q->close();
            if (!$client) throw new LegalBridgeError('principal_not_authorized',403);

            $q=$this->db->prepare('SELECT l.session_id_,s.status,s.user_id_ FROM LegalBridgeSessions l JOIN ChatSessions s ON s.id_=l.session_id_ WHERE l.credential_id=? AND l.user_id_=? AND l.external_system=? AND l.external_document_type=? AND l.external_document_id=? LIMIT 1');
            $q->bind_param('sisss',$credentialId,$userId,$system,$type,$id);
            $q->execute(); $existing=$q->get_result()->fetch_assoc(); $q->close();
            if ($existing) {
                if ((int)$existing['user_id_'] !== $userId) throw new LegalBridgeError('session_forbidden',403);
                $this->db->commit();
                return ['session_id'=>(int)$existing['session_id_'],'created'=>false,'status'=>(string)$existing['status']];
            }
            $q=$this->db->prepare('SELECT model_id FROM UserPreferences WHERE user_id_=? LIMIT 1');
            $q->bind_param('i',$userId);$q->execute();$pref=$q->get_result()->fetch_assoc();$q->close();
            $model = trim((string)($pref['model_id']??''));
            if ($model === '') throw new LegalBridgeError('model_not_configured',503);
            $created = (new SessionLifecycleService($this->db))->create($userId,$title,$model);
            $sessionId = (int)$created['id'];

            $q=$this->db->prepare('INSERT INTO LegalBridgeSessions (credential_id,user_id_,external_system,external_document_type,external_document_id,session_id_,current_revision,session_instructions) VALUES (?,?,?,?,?,?,?,?)');
            $q->bind_param('sisssiss',$credentialId,$userId,$system,$type,$id,$sessionId,$revision,$instructions);
            $q->execute();$q->close();
            $this->db->commit();
            return ['session_id'=>$sessionId,'created'=>true,'status'=>(string)$created['status']];
        } catch(Throwable $e) {
            $this->db->rollback();
            if ($e instanceof LegalBridgeError) throw $e;
            throw new LegalBridgeError('bridge_unavailable',503);
        }
    }

    public function assertOwned(string $credentialId,int $userId,int $sessionId): void
    {
        if($sessionId<=0) throw new LegalBridgeError('session_not_found',404);
        $q=$this->db->prepare('SELECT s.status FROM LegalBridgeSessions l JOIN ChatSessions s ON s.id_=l.session_id_ WHERE l.credential_id=? AND l.user_id_=? AND l.session_id_=? AND s.user_id_=? LIMIT 1');
        $q->bind_param('siii',$credentialId,$userId,$sessionId,$userId);
        $q->execute();$row=$q->get_result()->fetch_assoc();$q->close();
        if(!$row) throw new LegalBridgeError('session_not_found',404);
    }

    /** @return array<string,mixed> */
    public function history(string $credentialId,int $userId,int $sessionId,int $cursor,int $limit): array
    {
        $this->assertOwned($credentialId,$userId,$sessionId);
        $limit=max(1,min(100,$limit));$cursor=max(0,$cursor);
        $q=$this->db->prepare("SELECT id_,role,content_type,content,created_at FROM ChatMessages WHERE session_id_=? AND user_id_=? AND id_>? AND role IN ('user','assistant') ORDER BY id_ ASC LIMIT ?");
        $q->bind_param('iiii',$sessionId,$userId,$cursor,$limit);$q->execute();
        $messages=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        $next=$messages?(int)$messages[count($messages)-1]['id_']:$cursor;
        return ['messages'=>$messages,'next_cursor'=>$next,'has_more'=>count($messages)===$limit];
    }

    /** @return array<string,mixed> */
    public function activity(string $credentialId,int $userId,int $sessionId,int $cursor,int $limit): array
    {
        $this->assertOwned($credentialId,$userId,$sessionId);
        $cursor=max(0,$cursor);$limit=max(1,min(100,$limit));
        // Whitelist operational fields. Do not expose details_json, prompts,
        // extracted document text, model internals or private reasoning.
        $q=$this->db->prepare('SELECT id_,trace_id,phase,event_key,status,title,duration_ms,created_at FROM ChatActivityEvents WHERE session_id_=? AND user_id_=? AND id_>? ORDER BY id_ ASC LIMIT ?');
        $q->bind_param('iiii',$sessionId,$userId,$cursor,$limit);$q->execute();
        $events=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        return ['events'=>$events,'next_cursor'=>$events?(int)$events[count($events)-1]['id_']:$cursor,'has_more'=>count($events)===$limit];
    }
}
