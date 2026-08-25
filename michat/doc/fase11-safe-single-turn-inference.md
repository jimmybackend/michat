# Fase 11A.0 — Safe single-turn inference boundary

## Estado y alcance

11A.0 extrae la frontera de inferencia necesaria para una evaluación futura de next-work. No implementa `NextWorkDecision`, snapshot, evaluator, Task spawning, replanning ni un autonomous loop. Fase 11A y Fase 11 continúan abiertas.

## Arquitectura

`BedrockConverseClient` es la única primitiva compartida del directorio Chat que invoca una vez al SDK Bedrock y normaliza texto, mensaje de salida, Tool Uses, stop reason y usage. No conoce DB, configuración de agentes, Tasks, Tools, memoria ni persistencia.

`BedrockChatRuntime` consume esa primitiva y conserva las responsabilidades conversacionales existentes: configuración de agente/modelo, loop acotado, Tools, gates, observer, cancellation, heartbeat y `TaskExecutionBudget`. `BedrockSingleTurnInference` consume la misma primitiva con otra semántica: una única request textual, sin `toolConfig`, Tools, loop o retry. Rechaza cualquier bloque `toolUse` y también `stopReason=tool_use`.

La composición normal reutiliza el cliente creado por `Config::getBedrockRuntime(['http'=>['connect_timeout'=>20,'timeout'=>240]])` que ya empleaba Context Builder. Antes de 11A.0, la factory creaba ese cliente, pero `BedrockChatRuntime` obtenía internamente otro cliente por `Config::getBedrockRuntime()` sin esos argumentos. La extracción elimina esa inconsistencia en la composición de producción sin introducir un timeout paralelo.

## Límites y efectos

La request single-turn valida modelo, prompt no vacío, system instruction, temperatura, top-p y máximo de output. Los límites son 32.000 caracteres de prompt, 16.000 de system instruction y 8.192 tokens de output. La frontera devuelve resultado y usage únicamente como datos: no escribe ChatMessages, TokenUsage, ChatActivityEvents, Memory, ProjectContext, Tasks, Steps, Executions, Events, Artifacts, ToolCalls ni FileVersions.

11A.0 no selecciona `UserAIAgentConfigs` ni añade un `agent_key`; el consumidor futuro deberá resolver la configuración existente antes de construir `SingleTurnInferenceRequest`.

## 11A.1 — Next-work dry-run

11A.1 añade un motor interno, no conectado a HTTP, Worker ni finalización automática. `NextWorkSnapshotBuilder` obtiene mediante un repository read-only un Project owned, una Task terminal (`completed`, `failed` o `cancelled`) y colecciones limitadas de ProjectContext, Steps, Executions, Events, Artifacts y Tasks relacionadas. El DTO omite IDs internos, recorta cada texto y limita el JSON total.

`NextWorkAgentConfigResolver` carga la configuración efectiva mediante `loadDynamicAIAgentConfigs()`: usa `next_work_evaluator` cuando existe y, mientras no haya registro específico, cae explícitamente a `chat_main`. Modelo, instrucciones, plantilla y parámetros siempre proceden de `UserAIAgentConfigs`; los ceilings de 11A.1 solo pueden reducirlos. No se añadió seed, SQL ni migración de agente.

`NextWorkEvaluator` separa una system policy fija de los datos del snapshot marcados como `UNTRUSTED PROJECT DATA`, realiza como máximo una llamada por `SingleTurnInferenceInterface` y valida el JSON en PHP. Solo admite `stop`, `ask_user` y `propose_task`; esta última es una recomendación transitoria. Errores de configuración, inferencia o validación producen `ask_user`; scope inválido y Task no terminal se rechazan. Una Task cancelada nunca devuelve `propose_task` sin convertirla primero en consulta explícita al usuario.

No existe persistencia de decisiones, creación de Tasks, continuidad post-Task, Tools, retry de IA, UI NextWork ni autonomous loop. `chat.php` continúa como superficie conversacional y `task_center.php` como superficie operativa oficial; 11A.1 no modifica ninguna de las dos. Fase 11 continúa abierta. La deuda previa de `TaskApplicationServiceFactory`, que puede construir `TaskPlanningService` con Planner `null`, permanece fuera de alcance.

## 11B — Policy y budget durable

11B separa el modo de una Task de la policy de autonomía del Project. `ProjectAutonomyPolicies` conserva una fila owned por Project con modo `disabled|supervised|automatic`, estado `active|paused|stopped`, stop reason, límites opcionales y optimistic `lock_version`. La ausencia de policy se materializa de forma segura como `disabled`; los valores `NULL` usan defaults server-side y cualquier valor persistido queda limitado por ceilings absolutos.

`ProjectAutonomyCycles` aporta el envelope durable compartido por trabajo futuro. Un índice UNIQUE sobre `active_project_id_`, generado únicamente para ciclos activos, impide dos ciclos activos del mismo Project. Sus contadores acumulan decisiones, Tasks, replans, tokens, Tool calls, write Tool calls y segundos. `ProjectAutonomyReservations` registra reservas con UUID público y `UNIQUE(cycle_id_,idempotency_key)`; la reserva bloquea policy y ciclo con `FOR UPDATE`, comprueba todos los límites y actualiza contadores en una transacción. `consume` confirma la reserva y `release` la devuelve una sola vez.

`NextWorkAuthorizationService` no ejecuta ni persiste `NextWorkDecision`: `stop` y `ask_user` se deniegan sin reserva; `propose_task` queda denegada en disabled/paused/stopped, requiere aprobación en supervised o si la Task origen fue cancelada, y solo en automatic puede devolver `allowed` después de reservar budget. Esta autorización no sustituye approvals de Tools, allowlists, ownership, cancellation ni `TaskExecutionBudget`.

El coste USD no se incluye en 11B: `TokenUsage.estimated_cost_usd` y los calculadores actuales son estimaciones con tablas/fallbacks que pueden variar, por lo que **cost budget = NOT YET ENFORCEABLE**. No se simula enforcement económico.

11B todavía no crea Tasks, no enlaza Tasks con ciclos, no activa hooks post-Task, no añade endpoint o UI y no implementa un autonomous loop. `task_center.php` seguirá siendo la futura superficie operativa, sin cambios en esta subfase; Fase 11 continúa abierta.

## 11C — Proposal durable y Task spawning controlado

11C añade `NextWorkProposals` como provenance durable entre una Task terminal, su ciclo, una decisión `propose_task`, una reserva y como máximo una Task creada. La tabla conserva únicamente razón pública, título/objetivo y evidence bounded ya validados; no persiste snapshot, prompts ni razonamiento privado. Su state machine separa `pending_approval`, `authorized`, `spawning`, `spawned`, `rejected` y `failed`, con locking optimista y restricciones UNIQUE para dedupe, reserva y Task resultante.

`NextWorkProposalService` es una application service explícita, no un segundo Orchestrator. Hereda user/Project/Session/source/cycle server-side, revalida policy y budget, exige aprobación propia en modo supervised y usa reservas 11B. En automatic puede materializar inmediatamente; en supervised la aprobación solo autoriza crear una Task con modo supervised y no aprueba sus Steps o Tools.

La creación reutiliza `TaskApplicationService::createAutonomyTask`, el mismo `TaskOrchestrator`, Planner/fallback, repositories y activación existentes. Usa `origin_type=system`, `parent_task_id_` para lineage adicional y `autonomy:<proposal UUID>` como idempotency key; la Proposal sigue siendo la autoridad de provenance. Priority queda `normal`, `scheduled_at` queda `NULL` y ningún campo de scope procede del modelo.

Proposal, reserva y Task no forman una transacción distribuida. Las ventanas de crash se resuelven con estados durables, claves deterministas y reconciliación: un retry recupera la Task ya creada, consume la misma reserva de forma idempotente y finaliza la Proposal sin crear una segunda Task. Un fallo anterior a Task libera la reserva.

11C no conecta Task terminal con el evaluator, no cambia Worker, no crea Tools/endpoints/UI, no implementa replanning ni autonomous loop. `task_center.php` sigue siendo la futura superficie operativa y `chat.php` permanece separado. Fase 11 continúa abierta.

## 11D — Continuidad post-Task acotada y recuperable

La frontera terminal real sigue siendo `TaskQueueRepository::finish()` para ejecuciones Worker/HTTP, junto con cancellation, Wait reactivation y recovery que también persisten estados terminales. En vez de acoplar IA a cada transición, el único `TaskWorker` ejecuta un descubrimiento durable: busca Tasks terminales pertenecientes explícitamente a un ciclo y materializa una sola `PostTaskContinuation` mediante `UNIQUE(autonomy_cycle_id_,source_task_id_)`. Esto cubre crashes posteriores al UPDATE terminal y evita hooks duplicados.

`ProjectAutonomyCycleTasks` asocia explícitamente la root Task con un ciclo a depth 0. Las Tasks derivadas resuelven ciclo mediante `NextWorkProposal` y depth mediante la continuation de su source; no se añadió columna a Tasks. Las continuations tienen UUID, state machine `pending|processing|completed|waiting_user|waiting_approval|failed`, intentos, backoff, claim `FOR UPDATE SKIP LOCKED`, worker/lease y payload validado de decisión/usage, nunca prompt o razonamiento privado.

Por tick se procesan como máximo 3 continuations por defecto (ceiling 20), después de recovery, waits y recurrencia, y antes del claim normal; el claim normal siempre se intenta para conservar fairness. No existe loop autónomo ni espera recursiva de la child Task. Un lease vencido vuelve a ser reclamable y los errores reintentan como máximo tres veces.

Antes de inferencia se revalidan policy, ciclo y depth, y se reserva una decision unit idempotente. `NextWorkEvaluator::evaluateWithUsage()` conserva `evaluate()` y devuelve además usage transitorio; input/output tokens se cargan mediante una reserva separada del mismo budget, sin escribir TokenUsage conversacional. `stop` finaliza y cierra el ciclo; `ask_user` queda durable en `waiting_user`; `propose_task` delega íntegramente en 11C, quedando `waiting_approval` en supervised o enlazando la child Task en automatic. Cancelled sigue convirtiendo cualquier propuesta automática en pregunta al usuario.

No se modificaron Task Center, chat, Tools, planes ni replanning. No hay segundo Worker/Queue/Orchestrator, y Fase 11 continúa abierta.

## 11E.0 — Typed failure disposition y checkpoint durable de replanning

11E.0 resuelve el bloqueo arquitectónico previo sin implementar todavía un replan. `TaskFailureDisposition` separa `technical_failure` de `logical_replan_candidate` y limita este último a triggers server-side explícitos. `ValidationTaskStepExecutor` emite `validation_failed` tipado; cualquier Throwable no clasificado conserva el flujo técnico existente.

Para una Task perteneciente explícitamente a un ciclo activo, `TaskQueueRepository::finish()` persiste en una sola transacción la Execution failed, el Step failed histórico, la Task failed, Events y una `TaskReplanRequest` idempotente. La request queda `checkpointed`, con source Task/Step/cycle, trigger, lock version y razón pública; no contiene plan, prompt, snapshot ni reasoning. Un servicio read-only comprueba que Task y Step siguen failed, el ciclo está activo y no existe Execution/Step running, y únicamente permite cerrar el checkpoint como rejected/failed en esta microfase.

11D excluye Tasks con replan activo (`checkpointed|processing|proposed|pending_approval|approved`), de modo que la visibilidad atómica del request tiene precedencia sobre NextWork. Al rechazar o fallar terminalmente el checkpoint, el discovery de 11D puede volver a considerar la Task. No se añadió estado a Tasks/TaskSteps, no se reabre la Task, no se modifica ningún Step futuro y no se invoca Planner/modelo.

El wiring productivo nullable de `TaskApplicationServiceFactory` permanece como deuda: 11E.0 no llama Planner, por lo que corregirlo aquí habría sido una limpieza fuera de alcance. Deberá resolverse en 11E.1 reutilizando `task_planner` desde `UserAIAgentConfigs`, sin segundo Planner. Fase 11 y el replanning funcional siguen abiertos.

## 11E.1 — versioned remaining-plan replanning (PASS)

El checkpoint tipado de 11E.0 ahora es reclamado con lease por el mismo `TaskWorker`, antes de las continuations y con batch server-side acotado. Cada request reserva una única unidad durable de `replans`; un retry usa la misma clave y el uso real de input/output tokens del Planner se carga al mismo ciclo. No existe coste USD simulado.

`TaskPlanningService::planRemaining()` reutiliza `TaskPlanner`/`AiTaskPlanner`; `TaskPlannerFactory` resuelve `task_planner` desde `UserAIAgentConfigs` con precedencia por usuario y usa `BedrockSingleTurnInference`. La planificación inicial conserva feature flag y fallback. El snapshot remaining-plan es bounded y se entrega como `UNTRUSTED PROJECT DATA` subordinado a una policy que mantiene inmutable el objective y prohíbe Tools, Tasks, approvals y cambios de scope.

`TaskPlanRevisions` conserva una revisión base histórica y revisiones posteriores con numeración server-side. `TaskPlanRevisionSteps` registra membresía explícita. El apply bloquea request y Task, revalida policy/cycle/lock/checkpoint, rechaza Executions o Steps incompatibles, cancela solamente Steps `pending|ready`, agrega keys/positions nuevas sin renumerar historia, mantiene el Step failed y los Steps terminales intactos, y realiza `failed → ready` sin reutilizar retry. Supervised espera aprobación propia del replan; automatic solo aplica cuando Project y Task son automatic. Ningún Step se ejecuta inline.

Los estados activos del replan siguen inhibiendo 11D. `rejected|failed` liberan la Task para NextWork; `applied` deja de ser terminal. Un apply aprobado interrumpido se recupera por el mismo Worker sin repetir Planner, budget o Steps. No hay segundo Planner, Worker, Orchestrator, Task, Proposal, ToolCall, ChatMessage, DELETE de Steps ni loop recursivo.

No se modificó Task Center ni chat. MySQL real continúa en **SKIP** sin `TASK_TEST_DB_*`; la migración no se ejecutó contra producción. Fase 11 continúa **ABIERTA**: 11F/11G siguen pendientes.
