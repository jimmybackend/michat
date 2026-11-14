# Puente privado MiChat ↔ Esforzados Legal

**Estado: pruebas aisladas de aceptación aprobadas en CI para fusión de código, con el puente apagado. NO activar ni aplicar migraciones en producción.**

MiChat es el propietario de las sesiones, los archivos S3, el índice documental, la memoria y el runtime Bedrock. Esforzados Legal conserva sus expedientes, documentos originales y la aprobación de cambios. Nunca se comparte una conexión MySQL entre Legal y MiChat ni se incrusta la interfaz del chat.

## Componentes reales

| Función | Servicio real |
| --- | --- |
| Bootstrap / secretos | `app_bootstrap.php`, `Config/EnvironmentLoader.php`, `/etc/michat.env` |
| Identidad | `LegalBridgeAuth` + `LegalBridgePrincipals` + `Users` |
| Sesión | `LegalBridgeSessionService` → `SessionLifecycleService` |
| S3 privado | `S3Manager::uploadFile`, `FileS3`, `DocumentAccessRepository` |
| Indexación | `SessionAttachmentKnowledgeService::process` → `DocumentKnowledgeService::enqueue` |
| Estados | `CentralDocumentIndex::sources/status` y `DocumentKnowledgeJobs` |
| IA / RAG | `ChatExecutionServiceFactory`, `ChatExecutionService`, `ChatContextPreparationService`, `ContextBuilder`, `BedrockChatRuntime` |
| Memoria y tokens | `ChatMemoryFinalizationService`, `ChatTokenUsageService` |
| Progreso | `ChatActivityTelemetryService`, `ChatActivityEvents` |

No se implementa otro extractor, motor RAG, embedding ni cliente Bedrock. `ChatExecutionService` se utiliza con persistencia automática Task deshabilitada; el adaptador Legal persiste el turno en `ChatMessages` y llama a los servicios existentes de memoria y uso de tokens. Una compuerta deniega **toda** ejecución de herramientas del modelo, incluso si este devuelve `tool_use` inesperado.

## Migración y tablas

La migración aditiva `michat/sql/fase15_legal_bridge.sql` está registrada en `MigrationCatalog` y protegida por `MigrationRunner::assertPendingPreState`. Su ejecución requiere aprobación administrativa, **no forma parte de esta entrega**.

Tablas nuevas: `LegalBridgeClients` (credenciales habilitadas, sin clave secreta), `LegalBridgePrincipals` (identificador externo → usuario autorizado MiChat), `LegalBridgeNonces` (protección de replay), `LegalBridgeSessions` (vínculo externo a sesión + revisión activa + instrucciones), `LegalBridgeDocuments` (revisión hash → FileS3 privado). Se reutilizan `Users`, `UserPreferences`, `ChatSessions`, `ChatMessages`, `FileS3`, `SessionContextBlocks`, `ChatActivityEvents`, `TokenUsage`, `EmbeddingJobs` y las tablas centrales `DocumentKnowledgeSources`, `DocumentKnowledgeJobs` y `DocumentKnowledgeChunks`.

`michat/bin/migrations.php status` debe mostrar la historia coherente antes de plantear una ejecución. `apply` aplica **todas** las migraciones pendientes, no únicamente fase15. El historial y esquema real de EC2 no se han consultado ni modificado en este PR. No importar el dump de agosto a producción.

### Identidad multiusuario

El servidor Legal autentica al usuario en su propia plataforma y envía `external_subject` (identificador opaco estable de esa cuenta) dentro del **JSON firmado**. La tabla `LegalBridgePrincipals` debe contener previamente una asociación aprobada entre ese sujeto externo y `Users.id` de MiChat, con `enabled=1`. No se crea ni promueve ningún usuario mediante la API. Un mismo documento externo (por ejemplo `amparo_indirecto/4`) de dos sujetos distintos genera sesiones diferentes. Si no hay autorización preexistente, se rechaza la operación con 403. El servidor HMAC acredita a Legal, pero **no sustituye** los permisos de usuario: el vínculo de principal y la propiedad de sesión/documento se vuelven a validar en cada petición.

Administración de alta: el administrador comprueba la existencia de los usuarios MiChat, decide el emparejamiento específico con cada cuenta Legal, inserta las filas `LegalBridgeClients` y `LegalBridgePrincipals` en un proceso aprobado y registra la evidencia administrativa fuera de la API. No autorizar `user_id` recibido desde HostGator como principal MiChat. La clave ya creada no se almacena en MySQL.

## Transporte y firma HMAC

Endpoint: `POST https://chat.esforzados.com/api/legal-bridge.php`. Solo HTTPS verificado por PHP-FPM/servidor; no se confía en `X-Forwarded-Proto` introducido por el cliente. No cookies, CORS ni tokens de navegador. Cuerpo JSON, máximo 1 MiB; `document.attach` acepta texto UTF-8 de hasta 512 KiB. Hora UTC Unix, desviación máxima ±300 s. Por credencial: 60 peticiones aceptadas por minuto; nonces aleatorios hexadecimales minúsculos de 32–64 caracteres con unicidad por clave, conservados para impedir replay.

Encabezados:

```text
Content-Type: application/json
X-Legal-Key-Id: legal-hostgator
X-Legal-Timestamp: 1794700000
X-Legal-Nonce: <hex_aleatorio_32_a_64>
X-Legal-Signature: <hmac_sha256_hex_64>
```

**La firma cubre exactamente los bytes del cuerpo enviado**, incluidos `external_subject`, `operation` y `data`.

Cadena canónica: seis líneas unidas por cinco bytes LF (`\n`) **sin LF final**:

```text
POST
/api/legal-bridge.php
<sha256_hex_minusculo_del_cuerpo>
<timestamp_unix>
<nonce>
legal-hostgator
```

Firma `hash_hmac('sha256', $canonical, $secret)` con comparación temporal constante `hash_equals()`. Rechazar firmas inválidas, solicitudes antiguas, métodos/rutas ajenos, nonces repetidos y cuentas no vinculadas. Los logs no contienen secretos, documentos ni la respuesta íntegra de IA. Los registros caducados en `LegalBridgeNonces` se limpian por una tarea administrativa aprobada, no por cada petición.

### Ejemplo PHP de firma desde Legal

```php
$payload=[
  'external_subject'=>'cuenta-legal-interna-123',
  'operation'=>'health',
  'data'=>new stdClass(),
];
$body=json_encode($payload, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$ts=(string)time();
$nonce=bin2hex(random_bytes(24));
$keyId='legal-hostgator';
$canonical=implode("\n",['POST','/api/legal-bridge.php',hash('sha256',$body),$ts,$nonce,$keyId]);
$signature=hash_hmac('sha256',$canonical,$secretFromPrivateConfig);
// Enviar $body SIN volver a serializar, con los encabezados anteriores.
// Verificar certificado TLS; nunca usar CURLOPT_SSL_VERIFYPEER=false.
```

## Contrato de operaciones

Todos los cuerpos JSON comparten esta envoltura:

```json
{"external_subject":"cuenta-legal-interna-123","operation":"health","data":{}}
```

Las respuestas JSON usan `ok`; los fallos devuelven `{"ok":false,"error":{"code":"codigo_estable"}}` con el código HTTP correspondiente.

### `health`

Entrada `data:{}`. Si autenticación, habilitación y BD funcionan responde `{"ok":true,"enabled":true,"authenticated":true,"services":{"database":"available"}}`. No prueba de forma automática S3 ni Bedrock.

### `session.open`

```json
{"external_subject":"cuenta-legal-interna-123","operation":"session.open","data":{
 "external_system":"esforzados-legal",
 "external_document_type":"amparo_indirecto",
 "external_document_id":"4",
 "external_revision":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
 "title":"Revisión jurídica",
 "session_instructions":"Distinguir hechos, fuentes y datos por comprobar."
}}
```

Devuelve `{"ok":true,"session_id":123,"created":true,"status":"open"}` (ID ilustrativo). Es idempotente para `credential_id + user_id_ + external_system + external_document_type + external_document_id`. Las instrucciones iniciales están limitadas a 3.000 caracteres y se incorporan como contexto **de usuario**; no son instrucciones privilegiadas del sistema. Abrir sesión no adjunta ni indexa nada.

### `document.attach`

```json
{"external_subject":"cuenta-legal-interna-123","operation":"document.attach","data":{
 "session_id":123,
 "revision_sha256":"<sha256_hex_de_los_bytes_utf8>",
 "text":"HECHOS\nTexto del borrador..."
}}
```

Acepta solamente texto UTF-8 hasta 512 KiB, con hash SHA-256 que coincida con los bytes exactos. S3Manager crea un archivo `text/plain` privado e independiente en `Data/Chat/Uploads/<usuario>/<año>/<mes>/<día>/<sesión>/`. Se registra en FileS3 + LegalBridgeDocuments. El original almacenado por Legal nunca se lee, modifica ni reemplaza. Una revisión ya existente reutiliza el objeto y puede volver a solicitar su encolado. La activación de otra revisión agrega los archivos anteriores a `ChatSessions.meta.detached_file_ids`; el retriever actual debe excluirlos. Ningún objeto ni índice histórico se elimina.

Respuesta 202 nueva revisión o 200 reutilizada, con `file_id`, `revision_sha256`, `created`, `status:"pending"` e `index_enqueue:"accepted"|"failed"`. No afirmar `ready` tras subir el objeto. Requiere `MICHAT_DOCUMENT_KNOWLEDGE_ENABLED=1` y su esquema/worker operativo. El encolado es independiente de que el worker concluya.

### `document.status`

Entrada `{"session_id":123}`. Responde el estado de la **revisión activa**, `file_id` si existe, `revision_sha256`, `status`, `coverage`, `chunks_total`, `chunks_done` y `chunks_with_embedding`. Estados de contrato: `pending`, `extracting`, `indexing`, `ready`, `partial`, `error`. La condición `ready` requiere job completado, cobertura, todos los chunks vectorizados y modelo de embedding vigente. `partial` no acredita revisión exhaustiva. No usar el fichero S3 como señal de `ready`.

### `chat.send`

```json
{"external_subject":"cuenta-legal-interna-123","operation":"chat.send","data":{
 "session_id":123,
 "revision_sha256":"<sha256_activo>",
 "message":"Revisa los conceptos de violación y señala qué información falta."
}}
```

Requiere sesión abierta, revisión actual con acceso vigente y estado documental `ready` o `partial`. Preguntas máximas de 12.000 caracteres. Se serializa la conversación por sesión mediante lock MySQL para evitar doble ejecución simultánea. Usa ContextBuilder/Attachment RAG, Bedrock runtime y memoria existente. Herramientas de lectura/escritura arbitrarias **denegadas**. Persiste `ChatMessages`, llama a telemetría, uso de tokens y memoria existentes. Devuelve `reply`, `session_id`, `message_id`, `assistant_message_id`, `trace_id`, `revision_sha256` y `coverage`. Las citas, si hay fragmentos seleccionados, se conservan en la respuesta del motor; este adaptador aún **no** garantiza un arreglo independiente de evidencias verificadas.

### `chat.history`

Entrada `{"session_id":123,"cursor":0,"limit":50}`. Devuelve `messages`, `next_cursor`, `has_more`, orden por ID ascendente y únicamente roles `user/assistant`. Cursor exclusivo. Límite 1–100. Toda lectura vuelve a validar propietario.

### `chat.activity`

Entrada igual a historial. Devuelve eventos ya persistidos de `ChatActivityEvents` ordenados por ID y restringidos a la sesión/usuario. Campos: `id_`, `trace_id`, `phase`, `event_key`, `status`, `title`, `duration_ms`, `created_at`. No devuelve `details_json`, contenido completo, porcentajes inventados ni razonamiento privado.

### `document.suggestions`

```json
{"external_subject":"cuenta-legal-interna-123","operation":"document.suggestions","data":{
 "session_id":123,
 "revision_sha256":"<sha256_activo>",
 "section":"Conceptos de violación"
}}
```

Se ejecuta como un turno del motor compartido con instrucción de formato JSON. Respuesta con `suggestions` (máximo 12), cada propuesta con `section`, `original`, `proposed`, `justification`, `information_used`, `evidence_references` y `pending_confirmation`; `requires_review:true` y `saved_to_legal:false`. Si el modelo no devuelve JSON válido, responde `status:"invalid_model_output"` y lista vacía. **No escribe documentos de Legal ni confirma jurídicamente sus propuestas.** Las citas que el modelo incluya en texto estructurado requieren verificación antes de su aplicación.

## Errores y límites

- 400: `invalid_json`, `invalid_payload`, `unknown_operation`
- 401: `invalid_credentials`, `invalid_signature`, `request_expired`
- 403: `https_required`, `principal_not_authorized`, `document_forbidden`, `revision_forbidden`, `tools_forbidden`
- 404: `route_not_allowed`, `session_not_found`
- 405: `method_not_allowed`
- 409: `replayed_nonce`, `revision_unavailable`, `session_busy`, `session_not_open`, `document_not_ready`, `revision_limit`
- 413: `body_too_large`
- 422: `invalid_subject`, `invalid_document_reference`, `invalid_document_revision`, `invalid_message`, `invalid_suggestion_request`, `invalid_session_instructions`
- 429: `rate_limited`
- 503: `bridge_disabled`, `bridge_unavailable`, `document_pipeline_disabled`, `document_attach_failed`, `model_not_configured`, `chat_execution_failed`, `model_response_unavailable`

La infraestructura interna nunca se revela en errores HTTP. Las acciones que pueden haber persistido un mensaje o un objeto antes de un fallo no deben reintentarse a ciegas sin comprobar historial/revisión.

## Seguridad jurídica y RAG

El documento adjunto y sus instrucciones embebidas son **datos no confiables**. El modelo no debe convertir citas de documentos en instrucciones del sistema. Las instrucciones legales específicas son contexto de usuario, subordinadas a las políticas y controles técnicos. Distinguir narraciones, documentos y conclusiones; preservar declaraciones, autoridades, actos reclamados, conceptos de violación, suspensión y marcadores `[PENDIENTE]`; señalar contradicciones y datos no comprobados; preguntar un dato a la vez cuando convenga; no inventar normas, jurisprudencia, fechas, pruebas ni hechos; no asegurar que el amparo fue presentado. La decisión jurídica definitiva corresponde a la defensa.

Cuando cambia la revisión, el índice anterior permanece histórico pero se separa de la recuperación de la sesión mediante los controles de archivo activo y los estados de revisión. El historial conversacional de la sesión permanece, y las respuestas deben etiquetarse por `revision_sha256`. El envío de una nueva versión no altera el original Legal.

## Activación, despliegue y reversión

**No ejecutar estos pasos en esta tarea.** Antes de producción revisar el PR, tests, configuración de workers e historial real:
```sh
cd /var/www/michat
git status --short
php michat/bin/migrations.php status
```

No ejecutar `apply` ni `git pull` sin aprobación de despliegue y respaldo; `apply` puede incluir más migraciones pendientes. La autorización para fusionar código NO autoriza ejecutarlas. El bootstrap carga `MICHAT_ENV_FILE` o `.env`; el endpoint también intenta `/etc/michat.env` con `EnvironmentLoader`. Se debe confirmar que PHP-FPM puede leer el archivo privado y que el HTTPS efectivo se comunica como `HTTPS` al proceso PHP.

Conservar `LEGAL_BRIDGE_ENABLED=false` hasta completar pruebas con credencial ficticia en infraestructura de ensayo, vinculación administrativa de usuarios y aceptación operacional de procesamiento central. Para desactivar, cambiar `LEGAL_BRIDGE_ENABLED=false` bajo procedimiento aprobado y recargar la configuración efectiva de PHP-FPM. No borrar tablas, archivos ni conversaciones para desactivar.

## Pruebas y límites de certificación

Tests automáticos existentes: `michat/tests/legal_bridge_auth_test.php` (HMAC puro), `michat/tests/legal_bridge_mysql_contract.php` (usado por `migration_runner_mysql_test.php` sobre MySQL descartable), y workflow `.github/workflows/legal-bridge-tests.yml`. La suite general `.github/workflows/fase12b-validation.yml` ejecuta lint PHP, regresión, migraciones y contrato de catálogo cerrado.

**Pruebas aisladas completadas.** Se ejecutaron sobre MySQL temporal y dobles de S3/embeddings/Bedrock pruebas de adjuntos y revisiones, indexación completa/parcial/error, RAG filtrado por revisión, memoria acotada, propuestas no autorizadas, errores de Bedrock y protección del original ficticio. Evidencia en `docs/legal-bridge-acceptance.md`. **No confundir estas pruebas con validación AWS/HostGator real ni activación de producción.**

## INSTRUCCIONES PARA EL PROYECTO ESFORZADOS LEGAL

Crear un **cliente PHP del lado de HostGator** con `LEGAL_MICHAT_BASE_URL=https://chat.esforzados.com`, `LEGAL_MICHAT_KEY_ID=legal-hostgator` y `LEGAL_MICHAT_SECRET` en configuración **privada** (solo una copia administrada del secreto ya existente; no regenerarlo ni subirlo a GitHub). Mantener validación TLS y construir siempre un cuerpo JSON con `external_subject` autorizado, `operation` y `data`. Firmar los bytes exactos como se indica arriba; generar un nonce nuevo para cada solicitud. No implementar HMAC en JavaScript ni devolver el secreto al navegador.

1. Legal autoriza al usuario y al expediente en **su propia base** antes de llamar MiChat. El sujeto externo debe tener una vinculación aprobada con MiChat; nunca enviar un `user_id` MiChat.
2. Para un amparo `external_document_type=amparo_indirecto` y `external_document_id` estable, llamar `session.open` y conservar `session_id` de manera privada por usuario/documento.
3. Calcular `revision_sha256` sobre los bytes UTF-8 **exactos** que se adjuntarán. Enviar copia mediante `document.attach`; jamás transferir la fuente original para que MiChat la modifique.
4. Consultar `document.status` hasta recibir `ready` o aceptar conscientemente `partial`. Si hay `error`, mostrarlo y no inventar un estado.
5. Para cada mensaje enviar `session_id`, `revision_sha256` y la pregunta; recuperar `chat.history` con cursor, y `chat.activity` para el progreso real.
6. Solicitar `document.suggestions` únicamente para apartados concretos. Mostrar diferencias y datos por confirmar. Legal validará referencias y será el único sistema que permita aprobar y guardar los cambios en su base.
7. No activar cliente real hasta que PR #144 esté completamente probado, fusionado según autorización y el puente esté activado administrativamente.

La interfaz Legal puede ser completamente distinta de MiChat; no utilizar iframe ni copiar HTML/CSS/JS del chat.

## Concurrencia y aislamiento por revisión

Un único bloqueo MySQL GET_LOCK (nombre menor a 64 caracteres) serializa por `credential_id + user_id_ + session_id_` tanto `document.attach` como `chat.send`. De este modo no se activa otra revisión mientras se construye la respuesta de un turno. El adaptador pasa `legal_revision` a `ChatContextPreparationService`; para Legal se excluyen memorias globales/de proyecto y preguntas de otras sesiones. `SessionMemoryRepository` filtra los mensajes recientes y `SessionContextBlocks` por la revisión anotada en `ChatMessages.meta`, sin incorporar resúmenes globales de revisiones previas. El RAG documental mantiene las comprobaciones de `DocumentAccessRepository`, `CentralDocumentIndex` y `detached_file_ids`.

El código queda preparado para ser fusionado con `LEGAL_BRIDGE_ENABLED=false`, sin alterar infraestructura; la activación de red, las claves, la migración y la vinculación administrativa de usuarios siguen siendo operaciones separadas, sujetas a autorización explícita.
