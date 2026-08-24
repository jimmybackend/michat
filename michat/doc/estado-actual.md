# Estado actual de MiChat

Actualizado: 2026-08-21.

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

### Fase 10 — PLANIFICADA: Scheduling y automatización declarativa

El Worker persistente no equivale a un Scheduler de producto. Actualmente existen Worker, `scheduled_at` como *not-before*, Wait Steps temporales, dependencias, retry y recovery. Fase 10 deberá diseñar posteriormente scheduler de producto, recurrencia, triggers, deadlines operativos, condition watchers y retry/backoff declarativo.

### Fase 11 — PLANIFICADA: Autonomía operativa de MiChat

Partirá de Planner, Model Steps, Tool Steps, Worker, memoria/RAG, HITL, límites y recovery ya existentes. Antes de implementar deberán auditarse y diseñarse posibles subtareas, replanning, objetivos persistentes, delegación interna, roles o ejecutores, políticas de autonomía, presupuestos globales y continuidad entre sesiones.

### Fase 12 — PLANIFICADA: Industrialización y release estable

Comprenderá PSR-4 progresivo, reducción de `require` manuales, endpoints delgados, migraciones y versionado de esquema, instalación reproducible, abstracciones de proveedores IA o storage cuando aporten portabilidad real, E2E MySQL/AWS, revisión final de seguridad, packaging y release estable.

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
