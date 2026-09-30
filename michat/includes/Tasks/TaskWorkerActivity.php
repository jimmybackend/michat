<?php
declare(strict_types=1);

/**
 * TaskWorkerActivity
 *
 * Archivo JSON atómico de actividad del worker (escritura en .tmp + rename).
 * Sirve como "prueba de vida" para:
 *   - el panel de administración (PID, RAM aproximada, última actividad);
 *   - el autoapagado cooperativo por inactividad (getIdleSeconds());
 *   - evitar que el oneshot del timer duplique trabajo si el --loop ya vive.
 */
final class TaskWorkerActivity
{
    public function __construct(private string $file) {}

    /**
     * Registra la actividad actual del worker de forma atómica.
     * @param array<string,mixed> $extra datos opcionales (worker_id, worked, ...)
     */
    public function record(array $extra = []): void
    {
        $payload = [
            'pid' => getmypid(),
            'timestamp' => time(),
            'iso' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'memory_mb' => $this->currentMemoryMb(),
            'worked' => !empty($extra['worked']),
        ];
        foreach ($extra as $k => $v) {
            if (is_string($k) && $k !== 'pid' && $k !== 'timestamp') {
                $payload[$k] = is_scalar($v) ? $v : null;
            }
        }
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) return;

        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            // RuntimeDirectory de systemd normalmente ya existe; si no, intento crearla.
            @mkdir($dir, 0755, true);
        }
        $tmp = $this->file . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            @rename($tmp, $this->file); // operación atómica sobre el mismo filesystem
        } else {
            @unlink($tmp);
        }
    }

    /**
     * Lee el estado y verifica si el PID registrado sigue vivo.
     * @return array{active:bool,pid:?int,timestamp:?int,memory_mb:?float,worked:?bool,file:string}
     */
    public function getStatus(): array
    {
        $data = $this->readRaw();
        $pid = isset($data['pid']) ? (int) $data['pid'] : 0;
        $alive = $pid > 0 && $this->pidAlive($pid);
        return [
            'active' => $alive,
            'pid' => $alive ? $pid : null,
            'timestamp' => isset($data['timestamp']) ? (int) $data['timestamp'] : null,
            'memory_mb' => isset($data['memory_mb']) ? (float) $data['memory_mb'] : null,
            'worked' => isset($data['worked']) ? (bool) $data['worked'] : null,
            'file' => $this->file,
        ];
    }

    /** Segundos desde el último registro de actividad; PHP_INT_MAX si nunca hubo. */
    public function getIdleSeconds(): int
    {
        $data = $this->readRaw();
        $ts = isset($data['timestamp']) ? (int) $data['timestamp'] : 0;
        if ($ts <= 0) return PHP_INT_MAX;
        $idle = time() - $ts;
        return $idle < 0 ? 0 : $idle;
    }

    /** @return array<string,mixed> */
    private function readRaw(): array
    {
        if (!is_file($this->file) || !is_readable($this->file)) return [];
        $raw = @file_get_contents($this->file);
        if ($raw === false || $raw === '') return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function pidAlive(int $pid): bool
    {
        // posix_kill con señal 0 = sondeo de existencia sin matar nada.
        // Si posix no está disponible, fallback a /proc (Amazon Linux 2023).
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        return is_dir('/proc/' . $pid);
    }

    private function currentMemoryMb(): float
    {
        $bytes = memory_get_usage(true);
        if (function_exists('posix_getpid') && is_readable('/proc/self/status')) {
            $status = @file_get_contents('/proc/self/status');
            if ($status !== false && preg_match('/VmRSS:\s+(\d+)\s+kB/', $status, $m)) {
                // RSS real del proceso (más fiel que memory_get_usage en el servidor).
                return round(((int) $m[1]) / 1024, 1);
            }
        }
        return round($bytes / 1048576, 1);
    }
}
