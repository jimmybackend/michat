# Adjuntos de imagen: visión + RAG

MiChat puede convertir imágenes adjuntas de una sesión en conocimiento textual recuperable sin reenviar la imagen completa en cada pregunta.

## Formatos

- JPG / JPEG
- PNG
- WEBP
- GIF

El límite aplicado antes de invocar Bedrock Converse es 3.75 MiB por imagen y 8000 px por dimensión.

## Flujo

`session_upload.php` conserva el upload normal a S3/FileS3. Si el nombre del archivo corresponde a una imagen soportada, delega la preparación de conocimiento en `SessionImageKnowledgeService`:

1. valida ownership de sesión y FileS3;
2. lee la imagen desde S3;
3. valida tamaño y dimensiones;
4. ejecuta una sola inferencia multimodal mediante Bedrock Converse;
5. pide descripción visual, texto visible, datos/objetos, estructura, errores y términos clave;
6. persiste el resultado en `SessionContextBlocks` como `block_type='file'` y metadata `type='visual_summary'`;
7. encola y trata de crear inmediatamente el embedding con `embedding_main`;
8. el RAG de adjuntos existente recupera ese bloque junto con los resúmenes/chunks documentales.

No se añade una tabla ni un retriever nuevo.

## Modelo visual

Si existe una configuración efectiva `UserAIAgentConfigs.agent_key='attachment_vision'`, se respetan su `model_id`, `is_active`, instrucciones, plantilla y parámetros.

Si no existe, se intenta reutilizar `chat_main` solo cuando parece multimodal. Si `chat_main` es texto-only (por ejemplo Nova Micro), se usa como fallback integrado `amazon.nova-lite-v1:0`.

Para desactivar explícitamente visión en una instalación, cree/configure `attachment_vision` con `is_active=0` desde la administración de agentes.

## Tokens

La inferencia visual ocurre al preparar la imagen. Las preguntas posteriores no necesitan reenviar sus bytes: comparan la pregunta contra el embedding del `visual_summary` y solo inyectan al prompt el contexto textual relevante seleccionado por el RAG.

## Reintentos

Los botones manuales `Indexar` y `Semántica` reconocen imágenes y vuelven a ejecutar la preparación visual. Si el embedding falla después de guardar el resumen, `EmbeddingJobs` queda disponible para el proceso de mantenimiento de embeddings.
