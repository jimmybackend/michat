# Archivo vinculado, contenido verificable y OCR PDF

Este cambio corrige el recorrido observado después del PR #122: seleccionar un archivo creaba el vínculo y trataba de resumirlo, pero no garantizaba fragmentos originales. Un fallo del resumidor o del JSON podía dejar una conversación con el archivo visible y sin contexto para contestar.

## Selección y reintento

Buscar sigue consultando solo metadatos de FileS3. Seleccionar prepara primero fragmentos originales, con los permisos y localizadores existentes; el resumen y el JSON son pasos independientes. Un fallo de semántica o del auxiliar no elimina un índice válido ni impide su recuperación léxica. Se informa qué paso quedó pendiente. Los botones de indexación de archivos vinculados desde Drive usan el mismo endpoint autorizado, con CSRF, y actualizan el estado incluso cuando hay un error. No recargan mensajes.

Para esta preparación inmediata se conserva el lector/indexador de sesión y su presupuesto de 200.000 caracteres. Su cobertura parcial permanece visible. No equivale a extracción ilimitada ni reemplaza el worker central de Fase 13.

El JSON v1 es compatible con los anteriores y añade `chunks`: texto de cada fragmento, metadatos de página/línea, revisión y hash. Una segunda conversación puede recuperar esos fragmentos sin repetir extracción, resumen ni embeddings. Se comprueban identidad, propietario, revisión, hashes y ordinales antes de importar. Si un JSON antiguo solo conserva resumen, se prepara el índice literal una vez. El JSON puede contener partes originales privadas, además de resumen e interacciones; mantiene el límite de 2 MiB, acceso a través del original y escrituras condicionales. Si no cabe o no puede guardarse, los fragmentos siguen disponibles en MySQL y el aviso permanece visible.

Los estados de intento se guardan en `ChatSessions.meta.knowledge_file_states`, preservando otros metadatos con bloqueo transaccional. El panel distingue vínculo, extracción pendiente/fallida, OCR en proceso, texto consultable, resumen, embeddings y guardado del auxiliar. No presenta «sin indexar» como si fuera un problema de permiso cuando solo falta contenido. Para el último intento con ETag conocido, los conteos de fragmentos excluyen revisiones anteriores.

## Contexto del chat

El ContextBuilder conserva la pregunta original y el nombre del archivo aunque el compilador reescriba la consulta o el Router la clasifique como código/proyecto. Las restricciones de recuperación del usuario siguen vigentes.

Las preguntas explícitas reciben también el estado verificado de los vínculos propios. Si no hay contenido recuperado, los dos recorridos de respuesta (legacy y Task) devuelven el estado real de procesamiento en lugar de una negación genérica de acceso o contenido inventado. Si existe evidencia, ese diagnóstico no sustituye la respuesta del modelo. Los archivos bloqueados, eliminados, ajenos o desvinculados no se incorporan al contexto. Los resúmenes generados siguen identificados como tales; sus metadatos no se convierten en páginas literales.

## PDF y Textract

La extracción digital continúa intentando los lectores locales. El OCR asíncrono se usa cuando esos lectores no entregan texto; un PDF mixto que ya produjo texto puede seguir marcado como parcial y no recibe automáticamente OCR adicional en sus páginas omitidas. Cuando necesita Textract, el PDF usa `StartDocumentTextDetection` y `GetDocumentTextDetection`, con un token idempotente por revisión y ventana temporal inferior a la retención de siete días de AWS. Si S3 tiene VersionId se fija esa versión. Se recorren todos los NextToken y se preserva el Page real de cada línea. PARTIAL_SUCCESS, advertencias y páginas sin texto producen cobertura parcial explícita. Se rechazan ciclos de paginación, números de página no verificables y resultados que excedan 8 MiB de texto o 3000 páginas.

Un trabajo IN_PROGRESS retorna enseguida `ocr_pending`, sin bloquear el navegador con un bucle de espera y sin marcarlo completo. El botón de reintento recupera el mismo trabajo dentro de la ventana de idempotencia; no vuelve a subir el PDF. Cuando el motor central está activado, su worker reintenta la espera OCR sin consumir el presupuesto de fallos transitorios, con un máximo de seis horas antes de declarar fallo. Sin worker central, volver a pulsar reintentar recupera los resultados; cerrar el navegador no instala un worker ni importa automáticamente el OCR terminado.

Después de extraer se comprueba de nuevo el ETag original antes de publicar los fragmentos. Si el original cambia, se solicita un nuevo intento. Un PDF protegido, incompatible o dañado puede seguir fallando; el error no demuestra por sí mismo cuál de esas causas se aplica. Descargarlo por HTTP no determina su legibilidad. No se recibió ni examinó el PDF específico de las capturas.

El despliegue necesita los permisos efectivos de Textract asíncrono y lectura de la fuente S3. El código no modifica IAM ni infraestructura. No instala Poppler ni otros paquetes en EC2. No requiere migraciones ni cambios de composer/vendor.

## Verificación

- MariaDB real: selección → índice literal → caché entre conversaciones → recuperación de un importe y cita de página; fallos de resumen/JSON; pregunta original preservada y diagnóstico exacto de archivo vinculado sin contenido.
- Worker real con runtime controlado: OCR pendiente sin consumir fallos, reanudación y vencimiento de seis horas.
- Adaptador OCR: paginación, idempotencia, revisión, Page, PARTIAL_SUCCESS, límites y errores usando dobles de Textract.
- JavaScript: reintento canónico con CSRF, OCR pendiente, actualización tras fallo y aislamiento al cambiar de conversación.

Estas comprobaciones no certifican el archivo particular ni AWS en producción. Tras actualizar la instalación, reintentar el archivo ya vinculado, comprobar su estado y preguntar por un dato concreto. Abrir la cita y verificarla contra el original. Para PDF escaneado, repetir el reintento cuando termine OCR; si falla, revisar el estado visible y el error del servidor, sin asumir que el original está dañado.
