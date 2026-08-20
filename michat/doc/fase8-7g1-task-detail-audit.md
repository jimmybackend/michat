# Fase 8.7G.1 — Auditoría de lectura del detalle de Tasks

## Punto de partida verificado

La auditoría se realizó desde el commit `383ad9a`, merge de la persistencia de
artifacts de Fase 8.7. El checkout entregado no incluía una referencia local o
remota llamada `main`; por ello, la nueva rama se creó directamente desde ese
commit de merge, que era el `HEAD` disponible.

## Flujo productivo actual

El detalle se obtiene con `GET task_api.php?action=detail&task=<public_id>`.
`task_api.php` resuelve el usuario autenticado mediante `ChatIdentity`, compone
los repositories `mysqli`, el `TaskApplicationService` y el
`TaskApiController`. El controller enruta `action=detail` hacia
`TaskApplicationService::detail()`.

El servicio valida el formato del `public_id` y, antes de consultar cualquier
colección hija, llama a `TaskRepository::findOwnedByPublicId(public_id,
user_id)`. Una Task inexistente o ajena produce el mismo `404 not_found`, sin
revelar su existencia. Después, Steps, Dependencies, Executions y Events se
consultan con métodos `listOwned(task_id, user_id)` que vuelven a aplicar el
ownership mediante joins contra `Tasks`.

## Serialización pública existente

`TaskApplicationService` construye DTOs mediante listas permitidas:

- `taskDto()` excluye el ID interno del usuario y datos de infraestructura;
- `stepDto()` excluye `id_` y `task_id_`, pero actualmente incluye
  `input_json` y `checkpoint_json`;
- `executionDto()` excluye `id_`, `task_id_`, `step_id_`, `worker_id`,
  `lease_token` y `lease_expires_at`;
- `eventDto()` excluye IDs internos y `actor_user_id_`, pero incluye
  `details_json`.

El detalle devuelve las claves `task`, `plan`, `steps`, `dependencies`,
`executions` y `events`. El único consumidor web localizado usa este endpoint
para polling y solo lee `task.status`.

## Estado de TaskArtifacts

`TaskArtifactRepository::listByTask(task_id)` y `listByExecution(execution_id)`
son lecturas internas sin parámetro de usuario ni comprobación de ownership.
Devuelven IDs internos de Artifact, Execution y ToolCall, además de la tupla de
provenance y la fecha. Por tanto, no deben conectarse directamente al controller
ni considerarse una frontera autorizada.

La capa adecuada para autorizar y construir la respuesta sigue siendo
`TaskApplicationService::detail()`: ya comprueba la Task una sola vez antes de
leer sus hijos y evita que el controller conozca SQL o repositories concretos.
La composición productiva de `task_api.php` todavía no inyecta
`TaskArtifactRepository` en ese servicio.

## Diseño mínimo recomendado para el siguiente cambio

El siguiente cambio debe limitarse a extender el detalle existente, sin crear
otro endpoint:

1. Inyectar opcionalmente `TaskArtifactRepository` en
   `TaskApplicationService`, preservando los constructores usados por tests y
   adaptadores existentes.
2. Ejecutar `listByTask()` únicamente después de que `owned()` haya autorizado
   la Task.
3. Agregar una colección superior `artifacts` con un DTO público de whitelist:
   `execution_id`, `tool_call_id` nullable, `relation`, `resource_type`,
   `resource_id` y `created_at`.
4. No resolver todavía metadata de los recursos ni consultar `ToolCalls`; no
   exponer `params`, `result`, contenido, rutas, claves S3 o URLs.
5. Cubrir que una Task ajena sigue produciendo 404 antes de leer artifacts, que
   los campos privados no aparecen y que el wiring productivo usa el repository
   nuevo.

La asociación Artifact → Execution requiere conservar algún identificador en
ambas representaciones. El cambio pequeño debe decidir explícitamente si expone
el `TaskExecutions.id_` como `execution_id` en el DTO de executions o si usa un
identificador público ya existente como `trace_id`; no debe dejar artifacts con
una referencia imposible de correlacionar.

## Prompt pequeño propuesto

> **FASE 8.7G.2 — Exponer TaskArtifacts en el detalle autorizado de una Task.**
> Extiende exclusivamente el flujo existente
> `GET task_api.php?action=detail&task=<public_id>`. Inyecta
> `TaskArtifactRepository` en `TaskApplicationService` desde el adapter
> productivo y consulta `listByTask()` solo después de que `owned()` haya
> autorizado la Task para el usuario autenticado. Agrega al detalle una lista
> superior `artifacts` serializada mediante whitelist con `execution_id`,
> `tool_call_id` nullable, `relation`, `resource_type`, `resource_id` y
> `created_at`; permite correlacionarla de forma explícita con la lista pública
> de executions usando el cambio mínimo compatible. No resuelvas metadata de
> recursos, no consultes ni expongas `ToolCalls.params/result`, contenido,
> rutas, `s3_key`, `s3_path`, credenciales ni URLs. Mantén mysqli, el endpoint
> legacy como adapter delgado y compatibilidad con los constructores/tests
> existentes. Añade tests pequeños para ownership-before-read, whitelist del
> DTO, correlación con Execution y wiring productivo. No implementes UI ni otras
> fases.
