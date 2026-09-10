# Adjuntos de imagen: visión + RAG

Actualizado: 2026-09-09/10.

MiChat puede convertir imágenes adjuntas de una sesión en conocimiento textual recuperable sin reenviar la imagen completa en cada pregunta.

## Formatos

- JPG / JPEG
- PNG
- WEBP
- GIF

El flujo valida preventivamente los límites de Bedrock Converse antes y después de recuperar el objeto desde S3. La política actual protege un máximo de 3.75 MiB por imagen y 8000 px por dimensión.

## Flujo

`session_upload.php` conserva el upload normal a S3/FileS3. Si el archivo es una imagen soportada, delega la preparación de conocimiento en `SessionImageKnowledgeService`:

1. valida ownership de sesión y FileS3;
2. lee la imagen privada desde S3;
3. valida tamaño, formato y dimensiones;
4. ejecuta una inferencia multimodal mediante Bedrock Converse;
5. solicita descripción visual, texto visible, objetos/datos, estructura, tablas, código, errores, diagramas y términos clave cuando existan;
6. persiste el resultado en `SessionContextBlocks` como `block_type='file'` y metadata `type='visual_summary'`;
7. encola y trata de crear inmediatamente el embedding con `embedding_main`;
8. el RAG de adjuntos existente recupera ese bloque junto con resúmenes/chunks documentales.

No se añade una tabla ni un retriever nuevo.

## Configuración `attachment_vision`

Desde PR #80, la visión se administra desde **Preferencias -> Modelos IA -> Visión de imágenes adjuntas**.

El usuario puede:

- activar/desactivar `attachment_vision`;
- elegir el modelo visual efectivo.

Modelos Amazon ofrecidos por la UI:

- `amazon.nova-lite-v1:0`;
- Amazon Nova Pro compatible configurado por la aplicación;
- Amazon Nova Premier compatible configurado por la aplicación.

Nova Micro no se ofrece porque es texto-only. Nova Canvas tampoco se usa para comprensión visual porque su función es generación de imágenes.

La primera escritura puede provisionar de forma idempotente la configuración GLOBAL fija si todavía no existe; después se guarda el override USER. El runtime efectivo conserva la regla USER -> GLOBAL.

## Fallback

Si no existe una configuración efectiva `attachment_vision`, el servicio intenta reutilizar `chat_main` únicamente cuando el modelo parece multimodal. Si `chat_main` es texto-only, el fallback integrado es Nova Lite.

Una configuración `attachment_vision` explícitamente inactiva detiene la etapa visual.

## Tokens y telemetría

La inferencia visual ocurre al preparar la imagen. Las preguntas posteriores no necesitan reenviar sus bytes: comparan la pregunta contra el embedding del `visual_summary` y solo inyectan el contexto textual relevante seleccionado por RAG.

Desde PR #81, el consumo de `attachment_vision` se registra mediante la telemetría existente. La etiqueta funcional `vision` se normaliza a la fase histórica `rag` para ser compatible con el ENUM actual de `TokenUsage`, sin requerir migración SQL.

## Reintentos

Los botones manuales **Indexar** y **Semántica** reconocen imágenes y vuelven a ejecutar la preparación visual. Si el embedding falla después de guardar el resumen, `EmbeddingJobs` queda disponible para el proceso de mantenimiento de embeddings.

## Relación con generación de imágenes

`attachment_vision` analiza imágenes existentes; no genera imágenes nuevas.

La generación se controla por separado mediante `image_main`, que actualmente permite Amazon Titan Image Generator v2 y Nova Canvas. Ambas modalidades comparten autenticación, configuración AWS y almacenamiento S3, pero cumplen funciones diferentes.

## Pruebas

El contrato está protegido por:

- `michat/tests/session_attachment_image_vision_test.php`;
- `michat/tests/attachment_vision_preferences_test.php`;
- integración indirecta en las pruebas de TokenUsage/runtime multimodal.

## Base de datos

PR #79, #80 y #81 no añadieron tablas ni migraciones SQL para visión. El flujo reutiliza `FileS3`, `SessionContextBlocks`, `EmbeddingJobs`, embeddings y `TokenUsage` existentes.
