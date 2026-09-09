# Pipeline automático de conocimiento para adjuntos

## Objetivo

Cuando un usuario sube un archivo a una conversación, el archivo no debe quedar únicamente almacenado en S3. Debe quedar preparado para responder preguntas sobre su contenido.

## Flujo

`session_upload.php` ejecuta, después de registrar el archivo en `FileS3`:

1. extracción de texto con `session_file_extractor.php`;
2. creación de `SessionContextBlocks.block_type='file_chunk'`;
3. embedding inmediato con `embedding_main` usando `search_document`;
4. creación de resumen semántico `block_type='file'` con `attachment_semantic_prompt` + `smart_memory_general` o `smart_memory_code`;
5. embedding inmediato del resumen semántico;
6. conservación de `EmbeddingJobs` como mecanismo de retry si Bedrock no puede vectorizar en ese momento.

Los botones manuales **Indexar** y **Semántica** usan el mismo `SessionAttachmentKnowledgeService`, por lo que son reintentos explícitos del mismo pipeline y no implementaciones distintas.

## Recuperación en respuestas

El pipeline existente de `ContextBuilder` + `AttachmentContextRepository` continúa siendo el consumidor. En modo RAG compara el embedding de la pregunta con bloques `file` y `file_chunk` del modelo `embedding_main` vigente. En modo `always` puede utilizar los bloques aun sin embedding.

## Mantenimiento

`Preferencias > Mantenimiento > Procesar Embeddings` continúa sirviendo para reintentar jobs pendientes de adjuntos, memoria y proyecto. `Comprimir Sesiones` sigue siendo un proceso separado para la memoria jerárquica de conversación.

No se modifica el esquema SQL.
