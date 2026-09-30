<?php
declare(strict_types=1);

/**
 * TaskWorkerServiceManager
 *
 * Único camino permitido desde PHP al ciclo de vida del servicio systemd.
 * SIEMPRE ejecuta el helper binario whitelisted en sudoers:
 *   sudo /usr/local/sbin/michat-worker-control <start|stop|status> michat-task-worker.service
 *
 * Seguridad:
 *   - $action se valida contra una lista cerrada {start,stop,status};
 *   - el servicio es la constante TaskWorkerRuntimeConfig::SERVICE_NAME;
 *   - cada argumento pasa por escapeshellarg();
 *   - no se usa shell_exec sobre datos de usuario: el argv es 100% fijo.
 */
final class TaskWorkerServiceManager
{
    private const ALLOWED_ACTIONS = ['start', 'stop', 'status'];
    private const TIMEOUT_SECONDS = 25;

    /**
     * @return array{ok:bool,active:?bool,output:string,exit_code:int}
     */
    public static function executeCommand(string $action): array
    {
        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            return ['ok' => false, 'active' => null, 'output' => 'action_invalid', 'exit_code' => 2];
        }

        $config = TaskWorkerRuntimeConfig::fromEnvironment();
        $helper = $config->helperPath;
        $service = TaskWorkerRuntimeConfig::SERVICE_NAME;

        // Rutas absolutas fijas: nada proviene del request HTTP.
        $cmd = '/usr/bin/sudo ' . escapeshellarg($helper) . ' '
             . escapeshellarg($action) . ' ' . escapeshellarg($service);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($cmd, $descriptors, $pipes, '/var/www/michat', null);
        if (!is_resource($process)) {
            error_log('TaskWorkerServiceManager: proc_open falló para action=' . $action);
            return ['ok' => false, 'active' => null, 'output' => 'spawn_failed', 'exit_code' => -1];
        }

        fclose($pipes[0]);
        stream_set_timeout($pipes[1], self::TIMEOUT_SECONDS);
        stream_set_timeout($pipes[2], self::TIMEOUT_SECONDS);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        $exitCode = -1;
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) { $exitCode = (int) $status['exitcode']; break; }
            if (microtime(true) > $deadline) { proc_terminate($process, SIGKILL); break; }
            usleep(50000);
        }
        proc_close($process);

        $output = trim($stdout !== '' ? $stdout : $stderr);
        $normalized = strtolower($output);
        return [
            'ok' => in_array($normalized, ['active', 'inactive'], true),
            'active' => $normalized === 'active' ? true : ($normalized === 'inactive' ? false : null),
            'output' => mb_substr($output, 0, 400),
            'exit_code' => $exitCode,
        ];
    }

    /**
     * Arranque bajo demanda idempotente: si ya está activo no hace nada.
     * Se llama tras crear/actualizar tareas (task_api.php). Nunca lanza excepciones:
     * un fallo de permisos/sudo no debe romper la API de tareas; queda en error_log.
     */
    public static function ensureRunning(): bool
    {
        try {
            $status = self::executeCommand('status');
            if ($status['active'] === true) return true;
            $start = self::executeCommand('start');
            if ($start['active'] !== true) {
                error_log('TaskWorkerServiceManager: no se pudo activar el worker (' . $start['output'] . ')');
            }
            return $start['active'] === true;
        } catch (Throwable $e) {
            error_log('TaskWorkerServiceManager::ensureRunning fallo: ' . $e->getMessage());
            return false;
        }
    }
}
