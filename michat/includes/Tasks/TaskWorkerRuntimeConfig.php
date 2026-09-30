<?php
declare(strict_types=1);

/**
 * TaskWorkerRuntimeConfig
 *
 * Lee la configuración de entorno del modo "bajo demanda" del worker.
 * Nomenclatura MICHAT_WORKER_* para no chocar con las variables ya existentes
 * del propio worker (TASK_WORKER_ID, TASK_WORKER_LEASE_SECONDS, ...).
 *
 * Nota: app_bootstrap.php carga /etc/michat.env vía EnvironmentLoader antes de
 * incluir este archivo, por lo que getenv() ya ve estas claves en CLI y FPM.
 */
final class TaskWorkerRuntimeConfig
{
    public const DEFAULT_IDLE_TIMEOUT = 300;              // segundos
    public const DEFAULT_ACTIVITY_FILE = '/run/michat-task-worker/activity.json';
    public const DEFAULT_HELPER = '/usr/local/sbin/michat-worker-control';
    public const SERVICE_NAME = 'michat-task-worker.service';

    public function __construct(
        public readonly int $idleTimeoutSeconds,
        public readonly string $activityFile,
        public readonly string $helperPath,
    ) {}

    public static function fromEnvironment(): self
    {
        return new self(
            self::boundedInt('MICHAT_WORKER_IDLE_TIMEOUT', self::DEFAULT_IDLE_TIMEOUT, 30, 86400),
            self::nonEmptyString('MICHAT_WORKER_ACTIVITY_FILE', self::DEFAULT_ACTIVITY_FILE),
            self::nonEmptyString('MICHAT_WORKER_HELPER', self::DEFAULT_HELPER),
        );
    }

    private static function boundedInt(string $key, int $default, int $min, int $max): int
    {
        $raw = getenv($key);
        if ($raw === false || trim((string) $raw) === '') return $default;
        $value = filter_var(trim((string) $raw), FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) {
            throw new InvalidArgumentException($key . ' is outside the safe range');
        }
        return $value;
    }

    private static function nonEmptyString(string $key, string $default): string
    {
        $raw = trim((string) (getenv($key) ?: ''));
        return $raw !== '' ? $raw : $default;
    }
}
