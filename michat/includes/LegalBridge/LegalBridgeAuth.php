<?php
declare(strict_types=1);

/**
 * Legal-to-MiChat HMAC request verification. No browser cookies or supplied
 * MiChat user IDs are trusted. Persistence is required for replay prevention.
 */
final class LegalBridgeAuth
{
    public function __construct(private mysqli $db) {}

    public static function canonical(string $method, string $path, string $body, string $timestamp, string $nonce, string $keyId): string
    {
        return implode("\n", [strtoupper($method), $path, hash('sha256', $body), $timestamp, $nonce, $keyId]);
    }

    /** @return array{user_id:int,credential_id:string} */
    public function verify(string $method, string $path, string $body, array $headers, bool $https): array
    {
        if (getenv('LEGAL_BRIDGE_ENABLED') !== 'true') {
            throw new LegalBridgeError('bridge_disabled', 503);
        }
        if (!$https) throw new LegalBridgeError('https_required', 403);
        if ($method !== 'POST' || $path !== '/api/legal-bridge.php') {
            throw new LegalBridgeError('route_not_allowed', 404);
        }
        if (strlen($body) > 1048576) throw new LegalBridgeError('body_too_large', 413);
        $keyId = (string)($headers['x-legal-key-id'] ?? '');
        $timestamp = (string)($headers['x-legal-timestamp'] ?? '');
        $nonce = (string)($headers['x-legal-nonce'] ?? '');
        $signature = (string)($headers['x-legal-signature'] ?? '');
        if ($keyId !== 'legal-hostgator' || $keyId !== (string)getenv('LEGAL_BRIDGE_KEY_ID')) {
            throw new LegalBridgeError('invalid_credentials', 401);
        }
        if (!preg_match('/^[0-9]{10}$/D', $timestamp) || abs(time() - (int)$timestamp) > 300) {
            throw new LegalBridgeError('request_expired', 401);
        }
        if (!preg_match('/^[a-f0-9]{32,64}$/D', $nonce) || !preg_match('/^[a-f0-9]{64}$/D', $signature)) {
            throw new LegalBridgeError('invalid_signature', 401);
        }
        $secret = (string)(getenv('LEGAL_BRIDGE_SECRET') ?: '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $secret)) {
            throw new LegalBridgeError('bridge_unavailable', 503);
        }
        $expected = hash_hmac('sha256', self::canonical($method, $path, $body, $timestamp, $nonce, $keyId), $secret);
        if (!hash_equals($expected, $signature)) throw new LegalBridgeError('invalid_signature', 401);

        // Parse only after HMAC verification. Subject is inside the signed body.
        $signedRequest = json_decode($body, true);
        $subject = is_array($signedRequest) ? ($signedRequest['external_subject'] ?? null) : null;
        if (!is_string($subject) || !preg_match('/^[A-Za-z0-9_.:@-]{1,180}$/D', $subject)) {
            throw new LegalBridgeError('invalid_subject', 422);
        }

        // Row lock serializes the per-credential rate check and nonce insertion.
        $this->db->begin_transaction();
        try {
            $q = $this->db->prepare("SELECT c.credential_id FROM LegalBridgeClients c WHERE c.credential_id=? AND c.enabled=1 LIMIT 1 FOR UPDATE");
            $q->bind_param('s', $keyId);
            $q->execute();
            $row = $q->get_result()->fetch_assoc();
            $q->close();
            if (!$row) throw new LegalBridgeError('principal_not_authorized', 403);
            $p = $this->db->prepare("SELECT p.user_id_ FROM LegalBridgePrincipals p JOIN Users u ON u.id=p.user_id_ WHERE p.credential_id=? AND p.external_subject=? AND p.enabled=1 AND u.userstatus='Activo' AND u.chat=1 LIMIT 1");
            $p->bind_param('ss',$keyId,$subject);
            $p->execute(); $principal=$p->get_result()->fetch_assoc();$p->close();
            if (!$principal) throw new LegalBridgeError('principal_not_authorized',403);

            $q = $this->db->prepare('SELECT COUNT(*) AS n FROM LegalBridgeNonces WHERE credential_id=? AND created_at >= (UTC_TIMESTAMP() - INTERVAL 60 SECOND)');
            $q->bind_param('s', $keyId);
            $q->execute();
            $count = (int)($q->get_result()->fetch_assoc()['n'] ?? 0);
            $q->close();
            if ($count >= 60) throw new LegalBridgeError('rate_limited', 429);

            $q = $this->db->prepare('INSERT INTO LegalBridgeNonces (credential_id,nonce,expires_at) VALUES (?,?,UTC_TIMESTAMP() + INTERVAL 10 MINUTE)');
            $q->bind_param('ss', $keyId, $nonce);
            if (!$q->execute()) throw new LegalBridgeError('replayed_nonce', 409);
            $q->close();
            $this->db->commit();
            return ['user_id'=>(int)$principal['user_id_'],'credential_id'=>$keyId,'external_subject'=>$subject];
        } catch (Throwable $e) {
            $this->db->rollback();
            if ($e instanceof mysqli_sql_exception && (int)$e->getCode() === 1062) {
                throw new LegalBridgeError('replayed_nonce', 409);
            }
            if ($e instanceof LegalBridgeError) throw $e;
            throw new LegalBridgeError('bridge_unavailable', 503);
        }
    }
}

final class LegalBridgeError extends RuntimeException
{
    public function __construct(string $code, public readonly int $httpStatus) { parent::__construct($code); }
}
