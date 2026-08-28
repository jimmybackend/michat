# AI Memory & Trace Platform

«Plataforma conversacional open source orientada a proyectos, diseñada para integrar modelos generativos, memoria persistente, RAG, herramientas, observabilidad y trazabilidad completa del proceso de respuesta.»

## Overview

AI Memory & Trace Platform es una aplicación de inteligencia artificial desarrollada para explorar una arquitectura de chat más persistente, observable y controlable que un flujo convencional de "prompt → modelo → respuesta".

El sistema incorpora múltiples capas de memoria, recuperación semántica, contexto de proyecto, herramientas de desarrollo, configuración dinámica de modelos y trazabilidad operacional por respuesta.

El objetivo principal es que una conversación con IA pueda mantener continuidad a largo plazo y que, al mismo tiempo, sea posible inspeccionar qué información utilizó el sistema, qué componentes participaron y cuánto costó producir una respuesta.

El proyecto está desarrollado principalmente con PHP, JavaScript y MySQL, e integra servicios de Amazon Web Services, incluyendo Amazon Bedrock y Amazon S3.

---

## Key Features

### Persistent Memory Architecture

El sistema implementa diferentes tipos de memoria con responsabilidades independientes:

- **Session Memory** — contexto y resúmenes asociados a una conversación.
- **Project Memory** — decisiones, reglas, hechos y notas persistentes de un proyecto.
- **Procedural Memory** — preferencias, correcciones, reglas y patrones de trabajo del usuario.
- **Selective Q&A Memory** — recuperación de preguntas y respuestas anteriores.
- **Primordial Context** — información marcada para conservarse como contexto prioritario.

La memoria se trata como una fuente estructurada de conocimiento y no simplemente como una extensión ilimitada del prompt.

---

### Memory Context Router

Antes de construir el contexto final, el sistema puede clasificar la intención de la consulta y determinar qué tipos de memoria son relevantes.

Ejemplos:

```
Preference request  → Procedural Memory
Previous decision   → Project Memory
Known fact          → Structured project context
Past conversation   → Semantic / Q&A memory
Project code        → RAG
```

Esto reduce recuperación innecesaria y permite controlar qué fuentes participan en cada respuesta.

---

### Context Builder & Ranking

Los candidatos recuperados desde memoria y RAG pasan por una etapa de construcción y selección de contexto.

El pipeline puede:

- recuperar candidatos;
- eliminar duplicados;
- clasificarlos;
- aplicar límites;
- priorizar resultados;
- respetar presupuestos de contexto;
- registrar elementos seleccionados y descartados.

El objetivo es enviar al modelo únicamente el contexto más útil para la pregunta actual.

---

### Retrieval-Augmented Generation

La plataforma incluye un sistema RAG para proyectos y archivos adjuntos.

El flujo general es:

```
Project / File
      ↓
Source extraction
      ↓
Chunks
      ↓
Embeddings
      ↓
Semantic retrieval
      ↓
Ranking
      ↓
Prompt context
```

Los fragmentos indexados conservan metadatos como:

- proyecto;
- archivo fuente;
- tipo de chunk;
- clase o función;
- líneas de inicio y fin;
- checksum;
- tokens;
- embedding utilizado.

---

### Dynamic AI Agents

Los componentes de IA pueden configurarse mediante agentes independientes.

Ejemplos:

- `chat_main`
- `prompt_compiler`
- `embedding_main`
- `smart_memory_general`
- `smart_memory_code`

Cada agente puede disponer de su propia configuración:

- modelo;
- temperatura;
- "top_p";
- límite de tokens;
- instrucciones del sistema;
- plantilla de usuario;
- fallback;
- configuración adicional;
- estado activo/inactivo.

Esto permite utilizar modelos diferentes para tareas diferentes sin acoplar la lógica de la aplicación a un único LLM.

---

### Prompt Compiler

El sistema puede incluir una fase previa para:

- corregir errores;
- mejorar claridad;
- enriquecer instrucciones;
- incorporar contexto relevante.

El compilador está diseñado para ser fault tolerant.

Si el proceso falla, excede el tiempo configurado o produce un resultado inválido, la conversación continúa utilizando la pregunta original del usuario.

```
User Prompt
    ↓
Prompt Compiler
    ├── Success → Optimized Prompt
    └── Failure → Original Prompt
```

Una función auxiliar nunca debe impedir la respuesta principal.

---

### Pipeline Feature Flags

Diferentes componentes del pipeline pueden habilitarse o deshabilitarse independientemente.

Entre ellos:

- Prompt Compiler
- Memory Router
- Procedural Memory
- Project Memory
- Session Memory
- Q&A Memory
- Project RAG
- Attachment RAG
- Context Ranking
- Memory Backfill
- Project Tools
- Memory Writer

Esto permite realizar pruebas controladas y comparar configuraciones sin eliminar información persistente.

---

### Memory Writer

Después de generar una respuesta, el sistema puede analizar la interacción y determinar si contiene información reutilizable.

El Memory Writer puede detectar y consolidar:

- decisiones;
- reglas;
- hechos;
- preferencias;
- correcciones;
- workflows;
- patrones.

Las operaciones de escritura de memoria quedan registradas para facilitar auditoría y debugging.

---

### Execution Traceability

Uno de los objetivos principales del proyecto es poder responder:

> «Why did the AI produce this answer?»

Cada respuesta puede asociarse con un "trace_id".

El sistema registra eventos operacionales relacionados con:

- Request
- Prompt Compiler
- Memory Router
- Feature Flags
- Context Retrieval
- Context Ranking
- Context Builder
- RAG
- Prompt Preparation
- Model Invocation
- Tool Calls
- Response
- Memory Writer
- Completion

La trazabilidad representa actividad operacional de la aplicación y no expone razonamiento privado del modelo.

---

### User → Project → Session → Q&A

La navegación de trazabilidad sigue la estructura:

```
User
  ↓
Project
  ↓
Session
  ↓
Question
  ↓
Answer
  ↓
Execution Trace
```

También se admiten conversaciones que no pertenecen a ningún proyecto.

---

### Execution Graph

Cada respuesta puede representarse como un grafo de ejecución.

```
Question
   ↓
Memory Router
   ↓
Context Builder
   ↓
Context Ranker
   ↓
Final Prompt
   ↓
Model
   ↓
Tools
   ↓
Response
   ↓
Memory Writer
```

El grafo permite inspeccionar individualmente los eventos y los datos registrados en cada fase.

---

### Memory & RAG Graph Integration

Los recursos de memoria utilizados durante una respuesta pueden representarse como nodos relacionados con el pipeline.

Ejemplo:

```
                    Context Builder
                   /       |        \
                  /        |         \
                 ↓         ↓          ↓

       ProjectContext   Procedural   SourceChunk
            #17          Memory #8      #94
                 \         |          /
                  \        |         /
                   └───────┼────────┘
                           ↓
                     Final Prompt
```

El sistema diferencia entre:

- recursos realmente utilizados;
- candidatos recuperados pero descartados;
- snapshot histórico;
- estado actual del registro.

---

### Historical vs Current State

La trazabilidad conserva el estado histórico utilizado durante una respuesta.

Ejemplo:

```
Historical value:
PHP 8.3

Current value:
PHP 8.4
```

Modificar una memoria posteriormente no altera retroactivamente el contexto que utilizó una respuesta anterior.

Esta separación permite mantener auditabilidad.

---

### Live Node Editing

Los registros de memoria actuales pueden administrarse desde el grafo.

Entre los recursos editables se encuentran:

- Project Context;
- Procedural Memory;
- Session Context Blocks;
- indexed Source Chunks;
- session summaries.

Las modificaciones afectan al estado actual, nunca al snapshot histórico del trace.

Cuando corresponde, los embeddings asociados se invalidan y se solicita su regeneración.

---

### 2D & 3D Visualization

La plataforma incluye visualización del grafo en dos modos.

#### 2D

Optimizada para inspección técnica y secuencia temporal.

#### 3D

La visualización tridimensional utiliza el espacio para representar dimensiones distintas del proceso.

- **X** → pipeline subsystem
- **Y** → execution sequence
- **Z** → knowledge provenance

El pipeline principal se mantiene en el plano central.

Los recursos utilizados y descartados pueden separarse espacialmente para facilitar la inspección.

---

### Tools & Project Operations

La plataforma incluye infraestructura para registrar operaciones ejecutadas sobre proyectos.

Ejemplos de herramientas:

- `grep`
- `search`
- `view`
- `read_chunk`
- `str_replace`
- `create_file`
- `write_file`
- `delete_file`
- `move_file`
- `lint`
- `run_tests`
- `preview_diff`
- `restore_version`

Las ejecuciones pueden almacenar:

- parámetros;
- archivo objetivo;
- resultado;
- estado;
- duración.

---

### File Versioning

Las modificaciones de archivos pueden asociarse con versiones.

El sistema contempla información como:

- project
- session
- message
- filename
- version
- S3 path
- diff summary
- status
- SHA-256 before
- SHA-256 after
- model used

Esto permite conservar trazabilidad de modificaciones relacionadas con operaciones de IA.

---

### Metrics, Tokens & Costs

La aplicación registra consumo asociado a diferentes fases del pipeline.

Ejemplos:

- compile
- respond
- embedding
- rag
- edit
- summarize
- review

Las métricas pueden incluir:

- input tokens;
- output tokens;
- modelo utilizado;
- duración;
- costo estimado;
- herramientas utilizadas;
- escrituras de memoria.

Los datos pueden analizarse por:

- Response
- Session
- Project
- User
- Month

Los costos mostrados son estimaciones y dependen de la configuración de precios aplicada por la instalación.

---

## AWS Integration

La implementación actual utiliza servicios de Amazon Web Services.

### Amazon Bedrock

Utilizado para:

- modelos conversacionales;
- modelos especializados;
- embeddings;
- generación de respuestas;
- tareas auxiliares de memoria.

### Amazon S3

Utilizado para almacenamiento de:

- archivos;
- adjuntos;
- contenido asociado a proyectos;
- versiones;
- recursos procesados por la aplicación.

### Amazon EC2

La plataforma puede desplegarse sobre infraestructura EC2 utilizando un stack PHP/MySQL convencional.

---

## Database Architecture

La aplicación utiliza una arquitectura relacional en MySQL.

Entre las entidades principales se encuentran:

- Users
- Projects
- ChatSessions
- ChatMessages
- ProjectContext
- UserProceduralMemory
- SessionContextBlocks
- ProjectSources
- SourceChunks
- ChunkEmbeddings
- EmbeddingJobs
- PromptCompilations
- MemoryWriteEvents
- ToolCalls
- FileVersions
- LintAttempts
- ChatActivityEvents
- TokenUsage

Las relaciones utilizan índices y claves foráneas para mantener aislamiento entre usuarios, proyectos, sesiones y recursos.

---

## Security Principles

El proyecto aplica diferentes mecanismos de seguridad según el componente:

- sesiones autenticadas;
- validación de propiedad de recursos;
- consultas preparadas;
- protección CSRF en operaciones de escritura;
- validación de scopes;
- aislamiento por usuario;
- separación de configuración y credenciales;
- control de acceso sobre proyectos y sesiones.

Las credenciales privadas no deben almacenarse en el repositorio.

---

## Planned Portable Architecture

Antes de considerar una distribución estable, el proyecto está evolucionando hacia una arquitectura portable y orientada a objetos.

Objetivos:

```
Environment configuration
        ↓
Single application bootstrap
        ↓
Composer / PSR-4
        ↓
Infrastructure abstractions
        ↓
Domain services
        ↓
Thin HTTP controllers
```

La instalación final deberá poder utilizar configuración externa para:

- Application URL
- Database
- AWS region
- AWS credentials / IAM role
- S3
- AI models
- Storage

sin modificar el código fuente.

---

## Roadmap

### Completed / Implemented

- [x] Persistent sessions
- [x] Project context
- [x] Procedural memory
- [x] Selective Q&A memory
- [x] Semantic embeddings
- [x] Project RAG
- [x] Attachment retrieval
- [x] Memory Context Router
- [x] Context Builder
- [x] Context Ranking
- [x] Memory Writer
- [x] Pipeline feature flags
- [x] Fault-tolerant Prompt Compiler
- [x] Operational tracing
- [x] Q&A trace explorer
- [x] Execution graph
- [x] Memory/RAG graph integration
- [x] Live memory node editing
- [x] 2D visualization
- [x] 3D visualization
- [x] Token and cost observability
- [x] Phase 8: persistent Task Orchestrator
- [x] Basic Task Center for inspection and HITL operations

### Current roadmap

- [x] **Phase 9 — Closed:** Task Center 2.0 provides navigation, search, combined filters, pagination, operational List/Board views, visible priority and dates, waits, HITL actions, direct/inverse dependency management, owned history, executions, artifacts, and chat/trace navigation. Phase 9F completed accessibility, loading/error feedback, responsive and security hardening without database changes.
- [x] **Phase 10 — Closed:** 10A–10F deliver UTC one-shot scheduling, owned rescheduling, executable manual Tasks, durable daily/weekly recurrence, bounded materialization in the existing Worker, and owned recurrence administration in Task Center. The final pre-merge audit passed all available PHP/JS suites; isolated MySQL E2E remains explicitly pending because `TASK_TEST_DB_*` was unavailable. Event triggers, generic Automation Rules and autonomy remain outside Phase 10.
- [x] **Phase 11 — Closed / merged in PR #69:** operational autonomy reuses the existing Planner, Model/Tool Steps, Worker, shared inference runtime, Memory/RAG and HITL boundaries. It includes Project policies and budgets, bounded cycles and continuations, NextWork/Proposals, ASK_USER, versioned replanning, Task Center observability and HITL controls, and final hardening.
- [ ] **Phase 12 — Current:** **12A PASS** hardens public HTTP error safety. **12B closure/hardening is implemented as a merge candidate:** the schema chain is versioned through 14 migrations, generated-column compatibility is reconciled for MySQL 8, GLOBAL/USER AI configuration is clean-installable, privileged operations use DB-backed `system_role`, first/ordinary users are provisioned through CLI, destructive runtime reset is CLI-only and fail-closed, Task retry/heartbeat/model provenance are hardened, and manual Task results are durably linked to Chat history. Production EC2 proved the Worker → Bedrock → completed Task path; isolated destructive MySQL certification still requires `TASK_TEST_DB_*` and must remain reported as SKIP/NOT RUNNABLE when those credentials are absent. Phase 12 remains open for final external certification/release operations.

  Closure evidence and the exact implemented/pending boundary are documented in `michat/doc/fase12b-closure-audit.md`.

---

## Task Orchestrator

The platform includes supervised and automatic persistent Tasks, validated multi-step plans, same-owner Task-to-Task dependencies, durable workers and an integrated Task Center.

The objective is to evolve from:

```
Ask
 ↓
Answer
```

toward:

```
Objective
   ↓
Task
   ↓
Plan
   ↓
Steps
   ↓
Dependencies
   ↓
Models / Tools
   ↓
Results
   ↓
Memory
   ↓
Trace
```

This allows workflows to preserve structured state independently from individual chat messages; human approval remains authoritative when automatic execution is disabled.

```mermaid
flowchart LR
  UI[Chat / Task Center] --> API[Task Application API]
  API --> DB[(MySQL source of truth)]
  DB --> Worker[Leased Task Worker]
  Worker --> Model[Amazon Bedrock]
  Model --> Gate{Tool HITL gate}
  Gate -->|read| Tools[Tool Registry]
  Gate -->|write proposal| DB
  UI -->|approve exact fingerprint| API
  API --> DB
  DB -->|new Execution| Worker
  Tools --> Artifacts[ToolCalls / Artifacts / FileVersions]
  Worker --> Trace[Events / Trace / TokenUsage]
```

Write-capable tools pause durably and expose only a safe proposal. Approval is bound to the persisted fingerprint and consumed at most once by a new Execution. Task-level model, tool, write, token and duration limits are enforced server-side. Durable retry limits remain in MySQL.

Open `michat/task_center.php` to inspect owned Tasks, Steps, pending approvals, errors, artifacts and traces; cancel or retry eligible Tasks; and approve or reject the correct HITL contract without sending internal IDs.

---

## Requirements

The current implementation is based on approximately:

- PHP 8.1 or newer
- MySQL 8.0.16 or newer (JSON, generated columns, enforced `CHECK` constraints and `SKIP LOCKED` are used). This is the supported contract; real MySQL E2E remains environment-dependent and must not be inferred from static checks.
- JavaScript
- Composer
- AWS SDK for PHP
- Amazon Bedrock access
- Amazon S3

- PHP extensions: `mysqli`, `json`, `mbstring`, `curl`, `openssl` and `fileinfo`
- A web server capable of serving PHP (Apache or nginx with PHP-FPM)

---

## Installation

```bash
git clone https://github.com/jimmybackend/michat.git
cd michat

# Optional for local/simple deployments.
# Production may inject the same variables through PHP-FPM/systemd instead.
cp .env.example .env

# Choose a deployment-specific name. "michat" is only an example.
DB_NAME=michat
# Set the same DB_NAME in the effective environment together with
# DB_HOST/PORT/USER/PASSWORD.

composer install --no-dev --prefer-dist --optimize-autoloader

mysql -u root -p -e "CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"
mysql -u root -p "$DB_NAME" < adbbmis1_Cloud.sql
test "$?" -eq 0

# Record that this clean dump already represents the complete 14-migration schema.
php michat/bin/migrations.php baseline --profile=current-dump
```

`DB_NAME` is selected by each deployment. The schema dump does not create or select a database; it imports into the database already selected by the MySQL client. Configure the application with that same `DB_NAME` before opening the web UI or starting the Worker. A zero import exit code is required. The static clean-install contract is covered in-repo, while a real clean import remains `SKIP`/not certified until isolated `TASK_TEST_DB_*` credentials are provided.

`adbbmis1_Cloud.sql` is the canonical clean-install dump. `adbbmis1_Cloud-final.sql` is a production snapshot retained as reconciliation/audit evidence and may contain deployment-specific database metadata or runtime rows; do not use it as the portable installation source.

MiChat carga el archivo `.env` de la raíz cuando existe. Las variables ya
inyectadas por el proceso, PHP-FPM, Apache, EC2 o systemd tienen prioridad y no
son sobrescritas por el archivo. Por tanto, `.env` es una opción de despliegue,
no un requisito para producción.

Configure the effective environment with the variables required by the deployment:

```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=
DB_USER=
DB_PASSWORD=

AWS_REGION=
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_SESSION_TOKEN=
AWS_S3_BUCKET=
MICHAT_MAINTENANCE_SECRET=

# Optional deployment-path overrides
MICHAT_ENV_FILE=
MICHAT_VENDOR_AUTOLOAD=
MICHAT_CONFIG_FILE=
MICHAT_DB_BOOTSTRAP=
```

Do not commit a real `.env`, database credentials, AWS credentials or production
configuration files. If the EC2 instance uses an IAM role, long-lived
`AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` values should normally be omitted
and the AWS SDK credential provider chain should resolve the instance role.

`MICHAT_ENV_FILE` can point CLI/bootstrap loading at a private environment file.
`MICHAT_VENDOR_AUTOLOAD`, `MICHAT_CONFIG_FILE` and `MICHAT_DB_BOOTSTRAP`
override deployment paths without editing source. If unset, MiChat tries the
portable checkout layout first and then the validated EC2 fallbacks.

### Environment delivery in production

The application supports separating runtime configuration from the source tree.
The important contract is that the web process and the Task Worker receive the
same effective DB/AWS configuration; they do not need to receive it through the
same mechanism.

A production deployment can therefore use:

- a non-versioned `.env` outside public access, when appropriate;
- PHP-FPM/Apache environment injection for web requests;
- a systemd `EnvironmentFile` for the Worker;
- private PHP bootstrap/configuration files stored outside the repository when a
  legacy deployment still requires them.

Never reuse an environment file belonging to another application merely because
it is present on the same server.

#### Validated Amazon Linux / EC2 layout

The production layout used to validate the current Fase 12B fixes is:

```text
/var/www/html/chat          application code served by nginx/PHP-FPM
/etc/michat.env             private DB + AWS/Bedrock environment for systemd
/var/www/db-s3.php          private database bootstrap used by the web runtime
/var/www/Config-s3.php      private S3/application configuration for this deployment
/var/www/vendor/            Composer dependencies in this EC2 layout
```

Those absolute paths describe this validated EC2 deployment; they are not secret
values and are not intended to force every installation to use the same directory
layout. The private files themselves must remain outside Git and must have
restrictive filesystem permissions.

The current EC2 Worker is intentionally started with its environment explicitly
loaded by systemd. Running the PHP command manually as another user does **not**
guarantee that `/etc/michat.env` will be inherited.

For AWS environments, IAM roles should be preferred over long-lived credentials whenever possible.

Grant the runtime identity only the Bedrock model invocation and S3 bucket/object actions required by the configured agents and bucket. Do not grant blanket administrator access. The S3 bucket must remain private.

Point the web-server document root at the repository directory (or map `/michat` explicitly), deny access to `.env`, and allow the PHP user to write only application upload/cache locations used by your deployment. Never make the source tree world-writable.

Run a smoke test and the complete isolated test suite:

```bash
php michat/tests/task_api_test.php
for test in michat/tests/*_test.php; do php "$test"; done
```

Database integration tests use the optional `TASK_TEST_DB_*` variables and explicitly report `SKIP` when they are absent.

### Initial user and administrative CLI

A clean database intentionally contains no application user. Create the first
account only after importing the canonical dump and establishing the migration
baseline. The first account is created as `superadmin` only when `Users` is
empty:

```bash
export MICHAT_NEW_USER_PASSWORD='use-a-secret-value-from-your-secret-store'

php michat/bin/create_first_user.php \
  --email=admin@example.com \
  --firstname=Admin \
  --lastname=User \
  --curp=AAAAAAAAAAAAAAAAAA \
  --gender=Otro \
  --role=Administración

unset MICHAT_NEW_USER_PASSWORD
```

For an **existing installation upgraded from a schema that had no
`Users.system_role`**, the migration deliberately does not guess which legacy
account should become superadmin. If there are zero superadmins, bootstrap one
existing active account exactly once by proving that account's password and
supplying an explicit confirmation token:

```bash
export MICHAT_ACTOR_PASSWORD='existing-account-secret'
export MICHAT_BOOTSTRAP_CONFIRM='BOOTSTRAP_SUPERADMIN'

php michat/bin/bootstrap_superadmin.php \
  --email=existing-admin@example.com

unset MICHAT_ACTOR_PASSWORD MICHAT_BOOTSTRAP_CONFIRM
```

The command fails closed once any superadmin exists. It never selects or promotes
a numeric user ID implicitly.

Subsequent users are created by an authenticated active account with
`system.roles.manage`; both passwords are supplied by environment variables,
not command-line arguments:

```bash
export MICHAT_ACTOR_PASSWORD='actor-secret'
export MICHAT_NEW_USER_PASSWORD='new-user-secret'

php michat/bin/create_user.php \
  --actor-email=admin@example.com \
  --email=user@example.com \
  --firstname=Example \
  --lastname=User \
  --curp=BBBBBBBBBBBBBBBBBB \
  --gender=Otro \
  --role=Otros \
  --system-role=user

unset MICHAT_ACTOR_PASSWORD MICHAT_NEW_USER_PASSWORD
```

New users receive the canonical feature/preference profile. GLOBAL AI agent
configuration is inherited dynamically; provisioning does not clone one copy of
the GLOBAL catalog per user. In particular, `task_auto_execute` remains
disabled by default while the Task Orchestrator, asynchronous Worker and Planner
features are enabled in the canonical new-user profile.

There is no destructive web reset endpoint. Inspect a reset plan with:

```bash
php michat/bin/reset_runtime_data.php --dry-run
```

A destructive reset is intentionally restricted to development/test, requires a
superadmin-equivalent `system.reset` permission, an explicit confirmation token
and `--hard`. Production reset is refused.

### Worker

Run one job while validating an installation:

```bash
php michat/bin/task_worker.php --once
```

For production, supervise the durable loop with systemd or Supervisor rather than cron:

```ini
[Service]
WorkingDirectory=/var/www/html/chat
ExecStart=/usr/bin/php /var/www/html/chat/bin/task_worker.php --loop
Restart=always
User=apache
Group=apache
EnvironmentFile=/etc/michat.env
```

The service example above matches the validated Amazon Linux/EC2 deployment.
Other distributions may use a different PHP-FPM/web user, working directory or
environment-file path; adapt those deployment-specific values without committing
secrets.

Use a unique `TASK_WORKER_ID` per process. Lease expiry and recovery are handled by the worker; do not run overlapping cron invocations with the same worker identity.

---

## Architecture Philosophy

The project follows several design principles.

### Memory is not the prompt

Persistent knowledge should be stored independently and retrieved only when relevant.

### Retrieval should be selective

Semantic similarity alone is not always sufficient. Intent, scope, memory type and ranking should participate in context selection.

### Auxiliary AI must fail safely

Failure of a compiler, memory operation or retrieval subsystem should not unnecessarily prevent the main conversation from continuing.

### Historical traces are immutable

The system should distinguish:

> What the AI used then

from:

> What the database contains now

### Observability is part of the architecture

A production AI application should make it possible to inspect:

- what happened
- what information was selected
- what model was called
- what tools were executed
- how long it took
- how much it cost

---

## Project Status

This project is release-ready for controlled public deployments and remains under active development.

Review model access, IAM permissions, database backups and worker supervision before exposing a deployment to users. External AWS/MySQL E2E validation is environment-specific.

---

## Contributing

Contributions, discussions and technical feedback are welcome.

Before submitting significant changes, consider opening an issue describing:

- the problem;
- the proposed architecture;
- compatibility implications;
- database changes;
- security considerations.

Contribution guidelines will be expanded as the project approaches its first stable release.

---

## Security

Do not publish:

- AWS access keys;
- database passwords;
- production ".env" files;
- S3 secrets;
- private user information;
- production database dumps.

Security issues should not be disclosed publicly before maintainers have had an opportunity to review them.

A dedicated "SECURITY.md" is recommended for the public repository.

---

## License

This project is released under the MIT License.

You may use, modify, distribute and incorporate the software into other projects subject to the terms of the MIT License.

Third-party libraries and external services retain their respective licenses and terms of use.

See:

- LICENSE

for the complete license text.

---

## Disclaimer

This software is provided as an open source project for development, experimentation and integration of AI-assisted systems.

Model outputs may be inaccurate.

Users and deployers are responsible for validating generated content, securing credentials, configuring permissions and reviewing AI-generated modifications before using the software in production.
