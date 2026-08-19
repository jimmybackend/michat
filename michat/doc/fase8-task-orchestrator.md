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
