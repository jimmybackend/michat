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
