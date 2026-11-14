# Matriz de aceptación — Legal Bridge / PR #144

Este archivo distingue **comprobación automatizada** de **validación pendiente**. Ninguna prueba de producción ha sido ejecutada. Los tests MySQL usan una base creada y destruida dentro del runner de GitHub Actions. Las variables del cliente y los documentos son ficticios.

| # | Caso requerido | Prueba disponible | Estado |
|---|---|---|---|
| 1 | Puente deshabilitado | `legal_bridge_auth_test.php`: rechazo con `bridge_disabled` | Automatizada (pura) |
| 2 | Firma válida | `legal_bridge_auth_test.php` (firma canónica), `legal_bridge_mysql_contract.php` (verificación DB) | Automatizada |
| 3 | Firma inválida | `legal_bridge_auth_test.php` | Automatizada |
| 4 | Credencial desconocida | `legal_bridge_auth_test.php` | Automatizada |
| 5 | Solicitud caducada | `legal_bridge_auth_test.php` | Automatizada |
| 6 | Reutilización de nonce | `legal_bridge_mysql_contract.php` con constraint y transacción reales | Automatizada (MySQL descartable) |
| 7 | Usuario sin autorización | `legal_bridge_mysql_contract.php` (sujeto externo sin binding) | Automatizada (MySQL descartable) |
| 8 | Acceso a sesión ajena | `legal_bridge_mysql_contract.php` | Automatizada (MySQL descartable) |
| 9 | Creación de sesión | `legal_bridge_mysql_contract.php`, `SessionLifecycleService` | Automatizada (MySQL descartable) |
| 10 | Reutilización de sesión | `legal_bridge_mysql_contract.php` | Automatizada (MySQL descartable) |
| 11 | Adjuntar documento | `legal_bridge_document_mysql_contract.php`: S3 privado simulado + FileS3 real | Automatizada (por validar en CI) |
| 12 | Documento duplicado | `legal_bridge_document_mysql_contract.php`: idempotencia y nuevo encolado | Automatizada (por validar en CI) |
| 13 | Nueva revisión | `legal_bridge_document_mysql_contract.php` + `legal_bridge_central_index_mysql_contract.php`: fichero distinto, revisión vieja excluida | Automatizada (por validar en CI) |
| 14 | Estado pendiente | `legal_bridge_knowledge_status_test.php` con estados persistidos ficticios | Automatizada (pura) |
| 15 | Indexación completada | `legal_bridge_central_index_mysql_contract.php`: job real, chunks y embeddings simulados, cobertura completa | Automatizada (por validar en CI) |
| 16 | Error de Bedrock | `legal_bridge_chat_mysql_contract.php`: runtime simulado falla sin inventar respuesta | Automatizada (por validar en CI) |
| 17 | Recuperación de mensajes | `legal_bridge_mysql_contract.php` | Automatizada (MySQL descartable) |
| 18 | Evidencia RAG | `legal_bridge_central_index_mysql_contract.php`: recuperación real de chunks, filtro de revisión; `legal_bridge_chat_mysql_contract.php`: memoria filtrada | Automatizada (por validar en CI) |
| 19 | Rechazo de propuestas no autorizadas | `legal_bridge_chat_mysql_contract.php`: propuestas de usuario ajeno rechazadas antes de IA | Automatizada (por validar en CI) |
| 20 | Original sin modificaciones | `legal_bridge_document_mysql_contract.php`: hash del original ficticio intacto y objetos separados | Automatizada (por validar en CI) |

## Reglas para aprobar el PR

- Ningún fallo ni salto en la suite de regresión general y en `legal-bridge-tests.yml`.
- Completar todos los casos de la matriz, incluyendo pruebas de S3, worker, revisiones y Bedrock simulados o contra recursos explícitamente aislados.
- Verificar revocación de identidad, stale embedding, fallos después de un upload, seguridad del contenido documental y concurrencia.
- Revisar que `LEGAL_BRIDGE_ENABLED=false` en la configuración privada de EC2. No realizar esa comprobación por cambios en producción durante la tarea de código.
- Revisar la migración fase15 con el administrador y **no** ejecutar `migrations.php apply` en producción hasta autorización.
- Mantener PR #144 en borrador y sin fusión mientras existan pendientes.

## Alcance de las simulaciones

La ruta S3 se ejecuta con un uploader falso inyectado que escribe la misma tabla `FileS3` del esquema real. El índice documental central, los workers de índices, la tabla de chunks, el repositorio de acceso y el retriever son reales, pero su S3, extracción y modelo de embedding están simulados, sin claves ni redes. El adaptador conversacional usa el `ChatExecutionService` real con `CallableChatRuntime` para provocar respuestas y errores sin consumir Bedrock. La prueba no equivale a un ensayo de AWS/HostGator en producción.

Las pruebas son necesarias pero no sustituyen la inspección de la implementación frente a ataques de concurrencia, documentos maliciosos, ni la revisión de CI antes de fusionar.
