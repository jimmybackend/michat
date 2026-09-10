# Contexto operativo actual de MiChat

Actualizado: 2026-09-09/10 (America/Merida).

Este documento es la referencia corta para retomar trabajo en un chat nuevo o después de perder contexto. Antes de asumir rutas, estado de despliegue, límites entre Chat/Tasks o comportamiento multimodal, revisar este archivo junto con el código real de `main`.

## Repositorio

- Repositorio: `jimmybackend/michat`.
- Rama de integración: `main`.
- El HEAD debe verificarse con `git rev-parse HEAD`; no fijar este documento a un SHA que cambiaría cada vez que se actualiza documentación.
- El último bloque funcional de aplicación documentado aquí es PR #83: generación de video con Amazon Nova Reel.

Bloques recientes fusionados:

- PR #75: separa chat normal de Tasks y restaura limpieza runtime autorizada para superadmin.
- PR #76: preserva reanudación explícita de una Task ya aprobada después de separar Chat/Tasks.
- PR #77: adjuntos de sesión se preparan automáticamente para RAG y semántica.
- PR #78: corrige falso `validation_failed` en Tasks simples y añade refresco automático de Task Center cada 5 segundos.
- PR #79: análisis multimodal de imágenes adjuntas y reutilización mediante el RAG existente.
- PR #80: expone `attachment_vision` en Preferencias con modelos Amazon compatibles.
- PR #81: añade voz turn-based con Amazon Nova 2 Sonic y registra uso multimodal.
- PR #82: completa generación de imágenes con `image_main`, Amazon Titan Image Generator v2/Nova Canvas y S3.
- PR #83: completa generación de video con `video_main`, Amazon Nova Reel, Bedrock async y S3.

## EC2 real

Ruta del repositorio desplegado:

```text
/var/www/michat
```

No usar `/var/www/html/chat` como ruta del repo actual.

Dominio público usado en el despliegue de desarrollo:

- `https://chat.esforzados.com`
- `https://www.chat.esforzados.com`

Configuración de entorno:

```text
/etc/michat.env
```

Permisos esperados en la instalación actual:

- propietario/grupo: `root:apache`;
- modo: `0640`;
- debe ser legible por Apache y por el Worker.

Composer/vendor se instala después del clone cuando sea necesario.

## Worker persistente de Tasks

Unit systemd:

```text
/etc/systemd/system/michat-task-worker.service
```

Worker real:

```text
/usr/bin/php /var/www/michat/michat/bin/task_worker.php --loop
```

Usuario/grupo del proceso: `apache:apache`.

El servicio debe estar:

- `enabled`;
- `active`.

Comprobación:

```bash
sudo systemctl is-enabled michat-task-worker.service
sudo systemctl is-active michat-task-worker.service
ps aux | grep '[t]ask_worker.php'
```

Unit usada en EC2:

```ini
[Unit]
Description=MiChat Task Worker
After=network.target
Wants=network.target

[Service]
Type=simple
User=apache
Group=apache
WorkingDirectory=/var/www/michat
EnvironmentFile=/etc/michat.env
ExecStart=/usr/bin/php /var/www/michat/michat/bin/task_worker.php --loop
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
```

## Cambios locales conocidos en EC2

Se han observado cambios locales que NO deben destruirse automáticamente:

- `michat/bin/migrations.php` modificado;
- `vendor/autoload.php` modificado;
- directorios `vendor/*` no trackeados tras Composer.

No usar a ciegas:

- `git reset --hard`;
- `git clean -fd`.

Antes de cualquier operación destructiva, revisar `git status`.

## Límite de producto: Chat vs Tasks

- `michat/chat.php` es conversación normal.
- `michat/task_center.php` es la superficie explícita de trabajo orquestado.
- Una pregunta normal del Chat NO debe crear automáticamente una Task.
- Task Center, Task API y Worker siguen usando las flags `task_*` persistidas.
- La acción explícita de reanudar una Task aprobada puede recuperar su configuración Task sin reabrir la creación automática desde Chat.
- El Worker y HTTP comparten el runtime central de ejecución.

Referencia: `michat/doc/chat-task-boundary.md`.

## Task Center y Worker — estado validado

El Worker persistente ya fue probado ejecutando Tasks reales contra Bedrock.

PR #78 corrigió dos problemas:

- una Task simple podía completar el Step `model` y luego fallar falsamente en `validation` porque el Planner añadía controles sin `check/path` ejecutables;
- Task Center no reflejaba el avance hasta refrescar manualmente la página.

Estado actual:

- planes simples de respuesta `model/validation/finalize` se normalizan a un Step `model` cuando no existe validación determinista real;
- la validación técnica real no se debilitó;
- `michat/js/task-center-live.js` hace polling cada 5 s mientras existan estados no terminales;
- el polling se pausa con pestaña oculta y evita interrumpir formularios en edición.

Pruebas:

- `task_planner_executable_contract_test.php`;
- `task_center_live_refresh_test.php`.

## Adjuntos de sesión — documentos

PR #77 dejó automático el flujo al subir un documento:

1. archivo se registra en S3/FileS3;
2. extracción textual;
3. división en `file_chunk`;
4. embeddings con `embedding_main`;
5. resumen semántico `block_type='file'`;
6. embedding del resumen;
7. disponibilidad para Attachment RAG.

Formatos de texto/código incluyen PHP, JS, HTML, JSON, SQL, TXT, MD, CSV, Python, XML, YAML y logs.

Documentos extraíbles incluyen PDF, DOCX, XLSX, PPTX, ODT/ODS/ODP, RTF, EPUB y formatos Office antiguos cuando los conversores disponibles lo permiten.

Los botones **Indexar** y **Semántica** reutilizan el mismo servicio y son reintentos explícitos. `EmbeddingJobs` conserva trabajos pendientes si Bedrock falla al vectorizar.

El RAG no reinyecta el archivo completo en cada pregunta. Selecciona bloques `file`/`file_chunk` relevantes y limita el contexto.

Referencia: `michat/doc/attachment-knowledge-pipeline.md`.

## Adjuntos de sesión — imágenes y visión

PR #79 amplió el pipeline para:

- JPG/JPEG;
- PNG;
- WEBP;
- GIF.

Flujo:

1. imagen se guarda en S3/FileS3;
2. `SessionImageKnowledgeService` valida ownership y formato;
3. Bedrock Converse realiza análisis multimodal;
4. se extrae descripción visual, texto visible y contenido como tablas, código, errores, diagramas, objetos o estructura;
5. se guarda un `SessionContextBlocks.block_type='file'` con metadata `type='visual_summary'`;
6. `embedding_main` vectoriza el resumen;
7. Attachment RAG recupera ese conocimiento en preguntas posteriores.

No existe retriever paralelo ni tabla nueva.

PR #80 añadió `attachment_vision` en **Preferencias -> Modelos IA** con activación y modelo por usuario. Modelos visuales ofrecidos:

- Amazon Nova Lite;
- Amazon Nova Pro;
- Amazon Nova Premier.

Nova Micro no se usa para visión porque es texto-only. Nova Canvas pertenece a generación, no análisis visual.

PR #81 conectó el consumo de visión con `TokenUsage`: la fase funcional `vision` se normaliza a la categoría histórica `rag` para mantener compatibilidad con el esquema actual.

Referencia: `michat/doc/attachment-image-vision-rag.md`.

## Generación de imágenes

PR #82 completó la ruta existente de generación de imágenes mediante `image_main`.

Modelos permitidos:

- Amazon Titan Image Generator v2 — default;
- Amazon Nova Canvas — Legacy.

Características:

- modelo resuelto server-side desde configuración GLOBAL/USER;
- el navegador no controla un `model` arbitrario;
- ownership se valida antes de Bedrock/S3;
- generación inicial 1024x1024;
- PNG privado en S3;
- mensaje persistido con `content_type='image'`;
- telemetría registrada sin inventar tokens de texto.

No requiere migración SQL.

## Voz con Amazon Nova 2 Sonic

PR #81 añadió `voice_main`.

Estado actual:

- modelo `amazon.nova-2-sonic-v1:0`;
- voces `lupe` y `carlos`;
- modalidad push-to-talk / turn-based;
- entrada LPCM 16 kHz / 16-bit;
- bridge Node `michat/voice/sonic_turn.mjs`;
- `InvokeModelWithBidirectionalStreamCommand`;
- salida LPCM 24 kHz;
- persistencia de transcripción del usuario y respuesta del asistente en `ChatMessages`;
- tokens reales de Nova 2 Sonic en `TokenUsage`.

Requisito adicional de servidor:

```bash
cd /var/www/michat/michat/voice
npm install --omit=dev
```

Requiere Node.js 20+ si la voz se habilita. `voice_turn.php` busca `MICHAT_NODE_BINARY`, `/usr/bin/node` o `/usr/local/bin/node`.

No requiere migración SQL.

## Generación de video

PR #83 completó `video_main` usando exclusivamente generadores Amazon Nova Reel disponibles en el código actual.

Modelos:

- Amazon Nova Reel 1.1 — principal cuando la región es compatible;
- Amazon Nova Reel 1.0 — fallback regional soportado.

Flujo:

1. prompt del usuario;
2. backend resuelve `video_main` y valida región/modelo;
3. crea placeholder seguro con `AUTO_INCREMENT` real;
4. `StartAsyncInvoke` inicia `TEXT_VIDEO`;
5. Bedrock escribe en S3 privado;
6. el navegador consulta `GetAsyncInvoke` mediante el endpoint de status;
7. `InProgress` se normaliza a `in_progress`;
8. al aparecer `output.mp4`, el mensaje de video queda completado y visible en el historial.

Parámetros del clip simple actual:

- 6 segundos;
- 1280x720;
- 24 fps;
- seed server-side.

Se corrigieron además el `$owner_id` inexistente del endpoint histórico y la carrera basada en `MAX(id_)+1`.

Nova Reel está encapsulado detrás de `VideoGenerationPolicy`/`video_main` porque AWS lo clasifica como Legacy y anunció EOL para 2026-09-30.

No requiere migración SQL ni nuevas dependencias Composer/Node para video.

Referencia conjunta: `michat/doc/multimodal-media-aws.md`.

## Modelos Amazon y política actual

Por ahora la dirección del proyecto es mantener las capacidades de IA principales dentro de Amazon/AWS.

Claves importantes:

- `chat_main`;
- `prompt_compiler`;
- `embedding_main`;
- `smart_memory_general`;
- `smart_memory_code`;
- `attachment_vision`;
- `image_main`;
- `voice_main`;
- `video_main`;
- `task_planner`;
- `next_work_evaluator`.

La configuración efectiva sigue USER override -> GLOBAL -> fallback del componente cuando ese flujo lo permite.

## Base de datos y pruebas

Para PR #77 a #83:

- no hubo migraciones SQL nuevas por adjuntos, visión, voz, imagen o video;
- CI de GitHub Actions pasó antes del merge de los bloques funcionales;
- existen contratos PHP/JS específicos para las capacidades multimodales.

Pruebas recientes importantes:

- `session_attachment_auto_knowledge_test.php`;
- `session_attachment_image_vision_test.php`;
- `attachment_vision_preferences_test.php`;
- `voice_audio_contract_test.php`;
- `image_generation_contract_test.php`;
- `video_generation_contract_test.php`;
- `task_planner_executable_contract_test.php`;
- `task_center_live_refresh_test.php`.

Los E2E aislados de MySQL continúan dependiendo de `TASK_TEST_DB_*`; cuando esas credenciales no existen deben reportarse como `SKIP`, no como certificación real.

Durante pruebas manuales, el operador puede limpiar datos runtime de desarrollo y recrear pregunta/Task para evitar residuos de ejecuciones anteriores. No confundir esa práctica con una necesidad de migración.

## Regla para actualizar EC2 desde main

Usar actualización no destructiva:

```bash
cd /var/www/michat
git fetch origin
git checkout main
git pull --ff-only origin main
sudo systemctl restart michat-task-worker.service
git rev-parse HEAD
sudo systemctl is-active michat-task-worker.service
```

Si `git pull --ff-only` falla por cambios locales, NO limpiar el árbol automáticamente. Revisar primero:

```bash
git status --short
```

Resolver solo los paths en conflicto.

## Pendientes arquitectónicos conocidos

No confundir los hotfixes recientes con una extensión completa de todos los contratos. Sigue siendo trabajo futuro razonable:

- inputs tipados más completos para Steps planificados (`validation`, `tool`, `wait`);
- chaining explícito de outputs entre múltiples Steps `model` cuando sea necesario;
- revisar etiquetado de resultados cuando una Task falla después de producir salida intermedia;
- revisar zonas horarias de Task Center si reaparece inconsistencia UTC/local;
- decidir reemplazo de Nova Reel antes de su EOL si AWS publica un generador Amazon sucesor;
- voz full-duplex continua/barge-in, que no forma parte del modo actual;
- análisis RAG automático de videos subidos, que todavía no está implementado.

## Documentación vigente

- `README.md` — presentación e instalación principal.
- `docs/CHAT.md` — arquitectura real del Chat.
- `docs/TASK.md` — arquitectura real del Task Orchestrator.
- `michat/doc/README.md` — índice y jerarquía de documentación.
- `michat/doc/attachment-knowledge-pipeline.md` — adjuntos documentales y RAG.
- `michat/doc/attachment-image-vision-rag.md` — visión de imágenes.
- `michat/doc/multimodal-media-aws.md` — visión, imagen, voz y video Amazon.

Los documentos `fase*.md` y `estado-actual.md` se conservan como historial. Para continuidad diaria este archivo tiene prioridad sobre esos documentos, después del código real de `main`.

## Regla de continuidad

Cuando se abra un chat nuevo para trabajar en MiChat:

1. leer este archivo;
2. verificar HEAD real de `main`;
3. verificar `git status` del EC2 antes de sugerir comandos destructivos;
4. conservar separación Chat/Task Center;
5. reconocer que documentos e imágenes adjuntas comparten el Attachment RAG después de su preparación;
6. reconocer `image_main`, `voice_main` y `video_main` como modalidades distintas;
7. no asumir que un documento histórico de fase describe mejor el sistema que el código actual.
