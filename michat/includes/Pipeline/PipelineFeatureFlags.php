<?php

declare(strict_types=1);

/**
 * Fase 5: switches persistentes del pipeline por usuario.
 *
 * La ausencia de una fila significa ON. Esto conserva exactamente el
 * comportamiento previo a Fase 5 y permite instalar la tabla sin poblarla.
 */
final class PipelineFeatureFlags
{
    /** @var array<string,bool> */
    private const DEFAULTS = [
        'prompt_compiler' => true,
        'memory_router' => true,
        'procedural_memory_read' => true,
        'project_memory_read' => true,
        'session_memory_read' => true,
        'question_memory_read' => true,
        'project_rag' => true,
        'attachment_rag' => true,
        'context_ranking' => true,
        'memory_backfill' => true,
        'project_tools' => true,
        'memory_writer' => true,
        'task_orchestrator' => false,
        'task_auto_execute' => false,
        'task_async_execute' => false,
        'task_planner' => false,
    ];

    /**
     * Las Tasks son una superficie distinta del chat conversacional.
     * bedrock_chat2.php consume all() para construir su pipeline; por eso
     * las flags task_* se excluyen de esa vista sin alterar enabled(), que
     * sigue exponiendo la configuración real a task_api.php / Task Center.
     *
     * La única excepción en el chat es la acción reservada
     * execute_approved_task, usada para reanudar una Task de chat que ya
     * existía y fue aprobada. bedrock_chat2.php valida después public_id,
     * ownership, sesión y estado antes de ejecutar esa reanudación.
     *
     * @var list<string>
     */
    private const TASK_SURFACE_KEYS = [
        'task_orchestrator',
        'task_auto_execute',
        'task_async_execute',
        'task_planner',
    ];

    private mysqli $db;
    private int $userId;

    /** @var array<string,bool> */
    private array $flags;

    private bool $storageAvailable = true;
    private ?string $storageError = null;

    public function __construct(mysqli $db, int $userId)
    {
        $this->db = $db;
        $this->userId = $userId;
        $this->flags = self::DEFAULTS;
        $this->load();
    }

    /** @return array<string,bool> */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::DEFAULTS);
    }

    public static function isKnown(string $featureKey): bool
    {
        return array_key_exists($featureKey, self::DEFAULTS);
    }

    public function enabled(string $featureKey): bool
    {
        return self::isKnown($featureKey)
            ? (bool)($this->flags[$featureKey] ?? self::DEFAULTS[$featureKey])
            : false;
    }

    /**
     * Snapshot para el pipeline conversacional.
     *
     * Por defecto NO activa la superficie de Tasks. Esto evita que una
     * pregunta normal enviada a bedrock_chat2.php se materialice como Task.
     * Quien necesite inspeccionar todas las flags persistidas puede pasar
     * true; task_api.php usa enabled() y conserva las Tasks activas.
     *
     * La acción HTTP reservada execute_approved_task conserva la superficie
     * Task únicamente para reanudar una Task ya persistida y aprobada.
     *
     * @return array<string,bool>
     */
    public function all(bool $includeTaskSurface = false): array
    {
        $flags = $this->flags;
        if ($includeTaskSurface) return $flags;

        $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
        if ($action === 'execute_approved_task') return $flags;

        foreach (self::TASK_SURFACE_KEYS as $key) {
            $flags[$key] = false;
        }
        return $flags;
    }

    /** @return array<string,mixed> */
    public function diagnostic(): array
    {
        return [
            'version' => 5,
            'storage_available' => $this->storageAvailable,
            'storage_error' => $this->storageError,
            'configured' => $this->flags,
            'chat_effective' => $this->all(false),
        ];
    }

    private function load(): void
    {
        if ($this->userId <= 0) return;

        try {
            $stmt = $this->db->prepare(
                "SELECT feature_key, is_enabled
                 FROM UserPipelineFeatures
                 WHERE user_id_ = ?"
            );

            if (!$stmt) {
                $this->storageAvailable = false;
                $this->storageError = $this->db->error ?: 'UserPipelineFeatures no disponible';
                return;
            }

            $stmt->bind_param('i', $this->userId);
            if (!$stmt->execute()) {
                $this->storageAvailable = false;
                $this->storageError = $stmt->error ?: 'No se pudo leer UserPipelineFeatures';
                $stmt->close();
                return;
            }

            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $key = (string)($row['feature_key'] ?? '');
                if (!self::isKnown($key)) continue;
                $this->flags[$key] = (int)($row['is_enabled'] ?? 1) === 1;
            }
            $stmt->close();
        } catch (Throwable $e) {
            // Compatibilidad de instalación: si todavía no existe la tabla,
            // se conservan los defaults y el chat permanece operativo.
            $this->storageAvailable = false;
            $this->storageError = $e->getMessage();
        }
    }
}
