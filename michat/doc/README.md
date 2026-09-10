# Índice de documentación de MiChat

Actualizado: 2026-09-09/10.

Este directorio contiene documentación operativa actual y documentos históricos de las fases de desarrollo. No todos los archivos tienen la misma autoridad.

## Documentos actuales

### `contexto-operativo-actual.md`

Referencia corta para abrir un chat nuevo o retomar trabajo en EC2. Debe consultarse junto con el código real de `main`.

Incluye:

- repositorio y rama;
- layout EC2;
- Worker systemd;
- frontera Chat/Tasks;
- adjuntos y RAG;
- visión;
- imagen;
- voz;
- video;
- precauciones de despliegue;
- pendientes arquitectónicos conocidos.

### `chat-task-boundary.md`

Describe la frontera que impide que una pregunta normal del Chat se materialice automáticamente como Task, manteniendo Task Center/Worker independientes.

### `attachment-knowledge-pipeline.md`

Describe preparación automática de documentos e imágenes adjuntas, embeddings, resumen semántico, retry y recuperación mediante Attachment RAG.

### `attachment-image-vision-rag.md`

Describe el análisis multimodal de JPG/JPEG, PNG, WEBP y GIF, `attachment_vision`, `visual_summary`, embeddings y reutilización por RAG.

### `multimodal-media-aws.md`

Describe las modalidades Amazon actuales:

- `attachment_vision`;
- `image_main`;
- `voice_main`;
- `video_main`.

Incluye modelos, S3, telemetría, Node/Nova 2 Sonic y Nova Reel.

## Arquitectura general fuera de este directorio

### `../../README.md`

Presentación principal, instalación, arquitectura, requisitos, EC2, Task Orchestrator y capacidades multimodales.

### `../../docs/CHAT.md`

Arquitectura actual del núcleo conversacional.

### `../../docs/TASK.md`

Arquitectura actual del Task Orchestrator, Worker, scheduling, recurrencia, HITL y autonomía.

### `../../docs/MCMA.md`

Documento del proyecto/visión MCMA. Debe mantenerse separado del estado real de MiChat: no asumir integraciones MCMA que todavía no estén implementadas y verificadas.

## Documentos históricos de fases

Los archivos `fase*.md`, auditorías y documentos de cierre preservan decisiones y evidencia de una etapa concreta. Son útiles para entender por qué existe una parte del sistema, pero no deben usarse como única fuente para afirmar el comportamiento actual.

Ejemplos:

- `fase8-*` — evolución inicial del Task Orchestrator;
- `fase9-*` — Task Center, navegación, relaciones e historial;
- `fase10-scheduling-automation.md` — scheduling y recurrencia;
- `fase11-*` — autonomía, single-turn inference, observabilidad y hardening;
- `fase12b-*` — migraciones, MySQL, roles, multiusuario y readiness.

`estado-actual.md` es un registro histórico extenso que conserva estado anterior. Para continuidad diaria debe preferirse `contexto-operativo-actual.md`.

## Regla de autoridad

Cuando dos documentos parezcan contradecirse, usar este orden:

1. código fusionado en `main`;
2. `contexto-operativo-actual.md`;
3. README / `docs/CHAT.md` / `docs/TASK.md`;
4. documento específico del componente;
5. documentos históricos de fase.

Antes de despliegues, verificar siempre:

```bash
git rev-parse HEAD
git status --short
```

No ejecutar `git reset --hard` o `git clean -fd` sobre un EC2 con cambios locales conocidos sin revisar primero el árbol de trabajo.
