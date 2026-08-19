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

    /** @return array<string,bool> */
    public function all(): array
    {
        return $this->flags;
    }

    /** @return array<string,mixed> */
    public function diagnostic(): array
    {
        return [
            'version' => 5,
            'storage_available' => $this->storageAvailable,
            'storage_error' => $this->storageError,
            'configured' => $this->flags,
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
            // todo queda ON y el chat conserva su comportamiento anterior.
            $this->storageAvailable = false;
            $this->storageError = $e->getMessage();
        }
    }
}
