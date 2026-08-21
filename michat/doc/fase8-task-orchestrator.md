# Fase 8 — Task Orchestrator

Estado: IMPLEMENTADA Y CERRADA en `main`.

Esta documentación refleja el estado real del repositorio después de la integración de las fases 8.2 a 8.8. Sustituye las notas anteriores que todavía describían 8.6 como parcial.

## Modelo de dominio

`Task` representa el objetivo persistente; `TaskStep`, una unidad lógica del plan; `TaskDependency`, una dependencia Task → Task; `TaskExecution`, un intento de ejecución; `TaskEvent`, auditoría append-only del dominio; y `TaskArtifact`, la procedencia de recursos utilizados o producidos por una ejecución.

```text
Task
├── Steps
│   └── Executions
│       ├── trace_id
│       └── Artifacts
├── Dependencies
└── Events
```

Task State y Execution Trace son conceptos distintos. `TaskEvents` registra transiciones del dominio Tasks. La telemetría detallada de Bedrock, RAG, memoria, herramientas y respuesta continúa en `ChatActivityEvents` mediante `trace_id`.

## Fase 8.2 — HTTP API

Implementada.

`task_api.php` actúa como adaptador JSON delgado y delega la lógica a `TaskApiController`, `TaskApplicationService`, `TaskOrchestrator` y repositories. Las operaciones usan identidad de sesión, ownership, CSRF en escrituras, `public_id`, optimistic locking e idempotencia.

## Fase 8.3 — Integración con Chat

Implementada.

Los turnos de chat pueden registrarse como Task → Step → Execution sin romper el flujo legacy. El sistema conserva el `trace_id` real de ejecución y mantiene separación entre el estado de la Task y la traza operacional.

La integración contempla modo supervisado y automático. En modo supervisado una Task puede quedar en `waiting_user` hasta aprobación humana.

## Fase 8.4 — Task Planner

Implementada.

El Planner produce planes estructurados y validados server-side. Los tipos de Step admitidos incluyen `plan`, `model`, `tool`, `approval`, `wait`, `validation` y `finalize`. El resultado del modelo se considera entrada no confiable y se valida antes de persistirse.

Las dependencias entre Tasks son persistentes, limitadas al mismo propietario y ámbito autorizado, y evitan autorreferencias, duplicados y ciclos.

## Fase 8.5 — Worker persistente

Implementada.

Existe Worker CLI durable con claim transaccional, `FOR UPDATE SKIP LOCKED`, leases, heartbeat, recovery conservador y reintentos controlados.

```text
Task ready
  ↓
claim
  ↓
lease
  ↓
Execution running
  ↓
heartbeat
  ↓
completed / failed / waiting
```

El Worker no convierte una espera humana en aprobación y no ejecuta efectos no autorizados.

## Fase 8.6 — Step Execution Engine y runtime compartido

Implementada y cerrada.

`TaskStepExecutionService` utiliza una whitelist explícita de executors. HTTP y Worker convergen en servicios compartidos de ejecución y no dependen de llamadas HTTP internas para ejecutar Tasks.

El runtime incluye ejecución multi-step persistente, progresión determinista, waits durables, aprobación/rechazo de Steps, cancelación segura, ejecución de Tools registradas y finalización compartida de respuesta.

Los adaptadores server-side de `str_replace` y `code_edit` están implementados. Las ejecuciones físicas de herramientas se persisten en `ToolCalls` y se conserva su duración y resultado sanitizado.

La finalización compartida incluye persistencia de respuesta, memoria, embeddings cuando corresponde, Memory Writer, TokenUsage y eventos de actividad.

## Fase 8.7 — TaskArtifacts y procedencia

Implementada.

La tabla `TaskArtifacts` registra relaciones mínimas entre una `TaskExecution` y recursos utilizados, creados, modificados o generados. La migración se encuentra en:

`michat/sql/fase8_7b_task_artifacts.sql`

El esquema consolidado `adbbmis1_Cloud.sql` también contiene `TaskArtifacts`.

La persistencia de artefactos se realiza server-side y puede asociarse con `ToolCalls` sin duplicar contenido privado del recurso.

El detalle autorizado de una Task puede exponer artefactos mediante DTOs whitelist y resolver únicamente metadatos públicos seguros.

## Fase 8.8 — HITL, seguridad operacional y Task Center

Implementada y cerrada.

Las Tools con capacidad de escritura o riesgo suficiente pasan por un gate server-side Human-In-The-Loop.

El flujo implementado es:

```text
Tool propuesta
   ↓
Risk Policy
   ↓
Fingerprint persistente
   ↓
Durable pause
   ↓
Human approve / reject
   ↓
Consumo único de aprobación
   ↓
Nueva ejecución autorizada
```

La autorización está ligada al fingerprint persistido y se consume una sola vez. La aprobación de una operación no autoriza automáticamente una operación diferente.

El gate se aplica tanto a Steps explícitos de tipo `tool` como a Tool Use solicitado por el modelo durante una ronda de ejecución.

Existe `michat/task_center.php` para inspeccionar Tasks propias, Steps, estados, errores, aprobaciones pendientes, artefactos y trazas; cancelar o reintentar cuando el estado lo permite; y aprobar o rechazar propuestas sin exponer IDs internos sensibles.

También existe un presupuesto server-side por ejecución para limitar rondas de modelo, llamadas a herramientas, escrituras, tokens y duración, reduciendo el riesgo de ejecuciones descontroladas.

## Esquema de base de datos

El esquema consolidado actual incluye las estructuras de Fase 8, incluyendo Tasks, Steps, Executions, Events, Dependencies y TaskArtifacts.

La migración específica de TaskArtifacts existe por separado y el esquema principal ya contiene la tabla. Por lo tanto, al momento de esta actualización no se requiere una nueva modificación de DB únicamente para documentar el cierre de Fase 8.

Regla para fases futuras: cualquier cambio que introduzca o modifique tablas, columnas, índices, constraints, enums o relaciones debe actualizar simultáneamente:

1. la migración incremental correspondiente;
2. `adbbmis1_Cloud.sql` como esquema consolidado de instalación limpia;
3. la documentación MD de la fase;
4. tests de integración o contrato relacionados.

## Validación de cierre

Después de la integración de Fase 8 se ejecutó una revisión de preparación para producción con 45 scripts `*_test.php` terminando con exit 0, sin FAIL reportados; 271 archivos PHP pasaron `php -l`; 21 archivos JavaScript pasaron `node --check`; y `git diff --check` quedó limpio.

Parte de los tests de integración reportan SKIP cuando no existen variables `TASK_TEST_DB_*` o infraestructura AWS/MySQL externa. Esos SKIP representan validación E2E dependiente del entorno, no componentes de Fase 8 pendientes de implementación.

## Estado final

Fase 8 — Task Orchestrator: CERRADA.

Lo siguiente ya no es continuar 8.6, 8.7 u 8.8. Los siguientes trabajos deben tratarse como nuevas fases de evolución del proyecto, incluyendo portabilidad/industrialización de MiChat y el diseño de MCMA.
