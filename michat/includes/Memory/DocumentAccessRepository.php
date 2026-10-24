<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/session_file_extractor.php';
require_once dirname(__DIR__) . '/Drive/FederationImportedAccess.php';

/** Live FileS3 checks. A cached chunk never grants access to its source. */
final class DocumentAccessRepository
{
    public function __construct(private mysqli $db) {}

    public function session(int $userId, int $sessionId): ?array
    {
        if ($userId <= 0 || $sessionId <= 0) return null;
        $s = $this->db->prepare('SELECT id_,project_id_,meta,status FROM ChatSessions WHERE id_=? AND user_id_=? LIMIT 1');
        if (!$s) return null;
        $s->bind_param('ii', $sessionId, $userId);
        $s->execute(); $row = $s->get_result()->fetch_assoc(); $s->close();
        return $row && $row['status'] !== 'closed' ? $row : null;
    }

    public function file(int $userId, int $fileId): ?array
    {
        if ($userId <= 0 || $fileId <= 0) return null;
        $s = $this->db->prepare('SELECT id_,user_id_,Nombre,Ruta,Encriptado,Found,AccessType,Metadatos FROM FileS3 WHERE id_=? AND user_id_=? AND Found=1 LIMIT 1');
        if (!$s) return null;
        $s->bind_param('ii', $fileId, $userId);
        $s->execute(); $row = $s->get_result()->fetch_assoc(); $s->close();
        // Secure files require Drive's unlock flow; never bypass it with an index.
        if(!$row || ($row['AccessType'] ?? '')==='secure') return null;
        $meta=json_decode((string)$row['Metadatos'],true)?:[];
        if (!(new FederationImportedAccess($this->db))->allows($row)) return null;
        if(isset($meta['drive_source']) && !$this->original($row)) return null;
        return $row;
    }

    /** A working copy never bypasses live access to its original. */
    public function original(array $file): ?array
    {
        $meta=json_decode((string)($file['Metadatos']??''),true)?:[];$ref=$meta['drive_source']??null;
        if(!is_array($ref) || ($ref['format']??'')!=='michat.drive-working-copy' || ($ref['version']??0)!==1
            || (int)($ref['owner_id']??0)!==(int)$file['user_id_'] || (int)($ref['file_id']??0)===(int)$file['id_']) return null;
        $id=(int)($ref['file_id']??0);$owner=(int)$file['user_id_'];
        $s=$this->db->prepare("SELECT id_,user_id_,Nombre,Ruta,Encriptado,Found,AccessType,Metadatos FROM FileS3 WHERE id_=? AND user_id_=? AND Found=1 AND AccessType<>'secure' LIMIT 1");
        $s->bind_param('ii',$id,$owner);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
        if(!$row || idx_build_s3_key($row)!==($ref['key']??'') || !empty((json_decode((string)$row['Metadatos'],true)?:[])['drive_source'])
            || !(new FederationImportedAccess($this->db))->allows($row)) return null;
        return $row;
    }

    public static function linked(array $session, int $fileId): bool
    {
        $meta = json_decode((string)($session['meta'] ?? ''), true) ?: [];
        return in_array($fileId, array_map('intval', (array)($meta['knowledge_file_ids'] ?? [])), true);
    }

    /** Published sources only; project ownership is checked independently of the session FK. */
    public function projectFiles(int $userId, array $session): array
    {
        $project = (int)($session['project_id_'] ?? 0);
        if ($project < 1) return [];
        $s = $this->db->prepare("SELECT f.*, ps.id_ AS project_source_id, ps.s3_key AS published_key
            FROM ProjectSources ps JOIN Projects p ON p.id_=ps.project_id_
            JOIN FileS3 f ON f.id_=ps.files3_id_ AND f.user_id_=p.user_id_
            WHERE p.id_=? AND p.user_id_=? AND p.status<>'deleted'
              AND f.Found=1 AND f.AccessType<>'secure' ORDER BY ps.id_ LIMIT 101");
        $s->bind_param('ii', $project, $userId);
        $s->execute(); $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
        $out=[];
        foreach ($rows as $row) {
            if (idx_build_s3_key($row)!==$row['published_key'] || !$this->file($userId,(int)$row['id_'])) continue;
            $row['knowledge_origin']='project'; $row['knowledge_project_id']=$project;
            $out[(int)$row['id_']]=$row;
        }
        return $out;
    }

    /** One metadata-only catalog for planning, UI and preparation. No S3 or model calls. */
    public function conversationFiles(int $userId, int $sessionId): array
    {
        $session=$this->session($userId,$sessionId);
        if (!$session) return [];
        $meta=json_decode((string)$session['meta'],true)?:[];
        $ids=array_slice(array_values(array_filter(array_map('intval',(array)($meta['knowledge_file_ids']??[])),static fn($id)=>$id>0)),0,100);
        $extra=$ids?' OR id_ IN ('.implode(',',$ids).')':'';
        $pattern='Data/Chat/Uploads/'.$userId.'/%/'.$sessionId.'/';
        $s=$this->db->prepare("SELECT * FROM FileS3 WHERE user_id_=? AND Found=1 AND AccessType<>'secure' AND (Ruta LIKE ?$extra) ORDER BY id_ LIMIT 101");
        $s->bind_param('is',$userId,$pattern);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        $out=$this->projectFiles($userId,$session);
        foreach($rows as $row) {
            $id=(int)$row['id_'];
            if ((!idx_file_belongs_to_chat_session($row,$userId,$sessionId)&&!self::linked($session,$id)) || !$this->file($userId,$id)) continue;
            if (!isset($out[$id])) { $row['knowledge_origin']='session'; $out[$id]=$row; }
        }
        return array_values($out);
    }

    /** Fail closed if an indexed S3 object changed or cannot be verified. */
    public static function currentObject(array $meta, string $key): bool
    {
        $etag = (string)($meta['source_etag'] ?? '');
        if ($etag === '') return false; // Legacy evidence must be re-extracted against a verified revision.
        try {
            $head = Config::getS3()->headObject(['Bucket'=>Config::getBucket(),'Key'=>$key]);
            return hash_equals($etag,(string)($head['ETag'] ?? ''));
        } catch (Throwable $e) { return false; }
    }

    /** Verify both objects: unchanged copy bytes do not certify the current original. */
    public function currentFileObject(array $file, array $meta, string $key): bool
    {
        if (idx_build_s3_key($file) !== $key || !self::currentObject($meta, $key)) return false;
        $data = json_decode((string)($file['Metadatos'] ?? ''), true) ?: [];
        if (!isset($data['drive_source'])) return true;
        $original = $this->original($file);
        $ref = $data['drive_source'];
        return $original !== null
            && hash_equals((string)($ref['copy_etag'] ?? ''), (string)($meta['source_etag'] ?? ''))
            && self::currentObject(['source_etag' => (string)($ref['etag'] ?? '')], idx_build_s3_key($original));
    }

    public function requireFile(int $userId, int $sessionId, int $fileId): array
    {
        $session = $this->session($userId, $sessionId);
        $file = $session ? $this->file($userId, $fileId) : null;
        if (!$file || (!idx_file_belongs_to_chat_session($file, $userId, $sessionId) && !self::linked($session, $fileId)
            && !isset($this->projectFiles($userId,$session)[$fileId]))) {
            throw new RuntimeException('El archivo no pertenece a esta sesión o su acceso fue revocado');
        }
        return $file;
    }
}
