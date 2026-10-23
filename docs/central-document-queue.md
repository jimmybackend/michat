# Índice documental central y cola recuperable — Fase 13

Este incremento continúa el PR #119. Implementa un índice independiente de las conversaciones y una cola persistente para extracción, chunks y embeddings. No despliega servicios ni ejecuta migraciones en producción. La activación es explícita; el flag permanece desactivado por defecto.

## Comportamiento

- Subir un documento, o pulsar Indexar para un archivo propio vinculado desde Drive, encola una revisión identificada por usuario, FileS3, clave S3, ETag, formato y versión del pipeline.
- Dos chats autorizados que usan el mismo FileS3/revisión reutilizan la misma fuente, job y chunks. No se vuelve a subir el objeto original ni se crea una copia de S3.
- El worker extrae una vez y guarda el texto y su SHA-256 como checkpoint. La ruta nueva solicita todo el texto al adaptador, sin el prefijo de 200.000 caracteres del camino anterior.
- Los chunks se insertan en lotes de hasta 75 por defecto. Su ordinal y hash tienen un contrato idempotente: un reintento no reemplaza silenciosamente contenido ni duplica fragmentos.
- Cada embedding se guarda al completarse. Un reinicio continúa con los chunks que faltan. Por defecto se ejecutan cuatro llamadas por lote, con un máximo interno de dieciséis.
- El worker adquiere una lease de cinco minutos mediante compare-and-set. Los commits verifican token y vencimiento dentro de la transacción. Un worker reemplazado no puede publicar, avanzar el cursor ni marcar listo un documento.
- Fallos transitorios tienen backoff y un máximo de cinco fallos. Las interrupciones repetidas también consumen ese presupuesto cuando se recupera la lease. Reintentar explícitamente desde Indexar permite continuar el checkpoint; no borra el contenido ya válido.
- Un documento solo llega a `ready` cuando todos los chunks esperados tienen embeddings del modelo objetivo y el extractor no declaró omisiones. Las muestras SQLite o PDF mixtos incompletos conservan `coverage=partial` y no se anuncian como íntegros.
- Si embeddings están desactivados, el job queda `blocked` y la fuente `indexed`: su texto ya es consultable por búsqueda léxica. Al habilitar el modelo, pulsar Indexar reactiva únicamente la fase necesaria.
- Cambiar de modelo conserva extracción/chunks y agenda regeneración de vectores. El RAG nunca compara vectores de modelos distintos.
- Cambiar ETag o clave crea otra revisión del índice y conserva la anterior. La revisión antigua deja de aportar contexto si no coincide con el objeto actual.

## Tablas y migración

La migración nueva `michat/sql/fase13_document_knowledge.sql` añade exclusivamente:

| Tabla nueva | Finalidad |
|---|---|
| DocumentKnowledgeSources | Identidad de revisión, propietario, texto extraído, hashes, cobertura y progreso |
| DocumentKnowledgeChunks | Fragmentos, localizadores, ordinal único por revisión y vectores |
| DocumentKnowledgeJobs | Fase, cursor, lease, contador de fallos, disponibilidad y error |
| DocumentKnowledgeEvents | Auditoría de encolado, extracción, lotes, embeddings, fallos y finalización |

No altera FileS3, Users, ChatSessions, SessionContextBlocks, EmbeddingJobs ni sus registros. No elimina tablas, no desactiva restricciones, no modifica vendor/composer ni credenciales.

La migración forma parte del catálogo cerrado del runner. El perfil histórico `current-dump` sigue registrando las catorce migraciones del dump; Fase 13 queda pendiente y se aplica después. La ruta de actualización desde post-Fase10D conserva sus cuatro migraciones adoptadas y pasa a once pendientes. Si ya existe alguna tabla Fase 13 sin historial, el runner se detiene: no intenta reparar un estado parcial automáticamente.

Antes de activar en una instalación existente:

```sh
cd /var/www/michat
php michat/bin/migrations.php status
```

Revisar que la historia existente sea coherente y que la única migración pendiente esperada sea `fase13_document_knowledge`. Solo entonces:

```sh
php michat/bin/migrations.php apply
php michat/bin/migrations.php status
```

Si el estado es UNINITIALIZED, DRIFT, UNKNOWN o parcial, no aplicar a ciegas ni importar el dump: resolver la historia con el procedimiento existente del despliegue. Un baseline no es una operación para repetir sobre una base ya versionada.

## Activación y operación

1. Revisar y aplicar la migración con el entorno DB ya existente.
2. Configurar `MICHAT_DOCUMENT_KNOWLEDGE_ENABLED=1` en el entorno efectivo tanto de PHP-FPM/web como del worker. `.env.example` conserva `0` para que actualizar código no active cambios operativos.
3. Probar el worker manual con el usuario y entorno reales del despliegue:

```sh
php michat/bin/document_knowledge_worker.php --max-batches=1
```

El argumento es obligatorio y admite 1 a 99. Cada ejecución tiene un número finito de lotes, no un daemon infinito. La salida informa job, fase, estado y chunks; los errores se guardan en la cola y en eventos.

4. Revisar los ejemplos `deploy/systemd/michat-document-worker.service` y `.timer` antes de instalarlos. Comprobar usuario/grupo real, ruta de PHP, permisos, variables DB/AWS efectivas y capacidad de memoria. El ejemplo limita memoria a 512 MiB, ejecución a treinta minutos y usa temporales privados. No se instala ni habilita por Git o por la migración. No se modificaron unidades existentes del worker de tareas.
5. Ejecutar periódicamente `--max-batches=12`, mediante el mecanismo operativo aprobado. El timer de ejemplo espera un minuto desde que termina la ejecución anterior. No asumir que configurar el flag inicia el worker.

El worker no requiere una sesión web y no ejecuta código, macros ni SQL aportado como contenido documental. Reutiliza los adaptadores de extracción existentes; los conversores heredados siguen necesitando el endurecimiento indicado en la auditoría anterior. Los permisos de S3/Bedrock siguen siendo los de Config y del despliegue actual; no se amplían roles, buckets ni el puente AWS.

## Permisos, RAG e inspección

El índice central no concede permisos. El encolado verifica sesión propia y archivo propio permitido; el worker vuelve a comprobar propietario, Found, bloqueo, clave, formato y ETag antes de procesar cada lote. La recuperación verifica la pertenencia a la conversación o vínculo explícito, FileS3 y revisión S3 actuales. No enumera todo el bucket ni recupera archivos privados de otros usuarios.

Los archivos compartidos/federados aún requieren un adaptador específico de grants: este incremento continúa admitiendo solo archivos propios normales. Desvincular un archivo revoca su recuperación en ese chat; no cancela el índice central independiente del usuario ni elimina el original.

El RAG híbrido y ContextBuilder consumen los nuevos chunks mediante la misma política de selección. Las citas centrales usan `DKC:<id>` y tienen un enlace verificable a la pieza exacta del inspector, incluso si está después de la primera página. El inspector pagina los chunks centrales de 200 en 200; los contadores indican explícitamente que corresponden a la página mostrada. Los resúmenes anteriores válidos pueden seguir participando como resúmenes generados, no como citas literales.

El panel 🧠 muestra fase, estado, chunks procesados/esperados, caracteres extraídos y errores. Actualiza únicamente su listado cada cinco segundos mientras haya un job pendiente y el panel siga abierto en la misma conversación. No recarga los mensajes. Un cambio de modelo no muestra índices antiguos como vectores listos del modelo actual.

## Límites y trabajo que sigue pendiente

- Objeto S3: presupuesto actual de 64 MiB, heredado del lector seguro. Texto extraído: máximo 8 MiB o el presupuesto menor que permita max_allowed_packet con margen. Excederlo produce fallo visible y no guarda un prefijo como si fuera completo.
- El adaptador realiza la extracción inicial como una operación acotada; todavía no hay checkpoints por página durante OCR/extracción. Si el worker muere antes de guardar ese primer checkpoint, esa extracción se repetirá. Chunks y embeddings sí se reanudan por lotes desde MySQL.
- El texto extraído se conserva en MySQL como checkpoint privado. No hay todavía una política automática de retención/compactación; no ejecutar limpiezas destructivas para liberar espacio.
- Las omisiones declaradas por SQLite/PDF se conservan. Los formatos y límites de los adaptadores no se convierten en soporte universal por usar una cola.
- Imágenes continúan por el servicio visual existente. OCR asíncrono, tablas/imágenes multimodales integrales, audio/video, resúmenes jerárquicos completos, versiones universales y grants federados siguen pendientes.
- Los eventos incluyen modelo y tokens de entrada reportados por embeddings; no se inventa un session_id para escribir cargos en TokenUsage. La agregación de costos de estos jobs por usuario/proyecto necesita integración posterior.
- No hay certificación AWS/EC2 en vivo. Los tests de esta entrega sustituyen S3/Bedrock por dobles controlados, con MySQL/MariaDB reales para comprobar persistencia y migración.

## Validación y aceptación

Tests nuevos cubren índice compartido entre dos chats, idempotencia, recuperación de lease, rechazo de worker vencido, texto mayor de 200.000 caracteres, checkpoint persistente, embeddings fallidos/listos/desactivados, cambio de modelo y revisión, bloqueo y revocación, límite de recursos, presupuesto de reintentos, auditoría, ContextBuilder real y paginación/cita exacta. La prueba JavaScript cubre que el polling se detenga al cerrar/cambiar conversación o terminar el job.

CI ejecuta la suite completa, integración de cola con MariaDB 10.11 e integración real del runner/baseline/upgrade con MySQL 8, además de los fixtures PDF/PPTX/XLSX del incremento anterior. No sustituir estos resultados por una prueba estática.

Después de activar en un entorno autorizado:

1. Subir un TXT/PHP mayor a 200.000 caracteres con un importe único en la última línea; confirmar S3, job, avance, chunks y embeddings.
2. Esperar al estado final y preguntar por el importe. Abrir el enlace DKC, comprobar contenido y línea real. No aceptar una respuesta del modelo como prueba de extracción completa.
3. Vincular el mismo FileS3 desde otro chat del mismo usuario; pulsar Indexar y verificar que se reutilizan source_id/job_id y los mismos chunks, sin otro upload S3.
4. En una cuenta/entorno de pruebas, detener y reanudar el worker tras un lote. Verificar cursor y ausencia de duplicados.
5. Comprobar un fallo controlado de embeddings: texto consultable, job no listo, reintento recuperable. No provocar fallos modificando credenciales de producción.
6. Desvincular y bloquear archivos de prueba: no deben seguir aportando evidencia. Comprobar aislamiento con otro usuario.

## Rollback sin pérdida de información

Desactivar el flag en web/CLI y detener el timer/worker documental si se instaló. Se vuelve a la ruta anterior y los índices/historial Fase 13 se conservan. Los originales S3 no cambian. No borrar tablas ni filas de SchemaMigrations. Restaurar código antiguo no exige destruir datos; para volver a activar, conservar el código/runner que conoce esta migración y comprobar `status` antes de arrancar el worker.
