# Estado actual de MiChat

Actualizado: 2026-08-27.

Este archivo funciona como referencia de estado real del repositorio. Las decisiones de implementación deben verificarse contra código, esquema SQL, migraciones y tests; una nota histórica de una fase no debe prevalecer sobre el estado actual de `main`.

## Implementado

### Conversación, memoria y recuperación

- sesiones persistentes;
- contexto de proyecto;
- memoria procedural;
- memoria Q&A selectiva;
- embeddings semánticos;
- RAG de proyecto y adjuntos;
- Memory Context Router;
- Context Builder y ranking;
- Memory Writer;
- Prompt Compiler tolerante a fallos;
- feature flags del pipeline.

### Observabilidad

- trazabilidad operacional por `trace_id`;
- explorador Q&A;
- grafo de ejecución;
- integración de memoria/RAG en el grafo;
- edición de nodos de memoria actual;
- visualización 2D y 3D;
- TokenUsage y estimación de costes;
- `ChatActivityEvents` para actividad operacional.

### Fase 8 — Task Orchestrator

La Fase 8 está implementada y cerrada.

Incluye:

- dominio persistente Tasks / Steps / Executions / Events / Dependencies;
- API autorizada por `public_id`;
- integración Task ↔ Chat;
- modo automático y supervisado;
- Task Planner validado server-side;
- Worker persistente con leases, heartbeat y recovery;
- ejecución multi-step;
- Steps model/tool/validation/finalize/approval/wait/plan;
- runtime compartido entre HTTP y Worker;
- waits persistentes sin dormir el Worker;
- herramientas server-side registradas, incluyendo `str_replace` y `code_edit`;
- persistencia de `ToolCalls`;
- cancelación y retry controlados;
- finalización compartida de memoria, tokens y telemetría;
- `TaskArtifacts` y procedencia de recursos;
- resolución segura de metadatos públicos de artefactos;
- gate HITL para Tools de escritura/riesgo;
- fingerprint de propuesta y consumo único de aprobación;
- gate para Tool Use solicitado por el modelo;
- límites server-side por ejecución;
- Task Center (`michat/task_center.php`).

La documentación detallada está en `michat/doc/fase8-task-orchestrator.md`.

## Base de datos

`adbbmis1_Cloud.sql` es el esquema consolidado para una instalación limpia.

La Fase 8 ya está representada en el esquema actual. `TaskArtifacts` existe también como migración incremental en `michat/sql/fase8_7b_task_artifacts.sql`.

No debe inventarse una modificación SQL únicamente porque una fase nueva sea documentada. La DB cambia solo cuando la implementación necesita una estructura persistente nueva o modifica una existente.

Cuando una fase sí cambia la DB, el mismo trabajo debe incluir:

1. migración incremental idempotente o claramente versionada;
2. actualización de `adbbmis1_Cloud.sql` para instalación limpia;
3. repositories/servicios que utilicen el cambio;
4. tests de integración o contrato;
5. documentación MD actualizada.

## Instalación y configuración

El bootstrap actual puede cargar `.env` desde la raíz sin dependencia externa de dotenv y respeta variables ya inyectadas por PHP-FPM, Apache, EC2 o systemd.

La configuración portable por `.env` ya no debe figurar como totalmente pendiente. Sigue siendo válido continuar desacoplando configuración legacy y proveedores durante la industrialización.

## Validación disponible

La revisión posterior a Fase 8 reportó:

- 45/45 scripts `*_test.php` con exit 0;
- 0 FAIL;
- 271 archivos PHP sin errores de sintaxis;
- 21 archivos JavaScript válidos con `node --check`;
- `git diff --check` limpio.

Los tests que necesitan `TASK_TEST_DB_*`, MySQL real o infraestructura AWS pueden reportar SKIP cuando ese entorno no está disponible. Un SKIP E2E debe documentarse como validación externa pendiente, no como funcionalidad inexistente.

## Roadmap posterior a Fase 8

Fase 8 — Task Orchestrator está **CERRADA**. Su infraestructura ya incluye Tasks, Steps, Executions, Worker persistente, Planner, Tools server-side, HITL, approvals, waits temporales persistentes, dependencias, prioridad backend, `scheduled_at` como límite *not-before*, `due_at`, artifacts, events, trace, memoria/RAG y un Task Center básico.

### Fase 9 — CERRADA: Task Center 2.0

Fase 9 evolucionó la interfaz existente, sin reconstruir Task Center, hacia una UX operativa para proyectos, Tasks y trabajo humano/IA.

Su alcance cerrado comprende:

- navegación por proyecto y sesión;
- búsqueda, filtros combinables y paginación;
- prioridad, `scheduled_at`, `due_at`, progreso y Step actual visibles;
- explicación de waits, bloqueos y dependencias;
- dependencias visibles y gestionables;
- lista operativa y tablero por estados;
- approvals, resume, retry y cancel;
- artifacts e historial;
- navegación a chat y trace.

#### Fase 9A — Base de navegación y descubrimiento completada

`michat/task_center.php` es la superficie oficial e independiente de Task Center; `michat/chat.php` conserva la responsabilidad de conversación normal. Ambas reutilizan las mismas Tasks, Projects y ChatSessions existentes, sin duplicar backend ni modificar el esquema.

Fase 9A incorpora:

- búsqueda combinable sobre los campos reales `Tasks.title` y `Tasks.objective`;
- filtros combinables por estado, prioridad, proyecto y sesión;
- paginación real mediante el contrato existente `limit` / `offset` y su metadata `total`;
- persistencia de filtros y página en la URL;
- nombres de proyecto y sesión en el listado, junto con estado, prioridad y progreso;
- navegación chat → Task Center y Task → chat mediante una `session_id` validada contra las sesiones del usuario;
- estados vacíos y validación server-side de búsqueda y paginación;
- conservación del aislamiento por `Tasks.user_id_` tanto en listado como en conteo.

No se añadieron tablas, columnas ni índices. El resto de Fase 9 — fechas operativas más ricas, explicación y gestión visual de waits/dependencias, tablero, agrupaciones e historial/artifacts ampliados — permanece **PLANIFICADO**. Fase 9 debe seguir reutilizando el dominio y runtime cerrados en Fase 8, sin duplicarlos ni reabrirlos.

#### Fase 9B — Contexto operativo completado

Task Center expone ahora, sin alterar el motor ni el esquema:

- `scheduled_at` y `due_at`, incluyendo la presentación derivada de Tasks vencidas sin crear un estado persistente nuevo;
- el Step señalado por `Tasks.current_step_id_`, cargado junto al listado sin consultas N+1, con tipo, estado, agente y modelo cuando existen;
- situaciones operativas derivadas de estados reales: ejecutable, en ejecución, espera temporal, bloqueo por dependencias y acción humana requerida;
- la fecha pública `wait_until` exclusivamente para Wait Steps, sin exponer `checkpoint_json`;
- dependencias con título, condición, estado y distinción entre satisfechas y bloqueantes;
- gestión mínima para agregar y quitar dependencias mediante las APIs existentes, que mantienen en servidor ownership, scope de proyecto, duplicados, self-dependency y detección de ciclos.

Los filtros operativos adicionales siguen pendientes: 9B reutiliza los filtros de estado `waiting_user` y `waiting_dependency` de 9A en vez de añadir semánticas de consulta redundantes. Al cierre de 9B, el tablero y las agrupaciones todavía estaban planificados; 9C, documentada a continuación, implementa ese tablero. El scheduling declarativo continúa reservado para Fase 10.

#### Fase 9C — Tablero operativo completado

La superficie oficial `michat/task_center.php` ofrece ahora las vistas **Lista** y **Tablero** sobre la misma respuesta owned y paginada de Tasks. El tablero no es una fuente de verdad nueva: agrupa visualmente los estados persistentes del Orchestrator así:

- Pendientes: `pending`, `ready`;
- En ejecución: `running`;
- Requiere acción: `waiting_user`;
- Esperando / bloqueadas: `waiting_dependency`;
- Completadas: `completed`;
- Fallidas / canceladas: `failed`, `cancelled`;
- Otros: fallback defensivo que conserva visible cualquier valor desconocido.

Las tarjetas reutilizan el DTO enriquecido de 9B y abren el mismo detalle que la Lista; no consultan Steps, Dependencies, Events ni detalles individualmente durante el render del tablero. Búsqueda, estado, prioridad, proyecto, sesión y contexto operativo se comparten entre vistas, y `view=board` se conserva en la URL.

El tablero representa de forma explícita **la página filtrada actual** (`limit` / `offset`): su aviso muestra el rango visible frente al total filtrado y los contadores de columna se etiquetan como visibles. No carga todas las Tasks ni presenta esos conteos como totales globales.

9C no añade drag/drop, orden persistente, columnas configurables, endpoints genéricos de estado ni transiciones arbitrarias. Approve, reject, resume, retry y cancel permanecen como acciones de dominio en el detalle compartido. No se modificó la DB. Fase 9 continúa **EN CURSO**; historial/artifacts ampliados, dependencias inversas o grafo y pulido posterior permanecen pendientes.

#### Fase 9D — Relaciones y dependencias operativas completadas

Task Center sustituye la captura manual del `public_id` por un selector asistido que reutiliza `GET task_api.php?action=list`: busca de forma incremental con debounce, solicita como máximo 20 resultados owned, aplica el proyecto de la Task cuando existe y excluye en la experiencia la propia Task, las dependencias ya agregadas y resultados fuera de scope. El identificador elegido sigue siendo el `public_id` exigido por `add_dependency`; el filtrado visual no sustituye las validaciones server-side ante carreras, ciclos, duplicados, ownership, scope o Tasks inexistentes.

El detalle presenta ahora una sección de relaciones con dos sentidos navegables:

- **Esta Task depende de** muestra título y contexto humano, estado, prioridad, condición real y si la relación está satisfecha o continúa bloqueando;
- **Tasks que dependen de esta** muestra las Tasks posteriores, sus estados, prioridades, contextos y condición;
- seleccionar cualquier relación abre la misma abstracción de detalle, tanto desde Lista como desde Tablero, conservando el estado de filtros, vista y paginación en la URL;
- quitar una dependencia y agregar una nueva continúan usando exclusivamente `remove_dependency` y `add_dependency`.

Las dependencias inversas se incorporan al contrato `detail` como `dependents`. Una consulta JOIN compacta exige ownership de la Task requerida **y** de cada Task dependiente; no expone IDs internos ni ejecuta una consulta por relación. Las dependencias directas también enriquecen su DTO en su JOIN existente con prioridad, proyecto y sesión. El selector usa una única consulta listada/paginada y no solicita detalles por candidato, por lo que 9D no introduce N+1.

Se conservan exactamente las condiciones `completed`, `terminal_success` y `terminal_any`, con etiquetas humanas en la UI y valores de dominio sin cambios. El DAG fue evaluado y **pospuesto**: las listas bidireccionales aportan una representación más clara, accesible y móvil de las relaciones inmediatas, mientras un grafo local parcial podría sugerir una visión transitiva incompleta. No se añadieron tablas, columnas, índices, librerías ni endpoints.

Las verificaciones de 9D cubren filtrado y límite del selector, estados vacíos, DTOs directos/inversos navegables, condiciones, contrato API, ownership de ambos extremos en la consulta inversa, UI responsive y regresión de Task Center. La integración MySQL real queda marcada como SKIP cuando no están configuradas las variables `TASK_TEST_DB_*`.

Fase 9 continúa **EN CURSO**. Para 9E quedan el historial operativo, Events y Artifacts ampliados; para 9F quedan responsive/accesibilidad finales, estados de carga/error, E2E en navegador e integración MySQL real. Los filtros operativos específicos pendientes de 9C tampoco se adelantan en 9D.

#### Fase 9E — Historial operativo completado

Task Center transforma ahora los `TaskEvents` reales en una timeline operativa dentro del mismo detalle utilizado por Lista y Tablero. Cada entrada conserva el orden autoritativo de `TaskEvents.id_`, muestra `created_at`, `event_key` con una etiqueta humana cuando la clave es conocida, `summary`, el `actor_type` persistido y la transición `from_status → to_status` solamente cuando ambos valores existen. Los Events relacionados de forma explícita muestran el título del Step, el intento de Execution y acceso al trace correspondiente. Claves o actores futuros/desconocidos se presentan sin inferir semántica adicional.

El contrato público deja de devolver `details_json`: la timeline no renderiza payloads internos. La query owned enlaza Task, Step y Execution en una sola operación, exige ownership de la Task y comprueba coherencia de las relaciones mediante `task_id_`. Se consultan como máximo 101 filas para publicar los 100 Events más recientes en orden ascendente y el indicador `history.has_earlier`; la UI avisa honestamente cuando existe historial anterior no incluido.

La sección **Intentos de ejecución** presenta el `attempt_number` persistido, estado, Step relacionado, agente/modelo, inicio, final, error sanitizado y la acción **Ver trace** cuando `trace_id` existe. Los errores de Task, Step y Execution eliminan caracteres de control, redactan credenciales comunes, ocultan texto con forma de SQL y se acotan a una línea de 300 caracteres; los resúmenes públicos se limitan a 1000 caracteres. No se exponen IDs internos, `worker_id`, leases ni tokens. La query de Executions resuelve el Step con un único JOIN owned.

La sección **¿Qué produjo esta Task?** mantiene los Artifacts como colección independiente porque no existe un Event que los referencie inequívocamente. Añade fecha y, usando las colecciones ya cargadas, el Step y número de intento de la Execution de origen. Conserva exclusivamente la metadata pública resuelta en batch —nombre, versión o rango de líneas según el recurso—, sin IDs, paths, S3 keys, URLs inventadas ni previews.

Los `ToolCalls` no se incorporan directamente en 9E. No existe relación Task/Step/Execution en `ToolCalls`; la única relación inequívoca es opcional a través de un Artifact, y el acceso histórico existente separa deliberadamente su resolución para no exponer `params`, `target_path` o `result`. Los Events reales de aprobación de Tools sí aparecen en la timeline por sus claves persistidas.

No se modificó la DB ni se crearon tablas, Events, estados, endpoints o viewers. El detalle realiza un número constante de queries y resoluciones batch, sin fetch por Event, Execution, Step o Artifact. Las pruebas cubren orden estable con timestamps iguales, Events desconocidos, actor presente/desconocido, referencias reales, payload privado omitido, límite, ownership, Executions múltiples, errores sanitizados, traces, Artifacts y regresiones de 8/9A–9D. Las integraciones MySQL/AWS y E2E navegador permanecen como SKIP cuando falta infraestructura.

Al cierre de 9E, Fase 9 continuaba **EN CURSO** y quedaban para 9F el responsive y accesibilidad finales, estados de carga/error, consistencia visual, validación E2E autenticada, integración MySQL real, regresión/auditoría de seguridad integral y cierre formal de Task Center 2.0.

#### Fase 9F — Hardening y cierre completados

El cierre de Task Center 2.0 endurece la superficie existente sin añadir un bloque funcional ni modificar el dominio. Los filtros y el selector de dependencias tienen labels explícitos; Lista y Tablero conservan botones nativos; el detalle recibe foco después de una navegación dinámica; los resultados seleccionables comunican `aria-pressed`; y una región `role=status` anuncia carga, éxito y error sin depender de diálogos bloqueantes. Se añadieron estilos de `focus-visible`, soporte para movimiento reducido y targets/flujo responsive coherentes.

Las cargas de Lista ignoran respuestas antiguas que lleguen fuera de orden. Las acciones HITL y de dependencias utilizan guards locales, deshabilitan el control iniciador y anuncian progreso/resultado, mientras el servidor permanece como autoridad. Listado, Tablero, detalle y candidatos comunican `aria-busy`; los errores de protocolo o respuestas no JSON reciben mensajes públicos controlados.

El hardening responsive elimina la combinación de columnas mínimas que podía desbordar tablets, permite wrap de acciones y metadata, adapta cabecera/filtros/paginación a móvil y conserva el scroll horizontal intencional del Tablero. Las fechas visibles de Lista reutilizan la presentación UTC común.

La auditoría XSS confirmó escape de títulos, objetivos, Events, actores, errores, Artifacts, proyectos, sesiones, agentes y modelos. La prueba de seguridad ejecuta el helper real con `< > & " '` y verifica su neutralización. Los DTOs conservan privadas las identidades internas, leases, checkpoints, inputs, payloads de Events, referencias internas de Artifacts, paths y secretos.

9F no cambia DB, endpoints, estados, Worker, Orchestrator, Chat ni viewers. La suite disponible termina sin FAIL. La validación E2E autenticada, MySQL real y las integraciones AWS se registran como SKIP de entorno porque no hay navegador, sesión reutilizable ni `TASK_TEST_DB_*`; estos SKIPS no ocultan regresiones conocidas y no bloquean el cierre conforme a los criterios acordados.

**Fase 9 queda CERRADA.** 9A cerró navegación y descubrimiento; 9B, contexto operativo; 9C, Lista/Tablero; 9D, relaciones; 9E, historial; y 9F, accesibilidad, feedback, responsive, seguridad y regresión final. La deuda no bloqueante comprende E2E/MySQL/AWS en infraestructura completa, filtros operativos específicos, paginación histórica avanzada y una auditoría WCAG con navegador.

**SIGUIENTE FASE: Fase 10 — Scheduling y automatización declarativa.** No se implementa scheduling, recurrencia, cron ni automatización durante 9F.

### Fase 10 — EN CURSO: Scheduling y automatización declarativa

#### Fase 10A — Programación one-shot completada

`Tasks.scheduled_at` es ahora un contrato productivo opcional de creación: la API exige un timestamp ISO 8601 con `Z` u offset explícito, lo normaliza a UTC `datetime(6)` y lo persiste mediante Application → Orchestrator → Repository. Una creación programada emite `task_scheduled` sin publicar el input ni modificar `due_at`.

El límite *not-before* se aplica antes de crear una `TaskExecution` tanto en Worker async como en los dos caminos de inicio/reanudación HTTP sync. El claim compara contra `UTC_TIMESTAMP(6)`, por lo que una Task futura —aunque sea urgente— no desplaza trabajo ya elegible; una fecha vencida durante downtime entra naturalmente al volver el polling. Se reutilizan la queue, locks, leases, Worker, Orchestrator, estados e índice existentes: no hay cambios de DB, estado `scheduled`, Wait Step artificial, timers ni scheduler adicional.

Fase 10 permanece **EN CURSO**. Recurrencia, Automation Rules, occurrence identity, triggers y templates no existen y no forman parte de 10A.

#### Fase 10B — Administración one-shot completada

Task API incorpora la acción explícita `reschedule`, protegida por autenticación, feature flag, CSRF, `public_id` owned y `lock_version`. Reutiliza exactamente el parser UTC de 10A y solo permite editar Tasks `pending`, `ready` o `waiting_user` que todavía no tengan Executions; `running`, `waiting_dependency`, `failed`, `completed` y `cancelled` fallan cerradas. La actualización condicional incrementa el lock y compite bajo el mismo bloqueo de fila que el claim, no crea Executions ni modifica `due_at`. `scheduled_at = null` elimina el límite *not-before* y emite el mismo Event mínimo `task_rescheduled` que una reprogramación.

El detalle compartido por Lista y Tablero añade un editor compacto de fecha/hora local. JavaScript convierte `datetime-local` a ISO con zona, evita doble submit, comunica carga/éxito/error/conflicto y recarga siempre el detalle autoritativo; el backend vuelve a validar. Tasks no editables conservan visible su fecha histórica. No se añadió calendario, recurrencia, estado, tabla, índice, Worker, Queue ni cambio de DB.

#### Fase 10C — Tasks manuales ejecutables completada

`action=create` delega ahora en `TaskApplicationService::createManualTask`, la frontera canónica que valida Session/Project/Message owned, prioridad, modo, idempotency key y el contrato UTC de `scheduled_at`; crea o recupera la Task, materializa un plan server-side y activa su primer Step sin crear una Execution. `TaskPlanningService` valida todos los planes, persiste el primer Step como accionable y los siguientes como `pending`, y fija `input_json.execution_mode=async` para reutilizar exclusivamente el Worker. El Planner inyectado puede producir un plan; cuando está desactivado, no está compuesto o falla de forma recuperable se utiliza el `TaskPlan::fallback()` real y se registra `planning_fallback`. Fallos de validación/persistencia posteriores no se ocultan; la Task idempotente permanece recuperable mediante el mismo `create`.

El modo `automatic` termina en Task/primer Step `ready`; `supervised`, en `waiting_user` con `approval_requested`. Aprobar una Task manual solo la deja `ready` para el Worker: el resume HTTP sync permanece exclusivo de Tasks `origin_type=chat`. Una Task futura puede quedar completamente planificada, pero los guards de 10A impiden Execution antes de `scheduled_at`. `due_at` no cambia.

Task Center ofrece **Nueva Task manual** con título, objetivo, Project opcional, Session owned obligatoria, prioridad, modo y fecha one-shot opcional. Reutiliza catálogos y conversión local→ISO, filtra Sessions por Project, genera una idempotency key estable por intento lógico, evita doble submit, recarga Lista/Tablero y abre el detalle autoritativo. JavaScript no envía user IDs ni Steps.

Fase 10 continúa **EN CURSO**. 10C no añade tablas, columnas, índices, tipos de Step, estados, templates, recurrencia, Rules, Queue, Worker ni Scheduler.

**10D — Persistencia mínima de reglas temporales y ocurrencias: COMPLETADA (integración MySQL real pendiente cuando no existe `TASK_TEST_DB_*`).** Se añaden `TaskRecurrenceRules` y `TaskRecurrenceOccurrences` al dump consolidado y a un script de fase. La regla owned conserva Session/Project, estados administrativos `enabled|paused|cancelled`, recurrencia civil `daily|weekly`, zona IANA, `next_occurrence_at` UTC, misfire `skip|run_once|catch_up`, blueprint mínimo y optimistic locking. La ocurrencia usa `UNIQUE(rule_id_,logical_occurrence_at)`, estado de materialización, relación opcional a una Task nueva y una idempotency key determinista.

10D confirma **NEW TASK PER OCCURRENCE**: no reutiliza Tasks terminadas ni persiste Steps/Executions/Artifacts como plantilla. No introduce API o UI recurrente, Worker, Queue, Planner u Orchestrator alternativos.

**10E — Evaluador temporal recurrente integrado: COMPLETADA (MySQL real SKIP sin `TASK_TEST_DB_*`).** El único `TaskWorker` ejecuta recovery, waits, un batch recurrente acotado y después siempre intenta el claim normal. Reglas `enabled` vencidas se bloquean con `FOR UPDATE SKIP LOCKED`; `skip` avanza sin filas masivas, `run_once` usa el slot vencido más antiguo y `catch_up` conserva orden con límite obligatorio. La reserva UNIQUE precede a `TaskApplicationService::createManualTask()`, que recibe blueprint owned, key determinista y `scheduled_at` del slot lógico UTC.

Fallos de materialización quedan durables y sanitizados; occurrences `failed` o `reserved` huérfanas se recuperan por antigüedad y reutilizan row/key. Automatic queda `ready`, supervised `waiting_user`, sin Execution prematura. 10E no añade DB, API, UI, segundo Worker/Queue/Orchestrator ni Events sintéticos.

**10F — Administración de recurrencia y hardening: COMPLETADA (MySQL real SKIP sin `TASK_TEST_DB_*`).** Task API incorpora list/detail/create/pause/resume/cancel explícitos, owned, bajo feature flag y CSRF para mutaciones. Los DTO omiten IDs internos y limitan el detalle a 25 occurrences con Task UUID/failure code público. Task Center añade una pestaña accesible y responsive para daily/weekly, weekday, hora civil, timezone IANA, misfire, scope, prioridad/modo, locking y navegación a Tasks. Cancel no cancela Tasks existentes y pause no revoca reservas ya ganadas.

10F no cambia DB ni introduce cron/RRULE, triggers, Rules genéricas, otro Worker/Queue/Orchestrator o autonomía.

### FASE 10 — CERRADA

La auditoría final PRE-MERGE confirmó 10A–10F, scheduling one-shot, reschedule, creación manual ejecutable, recurrence daily/weekly, misfires, occurrence identity, materialización en el Worker existente, API/UI owned, locking, seguridad y ausencia de un segundo motor. Todas las suites PHP/JS disponibles pasan. **MySQL real permanece SKIP**, no PASS, porque `TASK_TEST_DB_*` no estaba configurado; esta validación E2E aislada queda como deuda externa no bloqueante y no autoriza usar producción.

Fase 11 fue implementada después de este cierre histórico de Fase 10 y quedó **CLOSED / MERGED** mediante el PR #69. Triggers event-driven genéricos, condition watchers, Rules abiertas, IA creando reglas y self-healing estratégico permanecen fuera de su alcance.

### Fase 11 — CLOSED / MERGED: Autonomía operativa de MiChat

Las notas de apertura/cierre intermedio que siguen conservan la secuencia histórica de cada subfase; el estado autoritativo consolidado está al final de esta sección.

**11A.0 — Safe single-turn inference boundary: COMPLETADA.** La integración Bedrock dispone de una primitiva compartida que realiza exactamente una llamada `converse` y normaliza texto, mensaje de salida, Tool Use, stop reason y usage sin persistencia. `BedrockChatRuntime` consume ahora esa misma primitiva y conserva su loop, Tools, gates, cancellation, heartbeat y budgets. Una frontera separada de single-turn valida parámetros, nunca envía Tools ni `toolConfig`, no reintenta y falla cerrada si Bedrock devuelve `toolUse` o `stopReason=tool_use`.

11A.0 no añadió DB, configuración de agentes, Tasks, Steps, Executions, Artifacts, memoria, telemetría, Worker, Queue, Orchestrator ni Planner. Al cierre de esa micro-subfase todavía no existían `NextWorkDecision`, snapshot ni evaluator; 11A.1 incorpora ahora el dry-run descrito a continuación.

**11A.1 — Next-work dry-run: COMPLETADA.** Existe un contrato transitorio estricto `stop|ask_user|propose_task`, un snapshot owned/read-only con límites de colecciones, texto y payload, y un evaluator single-turn sin Tools. La configuración se resuelve desde `UserAIAgentConfigs` mediante `ai_agent_runtime.php`, prefiriendo `next_work_evaluator` si existe y usando `chat_main` como fallback configurable; no hay modelo hardcodeado. El prompt separa policy de `UNTRUSTED PROJECT DATA`, la respuesta se valida en PHP y los fallos cierran en `ask_user`.

Al cierre aislado de 11A.1 todavía no se persistían decisiones ni existía integración con Worker/UI; 11B–11F incorporaron después policy, continuidad, replanning y Task Center sin modificar `chat.php`.

**11B — Policy y presupuesto persistente: COMPLETADA.** Cada Project puede tener una policy owned con modo `disabled|supervised|automatic`, estado `active|paused|stopped`, optimistic locking y límites efectivos sujetos a defaults/ceilings server-side. Ciclos con UUID público conservan contadores durables y una identidad activa única por Project; reservas con idempotency key se realizan bajo transacción y `FOR UPDATE`, se pueden consumir o liberar y no cobran dos veces un retry lógico.

La autorización tipada devuelve `allowed|denied|requires_approval`: solo `propose_task` puede reservar; supervised y Tasks canceladas exigen aprobación, mientras automatic requiere policy activa y budget disponible. Esto no crea Tasks ni reduce HITL de Tools. Cost USD queda **NOT YET ENFORCEABLE** porque el coste disponible es estimado y usa pricing/fallbacks no suficientemente autoritativos para un límite de seguridad.

**11C — Proposal persistente y spawning idempotente: COMPLETADA (MySQL real SKIP sin `TASK_TEST_DB_*`).** `NextWorkProposals` conserva provenance owned y bounded entre source Task, ciclo, autorización/reserva y una única Task resultante. Supervised queda `pending_approval` y requiere approve/reject propio; automatic solo materializa con policy activa y budget reservado. La creación usa el pipeline autoritativo de `TaskApplicationService`, Planner/fallback, Steps y Orchestrator con `origin_type=system`, lineage `parent_task_id_`, modo restrictivo, priority normal, sin scheduling y una idempotency key derivada de la Proposal. Estados durables y reconciliación cubren retries y ventanas de crash sin duplicar Tasks ni cobros.

11C no modifica NextWorkEvaluator, Worker, chat o Task Center; no añade endpoint, Tool, hook post-Task, replanning ni autonomous loop. La Proposal puede producir una Task real solo cuando el servicio interno es invocado explícitamente. Fase 11 continúa **ABIERTA**.

**11D — Continuidad post-Task acotada y recuperable: COMPLETADA (MySQL real SKIP sin `TASK_TEST_DB_*`).** El único TaskWorker descubre Tasks terminales asociadas explícitamente a un ciclo y crea una oportunidad durable única por cycle/source Task. Las continuations usan claim/lease, tres intentos máximos y batch bounded; reservan decision budget antes de inferencia, contabilizan usage real sin TokenUsage conversacional y delegan exclusivamente en NextWorkEvaluator y Proposal service. Stop cierra ciclo, ask_user queda durable, supervised espera approval y automatic crea una child Task que será reclamada posteriormente por el Worker normal.

11D no introduce loop in-memory, recursión, segundo Worker/Queue/Orchestrator, replanning, UI o ChatMessages. Fase 11 continúa **ABIERTA**.

**11E.0 — Typed failure disposition + durable replan checkpoint: COMPLETADA (MySQL real SKIP sin `TASK_TEST_DB_*`).** Los fallos técnicos conservan retry/continuidad normal; triggers lógicos server-side crean atómicamente, junto con Execution/Step/Task failed, una `TaskReplanRequest` owned e idempotente. El checkpoint demuestra ausencia de Execution/Step running y bloquea el discovery de 11D mientras permanezca activo.

11E.0 no llama Planner/modelo, no genera ni aplica planes, no cambia Steps futuros, no reabre Tasks y no implementa approval. La deuda del Planner nullable queda para 11E.1, que deberá reutilizar `task_planner`/`UserAIAgentConfigs`. Fase 11 continúa **ABIERTA**.

11B añade únicamente `ProjectAutonomyPolicies`, `ProjectAutonomyCycles` y `ProjectAutonomyReservations`, junto con `michat/sql/fase11b_project_autonomy.sql`; no modifica Tasks, Worker, Queue, Orchestrator, chat o Task Center. Todavía no existe spawning, continuidad automática, UI NextWork, replanning ni operational autonomous loop. Fase 11 continúa **ABIERTA**.

Partirá de Planner, Model Steps, Tool Steps, Worker, memoria/RAG, HITL, límites y recovery ya existentes. Antes de implementar deberán auditarse y diseñarse posibles subtareas, replanning, objetivos persistentes, delegación interna, roles o ejecutores, políticas de autonomía, presupuestos globales y continuidad entre sesiones.

### Fase 12 — CURRENT: Industrialización y release estable

La auditoría inicial post-PR #69 descartó imponer un orden histórico de PSR-4, endpoints o migraciones y abrió como primer bloque real **12A — Public HTTP error safety**. **12A Trace Metrics hardening: PASS**: `trace_metrics_api.php` conserva success, auth, ownership, parámetros y errores públicos conocidos, pero ya no refleja detalles internos de `RuntimeException`; los registra server-side y devuelve un 500 genérico estable. Esto no declara una release estable. Versionado/upgrade de schema, E2E MySQL/AWS/browser y operación/packaging de release permanecen pendientes de bloques auditados posteriores.

**12B — implementación/hardening reconstruidos y listos como merge candidate.** La rama de rescate real `rescue/fase12b-ec2` parte del mismo `main` desplegado y conserva los hotfixes validados en EC2. El cierre no depende ya de un workspace efímero: código, migraciones, tests y documentación están versionados en GitHub.

El contrato actual de 12B comprende:

- cadena cerrada y ordenada de **14 migraciones**, con `MigrationRunner`, historia/checksums, detección fail-closed de DRIFT/UNKNOWN/PARTIAL y perfiles controlados para clean baseline y upgrade soportado;
- `adbbmis1_Cloud.sql` como dump canónico de instalación limpia: no crea/selecciona una DB hardcodeada, no inventa usuarios, omite defaults históricos por usuario y contiene el catálogo GLOBAL funcional;
- reconciliación MySQL de generated columns al contrato final **VIRTUAL**, incluyendo `ProjectAutonomyCycles.active_project_id_` y `UserAIAgentConfigs.scope_owner_key`;
- configuración AI `GLOBAL/USER` sin propietario mágico `user_id_=1`; los overrides USER prevalecen por `agent_key` y GLOBAL aporta fallback;
- `Users.system_role ENUM('user','admin','superadmin') DEFAULT 'user'` y `AuthorizationService` DB-backed para operaciones privilegiadas, sin privilegios implícitos por ID de usuario;
- provisioning CLI con `create_first_user.php` y `create_user.php`, perfil inicial canónico y contraseñas suministradas por variables de entorno, no por argv; upgrades legacy con usuarios existentes disponen de `bootstrap_superadmin.php`, one-shot, sin ID mágico, sólo mientras existan cero superadmins y autenticando al target activo;
- `reset_runtime_data.php` únicamente CLI, dry-run por defecto, destructivo solo en development/test con confirmación explícita, permiso `system.reset`, auditoría y sin `TRUNCATE` ni `FOREIGN_KEY_CHECKS=0`; el endpoint legacy `truncate.php` fue eliminado;
- bootstrap portable mediante `MICHAT_ENV_FILE`, `MICHAT_VENDOR_AUTOLOAD`, `MICHAT_CONFIG_FILE` y `MICHAT_DB_BOOTSTRAP`, manteniendo como fallback el layout EC2 validado;
- hardening del Worker/Task runtime: heartbeat multi-table robusto, locks MySQL dentro del límite de 64 caracteres, Planner sin nuevo Step ejecutable `plan`, corrección de autonomía/Task Center y eliminación de recursión entre factories;
- retry coherente después de Execution `abandoned`: el budget global de Task sigue siendo autoritativo, el Step fallido autoriza únicamente el siguiente ordinal y el historial de Executions no se revive ni se borra;
- resultado durable de Tasks manuales: origen humano persistido en Chat cuando hace falta, respuesta completa en `ChatMessages.content`, referencia mediante `Tasks.result_message_id_`, summary acotado por separado y `TaskExecutions.model_id` actualizado con el modelo efectivo;
- Task Center presenta **Resultado final** separado de **Artifacts**, con preview/modelo y navegación a la conversación completa.

La evidencia operacional real de EC2 ya demostró el camino **Worker → Bedrock → Task completada** con el Worker ejecutado bajo el usuario `apache` y `EnvironmentFile=/etc/michat.env`. La validación automática de la rama incluye lint de todo PHP, contratos PHP críticos, contratos JavaScript y guard de secretos/backups; el run del commit `e8554116a74ed4b15b7fe54f9e63effd8570a860` terminó **PASS**.

La certificación destructiva MySQL aislada continúa deliberadamente **SKIP / NOT CERTIFIED** cuando faltan `TASK_TEST_DB_*`. No se debe ejecutar contra HostGator/producción ni reinterpretar un SKIP como PASS. La fotografía temporal de producción `adbbmis1_Cloud-final.sql` fue usada para reconciliación y retirada del árbol de release; `adbbmis1_Cloud.sql` es el único dump canónico distribuible para instalación limpia.

El detalle de cierre y las deudas externas está documentado en `michat/doc/fase12b-closure-audit.md`.

## Regla de cierre para nuevas fases

Una fase no se considera terminada solamente porque el código compile.

Antes de declararla cerrada se debe revisar, según aplique:

- implementación real;
- integración con el pipeline existente;
- seguridad y ownership;
- idempotencia/concurrencia;
- persistencia;
- migraciones y esquema consolidado;
- tests;
- documentación de arquitectura;
- README/estado del proyecto cuando cambie una capacidad pública;
- compatibilidad con instalación limpia.

La documentación debe describir lo que existe en `main`, distinguiendo claramente entre IMPLEMENTADO, VALIDACIÓN E2E PENDIENTE y PLANIFICADO.

Cada fase o subfase que cambie una capacidad pública debe actualizar, cuando corresponda, este archivo, la documentación arquitectónica relevante y `README.md` cuando cambie la instalación o una capacidad pública. El código y la documentación no deben divergir.

**11E.1 — replanning remaining-plan versionado: COMPLETADA.** El mismo Worker reclama `TaskReplanRequests` con lease y batch bounded, reserva una unidad idempotente de replan, reutiliza `TaskPlanningService` + `AiTaskPlanner` configurado por `UserAIAgentConfigs.task_planner`, contabiliza usage real y persiste revisiones/membresía durable. Supervised espera aprobación específica; automatic exige Project y Task automatic. El apply transaccional conserva Steps terminales, cancela solo futuro `pending|ready`, agrega Steps con keys/positions server-side y realiza `failed → ready` sin ejecución inline.

11D permanece inhibido durante estados activos y se habilita únicamente tras `rejected|failed`; un apply exitoso vuelve no terminal a la Task. Replanning no crea Tasks, Proposals, Tools, ChatMessages o memoria, no borra historia y no introduce Planner/Worker paralelo. La UI y el hardening fueron completados posteriormente por 11F/11G.

**11F.1 — Task Center autonomy observability read-only: COMPLETADA (browser/MySQL real pendientes cuando no hay entorno aislado).** `task_center.php` conserva Lista, Board, filtros, búsqueda, paginación, detalle, Steps, scheduling, recurrence, approvals preexistentes, executions, Events, Artifacts y traces. El detalle owned compone ahora el resumen de policy/ciclo/budgets del Project, lineage, continuations, decisiones NextWork persistidas, ask_user, Proposals, replans y revisiones con membership explícita de Steps. La UI limita colecciones/textos, escapa contenido no confiable, distingue plan actual del historial y muestra solo UUIDs públicos de autonomía.

Al cierre de 11F.1 no se creaban datos ni existían acciones de autonomía; esas operaciones fueron incorporadas posteriormente por 11F.2A/11F.2B y endurecidas/cerradas técnicamente por 11G. Estado histórico: 11A PASS, 11B PASS, 11C PASS, 11D PASS, 11E PASS y 11F.1 PASS, sujeto a la deuda externa de E2E MySQL/browser indicada.

**11F.2A — controles de policy/status/budgets: COMPLETADA (MySQL/browser real pendientes según entorno).** Task Center permite guardar explícitamente mode y nueve límites, pause/resume/stop, iniciar o resolver idempotentemente el ciclo activo y asociar la Task owned actual como root. Los comandos reutilizan servicios 11B/11D, CSRF, UUID público de Task, ownership derivado, ceilings server-side y optimistic locking; rechazan límites bajo consumo activo y nunca editan contadores/coste.

11F.1 permanece PASS. Al cierre de 11F.2A no se ejecutaba Worker/Planner/NextWork/Replan y el HITL de ask_user/Proposal/Replan quedaba reservado para 11F.2B, ahora completada. **11G.1/11G.2 completadas; Fase 11 lista para PRE-MERGE**.

**11F.2B — HITL operativo ASK_USER/Proposals/Replans: COMPLETADA (MySQL/browser real pendientes según entorno).** Task Center responde continuations con answer/actor/fecha durables, aprueba/rechaza Proposals y aprueba/rechaza Replans mediante servicios autoritativos. HTTP no ejecuta Worker, Planner, Steps o continuidad: Proposal autorizada se materializa por mantenimiento posterior del Worker y Replan aprobado se aplica por el Worker existente. Rechazos son idempotentes, ownership/CSRF/locks permanecen activos y las aprobaciones de Tools no se transfieren.

Estado: **11F.1 PASS, 11F.2A PASS, 11F.2B PASS**. Task Center ya observa y controla policy, budgets, status, cycle/root y HITL de autonomía. **11G.1/11G.2 completadas; Fase 11 lista para PRE-MERGE**.

**11G.1 — HARDENING COMPLETE (MySQL/browser real SKIP cuando el entorno no los ofrece).** La auditoría cross-user/cross-project, races, budgets, leases, cancellation, prompt injection, XSS/CSRF, bounds, Tool gates y Worker fairness corrigió scope exacto de ASK_USER, cancellation posterior a Proposal approval y atomicidad de Replan reject. No se añadió feature, Worker, Planner, Orchestrator, Tool o UI.

11A–11F permanecen PASS. **11G.1 HARDENING COMPLETE; 11G.2 CLOSURE AUDIT COMPLETE; Fase 11 PRE-MERGE PASS / READY TO MERGE**.


### FASE 11 — CLOSED / MERGED

11A, 11B, 11C, 11D, 11E.0, 11E.1, 11F.1, 11F.2A, 11F.2B, 11G.1 y **11G.2 closure audit** están implementadas y las suites disponibles pasan. La arquitectura conserva un TaskWorker, un TaskOrchestrator, una arquitectura de Planning/Planner, la inferencia compartida y un único Task Center. No hay blocker conocido.

La Fase 11 fue fusionada mediante el PR #69. La auditoría del diff completo desde `708816f` y las suites PHP/JS disponibles no detectó blockers de implementación. MySQL real y Browser E2E siguen SKIP por ausencia de entorno, como deuda externa no bloqueante; cost USD continúa NOT ENFORCEABLE.
