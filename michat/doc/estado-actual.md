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

## Trabajo siguiente

Los siguientes bloques ya no pertenecen a Fase 8:

- completar refactor orientado a objetos del código legacy;
- introducir autoload PSR-4 en Composer y reducir `require` manual progresivamente;
- consolidar migraciones/versionado de esquema;
- instalación portable / wizard cuando se defina su alcance;
- abstracción de proveedores de IA;
- abstracción de storage;
- ampliar regresión automatizada y E2E MySQL/AWS;
- preparar release público estable;
- diseñar e implementar MCMA como nueva evolución, sin fingir que ya existe en el repositorio.

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
