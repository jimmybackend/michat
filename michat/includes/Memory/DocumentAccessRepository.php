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
        return $row ?: null;
    }

    public function file(int $userId, int $fileId): ?array
    {
        if ($userId <= 0 || $fileId <= 0) return null;
        $s = $this->db->prepare('SELECT id_,user_id_,Nombre,Ruta,Encriptado,Found,AccessType,Metadatos FROM FileS3 WHERE id_=? AND user_id_=? AND Found=1 LIMIT 1');
        if (!$s) return null;
        $s->bind_param('ii', $fileId, $userId);
        $s->execute(); $row = $s->get_result()->fetch_assoc(); $s->close();
        // Secure files require Drive's unlock flow; never bypass it with an index.
        return $row && ($row['AccessType'] ?? '') !== 'secure' ? $row : null;
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
        if ($etag === '') return true; // legacy index: exact source revision is unknown
        try {
            $head = Config::getS3()->headObject(['Bucket'=>Config::getBucket(),'Key'=>$key]);
            return hash_equals($etag,(string)($head['ETag'] ?? ''));
        } catch (Throwable $e) { return false; }
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
