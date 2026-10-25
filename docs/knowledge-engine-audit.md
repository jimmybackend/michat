# Auditoría del motor de archivos — 24 de octubre de 2026

Base MiChat: `f1cce2e` (PR #134). Referencia ArcadeCloud: `965b1ad7` (PR #525).
El esquema inspeccionado es el definido por los repositorios; no certifica el esquema desplegado.

| Origen | Registro y alcance actual | Procesamiento | Diferencia pendiente |
|---|---|---|---|
| Proyecto | `Projects.user_id_`, `ProjectSources.project_id_`, `files3_id_` | `ProjectIndexer`, `SourceChunks`, `ChunkEmbeddings`, `EmbeddingJobs` | No utiliza la cola multimodal de sesiones; no hay membresía compartida comprobada |
| Sesión | `ChatSessions.user_id_`, ruta de subida y `meta.knowledge_file_ids` | `AttachmentRequestService`, `AttachmentProcessingJobs`, `SessionContextBlocks`; índice central opcional | El worker prepara contenido pero no genera la respuesta final con navegador cerrado |
| ArcadeCloud propio | Catálogo `FileS3`, copia privada y referencia `Metadatos.drive_source` | Motor de sesión, caché verificable junto al original | Requiere comprobar original y copia al recuperar evidencia |
| ArcadeCloud compartido | `FederationShares`, `FederatedResources`, importaciones con `Metadatos.federationcloud` | No existe adaptador de acceso compartido en MiChat | No ampliar `user_id_` para simular permisos; hacen falta vigencia, recurso, nodo y revisión |

## Conjunto 1: revisión y aislamiento

- Los bloques antiguos sin `source_etag` se excluyen. No se infiere actualidad por nombre, clave o existencia del objeto. La preparación al preguntar puede regenerarlos.
- La recuperación y el inspector verifican el ETag de la copia y del original; una copia intacta no legitima una revisión antigua del original.
- El índice central no acepta dos ETags vacíos como coincidencia ni evita comprobar el original de una copia.
- Una sesión en papelera (`status='closed'`) no autoriza procesamiento documental. Archivada sigue siendo legible.
- El RAG de proyectos exige que `SourceChunks.project_id_` coincida también con el proyecto de `ProjectSources`.
- No hay DDL ni modificaciones de originales en este conjunto.

Pruebas de comportamiento: `document_evidence_mysql_test.php`, `drive_chat_working_copy_mysql_test.php`, `central_document_queue_mysql_test.php`. MariaDB local real, objetos S3 y respuestas de proveedores controlados. No certifican AWS ni producción.

## Restricciones comprobadas

El workflow `arcadecloud-ec2-status-bridge.yml` asume OIDC y ejecuta `status.py`: consulta estado EC2 y registro SSM; no ejecuta procesamiento dentro del servidor. MiChat también tiene un workflow de estado EC2 de solo lectura. El permiso IAM del rol no amplía las operaciones implementadas por esos workflows. No se modificó infraestructura ni se instaló un worker.

La revisión ampliada encontró además `arcadecloud-aws-auto.yml`/`operator.py`, con diagnósticos SSM y acciones predefinidas, y el código de un puente MCP con shell bajo sus propios controles. El operador de Actions no admite comandos libres: su lista incluye diagnósticos del host/Drive, acciones de servicio, encendido de FastDrive y sincronización del helper de Drive. No incluye una prueba de ingesta ni despliegue de MiChat. La capacidad técnica de esos operadores no autoriza aquí sus acciones sobre infraestructura; no se ejecutaron.

Persisten los límites y dependencias descritos en `multimodal-file-processing.md`. Especialmente: revisión exhaustiva por lotes, respuesta final persistente, integración completa de proyectos y compartidos remotos, fotogramas, cachés históricos arbitrarios, versiones entre orígenes y conciliación AWS siguen pendientes hasta su implementación y pruebas específicas.

## Conjunto 2: fuentes publicadas del proyecto en el motor común

`DocumentAccessRepository.conversationFiles()` es el catálogo de metadatos para la interfaz, el planificador y la preparación. Incluye adjuntos de la conversación y fuentes publicadas con `ProjectSources.files3_id_` real, propietario autorizado y clave coincidente. No consulta S3 ni inicia análisis al listar. No inventa membresías: el esquema actual de MiChat autoriza al propietario del proyecto.

Las preguntas sobre esas fuentes utilizan ahora los mismos lectores, localizadores, JSON verificados, cola multimodal y embeddings que los adjuntos. Las preguntas de metadatos no extraen contenido. Los chunks y el contexto conservan el origen de proyecto; la publicación no se convierte en un permiso privado de sesión. Otra conversación del mismo proyecto reutiliza el JSON y sus vectores, mientras siga vigente la fuente. Retirarla o cambiar de proyecto invalida el acceso a trabajos y chunks de esa ruta.

Se mantienen `ProjectIndexer`, `SourceChunks`, `ChunkEmbeddings` y `EmbeddingJobs` por compatibilidad con herramientas de código y el RAG histórico. No se ha migrado ni certificado esa ruta histórica completa. Fuentes antiguas sin enlace `files3_id_` requieren reconciliación verificable; no se enlazan por nombre. Los catálogos conservan un límite explícito de consulta de 101 filas por ámbito; proyectos mayores requieren paginación adicional. No se implementa una revisión exhaustiva usando una selección RAG.

Prueba `project_attachment_engine_mysql_test.php`: dato al final de texto, localizadores, reutilización entre chats, exclusión de sesión libre/usuario ajeno/FK de proyecto ajeno, retirada sin borrar original, XLSX por cola persistente, vectores reutilizados y revocación al cambiar de proyecto. Usa MariaDB real aislada y proveedores controlados.

## Conjunto 3: permisos de importaciones federadas

`FederationImportedAccess` utiliza el esquema real de ArcadeCloud (DDL de referencia incluido en el fixture de pruebas, tomado de `965b1ad7`). Para archivos marcados `federationcloud.source=share_received`, MiChat comprueba `FederationShares` y `FederatedResources`: destinatario, dirección recibida, estado activo, expiración UTC, recurso, nodo origen, copia local y versión importada. Cambiar esas condiciones invalida búsqueda, acceso a la copia de chat, recuperación e incluso el resolvedor de descarga directa. No se altera el funcionamiento ni los permisos del Drive original.

La versión se verifica mediante `ContentId`/`ImportedContentId`; si no existe ContentId, se exige la fecha de revisión importada. Si faltan tablas, relaciones o prueba de versión, el acceso de MiChat se deniega. El adaptador no crea/repara tablas de FederationCloud ni sigue URLs remotas. El catálogo conserva cursores de las filas inspeccionadas aunque filtre todas las inaccesibles de una página.

Esto integra **importaciones locales verificables**, no archivos compartidos remotos sin importar, enlaces públicos o membresías genéricas entre propietarios. No hay ampliación de la consulta de propietario. El manifiesto y las interacciones siguen siendo privados del usuario de la copia importada; no se escriben en el origen remoto. La revocación depende del estado local sincronizado por FederationCloud, no de una comprobación en vivo contra el nodo remoto.

`federation_import_access_mysql_test.php` verifica el flujo selección→copia→extracción→cita y deniega revocación, expiración, cambio de destinatario, clave, nodo, versión, tombstone y esquema ausente. No borra originales ni realiza llamadas reales a AWS.

## Conjunto 4: preparación según la operación

Búsqueda literal y estructura piden ahora trabajos de **texto**: extracción necesaria, JSON verificado y chunks con localizadores. No ejecutan embeddings ni semántica. Sus claves de idempotencia son distintas de las de preparación completa, que conserva compatibilidad histórica. La salida pública declara `preparation_goal`, `text_ready` y `embeddings_completed` por separado. Una operación textual puede terminar aunque el modelo de embeddings esté desactivado; eso no declara el RAG completo.

Una petición completa posterior reutiliza el JSON extraído y completa las etapas solicitadas. Una operación textual sobre un índice completo conserva sus chunks y vectores verificados. Transcripción/OCR siguen siendo necesarios cuando el dato literal depende de ellos; su trabajo y costo no se omiten. Metadatos no inicia esos trabajos. No se añade DDL.

`operation_preparation_mysql_test.php` comprueba XLSX con embeddings desactivados, evidencia real `G7`, ausencia de llamadas a embeddings/semántica, preparación completa posterior y conservación de vectores. Proveedores controlados y MariaDB aislada.

## Conjunto 5: retirada y desvinculación sin borrar originales

El endpoint histórico `project_source_delete.php` eliminaba bytes S3 antes de retirar la fuente. Ahora tanto ese endpoint como `project_sources.php?action=remove` delegan en `ProjectSourceRemovalService`: operación transaccional, propietario bloqueado durante la mutación, retirada de publicación, chunks, vectores y jobs derivados; conserva `FileS3`, objetos S3 y `FileVersions`, e invalida `Projects.index_gen`. Exige POST y CSRF.

La acción de quitar adjuntos de la interfaz ahora desvincula la conversación, conservando también la copia de trabajo. `meta.detached_file_ids` impide que la ruta física de subida vuelva a conceder acceso o vuelva a listar el archivo. Seleccionarlo explícitamente otra vez restaura el vínculo y reutiliza la copia. Se permite desvincular una copia cuyo original fue revocado, sin acceder a sus bytes. Las fuentes de proyecto se retiran por su propia acción, con aviso de alcance de proyecto.

Distinción de operaciones: **desvincular** afecta al contexto de una conversación; **retirar fuente** afecta a la publicación del proyecto y su índice; **eliminar copia física** y **borrar original** no son efectos de esos botones. No se añadieron limpiezas destructivas. La recuperación universal de versiones y las herramientas históricas de edición/borrado siguen requiriendo una auditoría independiente.

Pruebas: `conversation_detach_mysql_test.php`, `project_source_withdrawal_mysql_test.php` y `safe_file_removal_ui_test.js`: aislamiento, revocación, reanexado sin duplicar, preservación de todos los bytes y filas FileS3, versiones, datos ajenos e índices sin FKs de cascada. Sin DDL adicional.

## Conjunto 6: preguntas específicas y recuperación

Las preguntas pertinentes comprueban la validez de la preparación aunque existan bloques históricos: tener un registro no certifica su revisión. Los índices centrales actuales se reutilizan; no se descargan otra vez para satisfacer esa comprobación. Una conversación anterior a la política nueva puede regenerar o importar evidencia verificable sin volver a adjuntar. Las preguntas específicas utilizan recuperación por archivo; los nombres sirven para escoger el archivo, no para elevar artificialmente todos sus chunks en el ranking. Las fechas del contenido no se confunden con la fecha de subida.

El índice central y el índice de sesión tienen IDs independientes: la correspondencia de evidencia exige archivo, revisión, ordinal y texto coincidentes. Un ID coincidente entre tablas no basta para escoger un fragmento. Las consultas no relacionadas siguen sin leer adjuntos. El worker persiste los trabajos revocados/desvinculados como bloqueados y atiende los siguientes; una petición explícita con permisos restablecidos reanuda el mismo trabajo.

Pruebas: `evidence_request_recovery_mysql_test.php` (dato final, citas, metadatos, pregunta ajena e índice antiguo), `attachment_request_planner_test.php` (colisión de IDs entre índices) y `processing_worker_revocation_mysql_test.php` (revocación sin lectura, no inanición y recuperación).

## Comprobación en producción pendiente

No se certificaron ni modificaron `/var/www/michat`, sus credenciales, configuración privada, cuotas AWS, workers instalados ni esquema desplegado. Fusionar no demuestra despliegue. Comprobar el commit efectivo del actualizador y seguir las instrucciones de actualización de `multimodal-file-processing.md`; estos conjuntos no añaden tablas. La creación automática fase14 existente sigue vigente y no debe reemplazarse por reproducción de migraciones históricas.

1. En una conversación libre, adjuntar un TXT con un dato único al final; comprobar que adjuntar no crea contenido. Preguntar por el dato y contrastar sus líneas con el original.
2. Publicar un documento como fuente del proyecto; preguntar desde dos conversaciones del mismo proyecto y comprobar reutilización. Intentar desde un chat libre y otro usuario: sin evidencia del proyecto.
3. Importar un Share de prueba mediante ArcadeCloud; seleccionarlo en MiChat, preguntar y verificar copia/cita. Revocar o expirar el Share y sincronizar FederationCloud: búsqueda, copia, JSON y recuperación deben denegarse en MiChat.
4. Con un XLSX de prueba y embeddings desactivados, buscar un valor literal. Debe devolver celda/hoja, sin etapas ni costos de embeddings/semántica. Después solicitar preparación completa con el modelo activo.
5. Quitar el adjunto: debe desaparecer del contexto y conservar los objetos. Seleccionarlo otra vez: reutilizar la copia. Retirar una fuente: conservar original y versiones; bloquear conocimiento derivado de esa publicación.
6. Preparar un archivo escaneado y uno de audio de prueba con los servicios reales. Contrastar páginas, palabras reconocidas y tiempos. Verificar fallos IAM/cuotas, ledger y tarifas ausentes; nunca interpretar CI como una llamada real de AWS.
7. Ejecutar el worker autorizado por el administrador con un trabajo revocado y otro válido: el primero queda bloqueado y el segundo avanza. Cerrar navegador solo certifica continuidad de **preparación**; la generación final desatendida sigue pendiente.

## Alcance todavía no completado

- Respuesta final persistente después de cerrar navegador, con recuperación ante resultado externo desconocido y sin duplicar mensajes/cobros.
- Compartidos remotos sin importación, membresías reales de proyecto y comprobación remota de revocación.
- Unificación de las herramientas históricas de proyecto y sus revisiones; reconciliación de fuentes sin `files3_id_`.
- Revisión exhaustiva por lotes y procesamiento incremental más allá de 8 MiB de extracción/16 MiB de manifiesto. El muestreo declarado no sustituye ese trabajo.
- Fotogramas de vídeo, análisis multimedia visual e importación general de resultados históricos Rekognition/Textract.
- Versionado/edición/recuperación universal para los tres orígenes, comparación exhaustiva de archivos grandes, invalidación por todos los parámetros de adaptadores/modelos (incluidos cambios de dimensiones con el mismo model ID).
- Disponibilidad real en producción de lectores antiguos, todos los formatos de imagen y contenedores multimedia; conciliación de costos con factura AWS.

Lectores existentes: PDF digital (`pdftotext`) y escaneado (Textract), DOCX/ODT/RTF/TXT/Markdown/EPUB, PPTX/PPSX/ODP, XLSX/XLSM/CSV/TSV/ODS, código y volcados SQL como texto seguro. SQLite sigue siendo muestra parcial de solo lectura. DOC/XLS/PPT/PPS dependen de herramientas específicas (`antiword`, `catdoc`, `xls2csv`, `catppt`); faltantes producen diagnóstico. Audio/vídeo dependen de Transcribe y formato real admitido; MOV/AVI/MKV necesitan conversión externa. El entorno local de esta auditoría dispone de `pdftotext`; no dispone de esos lectores antiguos ni LibreOffice. No se usaron conversiones genéricas sin aislamiento.

## Conjunto 7: archivos generados visibles sin sincronizar

`GeneratedFileCatalog` registra las salidas de análisis, contenido, transcripción y copia de transcripción en `FileS3`, con el usuario de la fuente autorizada, clave/ruta reales y bytes exactos. Se invoca después del éxito de S3 o de confirmar el resultado asíncrono; las salidas pendientes no se anuncian como archivos existentes. Las imágenes/vídeos generados y las rutas directas de subida del chat también registran sus archivos y carpetas del usuario. No se modificó infraestructura AWS ni se añadieron tablas.

El registro es idempotente: reutiliza las filas existentes de una sincronización y preserva nombre visible, seguridad y metadatos personalizados. Los reintentos/lecturas verificadas de JSON y los trabajos listos reparan sus registros conocidos sin iniciar otra transcripción o embeddings. Los derivados conservan la relación con la fuente y MiChat revalida sus permisos al buscar/descargar; también se verifica una copia de un JSON derivado. No se añaden solos al contexto de la conversación por aparecer en `FileS3`.

La búsqueda utiliza nombres en `<strong>` mediante `textContent` y tamaños legibles B/KB/MB/GB/TB/PB/EB con base 1024. Pruebas: `generated_file_catalog_mysql_test.php` cubre todos los JSON de la preparación multimodal controlada, tamaños UTF-8, propietario distinto de 1, reutilización, resultado S3 desconocido, permisos, nombres/protecciones conservados, carpetas y claves largas. `drive_knowledge_ui_test.js` comprueba unidades, negritas, nombres no ejecutables y el flujo existente de búsqueda/selección. Se mantiene la distinción entre pruebas controladas y comprobación real en producción.

### Panel de archivos y resultados por conversación y proyecto

El icono de carpeta junto al título abre un catálogo de solo lectura. El alcance de conversación incluye sus adjuntos y fuentes publicadas del proyecto; el alcance de proyecto reúne las conversaciones accesibles del mismo propietario y proyecto, excluyendo las eliminadas (`closed`). Los resultados se relacionan mediante la procedencia `michat_generated.source_file_id` de FileS3, incluyendo los vinculados al original de una copia de ArcadeCloud; los medios generados se relacionan mediante `session_id`. No se deduce pertenencia por coincidencias de nombres o prefijos arbitrarios, ni se inicia análisis, sincronización o enumeración de S3.

Cada lectura/descarga vuelve a validar alcance, propietario, permisos y vinculación con el original. La lectura de texto y JSON usa texto inerte, no ejecuta HTML/código y limita la vista previa al primer MiB, con aviso explícito; la descarga utiliza streaming del archivo completo. Los demás formatos usan los visores existentes. Los resultados se muestran como archivos registrados, sin afirmar que sean evidencia completa ni la revisión vigente. No se crean tablas ni se cambian originales.

El listado pagina de 50 en 50. Conserva los límites del catálogo existente (hasta 101 candidatos por consulta de adjuntos/fuentes y 100 vínculos explícitos) y declara posible parcialidad al alcanzar el umbral; para proyectos con más de 500 conversaciones limita el alcance y lo advierte. No lista JSON históricos que aún carezcan de registro: se incorporan cuando el flujo de reutilización verifica y registra sus claves conocidas. Los proyectos siguen utilizando el permiso de propietario existente; no se inventa membresía compartida.

Validación: `conversation_data_mysql_test.php` utiliza MariaDB aislada y objetos AWS controlados para probar copia real, JSON del origen, aislamiento de sesiones/proyectos/usuarios, fuentes publicadas, retiro, revocación y paginación. `conversation_data_panel_test.js` verifica navegación de alcance, lectura/descarga, tamaños legibles, nombres y JSON inertes y ausencia de llamadas de procesamiento. Esto no certifica despliegue ni permisos AWS de producción.

### Nombres visibles de los JSON derivados

Los nuevos resultados de MiChat se registran con `FileS3.Nombre` igual al nombre visible del origen más la operación, por ejemplo `legal actual.mp3 — Transcripción.json`, `informe.pdf — Contenido.json` o `informe.pdf — Análisis.json`. La copia de transcripción se identifica como `Transcripción (copia del chat)`. Se conserva íntegra la identidad `Encriptado`/`Ruta`, la convención de claves S3 y el contenido del JSON. Solo los nombres visibles demasiado largos se acortan para conservar el sufijo dentro de los 255 caracteres reales de la columna.

El panel, los resultados devueltos por la búsqueda y las descargas corrigen de forma gradual los nombres técnicos antiguos con procedencia verificada. El ajuste modifica únicamente `Nombre` y su marcador de nombre automático dentro de `Metadatos`, sin consultar ni escribir objetos S3. Los nombres personalizados se conservan, incluidos los de transcripciones existentes de ArcadeCloud; un nombre automático sigue el nombre actual del origen. No se necesita migración ni SQL manual. Las comparaciones al actualizar evitan sobrescribir un renombrado concurrente.

Prueba `derived_visible_names_mysql_test.php`: resultados reales del flujo controlado, corrección de registros antiguos desde el panel, persistencia del nombre, conservación de clave/ruta/tamaño/objetos, descarga con nombre legible, cambios del nombre original, etiquetas manuales, archivos protegidos y nombres Unicode largos. La comprobación utiliza MariaDB aislada y proveedores controlados; no valida un despliegue de producción.
