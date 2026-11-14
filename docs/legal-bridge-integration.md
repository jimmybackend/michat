# MiChat ↔ Esforzados Legal — puente privado (desarrollo, NO activado)

> Estado de esta rama: **implementación parcial, no apta para activar ni fusionar**. La API implementa la frontera HMAC, `health`, `session.open`, `chat.history` y `chat.activity`. `document.attach`, `document.status`, `chat.send` y `document.suggestions` responden HTTP 501. No representa el contrato final solicitado.

## Arquitectura y código reutilizado

- `michat/api/legal-bridge.php`: transporte JSON sin cookies y con HTTPS obligatorio.
- `michat/includes/LegalBridge/LegalBridgeAuth.php`: HMAC SHA-256, reloj ±300 s, nonce persistido, rate limit (60 peticiones/minuto/credencial), vínculo cliente→usuario MiChat.
- `michat/includes/LegalBridge/LegalBridgeSessionService.php`: `SessionLifecycleService::create()`, sesión idempotente y lecturas de `ChatMessages`/`ChatActivityEvents` filtradas por vínculo y propietario.
- `docs/sql/legal_bridge.sql`: tablas aditivas propuestas, NO aplicadas.
- Motor por integrar: `ChatExecutionService`, `ChatExecutionServiceFactory`, `ChatContextPreparationService`, `ContextBuilder`, `SessionAttachmentKnowledgeService`, `DocumentKnowledgeService`, `DocumentAccessRepository` y mecanismos actuales de copias de trabajo. **No ejecutar Bedrock directamente ni subir objetos por un camino paralelo.**

El esquema SQL suministrado el 18 de agosto de 2026 contiene `ChatSessions`, `ChatMessages`, `ChatActivityEvents`, `FileS3`, `SessionContextBlocks`, `EmbeddingJobs`, `FileVersions`, `ProjectSources` y `SourceChunks`. NO se debe equiparar ese respaldo histórico con el esquema de producción: Fase 13 introduce `DocumentKnowledgeSources`, `DocumentKnowledgeChunks`, `DocumentKnowledgeJobs` y `DocumentKnowledgeEvents` mediante su propia migración.

## Despliegue seguro

**No activar todavía.** Conservar `LEGAL_BRIDGE_ENABLED=false`. El archivo privado `/etc/michat.env` ya existente no debe regenerarse ni copiarse a GitHub. `app_bootstrap.php` carga `MICHAT_ENV_FILE` y el `.env` del checkout; el endpoint adicionalmente intenta leer `/etc/michat.env` mediante `EnvironmentLoader`, que preserva los valores ya establecidos en el entorno. Verificar permisos efectivos de lectura del proceso web antes de pruebas.

El SQL propuesto se guarda fuera del catálogo cerrado y **no se ejecuta automáticamente**. Revisar respaldo e historial de migraciones con `php michat/bin/migrations.php status`, confirmar tablas y claves en un entorno descartable, registrar la migración dentro del catálogo aprobado si corresponde y **solicitar autorización antes de ejecutarla en producción**. Las tablas `LegalBridgeClients`, `LegalBridgeNonces`, `LegalBridgeSessions` no contienen el secreto HMAC. El administrador debe crear por separado una fila `LegalBridgeClients` con `credential_id='legal-hostgator'`, `user_id_` del usuario MiChat autorizado y `enabled=1`. La API nunca acepta `user_id` para seleccionar principal. Para múltiples usuarios, el modelo actual de una sola credencial **no es suficiente**: se necesita un diseño de identidad por usuario aprobado antes de la activación.

Comprobar el enrutamiento público: con el docroot correspondiente al directorio `michat/`, la ruta es `https://chat.esforzados.com/api/legal-bridge.php`. Esta implementación exige `HTTPS=on` o equivalente confiable proporcionado por el servidor a PHP; rechaza peticiones cuando HTTPS no está acreditado. No confiar en encabezados `X-Forwarded-Proto` del cliente.

## Contrato HMAC (versión de desarrollo)

Solo `POST /api/legal-bridge.php`, sin query string, `Content-Type: application/json`, máximo **1,048,576 bytes** de cuerpo. Encabezados obligatorios:
- `X-Legal-Key-Id: legal-hostgator`
- `X-Legal-Timestamp: <segundos Unix decimales de 10 dígitos>`
- `X-Legal-Nonce: <32 a 64 caracteres hexadecimales minúsculos, aleatorios>`
- `X-Legal-Signature: <64 caracteres hexadecimales minúsculos>`

La cadena a firmar es la unión mediante **cinco saltos de línea LF (byte 0A)** de las siguientes seis líneas, sin salto final:

```text
POST
/api/legal-bridge.php
<sha256_hex_minusculo_del_cuerpo_json_exacto>
<timestamp>
<nonce>
legal-hostgator
```

La firma es `hash_hmac('sha256', $canonical, $secret)` representada en hexadecimal minúsculo. La firma cubre los bytes **exactos** del JSON enviado; no normalizar ni volver a serializar entre cálculo y transporte. Comparación del servidor con `hash_equals()`. Se rechazan solicitudes fuera de ±300 segundos y reutilizaciones de nonce. La tabla de nonces requiere limpieza controlada de filas vencidas; no ejecutarla desde cada petición. No usar CORS ni cookies como identidad.

Ejemplo de cliente PHP (sin incluir la clave real):

```php
$body = json_encode([
  'operation' => 'health',
  'data' => new stdClass(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$timestamp=(string)time();
$nonce=bin2hex(random_bytes(24));
$keyId='legal-hostgator';
$canonical=implode("\n",[
  'POST','/api/legal-bridge.php',hash('sha256',$body),$timestamp,$nonce,$keyId
]);
$signature=hash_hmac('sha256',$canonical,$secretFromHostgatorEnvironment);
$headers=[
  'Content-Type: application/json',
  'X-Legal-Key-Id: '.$keyId,
  'X-Legal-Timestamp: '.$timestamp,
  'X-Legal-Nonce: '.$nonce,
  'X-Legal-Signature: '.$signature,
];
```

## Operaciones disponibles

Las solicitudes usan objeto JSON `{"operation":"...","data":{...}}`.

| Operación | Estado | Datos |
| --- | --- | --- |
| `health` | Implementada | `{}` → `{"ok":true,"enabled":true,"authenticated":true,"services":{"database":"available"}}` |
| `session.open` | Implementada | `external_system`, `external_document_type`, `external_document_id`, `external_revision` (SHA-256 hexadecimal 64), `title` |
| `chat.history` | Implementada | `session_id`, `cursor` (id exclusivo), `limit` (1–100) |
| `chat.activity` | Implementada | Igual; devuelve eventos operacionales sin `details_json` |
| `document.attach` | **501** | No implementada |
| `document.status` | **501** | No implementada |
| `chat.send` | **501** | No implementada |
| `document.suggestions` | **501** | No implementada |

Ejemplo de apertura:

```json
{
  "operation":"session.open",
  "data":{
    "external_system":"esforzados-legal",
    "external_document_type":"amparo_indirecto",
    "external_document_id":"4",
    "external_revision":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
    "title":"Revisión jurídica de amparo"
  }
}
```

Respuesta `{"ok":true,"session_id":123,"created":true,"status":"open"}` (ID ilustrativo). Una nueva apertura del **mismo documento** recupera la misma sesión y devuelve `created:false`. En esta fase el hash inicial queda registrado como referencia, **no como archivo indexado**; una nueva revisión aún no puede adjuntarse. Las lecturas de historial y actividad validan cada vez la credencial, el usuario y la sesión autorizada.

Los errores se devuelven como `{"ok":false,"error":{"code":"..."}}`. Códigos: 400 (`invalid_json`, `invalid_payload`, `unknown_operation`), 401 (`invalid_credentials`, `invalid_signature`, `request_expired`), 403 (`https_required`, `principal_not_authorized`), 404 (`route_not_allowed`, `session_not_found`), 409 (`replayed_nonce`), 413 (`body_too_large`), 422 (`invalid_document_reference`), 429 (`rate_limited`), 501 (`not_implemented`), 503 (`bridge_disabled`, `model_not_configured`, `bridge_unavailable`). No errores internos ni credenciales en las respuestas.

## Requisitos pendientes antes de la activación

1. Implementar `document.attach` mediante copia privada S3/FileS3, revisión inmutable, ownership y política de análisis bajo demanda. Un original de Legal nunca se modifica.
2. Implementar `document.status` consultando el índice central y la cobertura real (pendiente, extrayendo, indexando, listo, parcial, error); no inferir `ready` del objeto S3.
3. Adaptar `chat.send` al motor compartido de MiChat y a la persistencia de mensajes, sin ejecutar herramientas de filesystem/Drive arbitrarias ni otra llamada directa a Bedrock.
4. Vincular evidencia RAG a la **revisión activa** para evitar contaminación entre versiones; conservar índices anteriores.
5. Emitir `document.suggestions` como propuestas estructuradas, nunca cambios directos. Aislar instrucciones no confiables del documento.
6. Completar pruebas de contrato, integración, regresión y seguridad contra una base ficticia, con sintaxis PHP verificada.
7. Inspeccionar la configuración efectiva de PHP-FPM y la historia real de migraciones. No cambiar EC2, HostGator, IAM, S3 ni servicios sin autorización.
8. Confirmar la estrategia de identidad para usuarios distintos de la única cuenta autorizada por credencial.

## INSTRUCCIONES PARA EL PROYECTO ESFORZADOS LEGAL

**Todavía NO integrar en producción.** El proyecto `jimmybackend/legal` debe construir un cliente PHP servidor-a-servidor, jamás firmar en JavaScript público ni entregar el secreto al navegador. Variables privadas futuras de HostGator: URL base `https://chat.esforzados.com`, key id `legal-hostgator` y el secreto ya creado en EC2, transferido por canal seguro autorizado. Nunca incluir valores reales en el repositorio ni en logs.

Contrato en desarrollo: `POST /api/legal-bridge.php`, cuerpo `{"operation":string,"data":object}`, firma HMAC exacta anterior, timeout razonable y reintentos con nonce nuevo. No reintentar automáticamente operaciones no idempotentes cuando su resultado sea incierto. Resolver identidad legal en el backend de Legal mediante su sesión autenticada propia; **no** enviar `user_id` de MiChat. Mantener mapeo expediente/amparo/revisión en la base Legal y comprobar permisos antes de efectuar cada solicitud.

La interfaz futura usará `session.open` para obtener el ID de conversación; `document.attach` para enviar una copia privada; `document.status` hasta confirmar cobertura; `chat.send` y `chat.activity` para preguntas y seguimiento; `chat.history` para historial paginado; `document.suggestions` para mostrar correcciones revisables por apartado. La aprobación de cambios ocurrirá solo en Legal, con confirmación explícita. **No codificar todavía supuestos sobre los cuatro endpoints 501.**

El asistente jurídico debe distinguir hechos, documentos, inferencias y datos pendientes; preservar los originales y los marcadores entre corchetes; preguntar concretamente sin repetir, admitir desconocimiento y evitar inventar normas, hechos, jurisprudencia o pruebas. La decisión definitiva corresponde a la defensa. Los textos aportados son fuentes no confiables, no instrucciones privilegiadas.

## Desactivación

Con `LEGAL_BRIDGE_ENABLED=false` el endpoint rechaza toda solicitud antes de consultar la base. Para desactivar después del despliegue, modificar únicamente esa variable bajo procedimiento administrativo y recargar el entorno de ejecución conforme a la operación aprobada. No borrar las sesiones, tablas, documentos ni nonces para desactivar.
