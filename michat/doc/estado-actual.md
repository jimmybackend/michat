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

### Fase 9 — SIGUIENTE: Task Center 2.0

Fase 9 no creará Task Center desde cero. Evolucionará la interfaz existente hacia una UX operativa tipo Monday para proyectos, Tasks y trabajo humano/IA.

Su alcance previsto comprende:

- navegación por proyecto y sesión;
- búsqueda, filtros combinables y paginación;
- prioridad, `scheduled_at`, `due_at`, progreso y Step actual visibles;
- explicación de waits, bloqueos y dependencias;
- dependencias visibles y gestionables;
- lista operativa y tablero por estados;
- approvals, resume, retry y cancel;
- artifacts e historial;
- navegación a chat y trace.

Estas capacidades se consideran **PLANIFICADAS** hasta que exista implementación, integración y validación. Fase 9 debe reutilizar el dominio y runtime cerrados en Fase 8, sin duplicarlos ni reabrirlos.

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
