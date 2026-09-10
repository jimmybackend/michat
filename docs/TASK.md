# Tasks — Orquestador persistente de trabajo de MiChat

> Estado: implementación verificada en `main`.
> Superficie web: `michat/task_center.php`.
> API: `michat/task_api.php`.
> Worker: `michat/bin/task_worker.php`.
> Actualizado: 2026-09-09/10.

## 1. Qué es el sistema de Tasks

El sistema de Tasks de MiChat ya no es solo un diseño previsto. Es un dominio persistente que permite crear trabajo explícito, planificarlo, ejecutarlo por Steps, pausarlo para aprobación humana, reintentarlo, programarlo y mantenerlo activo independientemente del navegador.

La idea actual es:

```text
Objetivo
  ↓
Task persistida
  ↓
Plan
  ↓
Steps
  ↓
Worker / HTTP sync
  ↓
Modelos + Tools
  ↓
Resultados + Artifacts + Events
```

La conversación normal pertenece a `chat.php`; el trabajo orquestado pertenece a `task_center.php` y a los servicios POO de `michat/includes/Tasks/`.

## 2. Frontera con el Chat

Una pregunta normal del Chat no debe crear automáticamente una Task.

La implementación actual separa ambas superficies:

- Chat usa un snapshot que enmascara flags `task_*`;
- Task Center y `task_api.php` usan la configuración Task persistida;
- el Worker continúa ejecutando trabajo async aunque el navegador se cierre;
- una Task previamente creada y aprobada puede reanudarse mediante una acción explícita del dominio Task.

Esta frontera evita que una pregunta conversacional termine solicitando aprobación de agente sin que el usuario haya pedido una Task.

Referencia: `michat/doc/chat-task-boundary.md`.

## 3. Estados y persistencia

MySQL es la fuente de verdad. El dominio utiliza tablas como:

- `Tasks`;
- `TaskSteps`;
- `TaskExecutions`;
- `TaskEvents`;
- `TaskDependencies`;
- `TaskArtifacts`;
- `TaskRecurrenceRules`;
- `TaskRecurrenceOccurrences`;
- tablas de autonomía, continuaciones y replanning de Fase 11.

Los estados se validan mediante state machines y servicios POO, evitando transiciones improvisadas en endpoints o en el Worker.

## 4. Creación manual y planificación

Task Center permite crear una Task manual con objetivo, sesión/proyecto, modo y programación.

La planificación puede usar `task_planner`. El Planner produce un plan limitado a tipos ejecutables y el servidor asigna posiciones y valida el resultado antes de persistirlo.

Después de PR #78, una respuesta normal que el Planner represente como `model -> validation -> finalize` se normaliza a un único Step `model` cuando esos controles no contienen una validación determinista ejecutable. Esto evita falsos `validation_failed` después de una respuesta correcta sin debilitar los validadores técnicos reales.

## 5. Modos de ejecución

### Supervised

La Task o un Step puede quedar en `waiting_user` y exigir una decisión humana antes de continuar.

### Automatic

La Task puede quedar `ready` y ser reclamada por el Worker sin aprobación inicial, siempre respetando políticas, budgets, dependencias, scheduling y gates de Tools.

La autonomía no significa ejecución sin límites. Los límites y la política siguen siendo autoridad server-side.

## 6. Worker durable

El Worker real es:

```bash
php michat/bin/task_worker.php --loop
```

En la instalación EC2 actual se supervisa mediante systemd. El Worker:

- usa identidad propia por proceso;
- reclama trabajo con lease;
- usa `FOR UPDATE SKIP LOCKED` donde corresponde;
- mantiene heartbeat de Task/Execution;
- recupera ejecuciones abandonadas de forma conservadora;
- observa cancelación cooperativa;
- respeta `scheduled_at`;
- procesa recurrencias, replans y continuaciones de forma acotada;
- comparte la misma frontera POO de ejecución que HTTP;
- no llama endpoints HTTP internos para ejecutar un Step.

Configuración principal del Worker se recibe por entorno, incluyendo `TASK_WORKER_ID`, lease, sleep, recovery y budgets.

## 7. Ejecución de Steps

El registry productivo permite Steps y componentes especializados. Entre los ejecutores actuales se encuentran:

- `model`;
- `tool`;
- `validation`;
- `approval`;
- `wait`;
- controles internos necesarios para progresión.

`ModelTaskStepExecutor` reutiliza `ChatExecutionService`, Memory/RAG y el runtime de Bedrock. El modelo efectivo queda persistido en la Execution.

`ToolTaskStepExecutor` ejecuta Tools a través del registry compartido y conserva ToolCalls/Artifacts con provenance real.

## 8. Tools

El registry de producción incluye, entre otras:

- `grep`;
- `search`;
- `view`;
- `str_replace`;
- `code_edit`.

Las Tools de lectura son distinguibles de las escrituras. Las operaciones de escritura no deben ejecutarse silenciosamente en una Task que requiere HITL.

## 9. HITL de Tools de escritura

Las Tools con efectos de escritura pueden pasar por un gate de aprobación humana.

El flujo es:

```text
Model / Tool Step propone escritura
  ↓
Policy clasifica riesgo
  ↓
Proposal segura + fingerprint persistido
  ↓
Task/Step/Execution -> waiting_user
  ↓
Usuario aprueba o rechaza
  ↓
Nueva Execution
  ↓
Fingerprint exacto se consume una sola vez
  ↓
Tool se ejecuta
```

La propuesta pública no expone parámetros sensibles, IDs internos ni payloads completos. La aprobación se vincula al fingerprint persistido y no a una descripción humana ambigua.

## 10. Scheduling

Fase 10A añadió `scheduled_at` como límite one-shot UTC.

- `NULL`: ejecutable cuando el resto de condiciones se cumplen;
- pasado/presente: elegible;
- futuro: no elegible todavía.

La prioridad no puede saltarse `scheduled_at`.

Task Center permite reprogramar Tasks elegibles mediante optimistic locking.

## 11. Recurrencia

Fases 10D–10F añadieron recurrencia durable:

- `daily`;
- `weekly`;
- timezone IANA;
- hora civil;
- manejo explícito de DST;
- políticas de misfire `skip`, `run_once` y `catch_up` según contrato;
- materialización acotada en el mismo Worker;
- una Task como máximo por slot lógico mediante idempotencia/constraints;
- pausa, resume y cancelación de reglas sin cancelar retrospectivamente Tasks ya materializadas.

No se creó un segundo Worker de recurrencia.

## 12. Dependencias

Las Tasks pueden depender de otras Tasks del mismo scope autorizado.

Task Center expone relaciones directas e inversas sin filtrar IDs internos. El Worker no debe reclamar un Step mientras las dependencias autoritativas no estén satisfechas.

## 13. Artifacts y versiones

Las ejecuciones de Tools pueden producir `TaskArtifacts` que enlazan de forma mínima y auditable recursos como:

- `ProjectSource`;
- `SourceChunk`;
- `FileVersion`;
- `FileS3`.

Los DTO públicos exponen únicamente metadata permitida. El contenido privado, rutas internas, leases, ToolCall IDs y payloads completos permanecen fuera de la respuesta pública.

`code_edit` y otras operaciones de edición reutilizan `FileVersions` para conservar versiones reales y provenance.

## 14. Resultados finales y ChatMessages

Las Tasks modernas pueden enlazar su resultado final a `ChatMessages.result_message_id_` mediante el servicio compartido de persistencia de respuesta.

Para Tasks legacy completadas sin ese vínculo, Task Center conserva compatibilidad de lectura usando `Tasks.result_summary` o el último `TaskSteps.output_summary` de modelo cuando corresponde.

La persistencia moderna usa el `AUTO_INCREMENT` real de MySQL y evita `MAX(id_)+1`.

## 15. Task Center

`michat/task_center.php` es la superficie de operación humana.

Incluye:

- Lista y Tablero;
- búsqueda y filtros;
- prioridades y fechas;
- detalle de Task y Step actual;
- acciones HITL;
- cancelación y retry cuando son válidos;
- programación y recurrencia;
- dependencias;
- historial y Events;
- Executions;
- Artifacts;
- navegación hacia chat/trace;
- controles y observabilidad de autonomía.

Desde PR #78, `michat/js/task-center-live.js` refresca cada 5 segundos mientras hay trabajo no terminal, pausa polling con la pestaña oculta y evita interrumpir formularios que el usuario está editando.

## 16. Retry, recovery y cancelación

El retry manual no revive una Execution histórica. Reactiva de manera controlada la Task y el Step fallido autorizado para que una nueva Execution sea creada.

Recovery diferencia trabajo realmente abandonado de pausas HITL. Cancelación se valida contra estados persistidos y los guards impiden iniciar Bedrock o una Tool cuando la Task ya fue cancelada.

## 17. Budgets

El runtime Task aplica límites server-side a dimensiones como:

- rondas de modelo;
- Tool Calls;
- input tokens;
- output/total tokens;
- escrituras;
- duración.

Estos límites no dependen del cliente y permanecen vigentes aunque la Task se ejecute automáticamente.

## 18. Autonomía de Fase 11

Fase 11 añadió autonomía operacional acotada por proyecto, no un loop libre e infinito.

Incluye:

- `ProjectAutonomyPolicies`;
- budgets/reservas;
- ciclos;
- `NextWorkEvaluator`;
- decisiones `stop`, `ask_user`, `propose_task`;
- Proposals;
- materialización de Tasks hijas;
- continuaciones post-Task;
- replanning versionado;
- límites de profundidad y consumo;
- observabilidad y controles en Task Center.

La política `disabled` es el estado seguro. Los modos supervised/automatic siguen sometidos a presupuesto y scope.

## 19. Observabilidad

Tasks conserva información observable como:

- estado actual;
- Step actual;
- modelo efectivo;
- attempts;
- Executions;
- Events;
- errors sanitizados;
- ToolCalls;
- Artifacts;
- `trace_id`;
- TokenUsage.

Task Center no expone lease tokens, worker IDs ni IDs internos necesarios solo para persistencia.

## 20. Seguridad

Reglas actuales:

- identidad del usuario resuelta server-side;
- ownership antes de lectura/mutación;
- `public_id` para navegación pública de Tasks;
- optimistic locking para mutaciones sensibles;
- CSRF en operaciones web;
- Tools de escritura con HITL cuando la política lo exige;
- no confiar en IDs de usuario enviados por el navegador;
- ejecución durable separada del navegador.

## 21. EC2 actual

Ruta del repositorio:

```text
/var/www/michat
```

Worker:

```text
/usr/bin/php /var/www/michat/michat/bin/task_worker.php --loop
```

Unit systemd:

```text
/etc/systemd/system/michat-task-worker.service
```

Entorno:

```text
/etc/michat.env
```

La instalación actual ejecuta el servicio como `apache:apache`.

## 22. Estado por fases

- Fase 8: cerrada — Task Orchestrator y ejecución real.
- Fase 9: cerrada — Task Center 2.0, relaciones, historial y hardening.
- Fase 10: cerrada — scheduling, manual Tasks y recurrencia.
- Fase 11: cerrada — autonomía acotada y replanning.
- Fase 12: hardening/release — seguridad, migraciones, roles, compatibilidad y certificación externa.

Los documentos de fase en `michat/doc/` son evidencia histórica. Para el estado operativo más reciente usar `michat/doc/contexto-operativo-actual.md` y verificar el código real de `main`.

## 23. Relación futura con MCMA

MCMA puede reutilizar este dominio de Tasks para trabajos de memoria cuando esa integración exista. No debe asumirse que MCMA ya controla las Tasks o que `task.php` es una pieza futura pendiente: el sistema Task real de MiChat hoy vive en Task Center, Task API, Worker y servicios POO.
