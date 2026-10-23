# Pipeline automático de conocimiento para adjuntos

Actualizado: 2026-09-09/10.

## Objetivo

Cuando un usuario sube un archivo a una conversación, el archivo no debe quedar únicamente almacenado en S3. Debe quedar preparado para responder preguntas sobre su contenido utilizando el RAG de adjuntos existente.

El pipeline distingue dos rutas:

- documentos/texto/código -> extracción + chunks + resumen semántico;
- imágenes soportadas -> visión multimodal + `visual_summary`.

Ambas rutas terminan reutilizando `SessionContextBlocks`, `embedding_main`, `EmbeddingJobs` y `AttachmentContextRepository`.

## Documentos, texto y código

Después de registrar el archivo en `FileS3`, `session_upload.php` delega la preparación en `SessionAttachmentKnowledgeService`:

1. extracción de texto con `session_file_extractor.php`;
2. creación de `SessionContextBlocks.block_type='file_chunk'`;
3. embedding inmediato con `embedding_main` usando `search_document`;
4. creación de resumen semántico `block_type='file'` con `attachment_semantic_prompt` + `smart_memory_general` o `smart_memory_code`;
5. embedding inmediato del resumen;
6. conservación de `EmbeddingJobs` como retry si Bedrock no puede vectorizar en ese momento.

Los botones manuales **Indexar** y **Semántica** reutilizan el mismo servicio, por lo que son reintentos explícitos y no implementaciones paralelas.

## Imágenes

Desde PR #79, JPG/JPEG, PNG, WEBP y GIF se enrutan a `SessionImageKnowledgeService`.

El flujo visual:

1. valida ownership de sesión y FileS3;
2. lee la imagen privada desde S3;
3. valida tamaño y dimensiones;
4. ejecuta Bedrock Converse con entrada multimodal;
5. extrae descripción visual, texto visible, estructura y contenido técnico útil;
6. persiste `SessionContextBlocks.block_type='file'` con metadata `type='visual_summary'`;
7. genera/encola embedding con `embedding_main`;
8. deja el resumen visual disponible para el mismo RAG de adjuntos.

No existe una tabla ni un retriever paralelo para imágenes.

La configuración visual se controla mediante `attachment_vision` en **Preferencias -> Modelos IA**. Los modelos ofrecidos son Amazon Nova Lite, Nova Pro y Nova Premier.

Más detalle: `michat/doc/attachment-image-vision-rag.md`.

## Recuperación en respuestas

`ContextBuilder` + `AttachmentContextRepository` continúan siendo el consumidor autoritativo.

En modo RAG, la consulta compara el embedding de la pregunta con bloques `file` y `file_chunk` de la sesión y selecciona únicamente contenido relevante dentro de los límites configurados. Los `visual_summary` participan como cualquier otro bloque `file`.

En modo `always`, el pipeline puede incluir bloques de adjuntos aun sin similitud semántica, respetando los límites de contexto.

La consecuencia importante es que un documento o una imagen no se reenvían completos en cada pregunta. Después de su preparación se recuperan los chunks/resúmenes relevantes, reduciendo contexto y consumo frente a reenviar el archivo entero repetidamente.

## Embeddings y mantenimiento

`Preferencias > Mantenimiento > Procesar Embeddings` continúa sirviendo para reintentar jobs pendientes de adjuntos, memoria y proyecto.

Si una extracción/resumen se guarda pero Bedrock falla al vectorizar, `EmbeddingJobs` conserva el trabajo pendiente.

`Comprimir Sesiones` sigue siendo un proceso separado para memoria jerárquica de conversación y no sustituye el pipeline de adjuntos.

## Telemetría

El análisis visual usa el runtime de IA y su consumo se integra con la telemetría existente. La fase funcional de visión se normaliza a la categoría histórica `rag` para ser compatible con el esquema actual de `TokenUsage`.

## Base de datos

Los cambios de PR #77, #79, #80 y #81 relacionados con este pipeline no requieren una migración SQL nueva. Reutilizan las tablas y contratos existentes.

## Pruebas de contrato

Entre las pruebas que protegen este flujo se encuentran:

- `michat/tests/session_attachment_auto_knowledge_test.php`;
- `michat/tests/session_attachment_image_vision_test.php`;
- `michat/tests/attachment_vision_preferences_test.php`.


## Operación desde Chat (2026-10-23)

- **Clip inferior**: abre el selector, crea sesión cuando falta y usa `session_upload.php` para guardar objetos privados en S3 bajo `Data/Chat/Uploads/{user}/{YYYY}/{MM}/{DD}/{session}/`. El proyecto vinculado se obtiene de `ChatSessions.project_id_`, no se confunde con los ficheros del proyecto.
- **🧠 Conocimiento de archivos**: recupera estado desde `chat2_session_files.php` y muestra nombre, chunks, embeddings y semántica; permite reintentar indexación (incluye semántica), volver a solicitar semántica, preguntar sobre un documento y comparar dos archivos desde el mismo chat.
- **Preguntas explícitas** ("qué dice el archivo", explicación, comparaciones): el `ContextBuilder` fuerza recuperación del índice propio de la sesión cuando el Router la omite. Si el embedding de consulta no existe o no hay una coincidencia semántica, utiliza de forma acotada fragmentos indexados; jamás supone que un archivo en S3 ya fue analizado.
- **Revisiones de código de un chat libre**: botón "nueva versión" sube un archivo corregido a un objeto S3 distinto, guardando `version_of_files3_id` y `revision_number` en `FileS3.Metadatos`, sin sobrescribir ni destruir el anterior. Para archivos de proyecto, `FileVersions` continúa siendo la tabla de auditoría del flujo `code_edit` y requiere `project_id_`.
- **Límites**: el extractor procesa hasta `IDX_MAX_EXTRACTED_CHARS` (actualmente 200.000 caracteres) y divide en chunks de tamaño acotado. El análisis de imagen requiere el modelo visual activo. Si `embedding_main`, IA semántica o AWS no están disponibles, la subida a S3 puede completarse mientras el análisis queda parcial o pendiente. No reportar una indexación completa hasta comprobar el estado; los jobs de `EmbeddingJobs` se procesan desde Mantenimiento.
- **Comparación/edición**: el modelo responde basándose en fragmentos disponibles; no modifica automáticamente código en S3 tras sugerir mejoras. Para guardar la revisión se debe subir el archivo revisado o usar el flujo de edición/versiones autorizado en un proyecto.
