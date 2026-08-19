# Fase 8 — Task Orchestrator

`Task` es el objetivo persistente; `TaskStep`, su unidad lógica; `TaskDependency`, una relación dirigida; `TaskExecution`, un intento; y `TaskEvent`, auditoría append-only del dominio.

```text
Task
├── Steps
│   └── Executions
│       └── trace_id (Fase 7)
├── Dependencies
└── Events
```

**Task State ≠ Execution Trace.** `ChatActivityEvents` continúa siendo propiedad de Fase 7 y no se copia en `TaskEvents`.

## Fase 8.2 — HTTP API

`task_api.php` es un adaptador JSON delgado: obtiene identidad con `ChatIdentity`, delega en `TaskApiController` y este en `TaskApplicationService`, `TaskOrchestrator` y repositories `mysqli`. GET `list` y `detail` son de lectura; POST `create`, `cancel` y `retry` requieren sesión, CSRF y el feature flag `task_orchestrator` activo. Todas las tareas se buscan por `public_id` y ownership derivado de sesión.

`create` valida coherencia User → Project → Session → Message y soporta idempotencia por `(user_id_, idempotency_key)`. `cancel` y `retry` reciben `lock_version`; conflictos y transiciones inválidas producen 409. Validación produce 422, ausencia 404, falta de autenticación 401 y errores internos 500 sin detalles SQL. Las respuestas son DTO controlados y nunca exponen `lease_token`.

## Fase 8.3 — Chat Integration

Cuando el feature flag `task_orchestrator` está activo, el turno final del chat se
registra mediante `ChatTaskBridge` sin sustituir ni reordenar el pipeline existente:

```text
ChatMessage
   ↓
Task
   ↓
TaskStep (respond)
   ↓
TaskExecution
   ↓
trace_id
```

**Task State != Execution Trace.** La Task conserva el estado del objetivo y la
Execution referencia exactamente el `trace_id` usado por `ChatActivityEvents`; los
eventos de RAG, memoria, herramientas y Bedrock no se copian a `TaskEvents`.

La idempotencia deriva de `request_id`, usuario autenticado y sesión mediante una
clave SHA-256 acotada. El índice de Tasks y el `trace_id` único de Executions permiten
reutilizar Task, Step y Execution en reintentos. `compile_only` termina antes del
bridge y nunca crea objetos del dominio Tasks.

La integración es **fail-open**: un error interno del bridge se registra en el log y
no impide la respuesta legacy. Un error real del modelo que ya inició el turno sí se
refleja como Execution, Step y Task fallidas, con mensaje sanitizado. La ejecución
sigue siendo síncrona dentro de la petición HTTP: no hay worker, planner, scheduler,
polling ni UI de tareas en esta fase. Con el flag apagado no se consulta ni escribe
ninguna tabla del Task Orchestrator.

## Fase 8.3S — Supervised Chat Integration

Los flags, ambos desactivados por defecto, separan el uso del dominio Tasks de la
autorización para ejecutar:

- **Orchestrator OFF** → chat legacy, sin Task.
- **Orchestrator ON + Auto OFF** → `Task → waiting_user → Human approval`.
- **Orchestrator ON + Auto ON** → `Task → ready → beginExecution()` inmediatamente.

El bridge separa `prepareTurn()` de `beginExecution()`. La preparación crea de
forma idempotente la Task y el único Step `respond`; en modo supervisado ambos
quedan `waiting_user` y no existe TaskExecution ni trace de ejecución. `approve`
y `reject` requieren sesión, CSRF, ownership, estado y `lock_version`; la decisión
se audita como un TaskEvent con actor humano. Aprobar deja Task y Step en `ready`;
el navegador llama después a `execute_approved_task`. Rechazar cancela ambos sin
invocar el pipeline.

La reanudación reconstruye el turno desde `Tasks.session_id_`,
`Tasks.origin_message_id_`, `ChatMessages` y el `TaskSteps.input_json` mínimo
(request id, referencia/decisión de compilación y parámetros de generación). No
se persisten cookies, CSRF ni credenciales. El mismo `beginExecution()` atiende
la ejecución automática y la aprobada, y protege el intento único.

Cada ejecución retardada obtiene un trace estándar nuevo de Fase 7, enlazado por
`TaskExecution.trace_id` con `ChatActivityEvents.trace_id`; no se copian eventos.
Por tanto, **Task State != Execution Trace** y **Prompt approval != Task approval**.
`compile_only` sigue terminando antes de crear cualquier entidad Task.

La idempotencia deriva una clave SHA-256 de usuario, sesión y `request_id`; las
decisiones usan optimistic locking y un segundo execute no genera otro intento.
MySQL permanece como fuente de verdad y `list` puede recuperar las Tasks
`waiting_user` de una sesión después de recargar.

El chat legacy permanece intacto con el orchestrator apagado. En auto ON se
conserva el fail-open pasivo. En auto OFF se responde con error controlado si
falla Tasks: un fallo técnico nunca se convierte en permiso implícito ni inicia
Bedrock, Tools o el pipeline principal.
