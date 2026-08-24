# Fase 10 — Scheduling y automatización declarativa

## Estado

Fase 10 está **CERRADA** tras la auditoría final PRE-MERGE. 10A–10C cubren scheduling one-shot y creación manual ejecutable; 10D aporta el contrato persistente de recurrencia; 10E integra su evaluación acotada en el Worker existente; y 10F añade administración owned en API y Task Center. La integración MySQL real continúa pendiente cuando no existe `TASK_TEST_DB_*` y se registra como `SKIP`, no como `PASS`.

## Modelo recurrente mínimo (10D)

`TaskRecurrenceRules` representa una definición administrativa, no una Task. Es owned por `user_id_`, exige una `ChatSession` y admite el Project coherente de esa Session. Sus únicos estados son `enabled`, `paused` y `cancelled`; pause conserva `next_occurrence_at`, resume recalcula una fecha ya vencida y cancel impide reservas nuevas sin cancelar Tasks ya creadas.

La recurrencia inicial es calendario civil `daily` o `weekly`: `local_time`, weekday ISO para weekly y una zona IANA validada con `DateTimeZone`. `next_occurrence_at` se persiste en UTC para selección eficiente. En un salto DST hacia delante, PHP normaliza una hora inexistente hacia delante conservando sus minutos; en el solapamiento de otoño se elige la primera ocurrencia del wall time. No se aceptan cron, RRULE, offsets fijos ni intervalos de duración.

La política de misfire es tipada: `skip`, `run_once` o `catch_up`. 10D solo la persiste; 10E aplica límites explícitos a `catch_up` para evitar una explosión de Tasks.

El blueprint contiene únicamente `task_title`, `task_objective`, Session, Project opcional, prioridad y modo. No guarda Steps, Executions, Artifacts, ToolCalls ni secretos. Cada ocurrencia deberá volver a entrar por `TaskApplicationService::createManualTask()` para obtener una Task y Steps nuevos.

## Identidad y concurrencia

`TaskRecurrenceOccurrences` representa un slot lógico de materialización, no una Execution. `UNIQUE(rule_id_, logical_occurrence_at)` es la autoridad **at-most-one Task per logical occurrence** ante evaluadores concurrentes. La Task futura usará una idempotency key determinista `recur:` más SHA-256 de `public_id` de regla y slot UTC, bajo el UNIQUE owned ya existente en Tasks.

Una ocurrencia pasa por `reserved`, `materialized`, `skipped` o `failed`. `task_id_` enlaza la Task nueva; un fallo antes del enlace queda durable y puede reintentarse con la misma reserva/key. No se promete exactly-once de efectos externos. No se escriben eventos de regla en `TaskEvents`, porque esa tabla exige `task_id_`; timestamps y `lock_version` son la auditoría mínima de 10D.

## Persistencia e índices

10D añade `TaskRecurrenceRules` y `TaskRecurrenceOccurrences` al dump consolidado y al script idempotente `michat/sql/fase10d_task_recurrence.sql`. Índices: reglas por `(status,next_occurrence_at)`, ownership por `(user_id_,status)`, slot UNIQUE, Task UNIQUE y estado de occurrence. No se añade Worker, Queue, Orchestrator, Planner, API ni UI alternativos.

## Frontera dejada por 10D

10D dejó preparado el contrato que consume el evaluador 10E descrito a continuación: reglas `enabled` vencidas, reserva durable, frontera 10C, enlace/fallo y avance versionado, siempre sobre el Worker existente.

## Evaluador temporal recurrente (10E)

10E integra `TaskRecurrenceEvaluator` en el único `TaskWorker::once()` existente, después de recovery y Wait Steps y antes del claim normal. El claim se intenta siempre, incluso si recurrence hizo trabajo o falló, para conservar fairness. Por ciclo se procesan como máximo 10 reglas, 5 slots catch-up por regla y 10 retries por defecto; `TASK_WORKER_RECURRENCE_*` permite ajustar esos límites dentro de rangos server-side seguros.

La selección usa `status='enabled' AND next_occurrence_at<=UTC_TIMESTAMP(6)`, el índice 10D y `LIMIT 1 FOR UPDATE SKIP LOCKED`. Cada regla se procesa una vez por ciclo del proceso. Bajo el lock se reserva el slot antes de crear trabajo y se avanza `next_occurrence_at`; UNIQUE sigue siendo la autoridad concurrente.

Misfires:

- `skip`: avanza directamente al primer slot futuro, sin crear cientos de rows; `occurrences_skipped` cuenta una decisión agregada.
- `run_once`: materializa el slot vencido más antiguo y avanza al primer slot futuro.
- `catch_up`: materializa en orden hasta el límite por regla/ciclo y conserva en `next_occurrence_at` el siguiente slot pendiente.

La materialización ocurre fuera del lock de regla y llama exclusivamente a `TaskApplicationService::createManualTask()`. Envía el blueprint owned, la key determinista de 10D y `scheduled_at=logical_occurrence_at` en ISO UTC; como el slot ya venció, 10A la considera elegible. Automatic queda `ready`; supervised queda `waiting_user`. El evaluador no crea Executions ni ejecuta Steps.

Un fallo se guarda como `task_validation_failed` o `task_materialization_failed`, nunca como stack/SQL/payload. Occurrences `failed` o `reserved` sin Task y antiguas se reclaman en batch actualizando `updated_at`/`lock_version`; tras un crash se reutilizan la misma row y key. Si cancel/pause ganó el lock no se reserva; si la reserva ganó primero, ese slot adquirido se recupera/materializa independientemente. Tasks ya enlazadas nunca se cancelan por cancelar la regla.

El Worker expone métricas internas del ciclo: `rules_checked`, `occurrences_reserved`, `tasks_materialized`, `occurrences_failed`, `occurrences_skipped` y `retries_claimed`. No se añadió tabla, API, UI, Worker, Queue, Orchestrator ni event bus. MySQL real permanece **SKIP** mientras `TASK_TEST_DB_*` no esté disponible.

## Frontera preparada por 10E

10E dejó disponibles reglas y occurrences persistidas para la administración owned de 10F, sin ampliar hacia Automation Rules por eventos ni autonomía. La validación MySQL aislada continúa pendiente por limitación del entorno.

## Administración operativa y hardening (10F)

10F añade al Task API acciones explícitas `recurrence_list`, `recurrence_detail`, `recurrence_create`, `recurrence_pause`, `recurrence_resume` y `recurrence_cancel`. GET y mutaciones quedan detrás del feature flag; POST conserva CSRF. La capa `TaskRecurrenceApplicationService` resuelve UUID owned antes de usar IDs internos y delega todas las mutaciones en `TaskRecurrenceService`. Los DTO son whitelist: omiten IDs internos/user/Worker y el detalle limita a 25 occurrences; cada occurrence solo expone slot, estado, UUID público de Task, failure code conocido y timestamps.

Task Center conserva Lista/Tablero y añade una pestaña responsive de reglas. El formulario acepta exclusivamente daily/weekly, weekday ISO condicional, hora civil `HH:MM`, zona IANA, `skip|run_once|catch_up`, Session owned, Project coherente, prioridad y modo. `local_time` no usa conversión ISO/UTC del scheduling one-shot. Las cards muestran definición, contexto, próxima fecha y estado; el detalle ofrece pause/resume/cancel con `lock_version`, occurrences recientes y navegación a Task por UUID.

Cancelar una regla no cancela Tasks ya creadas. Pause impide reservas nuevas, pero una occurrence ganada previamente puede continuar conforme a 10E. La UI usa loading, `aria-busy`, feedback live, bloqueo de doble submit, recarga autoritativa tras conflictos, `textContent` para datos dinámicos y cards responsive. No se exponen métricas globales: la observabilidad pública se limita a reglas/occurrences persistidas; `TaskWorker::recurrenceMetrics()` continúa interna.

10F no añade DB, frecuencias, cron/RRULE, Worker, Queue, Orchestrator, Events sintéticos ni Automation Rules genéricas. MySQL real sigue **SKIP** sin `TASK_TEST_DB_*`.

## Cierre de Fase 10

**FASE 10 — CERRADA.** La auditoría final PRE-MERGE confirmó 10A–10F y todas las validaciones PHP/JS disponibles sin fallos. MySQL real continúa **SKIP**, no PASS, por ausencia de `TASK_TEST_DB_*`; debe validarse más adelante exclusivamente contra una base aislada. No es un cierre de triggers genéricos ni autonomía: Events-driven automation, condition watchers, reglas booleanas abiertas, IA creando Rules, agent loops y self-healing quedan fuera de Fase 10. Fase 11 permanece planificada como el siguiente bloque.
