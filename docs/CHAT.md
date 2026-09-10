# Chat — Núcleo conversacional de MiChat

> Estado: implementación verificada en `main`.
> Superficie principal: `michat/chat.php`.
> Runtime de respuesta: `michat/bedrock_chat2.php` + servicios POO de `michat/includes/Chat/`.
> Actualizado: 2026-09-09/10.

## 1. Responsabilidad actual

El Chat es la superficie conversacional normal de MiChat. Su responsabilidad es recibir una interacción, recuperar el contexto permitido por configuración, ejecutar el modelo o la modalidad seleccionada y persistir la respuesta con su telemetría.

La regla de producto actual es explícita:

**una pregunta normal del Chat no debe convertirse automáticamente en una Task.**

```text
Usuario
  ↓
chat.php
  ↓
Pipeline conversacional
  ↓
Memory / RAG / Tools permitidas
  ↓
Amazon Bedrock
  ↓
ChatMessages + TokenUsage + Trace
```

Task Center es una superficie distinta para trabajo persistente, planificado y potencialmente supervisado.

## 2. Frontera Chat / Tasks

La separación quedó endurecida después de las fases del Task Orchestrator:

- `chat.php` y `bedrock_chat2.php` trabajan con un snapshot conversacional de `PipelineFeatureFlags`;
- ese snapshot enmascara `task_orchestrator`, `task_auto_execute`, `task_async_execute` y `task_planner` para una pregunta normal;
- `task_api.php`, Task Center y Worker siguen leyendo las flags Task persistidas;
- una Task ya existente y aprobada puede reanudarse por una acción explícita, con ownership y contexto persistido.

Esto evita el comportamiento anterior en el que una pregunta del chat podía terminar pidiendo aprobación de un agente sin que el usuario hubiera entrado a Task Center.

Referencia: `michat/doc/chat-task-boundary.md`.

## 3. Pipeline conversacional

El runtime central prepara contexto y ejecución mediante servicios POO compartidos. Conceptualmente:

```text
Pregunta
  ↓
Feature flags
  ↓
Memory Context Router
  ↓
Context Builder / Ranking
  ↓
Project RAG + Attachment RAG
  ↓
Prompt final
  ↓
Bedrock runtime
  ↓
Tools conversacionales permitidas
  ↓
Respuesta final
  ↓
Persistencia + Memory Writer + TokenUsage + Trace
```

El Worker de Tasks reutiliza la misma frontera central de ejecución cuando un Step `model` necesita conversación/modelo, pero no convierte el chat normal en Task.

## 4. Memoria

El Chat puede recuperar distintas fuentes de conocimiento:

- Session Memory;
- Project Memory;
- Procedural Memory;
- Selective Q&A Memory;
- Primordial Context;
- Project RAG;
- Attachment RAG.

La memoria se almacena por separado y se recupera selectivamente. El objetivo no es concatenar toda la historia al prompt, sino construir un contexto acotado y útil para la pregunta actual.

El `Memory Writer` puede analizar una respuesta final y producir candidatos reutilizables como decisiones, reglas, hechos, preferencias, correcciones o patrones.

## 5. Adjuntos de documentos

Desde PR #77, subir un documento a una sesión dispara automáticamente el pipeline de conocimiento:

1. almacenamiento S3/FileS3;
2. extracción textual;
3. bloques `file_chunk`;
4. embeddings con `embedding_main`;
5. resumen semántico `file`;
6. embedding del resumen;
7. recuperación posterior mediante Attachment RAG.

Los controles manuales **Indexar** y **Semántica** reutilizan el mismo servicio y funcionan como reintentos.

Referencia: `michat/doc/attachment-knowledge-pipeline.md`.

## 6. Adjuntos de imagen y visión

Desde PR #79, JPG/JPEG, PNG, WEBP y GIF pueden analizarse al adjuntarse.

`SessionImageKnowledgeService`:

- valida ownership;
- obtiene la imagen privada desde S3;
- valida tamaño y dimensiones;
- llama Bedrock Converse con entrada multimodal;
- extrae descripción visual, texto visible, tablas, código, errores, diagramas y estructura cuando existen;
- persiste `visual_summary` como `SessionContextBlocks.block_type='file'`;
- reutiliza `embedding_main` y el Attachment RAG existente.

Desde PR #80, `attachment_vision` aparece en **Preferencias -> Modelos IA** y permite elegir entre Amazon Nova Lite, Nova Pro y Nova Premier y activar/desactivar la etapa visual.

Desde PR #81, el uso de visión se integra con la telemetría existente sin ampliar el ENUM histórico de fases: la fase funcional de visión se registra dentro de la categoría `rag`.

Referencia: `michat/doc/attachment-image-vision-rag.md`.

## 7. Generación de imágenes

Desde PR #82, el botón de generación de imagen existente usa una configuración dedicada `image_main`.

Modelos soportados por la política actual:

- Amazon Titan Image Generator v2 — predeterminado;
- Amazon Nova Canvas — opción Legacy.

Características:

- el navegador no elige arbitrariamente el modelo;
- el backend resuelve `image_main` desde configuración efectiva GLOBAL/USER;
- el endpoint valida ownership antes de Bedrock y S3;
- la generación inicial usa `TEXT_IMAGE` y una resolución server-side de 1024x1024;
- el PNG queda en S3 privado;
- `ChatMessages.content_type='image'` conserva el resultado en el historial.

## 8. Voz con Amazon Nova 2 Sonic

Desde PR #81, `voice_main` permite hablar con MiChat y escuchar la respuesta.

Contrato actual:

- modelo `amazon.nova-2-sonic-v1:0`;
- voces españolas `lupe` y `carlos`;
- modalidad push-to-talk / turn-based;
- captura del micrófono por HTTPS;
- entrada LPCM 16 kHz / 16-bit;
- bridge Node.js `michat/voice/sonic_turn.mjs`;
- API bidireccional `InvokeModelWithBidirectionalStreamCommand`;
- salida LPCM 24 kHz;
- transcripciones finales de usuario y asistente persistidas como mensajes de texto;
- consumo del turno persistido en `TokenUsage`.

`voice_turn.php` valida autenticación, CSRF, ownership de sesión, modelo y límites antes de ejecutar el bridge.

La instalación requiere Node.js 20+ y:

```bash
cd michat/voice
npm install --omit=dev
```

## 9. Generación de video

Desde PR #83, `video_main` completa la ruta de generación de video ya presente en la interfaz.

Contrato actual:

- Amazon Nova Reel 1.1 como principal en región compatible;
- Amazon Nova Reel 1.0 como fallback regional soportado;
- modelo resuelto server-side;
- `TEXT_VIDEO`;
- `StartAsyncInvoke` para iniciar;
- `GetAsyncInvoke` para polling;
- 6 segundos, 1280x720, 24 fps en el clip simple actual;
- Bedrock escribe `output.mp4` en S3 privado;
- el mensaje placeholder usa `AUTO_INCREMENT` real, no `MAX(id_)+1`;
- `InProgress` se normaliza a `in_progress` para que el polling continúe;
- el video final se persiste en `ChatMessages` y se renderiza en el historial.

Nova Reel está encapsulado en `video_main` para poder sustituirlo sin rehacer el chat cuando cambie la oferta de modelos Amazon.

Referencia multimodal conjunta: `michat/doc/multimodal-media-aws.md`.

## 10. Configuración dinámica de IA

El runtime central utiliza configuración efectiva GLOBAL/USER. Los overrides USER ganan por `agent_key` sobre el catálogo GLOBAL.

Claves relevantes para Chat y modalidades asociadas:

- `chat_main`
- `prompt_compiler`
- `embedding_main`
- `smart_memory_general`
- `smart_memory_code`
- `attachment_vision`
- `image_main`
- `voice_main`
- `video_main`

Los agentes pueden manejar modelo, activación, instrucciones y parámetros compatibles con cada modalidad.

## 11. Herramientas conversacionales y de proyecto

La plataforma mantiene herramientas registradas como `grep`, `search`, `view`, `str_replace` y `code_edit` dentro de la infraestructura común.

La política de Tasks puede colocar un gate HITL sobre escrituras cuando esas herramientas son usadas desde un Step Task. El Chat genérico permanece desacoplado de esa política Task y recibe su registry/gate según la composición concreta del runtime.

## 12. Persistencia y observabilidad

Las respuestas finales utilizan IDs reales de `ChatMessages` y la persistencia compartida evita carreras basadas en `MAX(id_)+1`.

El pipeline puede registrar:

- modelo efectivo;
- prompt/input tokens;
- completion/output tokens;
- duración;
- Tool Calls;
- eventos operacionales;
- `trace_id`;
- Memory Writer;
- selección de memoria/RAG.

La trazabilidad describe actividad operacional observable y no expone razonamiento privado interno del modelo.

## 13. Seguridad

Principios actuales:

- identidad resuelta server-side;
- ownership de sesión/proyecto/mensaje antes de efectos sensibles;
- `user_id` del request no autentica por sí mismo;
- CSRF en mutaciones;
- S3 privado;
- modelos de imagen/video resueltos por política server-side;
- configuración GLOBAL administrada por permisos DB-backed;
- sin privilegios mágicos por ID de usuario.

## 14. Relación futura con MCMA

MCMA es un proyecto relacionado con memoria portable, pero el Chat actual no debe documentarse como si ya delegara su filesystem o toda su memoria a MCMA.

La frontera correcta hoy es:

- MiChat Chat: conversación, contexto, RAG y modalidades;
- MiChat Task Center: trabajo persistente y agentes;
- MCMA: evolución separada para administración portable de memoria cuando su integración sea implementada y verificada.

## 15. Regla de mantenimiento de este documento

Este documento describe implementación real. Para retomar trabajo después de un cambio importante:

1. revisar `michat/doc/contexto-operativo-actual.md`;
2. verificar `main`;
3. actualizar este documento solo con capacidades fusionadas y verificadas;
4. mantener separadas capacidades actuales y planes futuros.
