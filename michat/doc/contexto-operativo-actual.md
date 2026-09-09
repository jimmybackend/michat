# Contexto operativo actual de MiChat

Actualizado: 2026-09-09 (America/Merida).

Este documento es la referencia corta para retomar trabajo en un chat nuevo o después de perder contexto. Antes de asumir rutas, estado de despliegue, límites entre Chat/Tasks o comportamiento de adjuntos, revisar este archivo junto con el código real de `main`.

## Repositorio

- Repositorio: `jimmybackend/michat`.
- Rama de integración: `main`.
- El último bloque funcional fusionado antes de este documento fue PR #79: `feat: analizar imágenes adjuntas y reutilizarlas vía RAG`.
- PR #77: adjuntos de sesión se preparan automáticamente para RAG y semántica.
- PR #78: corrige el falso `validation_failed` en Tasks simples y añade refresco automático de Task Center cada 5 segundos.
- PR #79: añade análisis multimodal de imágenes adjuntas y reutilización mediante el mismo RAG existente.

## EC2 real

Ruta del repositorio desplegado:

`/var/www/michat`

No usar `/var/www/html/chat` como ruta del repo.

Dominio público:

- `https://chat.esforzados.com`
- `https://www.chat.esforzados.com`

Configuración de entorno:

`/etc/michat.env`

Permisos esperados:

- propietario/grupo: `root:apache`
- modo: `0640`
- debe ser legible por Apache.

Composer/vendor se instala después del clone cuando sea necesario.

## Worker persistente de Tasks

Unit systemd:

`/etc/systemd/system/michat-task-worker.service`

Worker real:

`/usr/bin/php /var/www/michat/michat/bin/task_worker.php --loop`

Usuario/grupo del proceso: `apache:apache`.

El servicio debe estar:

- `enabled`
- `active`

Comandos de comprobación:

```bash
sudo systemctl is-enabled michat-task-worker.service
sudo systemctl is-active michat-task-worker.service
ps aux | grep '[t]ask_worker.php'
```

## Cambios locales conocidos en EC2

Se han observado cambios locales que NO deben destruirse automáticamente:

- `michat/bin/migrations.php` modificado;
- `vendor/autoload.php` modificado;
- directorios `vendor/*` no trackeados tras Composer.

No usar a ciegas:

- `git reset --hard`
- `git clean -fd`

Antes de cualquier operación destructiva, revisar `git status`.

## Límite de producto: Chat vs Tasks

- `michat/chat.php` es conversación normal.
- `michat/task_center.php` es la superficie explícita de Tasks/trabajo orquestado.
- Una pregunta normal del chat NO debe crear automáticamente una Task.
- Tasks se crean/gestionan desde Task Center o por acciones explícitas del dominio Task.
- El Worker y HTTP comparten el runtime central de ejecución.

## Task Center y Worker — estado validado

El Worker persistente ya fue probado ejecutando Tasks reales.

Problema corregido en PR #78:

- antes, una Task simple podía completar el Step `model` y luego fallar falsamente en `validation` porque el Planner no transportaba `check/path` ejecutables;
- los planes simples de respuesta compuestos solo por `model/validation/finalize` se normalizan a un Step `model` ejecutable;
- la validación técnica real no se debilitó;
- Task Center tiene una capa de polling cada 5 s (`michat/js/task-center-live.js`) mientras existan estados no terminales, sin interrumpir edición del usuario.

Pruebas añadidas y validadas:

- `task_planner_executable_contract_test.php`
- `task_center_live_refresh_test.php`

## Adjuntos de sesión — documentos

PR #77 dejó automático el flujo de conocimiento al subir un archivo:

1. archivo se registra en S3/FileS3;
2. extracción textual;
3. división en `file_chunk`;
4. embeddings con `embedding_main`;
5. resumen semántico `block_type='file'`;
6. embedding del resumen;
7. disponibilidad inmediata para RAG cuando la vectorización termina correctamente.

Formatos de texto/código incluyen, entre otros:

- PHP, JS, HTML, JSON, SQL, TXT, MD, CSV, Python, XML, YAML, logs.

Documentos extraíbles incluyen, entre otros:

- PDF, DOCX, XLSX, PPTX, ODT/ODS/ODP, RTF, EPUB y formatos Office antiguos mediante conversores disponibles.

El RAG no reinyecta el archivo completo en cada pregunta. Compara embeddings de la consulta contra `file`/`file_chunk`, selecciona solo bloques relevantes y limita el contexto. Esto reduce tokens de entrada frente a reenviar el documento completo repetidamente.

## Adjuntos de sesión — imágenes

PR #79 amplió el mismo pipeline para imágenes:

Formatos soportados por el servicio visual:

- JPG/JPEG
- PNG
- WEBP
- GIF

Flujo:

1. imagen se guarda en S3/FileS3;
2. `SessionImageKnowledgeService` valida ownership y formato;
3. Bedrock Converse realiza análisis multimodal;
4. se extrae descripción visual, texto visible y contenido útil como tablas, código, errores, diagramas o estructura;
5. se guarda un `SessionContextBlocks.block_type='file'` con metadata `type='visual_summary'`;
6. `embedding_main` vectoriza el resumen;
7. el RAG de adjuntos existente recupera ese conocimiento en preguntas posteriores.

No se creó un retriever paralelo ni tablas nuevas para imágenes.

El agente opcional `attachment_vision` puede controlar modelo, activación e instrucciones. Si no existe, el servicio usa un fallback multimodal compatible.

Límites preventivos del flujo visual: tamaño y dimensiones se validan antes de llamar a Bedrock para evitar fallos opacos.

## Base de datos y pruebas

Para los PR #77, #78 y #79:

- no hubo migraciones SQL nuevas;
- no fue necesario cambiar el esquema para esos bloques;
- CI de GitHub Actions pasó en los PR recientes antes del merge.

Durante pruebas manuales, el operador suele limpiar los datos runtime de prueba de la BD y volver a crear la pregunta/Task desde cero para evitar interpretar residuos de ejecuciones fallidas anteriores. No confundir esta práctica de testing con una necesidad de migración.

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

Si `git pull --ff-only` falla por cambios locales, NO limpiar el árbol automáticamente. Revisar primero `git status` y resolver solo los paths en conflicto.

## Pendientes arquitectónicos conocidos

No confundir los hotfixes recientes con una extensión completa del contrato de Steps. Sigue siendo trabajo futuro razonable:

- inputs tipados para Steps planificados (`validation`, `tool`, `wait`);
- chaining explícito de outputs entre múltiples Steps `model` cuando se necesite;
- revisar el etiquetado de resultados cuando una Task falla después de producir una salida intermedia;
- revisar la presentación de zonas horarias en Task Center si reaparece inconsistencia entre UTC y hora local.

## Regla de continuidad

Cuando se abra un chat nuevo para trabajar en MiChat:

1. leer este archivo;
2. verificar HEAD real de `main`;
3. verificar `git status` del EC2 antes de sugerir comandos destructivos;
4. conservar separación Chat/Task Center;
5. no asumir que una fase histórica describe mejor el sistema que el código y este estado operativo.