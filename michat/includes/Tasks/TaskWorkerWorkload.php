<?php
declare(strict_types=1);

/**
 * TaskWorkerWorkload
 *
 * Decide si hay trabajo real para el worker, REUTILIZANDO estrictamente las
 * tablas y estados existentes (Tasks, TaskSteps, TaskExecutions,
 * TaskDependencies, TaskRecurrenceRules/Occurrences). NO crea tablas ni campos.
 *
 * Patrón de BD del proyecto: mysqli crudo con $db_connection global definido
 * por db-s3.php (ver bin/task_worker.php y TaskQueueRepository). Aquí se usa
 * exactamente ese wrapper, siempre con sentencias literales preparadas
 * (prepare/execute) — sin variables interpoladas en el SQL.
 */
final class TaskWorkerWorkload
{
    /**
     * ¿Hay algo que un ciclo del worker pueda hacer AHORA MISMO?
     * Es la suma acotada de las cuatro señales de trabajo + ejecuciones activas.
     */
    public static function hasPendingWork(mysqli $db): bool
    {
        return self::getPendingCount($db) > 0 || self::hasActiveExecutions($db);
    }

    /**
     * Conteo total de trabajo pendiente (para el panel y para el idle-check).
     */
    public static function getPendingCount(mysqli $db): int
    {
        return self::scalar(
            $db,
            // 1) Pasos "ready" reclamables: mismo predicado que TaskQueueRepository::lockNextAsyncRespond()
            //    (task ready/running, paso ready, scheduled_at vencido, dependencias satisfechas,
            //     sin ejecución viva previa) UNION pasos sync heredados (execution_mode != 'async').
            "SELECT
               (SELECT COUNT(*) FROM Tasks t
                  JOIN TaskSteps s ON s.task_id_ = t.id_
                 WHERE t.status IN ('ready','running')
                   AND s.status = 'ready'
                   AND COALESCE(t.scheduled_at, UTC_TIMESTAMP(6)) <= UTC_TIMESTAMP(6)
                   AND NOT EXISTS (SELECT 1 FROM TaskExecutions e
                                    WHERE e.task_id_ = t.id_
                                      AND e.status IN ('queued','running','waiting'))
                   AND NOT EXISTS (SELECT 1 FROM TaskDependencies d
                                    JOIN Tasks r ON r.id_ = d.depends_on_task_id_
                                   WHERE d.task_id_ = t.id_
                                     AND NOT (CASE d.condition
                                                WHEN 'terminal_any' THEN r.status IN ('completed','failed','cancelled')
                                                ELSE r.status = 'completed' END)))
             + (SELECT COUNT(*) FROM Tasks t
                  JOIN TaskSteps s ON s.task_id_ = t.id_
                 WHERE t.status = 'pending'
                   AND s.status = 'ready'
                   AND COALESCE(t.scheduled_at, UTC_TIMESTAMP(6)) <= UTC_TIMESTAMP(6)
                   AND JSON_UNQUOTE(JSON_EXTRACT(s.input_json, '\$.execution_mode')) <> 'async')
             + (SELECT COUNT(*) FROM TaskExecutions WHERE status = 'queued')
             + (SELECT COUNT(*) FROM TaskExecutions
                 WHERE status = 'running' AND lease_expires_at < NOW(6))
             + (SELECT COUNT(*) FROM TaskSteps s
                  JOIN Tasks t ON t.id_ = s.task_id_
                 WHERE s.step_type = 'wait'
                   AND s.status = 'waiting_dependency'
                   AND t.status = 'waiting_dependency'
                   AND JSON_UNQUOTE(JSON_EXTRACT(s.checkpoint_json, '\$.wait_until'))
                       <= DATE_FORMAT(UTC_TIMESTAMP(6), '%Y-%m-%d %H:%i:%s.%f'))
             + (SELECT COUNT(*) FROM TaskRecurrenceRules
                 WHERE status = 'enabled' AND next_occurrence_at <= UTC_TIMESTAMP(6))
             + (SELECT COUNT(*) FROM TaskRecurrenceOccurrences o
                  JOIN TaskRecurrenceRules r ON r.id_ = o.rule_id_
                 WHERE o.task_id_ IS NULL
                   AND o.status IN ('reserved','failed')
                   AND o.updated_at <= UTC_TIMESTAMP(6) - INTERVAL 300 SECOND)
             AS pending"
        );
    }

    /**
     * Ejecuciones VIVAS realmente (lease vigente). Si hay alguna, el worker NO
     * debe apagarse ni vía idle-timeout ni vía el botón "Apagar worker".
     */
    public static function hasActiveExecutions(mysqli $db): bool
    {
        return self::scalar(
            $db,
            "SELECT COUNT(*) c FROM TaskExecutions
              WHERE status IN ('running','waiting')
                AND (lease_expires_at IS NULL OR lease_expires_at >= NOW(6))"
        ) > 0;
    }

    private static function scalar(mysqli $db, string $sql): int
    {
        // Las consultas son literales fijas; prepare/execute evita cualquier
        // inyección aunque mañana alguien añada parámetros.
        $stmt = $db->prepare($sql);
        if ($stmt === false) throw new RuntimeException('workload_query_failed');
        if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('workload_query_failed'); }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row ? reset($row) : 0);
    }
}
