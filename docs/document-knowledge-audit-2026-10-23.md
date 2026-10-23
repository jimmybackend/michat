# MiChat / ArcadeCloud: auditoría y primer incremento verificable

Fecha: 2026-10-23. Base auditada: `477731c5dcfa030d5b612fc716951fec45ccae70`.

**La misión universal todavía no está terminada.** Este cambio corrige el acceso a evidencia documental y permite vincular archivos propios de Drive. No constituye certificación de producción ni de cobertura integral de documentos grandes. No se desplegó en EC2 y no se modificó MySQL de producción.

## Auditoría del recorrido real

Se inventariaron 522 archivos del checkout (499 dentro de `michat/` antes del cambio), los workflows, el esquema SQL versionado y las rutas de subida, extracción, visión, memoria, contexto, frontend, S3 y versiones. No existe AGENTS.md. La base de datos real no se consultó: los campos utilizados proceden del esquema y consultas existentes.

- `session_upload.php` guarda mediante `S3Manager::uploadFile`, registra FileS3 y ejecuta el servicio de documentos o imágenes. Almacenamiento exitoso no implica extracción/embeddings exitosos.
- `SessionAttachmentKnowledgeService` extrae contenido, genera file_chunk, intenta embeddings y crea un resumen con instrucciones configurables. `SessionImageKnowledgeService` produce visual_summary mediante Converse.
- `EmbeddingJobs` conserva reintentos; `process_embedding_queue.php` es la vía existente de mantenimiento. El cambio limita los intentos inmediatos a 8 bloques por defecto, configurable con `embedding_main._extra.attachment_immediate_blocks` (0–32). Los demás se encolan.
- `ChatContextPreparationService` y `bedrock_chat2.php` utilizan `ContextBuilder`, que consume `AttachmentContextRepository` y aplica `ContextRanker`. La anterior función de recuperación embebida en bedrock_chat2 fue retirada: no tenía otros consumidores.
- `FileVersions` exige proyecto. Las revisiones de conversaciones libres ya se conservan como objetos distintos y vínculos en FileS3.Metadatos. No se convirtió este mecanismo en un editor universal.

Problemas confirmados antes del cambio:

1. La recuperación descartaba bloques sin vector, y solo consideraba los primeros 200 con el modelo activo. Texto extraído podía quedar fuera de cualquier respuesta.
2. La ruta de contingencia seleccionaba prefijos de archivos, sin buscar el importe o término exacto; un archivo largo podía ocultar los demás.
3. La validación de sesión no volvía a validar FileS3 al recuperar cada archivo. Bloqueos/eliminaciones/vínculos revocados necesitaban una comprobación viva.
4. Los localizadores dentro del texto podían perderse al dividir una página, diapositiva u hoja en varios chunks. El normalizador cambiaba líneas e indentación del código.
5. Un resumen vectorizado podía producir un indicador de disponibilidad aunque faltasen embeddings de chunks. La extracción parcial no tenía un estado propio en el listado.
6. PPTX suponía que el número interno de la parte era el orden visible, y emparejaba notas por ese número. XLSX usaba el nombre interno de la hoja.
7. El límite de extracción sigue siendo 200.000 caracteres. SQLite sigue siendo una muestra. No existe un trabajo recuperable de extracción universal.

## Implementado

- Recuperación híbrida lexical/semántica independiente de que embedding_main esté activo. Solo compara vectores del mismo modelo y dimensión; rechaza componentes no finitos.
- Recorrido por clave id en lotes de 200; no hay un tope silencioso en el bloque 200. Presupuesto de 10 segundos, máximo 500 candidatos retenidos, 24 resultados incluidos vecinos y límite de contexto. Telemetría scan_complete y aviso al modelo cuando la búsqueda no termina.
- Diversidad por archivo, búsqueda explícita de nombres citados y recuperación de un fragmento vecino cuando hay espacio. Sigue siendo una comparación asistida sobre fragmentos; no demuestra equivalencia integral.
- Comprobación de propietario, sesión, Found, AccessType, ruta y vínculo. Los archivos secure se excluyen aunque exista un índice antiguo. Se valida también en el inspector.
- Lectura S3 condicionada al ETag, SHA-256 de fuente y chunks. Los nuevos índices con ETag se invalidan si HeadObject cambia o falla. Los índices antiguos sin ETag necesitan reindexación para comprobar revisión exacta.
- Localizadores estructurados en source_ids y citas SCB verificables mediante el inspector. Código/texto conserva líneas originales e indentación. Se diferencia resumen generado de evidencia original. No se añaden páginas o celdas no extraídas.
- PPTX usa presentation.xml y sus relaciones para el orden visible; las notas se resuelven por relación. Si falta el manifiesto solo se cita la parte interna, sin inventar posición.
- XLSX resuelve nombres de hojas desde workbook.xml. Conserva direcciones dispersas, fórmulas literales y valores cacheados, sin ejecutar cálculos.
- Rechazo de texto binario disfrazado, reconocimiento de UTF-8/UTF-16 con BOM, firma PDF y límites de tamaño/expansión ZIP. No se ejecutan archivos ni SQL de entrada.
- Las páginas sin texto dentro de un PDF mixto indican necesidad de OCR/visión. Se muestra extracción parcial en API y panel, sin contarla como índice íntegro listo.
- Panel 🧠: búsqueda y vínculo de hasta 100 archivos propios compatibles por conversación, reutilizando S3. Vincular no equivale a indexar; el botón Indexar prepara el contenido. Desvincular conserva el archivo y revoca su recuperación. AJAX refresca únicamente el listado, con protección frente a cambios de conversación y CSRF para vínculos.

## Permisos de ArcadeCloud revisados

Se contrastó el código de `jimmybackend/s3`: FileS3 pertenece a Users.id, y ambos sistemas consultan Users. ShareAccessService/ShareFileRepository verifican propietario y clave; ShareTokenStore conserva enlaces mediante tokens. FederationAccessRepository y FederationShareDriveRepository manejan permisos federados distintos, con expiración y decisiones.

Este incremento admite **archivos propios normales**, no importa concesiones federadas ni tokens públicos como permisos implícitos. No enumera el bucket, no permite una clave S3 arbitraria y no concede acceso entre usuarios. La integración de archivos compartidos sigue pendiente de un adaptador que consulte y revalide el permiso de origen en cada recuperación.

## Compatibilidad y límites reales

| Formato | Ruta disponible | Comprobación de este incremento |
|---|---|---|
| TXT/Markdown, código, SQL, JSON, YAML, XML, CSV/TSV | Texto seguro; sin ejecutar código, SQL o fórmulas | Texto extenso y coordenadas de líneas con fixtures ejecutables; CSV/TSV aún no tienen adaptador tabular de celdas |
| PDF digital | PdfParser opcional / Poppler | PDF real de dos páginas extraído con Poppler; importe localizado en página 2 |
| PDF escaneado | Textract existente como respaldo | Servicio AWS sin certificar; PDF mixto marca páginas sin texto. Falta OCR asíncrono integral |
| PPTX/PPSX | ZIP/XML, relaciones y notas | PPTX real generado, notas y orden visible distinto del número interno |
| XLSX/XLSM | ZIP/XML, celdas/fórmulas/valores cacheados | XLSX real generado con B25 y Z25 dispersas; no recalcula, expande fórmulas compartidas ni interpreta gráficos |
| SQLite | Apertura de solo lectura; muestra | Pruebas existentes ejecutadas: datos intactos y omisiones advertidas; no exporta integralmente |
| DOCX, RTF, ODT/ODS/ODP, EPUB | Adaptadores existentes | No certificados integralmente en este incremento. No afirmar preservación de diseño/tablas o páginas |
| DOC/XLS/PPT antiguos | antiword/catdoc/xls2csv/catppt/LibreOffice | Dependencias externas; no probados aquí. Conversores requieren endurecimiento adicional y aislamiento |
| JPG/PNG/WEBP/GIF | Converse vision existente | Guardas y contratos; servicio de visión AWS no probado en vivo |
| Audio/video | Almacenamiento | Sin ASR/fotogramas universales implementados |

Límites explícitos: 64 MiB por objeto para la ruta síncrona, 32 MiB por entrada ZIP y 128 MiB de expansión total, máximo 10.000 entradas. Esos límites rechazan la operación y no la marcan como completada. El límite textual de 200.000 caracteres se informa como extracción parcial; no se eliminó ni se sustituyó por una cola de extracción.

## Validación

- PHP 8.3.6 local; sintaxis de todos los PHP.
- 159 comprobaciones ejecutables de extracción/localizadores/ranking: incluye PDF real con Poppler, ZIP/XML reales de PPTX y XLSX, texto largo, binarios y UTF-16.
- 21 comprobaciones contra MariaDB 10.11.14 real, usando tablas TEMPORARY derivadas del DDL versionado. Comprueban recuperación tras el bloque 200, aislamiento entre usuarios, bloqueos, eliminación, vínculo/revocación, modelos incompatibles, persistencia de embeddings, fallo y cola de reintento, presupuesto inmediato e invalidación de ETag.
- S3 y Bedrock se sustituyen por dobles deterministas en esa integración local. No se presenta como prueba AWS.
- Prueba JavaScript ejecutable de búsqueda, vínculo, CSRF, desvinculación y cambio de conversación.
- Suite PHP y JavaScript existente y CI de PR deben pasar antes de fusionar. CI ahora instala ZIP/SQLite/DOM y aporta MariaDB para que las pruebas funcionales no se omitan por faltar extensiones o base de datos.

## Migración y actualización

**Sin migración SQL.** Se reutilizan FileS3, ChatSessions.meta, SessionContextBlocks.source_ids y EmbeddingJobs. No se cambia composer.json, composer.lock, vendor ni credenciales. Quitar los vínculos knowledge_file_ids deja intactos los objetos y los índices. Un rollback de código no elimina esas claves ni los nuevos localizadores.

Con el helper existente instalado en EC2:

```sh
sudo /usr/local/sbin/michat-updater check
sudo /usr/local/sbin/michat-updater apply
```

El helper valida rama, origen, fast-forward, dependencias y cambios locales. No reemplazarlo por reset --hard. Después de aplicar, reindexar los documentos de prueba desde 🧠 para generar localizadores y ETag. No ejecutar el volcado adbbmis1_Cloud.sql contra la base compartida.

## Prueba final de producción pendiente

1. Subir un PDF digital de dos páginas con un importe conocido solo en la segunda. Verificar objeto privado en S3, chunks, estado y ausencia de error de extracción.
2. Preguntar por el importe; comprobar respuesta, nombre, cita SCB y página real en el inspector. Confrontar siempre la fuente, no solo la respuesta.
3. Repetir con un término al final de código, una fórmula B25 de XLSX y notas de PPTX. Los archivos mayores al límite actual deben mostrar extracción parcial, nunca completitud.
4. Desactivar temporalmente el agente de embeddings en una cuenta de pruebas y comprobar búsqueda exacta sobre texto extraído; restaurarlo y procesar pendientes desde Mantenimiento. Estas operaciones no se ejecutaron en producción.
5. Vincular un archivo propio de Drive y verificar que no aparece otro objeto S3. Desvincularlo y comprobar que deja de aportar evidencia.
6. Repetir con una cuenta sin permiso, archivo secure y Found=0 en un entorno de pruebas: nunca recuperar su texto. No modificar archivos reales para simular borrado.
7. Revisar TokenUsage y EmbeddingJobs; no interpretar un resumen listo como todos los chunks completos.

Los puentes AWS revisados solo permiten estado/registro SSM; no ejecutan despliegue ni una sesión de integración de la aplicación. No se ampliaron sus operaciones.

## Trabajo pendiente para aceptar la misión universal

- Índice documental central independiente de la conversación, con reutilización de chunks entre contextos.
- Cola de extracción por páginas/lotes con checkpoints, leases, reintentos e idempotencia, sin límite de prefijo para archivos grandes.
- OCR asíncrono, transcripción y análisis de fotogramas, extracción multimodal de tablas/imágenes, y resumen jerárquico de todo el documento.
- Adaptador de permisos compartidos/federados con revocación en origen.
- Auditoría persistente de cada operación, comparaciones estructurales completas y edición/versionado/recuperación universal con pruebas.
- Certificación AWS/EC2 y prueba del criterio fundamental con el modelo real. Las pruebas locales no sustituyen esa aceptación.
