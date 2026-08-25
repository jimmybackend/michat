# Fase 11G.2 — cierre técnico

## Resultado

**FASE 11 — PRE-MERGE PASS / READY TO MERGE.** No significa merged ni releaseada.

## Arquitectura final

Existe un único `TaskWorker`, `TaskOrchestrator`, pipeline de `TaskPlanningService`/Planner, infraestructura compartida de single-turn/Bedrock y `task_center.php` como superficie operativa. `chat.php` permanece conversacional. No hay Worker, Planner, Orchestrator, runtime o UI paralelos.

## Flujo bounded

Una Task terminal produce como máximo una continuation lógica por Task/cycle. El Worker reserva decision/usage, ejecuta NextWork single-turn y termina en stop, ASK_USER o Proposal; supervised espera HITL y automatic respeta policy/budgets. Proposal autorizada se reconcilia bounded e idempotentemente y crea una child Task por el pipeline normal, no la ejecuta inline.

Un fallo lógico persiste Task/Step/Execution failed y ReplanRequest en una transacción. Mientras Replan está activo, 11D lo excluye. El Worker genera una revision versionada; approval y apply permanecen separados. Apply conserva historia, cancela únicamente futuro reemplazable, agrega membership explícita y reabre la misma Task atómicamente.

## Policy, budgets y HITL

Los modos son disabled/supervised/automatic y los estados active/paused/stopped. Se aplican Tasks, decisions, replans, depth, runtime, input/output tokens, Tool calls y write Tool calls con reservations idempotentes. Cost USD continúa **NOT ENFORCEABLE**.

ASK_USER, Proposal approval, Replan approval y Tool approval son dominios separados. Aprobar un plan o Proposal nunca equivale a aprobar Tools.

## Schema

Se auditaron las migraciones 11B, 11C, 11D, 11E.0, 11E.1 y 11F.2 contra `adbbmis1_Cloud.sql`: tablas, columnas, enums, defaults, índices, FKs y UNIQUE requeridos están representados en el dump consolidado. No existe migración 11G porque hardening/closure no requirió schema nuevo.

## Deuda

**Blockers: ninguno conocido.**

Deuda no bloqueante: MySQL real SKIP sin `TASK_TEST_DB_*`; Browser E2E SKIP sin browser/auth; coste USD no enforceable; posible batching futuro del historial de revisions; ASK_USER multi-round futuro; lease dedicado de Proposal podría mejorar contención aunque la idempotencia durable actual evita duplicación.

La auditoría pre-merge revisó el diff completo desde `708816f`, ejecutó las 74 suites PHP/JS disponibles sin fallos y no encontró archivos accidentales, secretos, binarios, debug temporal, arquitectura paralela ni cambios a `chat.php`.
