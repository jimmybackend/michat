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

## Fase 8.4 — Task Planner

El flujo autorizado es:

```text
Task aprobada
      ↓
Dependency Check
      ↓
Task Planner
      ↓
TaskPlan
      ↓
TaskSteps
```

Los flags se interpretan conjuntamente: `task_orchestrator` habilita el dominio, `task_auto_execute` decide si se requiere aprobación inicial y `task_planner` (desactivado por defecto) habilita el plan estructurado. Con `task_auto_execute` OFF, el Planner IA tampoco se ejecuta antes de aprobación humana. Si el Planner está desactivado o falla, se conserva el plan determinista de un Step `respond`.

El resultado IA se trata como entrada no confiable: solo se aceptan entre 1 y 8 Steps, claves únicas con formato seguro, longitudes limitadas y los tipos `plan`, `model`, `tool`, `approval`, `wait`, `validation` y `finalize`. El servidor valida agentes, asigna `position` y persiste el plan completo de forma transaccional. El placeholder `respond` solo se sustituye antes de cualquier ejecución; los planes existentes y los Steps con historial no se replantean.

`TaskDependencies` representa exclusivamente **Task → Task**. `completed` y `terminal_success` requieren que la Task requerida esté `completed` (no existe un estado `success`); `terminal_any` admite `completed`, `failed` o `cancelled`. Solo se admiten dependencias entre Tasks del mismo usuario y del mismo ámbito de proyecto (incluidas dos Tasks sin proyecto), sin autorreferencias, duplicados ni ciclos. Una Task autorizada con requisitos pendientes queda en `waiting_dependency`; la aprobación ya concedida no se repite al liberarse.

El orden entre Steps se representa inicialmente mediante `position`. No existe todavía DAG Step → Step. El Planner es actividad previa de orquestación: no crea `TaskExecution`, no marca Steps como ejecutados y no introduce trazabilidad ni contabilidad de costes paralelas.

## Fase 8.5 — Worker

La ejecución persistente separa aprobación de ubicación de ejecución. `task_auto_execute`
decide si se requiere aprobación humana; `task_async_execute` (OFF por defecto) decide
si el pipeline se ejecuta en HTTP o queda `ready` para el Worker. Al crear el Step
`respond`, el modo queda congelado en `input_json.execution_mode`; la ausencia del
campo significa `sync` y protege Tasks legacy.

Los cuatro modos son: **Supervised + Sync** (aprobar y continuar por HTTP),
**Supervised + Async** (aprobar y dejar `ready`), **Automatic + Sync** (pipeline
inmediato) y **Automatic + Async** (planificar y dejar `ready`). El Worker nunca
convierte `waiting_user` en aprobación ni reclama `waiting_dependency`.

```text
Task ready
    ↓
claim (FOR UPDATE SKIP LOCKED)
    ↓
lease (worker_id + lease_token + lease_expires_at)
    ↓
Execution running
    ↓
heartbeat
    ↓
completed / failed
```

MySQL es la cola y la fuente de verdad. El orden de claim es `urgent`, `high`,
`normal`, `low`, seguido por `scheduled_at/created_at`; una fecha futura no es
elegible. Cada claim crea un trace nuevo. Heartbeat renueva Execution y Task sin
crear eventos. Todas las mutaciones terminales vuelven a comprobar el token, por lo
que un Worker que perdió el lease no puede completar.

```text
Worker dies
   ↓
lease expires
   ↓
Execution abandoned
   ↓
Task failed
   ↓
manual retry (nuevo Execution y nuevo trace)
```

La recuperación es intencionadamente conservadora: no reintenta automáticamente
Tools ni otros efectos potencialmente no idempotentes, conserva Execution y trace,
y deja el retry al usuario. Se procesa en lotes acotados. Al completarse una
prerrequisito, `TaskDependencyService` puede volver a evaluar la dependiente y
moverla de `waiting_dependency` a `ready`; no se recorre el grafo en una transacción
gigante.

Fase 8.5 solo reclama el placeholder real `respond`. Conserva el Plan y no simula
Steps `tool`, `validation` u otros: el executor genérico multi-Step pertenece a 8.6.
El adaptador CLI no contiene SQL ni llama a `bedrock_chat2.php` por HTTP; usa la
frontera POO server-side `TaskExecutionRunner`. Claim y heartbeat no son endpoints.

```bash
php michat/bin/task_worker.php --once
php michat/bin/task_worker.php --loop
```

También se admiten `--max-jobs=N` y `--sleep=N`. La infraestructura externa debe
administrar el proceso; el script no daemoniza. Una vez que una Task async está
`ready`, cerrar o recargar el navegador no afecta su estado, Plan ni ejecución.

## Fase 8.6 — Step Execution Engine

La frontera compartida acepta DTOs validados y no superglobales:

```text
bedrock_chat2.php (adaptador HTTP)
       ↓
ChatExecutionService
       ↑
TaskExecutionRunner / Worker
```

`TaskStepExecutionService` selecciona mediante una whitelist explícita los executors
`model`, `tool`, `validation`, `finalize`, `approval`, `wait` y `plan`; nunca instancia
una clase indicada por el Planner. `model` delega en el runtime server-side inyectado,
y `tool` converge en `ToolRegistry`, que clasifica efectos como `read_only`,
`idempotent_write` o, por defecto, `non_idempotent`. Las aprobaciones y esperas se
persisten como `waiting_user` y retornan el control, sin mantener dormido al Worker.
La validación inicial segura comprueba existencia de rutas relativas, sin shell.

```text
Task → Step 1 → Execution 1 → Step 2 → Execution 2 → … → Task completed
```

El progreso es determinista (`completed / total executable`, entero): `skipped`, una
aprobación esperando y una espera pendiente no representan trabajo completado. Cada
intento conserva su Execution histórica y obtiene attempt/trace/lease nuevos. Los
heartbeats no producen eventos; telemetry Bedrock/RAG/Memory/Tools permanece en
`ChatActivityEvents`, mientras transiciones de dominio permanecen en `TaskEvents`.

Fase 8.6 captura en los DTO referencias de artefactos producidos por Tools (por
ejemplo un `file_version` real) sin copiar contenido ni insertar otra versión. La
persistencia formal `TaskArtifacts` se implementará en Fase 8.7; no se modifica el
esquema en esta fase. La extracción de todas las tools legacy y el lint ampliado se
harán incrementalmente: únicamente handlers registrados explícitamente pueden ser
usados por Steps, y `plan` nunca planifica recursivamente.
# Fase 8.6B: runtime compartido y progresión

La composición CLI ya no acepta una callable global. `ChatExecutionServiceFactory`
construye `BedrockChatRuntime` con el cliente central de `Config`, configuración
dinámica de agentes y el `ToolRegistryFactory` de producción.

```text
HTTP Adapter ─┐
              ↓
       ChatExecutionService
              ↑
Worker ───────┘
```

El Worker reclama el Step `ready` de menor posición, crea una Execution y trace
por intento, y al completarlo calcula `floor(100 * (completed + skipped) / total)`.
Si queda otro Step, lo deja `ready`, mantiene la Task `running` y actualiza
`current_step_id_`; sólo el último limpia `current_step_id_` y completa al 100%.

```text
Task → Step → Execution → Executor → Progression → Next Step
```

Los Steps `approval` y `wait` no duermen: liberan el lease y persisten el estado
de espera. La ampliación de condiciones/checkpoints de espera pertenece a 8.8.
