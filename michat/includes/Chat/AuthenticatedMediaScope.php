<?php
declare(strict_types=1);

final class MediaAuthenticationException extends RuntimeException {}
final class MediaIdentityMismatchException extends RuntimeException {}
final class MediaScopeNotFoundException extends RuntimeException {}

final class AuthenticatedMediaScope
{
    public function __construct(private mysqli $db) {}

    public function authenticatedUserId(mixed $requestedUserId = null): int
    {
        $userId=ChatIdentity::resolveUserId($this->db);
        if($userId<=0)throw new MediaAuthenticationException('Sesión de usuario no válida');
        if($requestedUserId!==null&&is_numeric($requestedUserId)&&(int)$requestedUserId!==$userId)throw new MediaIdentityMismatchException('user_id no coincide con la sesión autenticada');
        return$userId;
    }

    /** @return array<string,mixed> */
    public function resolveOwnedSession(int$userId,int$sessionId):array
    {
        $stmt=$this->db->prepare('SELECT id_,user_id_,status FROM ChatSessions WHERE id_=? AND user_id_=? LIMIT 1');
        if(!$stmt)throw new RuntimeException('database_error');$stmt->bind_param('ii',$sessionId,$userId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
        if(!$row)throw new MediaScopeNotFoundException('Sesión no encontrada');return$row;
    }

    /** @return array<string,mixed> */
    public function resolveOwnedMessage(int$userId,int$messageId):array
    {
        $sql='SELECT m.id_,m.session_id_,m.user_id_,m.role,m.content_type,m.s3_key,m.mime_type,m.size_bytes,m.duration_ms,m.model_id,m.meta FROM ChatMessages m INNER JOIN ChatSessions s ON s.id_=m.session_id_ AND s.user_id_=? WHERE m.id_=? AND m.user_id_=? LIMIT 1';
        $stmt=$this->db->prepare($sql);if(!$stmt)throw new RuntimeException('database_error');$stmt->bind_param('iii',$userId,$messageId,$userId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
        if(!$row)throw new MediaScopeNotFoundException('Mensaje no encontrado');return$row;
    }
}
