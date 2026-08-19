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
