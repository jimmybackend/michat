# Capacidades multimodales Amazon en MiChat

Actualizado: 2026-09-09/10.

Este documento resume la arquitectura actual de visión, generación de imágenes, voz y generación de video. Por decisión de producto, las modalidades actuales se mantienen dentro de Amazon Web Services y Amazon Bedrock.

## 1. Principio común

Aunque cada modalidad tiene un contrato distinto, comparten reglas de arquitectura:

- identidad autenticada resuelta server-side;
- ownership validado antes de DB/S3/Bedrock;
- modelos elegidos desde configuración efectiva GLOBAL/USER;
- el navegador no puede sustituir arbitrariamente el modelo;
- S3 privado para archivos generados;
- `ChatMessages` como historial visible;
- `TokenUsage`/telemetría sin inventar métricas de tokens donde el modelo no facture como texto;
- políticas separadas por modalidad para poder cambiar modelos sin rehacer la UI.

Claves actuales:

- `attachment_vision`;
- `image_main`;
- `voice_main`;
- `video_main`.

## 2. Visión de imágenes adjuntas — `attachment_vision`

Objetivo: convertir una imagen ya subida en conocimiento reutilizable por RAG.

Formatos actuales:

- JPG/JPEG;
- PNG;
- WEBP;
- GIF.

Flujo:

```text
Upload
  ↓
S3 / FileS3
  ↓
SessionImageKnowledgeService
  ↓
Bedrock Converse multimodal
  ↓
visual_summary
  ↓
embedding_main
  ↓
Attachment RAG
```

El resumen visual puede contener descripción, texto visible, tablas, código, errores, diagramas, estructura, objetos y términos relevantes.

Modelos ofrecidos desde Preferencias:

- Amazon Nova Lite;
- Amazon Nova Pro;
- Amazon Nova Premier.

Nova Micro no se usa para esta modalidad porque es texto-only. Nova Canvas pertenece a generación, no comprensión visual.

El resultado visual se persiste como `SessionContextBlocks.block_type='file'` con metadata `type='visual_summary'`. Las preguntas posteriores recuperan ese texto por similitud semántica y no reenvían la imagen completa.

La telemetría de visión se registra bajo la fase histórica `rag` para mantener compatibilidad con el esquema actual.

## 3. Generación de imágenes — `image_main`

Objetivo: crear una imagen nueva desde texto y dejarla disponible en el historial del chat.

Modelos actuales permitidos:

- Amazon Titan Image Generator v2 — predeterminado;
- Amazon Nova Canvas — opción Legacy mientras siga disponible.

Flujo:

```text
Prompt
  ↓
image_main
  ↓
ImageGenerationPolicy
  ↓
Bedrock Runtime / TEXT_IMAGE
  ↓
PNG
  ↓
S3 privado
  ↓
ChatMessages content_type=image
```

La resolución inicial se mantiene en 1024x1024 y se decide server-side para evitar que el cliente fuerce parámetros caros o incompatibles.

El endpoint valida ownership de sesión antes de Bedrock y almacenamiento. El `model` no se toma de `POST`; se resuelve desde `UserAIAgentConfigs`.

La llamada se registra en `TokenUsage` sin inventar input/output tokens de texto. La metadata de la generación conserva la unidad funcional de imagen.

## 4. Voz — `voice_main`

Objetivo: permitir un turno hablado completo y persistir la conversación resultante.

Modelo actual:

- `amazon.nova-2-sonic-v1:0`.

Voces españolas disponibles:

- Lupe;
- Carlos.

El modo actual es push-to-talk / turn-based, no una llamada full-duplex continua.

Flujo:

```text
Micrófono navegador
  ↓
LPCM 16 kHz / 16-bit
  ↓
voice_turn.php
  ↓
Node bridge sonic_turn.mjs
  ↓
InvokeModelWithBidirectionalStreamCommand
  ↓
Nova 2 Sonic
  ↓
LPCM 24 kHz + transcripciones finales
  ↓
ChatMessages + TokenUsage
```

`voice_turn.php` valida:

- sesión autenticada;
- CSRF;
- ownership;
- archivo de audio;
- duración/tamaño;
- `voice_main` activo;
- modelo permitido;
- voz seleccionada.

El bridge ignora texto especulativo y devuelve la transcripción final del usuario y del asistente. Ambas se persisten en el historial de texto y el consumo de Nova 2 Sonic se registra en `TokenUsage`.

### Dependencia de despliegue

La voz requiere Node.js 20+ y:

```bash
cd michat/voice
npm install --omit=dev
```

`voice_turn.php` busca el binario en este orden:

1. `MICHAT_NODE_BINARY`;
2. `/usr/bin/node`;
3. `/usr/local/bin/node`.

## 5. Video — `video_main`

Objetivo: iniciar una generación de video asíncrona en Bedrock y presentar el MP4 final en el chat.

Modelos actuales:

- Amazon Nova Reel 1.1 — principal cuando la región lo soporta;
- Amazon Nova Reel 1.0 — fallback regional soportado.

Contrato del clip simple actual:

- `TEXT_VIDEO`;
- 6 segundos;
- 1280x720;
- 24 fps;
- seed server-side.

Flujo:

```text
Prompt
  ↓
video_main
  ↓
VideoGenerationPolicy
  ↓
StartAsyncInvoke
  ↓
Bedrock escribe en S3
  ↓
GetAsyncInvoke / polling
  ↓
output.mp4
  ↓
ChatMessages content_type=video
```

La implementación corrige problemas del endpoint histórico:

- ya no requiere que el navegador envíe `model`;
- ya no usa `$owner_id` indefinido;
- el placeholder usa `AUTO_INCREMENT` real de MySQL;
- el estado AWS `InProgress` se normaliza a `in_progress` para el polling;
- ownership se valida antes de DB, S3 y Bedrock;
- el output se guarda bajo un namespace privado de usuario/sesión/mensaje.

La generación de video se factura por una unidad distinta a tokens de texto. MiChat registra la ejecución sin inventar prompt/completion tokens y conserva metadata como `billing_unit='video_second'`.

### Ciclo de vida de Nova Reel

AWS clasifica Nova Reel como Legacy y anunció EOL para el 30 de septiembre de 2026. Por eso MiChat mantiene el generador aislado detrás de `video_main` y `VideoGenerationPolicy`. Cuando exista un reemplazo Amazon apropiado, la intención es cambiar la política/modelo sin rediseñar la UI, el historial ni el contrato S3.

## 6. Preferencias

Las cuatro modalidades se administran desde **Preferencias -> Modelos IA**.

La configuración efectiva sigue la política:

```text
USER override
   ↓ si no existe
GLOBAL
   ↓ si no existe y el flujo lo admite
fallback seguro del componente
```

No todas las modalidades permiten fallback cuando se desactivan explícitamente. Un `is_active=0` efectivo debe respetarse.

## 7. S3

Archivos generados se almacenan en bucket privado. El navegador no recibe credenciales AWS.

Ejemplos de namespaces usados por la aplicación:

- imágenes generadas bajo el namespace de generaciones de imagen;
- videos bajo `Chat/GenerationsVideos/{user}/{session}/msg_{message}/...`.

Las rutas concretas pueden incluir un prefijo de despliegue configurado por `Config::RUTA_RAIZ`.

## 8. Base de datos

PR #79 a #83 reutilizan el esquema existente. No introducen una migración SQL nueva para:

- visión;
- preferencias visuales;
- voz;
- generación de imágenes;
- generación de video.

Se reutilizan principalmente `UserAIAgentConfigs`, `ChatMessages`, `FileS3`, `SessionContextBlocks`, `EmbeddingJobs` y `TokenUsage`.

## 9. Pruebas

Contratos principales:

- `session_attachment_image_vision_test.php`;
- `attachment_vision_preferences_test.php`;
- `voice_audio_contract_test.php`;
- `image_generation_contract_test.php`;
- `video_generation_contract_test.php`.

El CI verifica además sintaxis PHP, suite de regresión, contratos JavaScript y guardas de secretos/backups.

## 10. Alcance actual y siguientes cambios

Implementado:

- visión reutilizable por RAG;
- imagen text-to-image;
- voz turn-based;
- video text-to-video asíncrono.

No se debe documentar todavía como implementado:

- conversación de voz full-duplex continua con barge-in;
- generación de video con un sucesor de Nova Reel que AWS aún no haya integrado en este repositorio;
- análisis semántico automático de videos subidos como conocimiento RAG.

Esos puntos requieren una implementación explícita futura.
