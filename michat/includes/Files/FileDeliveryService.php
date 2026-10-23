<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/session_file_extractor.php';

final class FileDeliveryException extends RuntimeException {}

/** Read-only delivery: an S3 key is a locator, never an access grant. */
final class FileDeliveryService
{
    public function __construct(private mysqli $db, private object $s3, private string $bucket) {}

    public function resolve(int $userId, string $key, array $session): array
    {
        if ($userId < 1) throw new FileDeliveryException('Sesión inválida.', 401);
        if ($key === '' || str_ends_with($key, '/') || preg_match('/[\x00-\x1f\x7f]/', $key)) {
            throw new FileDeliveryException('Parámetro archivo inválido.', 400);
        }
        // Compare the complete canonical key, including its directory. A basename
        // alone must never grant access to a different object with the same name.
        $base = basename($key);
        $stmt = $this->db->prepare('SELECT id_,user_id_,Nombre,Ruta,Encriptado,Found,AccessType,PasswordHash FROM FileS3 WHERE Encriptado=? OR Encriptado=? OR CONCAT(TRIM(TRAILING "/" FROM Ruta),"/",Encriptado)=?');
        $stmt->bind_param('sss', $key, $base, $key);
        $stmt->execute();
        $result = $stmt->get_result();
        $registered = false; $own = null;
        while ($row = $result->fetch_assoc()) {
            if (idx_build_s3_key($row) !== $key) continue;
            $registered = true;
            if ((int)$row['user_id_'] === $userId && (int)$row['Found'] === 1) $own = $row;
        }
        $stmt->close();
        if ($own !== null) {
            if (strtolower((string)$own['AccessType']) === 'secure' || trim((string)$own['PasswordHash']) !== '') {
                if (!self::unlocked($session, [$key, (string)$own['Encriptado'], (string)$own['id_']])) {
                    throw new FileDeliveryException('Archivo protegido. Desbloquéalo primero en ArcadeCloud.', 423);
                }
            }
            $name = (string)$own['Nombre'];
            $ext = pathinfo($key, PATHINFO_EXTENSION);
            if ($ext !== '' && pathinfo($name, PATHINFO_EXTENSION) === '') $name .= '.' . $ext;
            return ['key'=>$key, 'name'=>$name];
        }
        // Deleted, revoked or other-owner Drive records cannot be resurrected by
        // an old chat message. Generated chat media without FileS3 remains usable.
        if ($registered) throw new FileDeliveryException('Archivo no encontrado o sin acceso.', 404);
        $stmt = $this->db->prepare("SELECT m.id_ FROM ChatMessages m JOIN ChatSessions s ON s.id_=m.session_id_ WHERE s.user_id_=? AND s.status<>'closed' AND (m.s3_key=? OR m.thumb_s3_key=?) LIMIT 1");
        $stmt->bind_param('iss', $userId, $key, $key);
        $stmt->execute(); $found = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$found) throw new FileDeliveryException('Archivo no encontrado o sin acceso.', 404);
        return ['key'=>$key, 'name'=>$base];
    }

    private static function unlocked(array $session, array $keys): bool
    {
        foreach (['secure_ok_files','secure_files_ok','unlocked_files'] as $bucket) {
            foreach ($keys as $key) {
                $v = $session[$bucket][$key] ?? false;
                if (is_numeric($v) ? (int)$v >= time() : (bool)$v) return true;
            }
        }
        return false;
    }

    public static function disposition(string $name, bool $inline): string
    {
        $name = preg_replace('/[\x00-\x1f\x7f\/\\\\]/', '-', $name) ?? '';
        $name = trim($name) ?: 'archivo';
        $fallback = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);
        return ($inline ? 'inline' : 'attachment') . '; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($name);
    }

    /** Null means a full response; invalid/multiple ranges receive 416. */
    public static function range(?string $header, int $size): ?array
    {
        if ($header === null || $header === '') return null;
        if ($size <= 0 || !preg_match('/^bytes=(\d*)-(\d*)$/D', $header, $m) || ($m[1] === '' && $m[2] === '')) {
            throw new FileDeliveryException('Rango no válido.', 416);
        }
        if ($m[1] === '') {
            $length = (int)$m[2];
            if ($length <= 0) throw new FileDeliveryException('Rango no válido.', 416);
            $start = max(0, $size - $length); $end = $size - 1;
        } else {
            $start = (int)$m[1]; $end = $m[2] === '' ? $size - 1 : min((int)$m[2], $size - 1);
            if ($start >= $size || $end < $start) throw new FileDeliveryException('Rango no válido.', 416);
        }
        return [$start, $end];
    }

    public function send(array $file, bool $inline): void
    {
        if ($this->bucket === '' || $this->bucket === 'yours3bucket') {
            throw new FileDeliveryException('Falta configurar AWS_S3_BUCKET en el servidor.', 503);
        }
        if ($inline && strtolower(pathinfo($file['key'], PATHINFO_EXTENSION)) !== 'pdf') {
            throw new FileDeliveryException('Este visor requiere un archivo PDF.', 415);
        }
        $params = ['Bucket'=>$this->bucket, 'Key'=>$file['key']];
        $head = $this->s3->headObject($params);
        $size = (int)$head['ContentLength'];
        $rangeHeader = $_SERVER['HTTP_RANGE'] ?? null;
        // If-Range dates or changed ETags request the full current object.
        if (isset($_SERVER['HTTP_IF_RANGE']) && $_SERVER['HTTP_IF_RANGE'] !== ($head['ETag'] ?? '')) $rangeHeader = null;
        try { $range = self::range($rangeHeader, $size); }
        catch (FileDeliveryException $e) { header('Content-Range: bytes */' . $size); throw $e; }
        if ($range !== null) $params['Range'] = 'bytes=' . $range[0] . '-' . $range[1];
        if (!empty($head['ETag'])) $params['IfMatch'] = $head['ETag'];
        $result = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD' ? null : $this->s3->getObject($params);
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code($range === null ? 200 : 206);
        $mime = (string)($head['ContentType'] ?? 'application/octet-stream');
        if (!preg_match('~^[a-zA-Z0-9.+-]+/[a-zA-Z0-9.+-]+$~D', $mime)) $mime = 'application/octet-stream';
        header('Content-Type: ' . ($inline ? 'application/pdf' : $mime));
        header('Content-Disposition: ' . self::disposition($file['name'], $inline));
        header('X-Filename: ' . rawurlencode($file['name']));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header('Pragma: no-cache');
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . ($range === null ? $size : $range[1] - $range[0] + 1));
        if ($range !== null) header('Content-Range: bytes ' . $range[0] . '-' . $range[1] . '/' . $size);
        if ($result === null) return;
        $body = $result['Body'];
        if (is_string($body)) { echo $body; return; }
        try { while (!$body->eof()) { echo $body->read(65536); } }
        finally { $body->close(); }
    }
}
