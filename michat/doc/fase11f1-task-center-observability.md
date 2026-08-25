# Fase 11F.1 — Task Center 3.0: observabilidad read-only

## Auditoría de la superficie existente

La implementación extiende, sin reemplazar, `task_center.php`. La página usa `js/task-center.js`, `js/task-operational-context.js`, `js/task-recurrence.js`, `css/design-system.css` y `css/task-center.css`. `task_api.php` entrega el envelope `ok/error`; GET list/detail y recurrence son lecturas, mientras POST mantiene CSRF de sesión para las mutaciones históricas. Tasks usan UUID público; Projects y Sessions mantienen sus identificadores legacy en catálogos owned.

La Lista y el Board comparten búsqueda, filtros de status/priority/Project/Session, páginas de 25 elementos y el mismo detalle. El detalle existente conserva overview, scheduling one-shot, Steps/agent key, approvals de Task/Step/Tool, relaciones, executions/traces, artifacts y una timeline limitada a 100 Events. La pestaña de recurrencia conserva Rules, occurrences, timezone/misfire y sus estados. Los patrones de loading, empty, error, safe 404, foco, teclado, live regions y breakpoints móviles se reutilizan.

## Arquitectura

`TaskCenterAutonomyReadService` es la única frontera nueva. Recibe exclusivamente la identidad autenticada y una Task ya resuelta como owned. Todas sus consultas vuelven a encadenar user/Project/Task/cycle, son SELECT acotados y no llaman `getOrCreate`, Worker, evaluator, Planner ni servicios de escritura. El DTO omite IDs internos, leases, worker identity, prompts, snapshots, respuesta raw y configuración de agentes.

El endpoint GET `action=detail` existente incorpora dos nodos: `project_autonomy` y `autonomy`. No se añade endpoint POST. El resumen de Project se obtiene solo desde el Project de la Task owned, evitando aceptar ownership desde querystring. Una policy ausente se representa como no configurada/disabled y nunca se crea al leer.

## Presentación

El panel muestra policy mode/status/stop reason; ciclo público y estado; profundidad; ocho budgets con used/limit/remaining; y declara explícitamente que coste no es enforceable. Para la Task muestra root/child/parent, continuation, stop/ask_user/propose_task, reason/question, Proposal y Task spawned. Approval y ask_user son estados informativos sin controles.

Replanning muestra Request y Task como estados diferentes, trigger/disposition/source Step, revision/model/timestamps y el historial versionado. `TaskPlanRevisionSteps` es la autoridad de membership; Steps cancelados o fallidos históricos se etiquetan como no ejecutables. `agent_key` es el único dato de configuración de agentes mostrado.

Los nuevos event keys se integran al renderer histórico existente. Todo texto durable se pasa por `esc`; links internos se construyen únicamente con UUIDs recibidos en campos específicos. Colecciones: 10 continuations/Proposals, 20 Requests/revisions, 64 Steps por revisión; evidence y strings se truncan en SQL. El detalle completo se consulta solo al abrir una Task, nunca por tarjeta del Board.

## Límites deliberados

Al cierre histórico de 11F.1 no había writes de autonomy, migración, página paralela ni cambios a `chat.php`. 11F.2A añadió los controles delimitados abajo, 11F.2B añadió las decisiones humanas y 11G completó el hardening y la auditoría de cierre. Browser E2E requiere una sesión autenticada reutilizable. MySQL integration requiere exclusivamente `TASK_TEST_DB_*`; producción no se usa.

## 11F.2A — controles de policy, ciclo y budgets

El mismo panel incorpora writes explícitos para cambiar `disabled|supervised|automatic`, guardar los nueve límites, pausar, reanudar, detener, iniciar/resolver el ciclo activo y asociar la Task abierta como root. Todos usan el POST/envelope/CSRF existente y envían la Task UUID como única autoridad de scope; Project, user y policy se resuelven server-side. Policy mode/status/budgets delegan en `AutonomyPolicyService`, cycle en `AutonomyBudgetService` y enrollment en `PostTaskContinuationService`.

Los límites deben llegar completos como enteros positivos dentro de `AutonomyPolicy::CEILINGS`; no existe write de contadores ni coste. En ciclo activo se rechaza un límite inferior al consumo durable. Policy usa `lock_version`; cycle start permanece idempotente bajo el servicio y UNIQUE existentes; enrollment verifica explícitamente cycle activo, mismo scope, depth cero y que la Task no pertenezca a otro ciclo.

Estos comandos no ejecutan Worker, Planner, NextWork o Replan y no modifican Tasks, Proposals, continuations históricas ni approvals. Responder ask_user y decidir Proposal/Replan permanecen exclusivamente para 11F.2B.

## 11F.2B — HITL operativo de autonomía

Task Center completa el HITL de Fase 11 con respuesta durable a `waiting_user`, approve/reject de Proposal y approve/reject de Replan. La respuesta se guarda en `PostTaskContinuations` sin ChatMessage, conserva question, registra actor/fecha y vuelve a `pending`; el Worker posterior reevalúa con la respuesta como dato no confiable. Submit idéntico es idempotente y una respuesta diferente entra en conflicto.

Proposal approval usa `NextWorkProposalService` pero difiere spawning: HTTP solo deja `authorized`; el mismo mantenimiento bounded de continuations reconcilia posteriormente y materializa mediante `TaskApplicationService`/Orchestrator/Planner, nunca desde el endpoint. Replan approval queda `approved`; el Worker existente aplica posteriormente la revisión. Rechazos son terminales e idempotentes y no invocan continuidad inline.

Todos los controles son POST con CSRF, UUIDs públicos, ownership derivado y lock version donde existe. Aprobar una revisión no aprueba sus Tools: gates, write approvals y cancellation permanecen vigentes. 11G completó el hardening y la auditoría de cierre técnico.

## 11G.1 — hardening de autonomía

La auditoría transversal reforzó tres carreras reales: ASK_USER ahora exige coincidencia exacta user/Project/Task/continuation y deja ganar cancellation; spawning diferido invalida una aprobación anterior a una cancelación posterior de la source Task; y Replan reject agrupa row lock, Request/Revision y Event en una transacción. Se verificaron optimistic locks, UNIQUE/idempotency de cycles/reservations/continuations/Proposals, leases de continuations/replans, apply atómico, exclusión Replan-vs-11D, CSRF/XSS, bounds y separación de Tools.

El único TaskWorker conserva el orden recovery → waits → recurrence → replans bounded → Proposal/continuation bounded → claim normal. Proposal reconciliation no añade Worker ni Planner y sigue protegida por reservation/Task idempotency. MySQL real y browser E2E permanecen SKIP cuando faltan `TASK_TEST_DB_*` y browser/auth. 11G.1 y 11G.2 están completos; la auditoría pre-merge terminó PASS y Fase 11 está READY TO MERGE, pero no merged/released.
