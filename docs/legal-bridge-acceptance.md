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
| 11 | Adjuntar documento | `LegalBridgeDocumentService` implementado; falta ensayo con S3 privado simulado/aislado | **Pendiente** |
| 12 | Documento duplicado | Reutilización por `LegalBridgeDocuments` y SHA; falta ensayo S3 aislado | **Pendiente** |
| 13 | Nueva revisión | FileS3 independiente y detached_file_ids; falta verificar retriever con revisiones y AWS simulado | **Pendiente** |
| 14 | Estado pendiente | `legal_bridge_knowledge_status_test.php` con estados persistidos ficticios | Automatizada (pura) |
| 15 | Indexación completada | `legal_bridge_knowledge_status_test.php` valida sólo la conversión de estado; no completó embeddings reales | Parcial / falta integración |
| 16 | Error de Bedrock | Runtime compartido empleado; falta inyectar un fallo Bedrock en integración aislada | **Pendiente** |
| 17 | Recuperación de mensajes | `legal_bridge_mysql_contract.php` | Automatizada (MySQL descartable) |
| 18 | Evidencia RAG | ContextBuilder reutilizado; falta certificar filtrado por revisión y citas con datos ficticios | **Pendiente** |
| 19 | Rechazo de propuestas no autorizadas | Auth/session checks implementados; falta prueba directa de `document.suggestions` sin autorización | **Pendiente** |
| 20 | Original sin modificaciones | No hay código que escriba en Legal; falta comparar bytes del original durante ensayo de revisiones | **Pendiente** |

## Reglas para aprobar el PR

- Ningún fallo ni salto en la suite de regresión general y en `legal-bridge-tests.yml`.
- Completar todos los casos de la matriz, incluyendo pruebas de S3, worker, revisiones y Bedrock simulados o contra recursos explícitamente aislados.
- Verificar revocación de identidad, stale embedding, fallos después de un upload, seguridad del contenido documental y concurrencia.
- Revisar que `LEGAL_BRIDGE_ENABLED=false` en la configuración privada de EC2. No realizar esa comprobación por cambios en producción durante la tarea de código.
- Revisar la migración fase15 con el administrador y **no** ejecutar `migrations.php apply` en producción hasta autorización.
- Mantener PR #144 en borrador y sin fusión mientras existan pendientes.
