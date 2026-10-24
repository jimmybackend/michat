# Preparación de archivos al preguntar

Adjuntar conserva la copia de trabajo y los metadatos; no inicia embeddings ni análisis. Se mantiene el borrador automático con el nombre. Al enviar una pregunta sobre un documento complejo, el navegador espera la preparación y muestra las fases reales antes de solicitar la respuesta. Texto y código conservan la ruta ligera (lectura/búsqueda según intención).

## Preparación y evidencia

`AttachmentProcessingJobs` conserva revisión, fase, checkpoints, reintentos y espera de proveedores. Las fases son extracción, chunks, embeddings por lotes, semántica y persistencia. Un fallo no se marca como terminado. Se verifican permisos y ETags de original y copia en cada avance; un bloqueo por configuración requiere una nueva petición tras corregirla. La recuperación usa el modelo activo y las respuestas añaden referencias de los fragmentos realmente recuperados. Esto no significa que cada respuesta revise todos los fragmentos: la recuperación selecciona evidencia relevante. Un límite de salida se anuncia como respuesta incompleta.

El manifiesto original es `<clave-original>.michat-u<propietario>.content.v1.json`; se comprueban identidad y revisión antes de reutilizarlo. Se guarda también junto a la copia del chat. Las interacciones siguen en el JSON de análisis existente. No se sobrescriben los bytes originales. El panel permite descargar el JSON derivado con autorización vigente.

Para audio/video se reutiliza primero una transcripción canónica de ArcadeCloud: propietario, `origen_encriptado`, trabajo Transcribe, URI y fecha deben corresponder al original. En caso contrario se crea un trabajo determinista y se consulta hasta terminar. Se copia el resultado JSON al chat. Las citas usan tiempos cuando AWS los aporta; el prompt explica que la evidencia es una transcripción, sin afirmar escucha directa ni análisis de fotogramas.

## Compatibilidad y límites

- XLSX/XLSM/ODS/CSV/TSV, DOCX/ODT, PPTX/PPSX/ODP, PDF digital y escaneado utilizan los lectores actuales; PDF sin texto depende de Textract. Las pruebas nuevas recorren XLSX, DOCX, PPSX con notas y PDF con OCR asíncrono.
- DOC/XLS/PPT/PPS necesitan los lectores seguros instalados (`antiword`, `catdoc`, `xls2csv`, `catppt`, según formato). No se usa conversión genérica por LibreOffice ni se ejecutan macros.
- Imágenes usan la interpretación del servicio de visión existente. Esa interpretación no recibe líneas originales ficticias.
- MP3/MP4/WAV/FLAC/OGG/AMR/WEBM/M4A/OPUS se encaminan a Transcribe; la aceptación efectiva depende del formato real y del servicio. MOV/AVI/MKV requieren conversión externa. No se implementa análisis visual de vídeo ni extracción de fotogramas.
- No se importan todavía resultados Rekognition/Textract arbitrarios de los metadatos históricos de ArcadeCloud. Se reutilizan los manifiestos verificados de MiChat y las transcripciones verificadas de ArcadeCloud.
- Extracción máxima de 8 MiB y manifiesto de 16 MiB: excederlos produce error explícito, sin afirmar cobertura completa. Vectores cacheados hasta 4 MiB; los restantes permanecen en la BD y pueden recalcularse en otra conversación. La cobertura parcial conserva su estado.
- El navegador abierto avanza trabajos y continúa la pregunta automáticamente. El worker permite continuar preparación con el navegador cerrado; no genera por sí solo la respuesta final de esa pregunta. No se ha instalado un programador en EC2.

## Costos

`AttachmentProcessingCosts` registra intentos antes de llamar al SDK, resultado, unidades observadas y precio estimado. Una caída puede dejar un resultado desconocido. Tarifas ausentes quedan `NULL/unpriced`, nunca se presentan como gratis. El dashboard suma importes conocidos y avisa de operaciones sin tarifa. Transcripciones reutilizadas no se vuelven a atribuir como una nueva transcripción. La duración estimada procede de los tiempos disponibles y puede diferir de la facturación AWS; no es reconciliación con factura, almacenamiento acumulado ni medición de todas las transferencias.

Configurar `MICHAT_PROCESSING_PRICES_PATH` con un JSON privado de tarifas verificadas para la región y modelos del despliegue. Ejemplo de estructura (sin precios inventados):

```json
{"source_id":"catalogo-region-fecha","rates":{}}
```

Claves: `s3.head_request`, `s3.get_request`, `s3.put_request`, `s3.copy_request`, `textract.detect_document_text_page`, `transcribe.standard_batch_second`, y `bedrock.<modelo_normalizado>.input_token`/`output_token` (por ejemplo `amazon_titan_embed_text_v2_0`). Revisar las claves observadas del ledger antes de completar el catálogo; no copiar tarifas de otra región.

## Actualización segura

Desde la raíz del checkout EC2, con la configuración privada habitual disponible para PHP CLI:

```sh
git pull --ff-only origin main
# Al abrir MiChat se crean automáticamente las dos tablas fase14.
php michat/bin/attachment_processing_worker.php --max-batches=10
```

La migración `fase14_attachment_processing.sql` añade dos tablas sin modificar tablas ni datos anteriores. Desde esta corrección, el arranque autenticado de MiChat, su worker y las acciones de procesamiento preparan esas tablas automáticamente después de actualizar Git; no se necesita ejecutar SQL manualmente. El actualizador web verifica además el esquema y comunica el resultado. No adoptar una historia desconocida para forzar `apply`: si `status` denuncia una incompatibilidad, resolverla antes. Para revertir aplicación, detener el worker y volver al commit anterior; las tablas nuevas pueden permanecer. Una retirada definitiva de esas dos tablas exige respaldar previamente auditoría/checkpoints y un procedimiento separado; no hay borrado automático.

El worker usa los mismos permisos privados de la aplicación para S3, Bedrock, Textract y Transcribe. No conceder acceso público ni ampliar globalmente el bucket. La configuración global de agentes debe habilitar `embedding_main`, semántica y visión según corresponda.

## Verificación

Las pruebas de integración nuevas usan MariaDB real, documentos de prueba y respuestas AWS controladas (incluyendo objetos oficiales `Aws\Result`), no recursos de producción. Cubren reintentos, reinicio, deduplicación, caché entre chats, revocación, otro usuario, notas, celdas, evidencia al final de documentos y costos desconocidos. JavaScript verifica espera, fallo, parcialidad y cambio de conversación.

En producción comprobar por separado: (1) adjuntar sin crear contenido; (2) preguntar por una celda/nota/dato conocido y observar fases; (3) confirmar citas contra el archivo; (4) verificar embeddings del modelo activo y JSON en original/copia; (5) audio/video con JSON existente sin iniciar otro trabajo, luego uno nuevo; (6) revocar acceso y comprobar denegación; (7) confirmar costos conocidos y pendientes en dashboard. Los tests controlados no certifican permisos IAM, cuotas, modelos ni facturación del entorno real.

## Corrección de entradas manuales y salida cortada

Los botones de indexar y crear semántica, incluida la acción de análisis de Drive, usan la misma cola para documentos complejos, imágenes y multimedia. El navegador continúa consultando esa tarea y no anuncia éxito al encolarla. MP3 y otros medios no pasan al detector de texto. La comprobación antes de preguntar consulta los adjuntos autorizados de la BD aunque la lista del navegador aún esté cargando. Si faltan las tablas fase14, se crean automáticamente bajo bloqueo y se continúa con el archivo. Si falla la preparación, se indica revisar permisos MySQL y el log.

Una respuesta con `stopReason=max_tokens` puede realizar hasta tres continuaciones automáticas adicionales, usando el contexto original y la salida previa. Se desactivan herramientas en esas continuaciones, se informa del avance y se suman todos los tokens para costos. Los presupuestos y la cancelación de Tasks siguen vigentes. Si ese presupuesto de continuación tampoco alcanza, permanece el aviso de respuesta incompleta; no implica que se haya perdido texto extraído del PDF. No se modifican tarifas ni configuraciones globales de salida.

Regresiones específicas: `bedrock_text_continuation_test.php` prueba unión de salida, citas, tokens, límite y presupuesto de Tasks; `manual_media_preparation_http_mysql_test.php` usa endpoints HTTP reales, MariaDB y un MP3 de silencio generado para comprobar rutas manuales, permisos y diagnóstico de esquema. La transcripción final se prueba aparte con respuestas AWS controladas, sin afirmar una llamada real al proveedor.

## Cobertura de transcripciones en las respuestas

La procedencia AWS no impide analizar el texto reconocido. La respuesta distingue cobertura del texto extraído y acceso directo al medio: `all_extracted_text` indica que todos los chunks íntegros de la transcripción disponible están en el contexto final; `selected_text` indica selección o recorte; `extraction_partial` conserva una extracción incompleta; `unknown` evita inventar cobertura cuando faltan metadatos. Un solo chunk puede contener toda la transcripción disponible. Esto no certifica que AWS haya reconocido cada palabra ni que el final del habla transcrita sea la duración total del medio. Los números y fechas ambiguos se conservan sin inventar correcciones.

El cómputo usa los fragmentos efectivamente seleccionados tras el ranking, separados por archivo y revisión, sin contar duplicados como cobertura adicional. Las búsquedas literales y los recortes por presupuesto conservan su carácter de selección. Las citas de MP3 muestran transcripción y tiempos; la limitación de fotogramas corresponde exclusivamente a vídeo. Los índices y JSON actuales se reutilizan sin exigir retranscripción.
