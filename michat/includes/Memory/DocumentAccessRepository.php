<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/session_file_extractor.php';

/** Live FileS3 checks. A cached chunk never grants access to its source. */
final class DocumentAccessRepository
{
    public function __construct(private mysqli $db) {}

    public function session(int $userId, int $sessionId): ?array
    {
        if ($userId <= 0 || $sessionId <= 0) return null;
        $s = $this->db->prepare('SELECT id_,meta,status FROM ChatSessions WHERE id_=? AND user_id_=? LIMIT 1');
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
        if(!$row || idx_build_s3_key($row)!==($ref['key']??'') || !empty((json_decode((string)$row['Metadatos'],true)?:[])['drive_source'])) return null;
        return $row;
    }

    public static function linked(array $session, int $fileId): bool
    {
        $meta = json_decode((string)($session['meta'] ?? ''), true) ?: [];
        return in_array($fileId, array_map('intval', (array)($meta['knowledge_file_ids'] ?? [])), true);
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
        if (!$file || (!idx_file_belongs_to_chat_session($file, $userId, $sessionId) && !self::linked($session, $fileId))) {
            throw new RuntimeException('El archivo no pertenece a esta sesión o su acceso fue revocado');
        }
        return $file;
    }
}
