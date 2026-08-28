# Fase 12B — closure audit de la rama de rescate

Actualizado: 2026-08-27.

## Estado

**IMPLEMENTACIÓN / HARDENING: MERGE CANDIDATE**

Rama auditada:

```text
rescue/fase12b-ec2
```

Base de comparación:

```text
main @ 5d8e756319d5f51e486743075844534bdc3b3ea9
```

Esta rama reconstruye el trabajo de Fase 12B sobre una referencia Git real después
de que un workspace anterior quedara aislado sin remoto. Los hotfixes que ya
habían demostrado funcionamiento en EC2 fueron recuperados desde el deployment y
posteriormente se completaron los contratos de release que faltaban.

Este documento distingue deliberadamente entre:

- **implementado y cubierto por contratos automáticos**;
- **probado operacionalmente en EC2**;
- **pendiente de certificación externa**.

No convierte un SKIP de infraestructura en PASS.

## 1. Recuperación de fixes validados en EC2

La rama conserva los fixes observados durante las pruebas reales del Worker y del
Task Center:

- heartbeat de `TaskExecutions + Tasks`: un UPDATE multi-table válido no se
  interpreta como fallo por exigir exactamente un affected row; si no hay cambios
  se revalida ownership/lease;
- el Planner sólo puede producir nuevos Steps ejecutables
  `model | tool | approval | wait | validation | finalize`;
- `PlanTaskStepExecutor` permanece fail-closed para historia/estado legacy y no
  se convierte en no-op;
- `AutonomyPolicyRepository` cualifica
  `LAST_INSERT_ID(ProjectAutonomyPolicies.id_)`;
- `TaskCenterAutonomyReadService` deja de solicitar la columna inexistente
  `x.lock_version`;
- nombres de `GET_LOCK` de Chat Activity, Memory finalization y Code Edit quedan
  dentro del límite MySQL de 64 caracteres y RELEASE usa el mismo nombre;
- `NextWorkProposalServiceFactory` utiliza el core de
  `TaskApplicationServiceFactory` para evitar composición recursiva.

## 2. Retry y coherencia de recovery

El retry de una Task fallida ya no confunde el ordinal histórico de Execution con
el budget técnico del Step.

Contrato final:

1. la Task debe estar `failed`;
2. el `current_step_id_` debe señalar un Step `failed`;
3. `Tasks.max_attempts` continúa siendo el budget global autoritativo;
4. el siguiente ordinal se calcula desde el intento actual de la Task;
5. `TaskStepRepository::authorizeRetry()` expande únicamente
   `TaskSteps.max_attempts` hasta ese ordinal autorizado, limpia el error y
   reactiva el Step mediante optimistic lock;
6. no se revive, reescribe ni elimina una Execution histórica;
7. el Worker crea una nueva Execution al reclamar de nuevo el Step.

La recuperación de lease expirada conserva la Execution como `abandoned` y
requiere retry explícito.

## 3. Resultado durable de Tasks y procedencia del modelo

Las respuestas completas de Tasks humanas no se guardan dentro de un summary ni
se convierten en Artifacts.

El contrato implementado es:

```text
Task manual
   -> ChatMessages user (si no existía origin_message_id_)
   -> Model Step final visible
   -> ChatMessages assistant con contenido completo
   -> Tasks.result_message_id_
   -> Task Center "Resultado final"
```

Detalles:

- `TaskManualChatMessageService` materializa de forma idempotente el mensaje
  humano de una Task creada en Task Center y marca su procedencia
  `source=task_center`;
- `ChatResponsePersistenceService` conserva el texto completo en
  `ChatMessages.content`;
- `Tasks.result_summary` y `TaskSteps.output_summary` siguen siendo campos
  cortos de inspección, no la fuente de verdad de la respuesta;
- `Tasks.result_message_id_` referencia el assistant message owned;
- `TaskExecutions.model_id` se actualiza con el `modelId` efectivo devuelto
  por el runtime, no solamente con el modelo solicitado antes del claim;
- Task Center muestra **Resultado final** separado de **Artifacts**, con preview,
  modelo y enlace a la conversación completa.

Los Artifacts siguen representando recursos reales como archivos, chunks o
versiones, no texto conversacional.

## 4. Schema, migraciones y clean install

La cadena soportada queda cerrada en **14 migraciones**, en orden explícito:

1. `fase8_1_task_orchestrator`
2. `fase8_6d_3d_toolcalls_code_edit`
3. `fase8_7b_task_artifacts`
4. `fase10d_task_recurrence`
5. `fase11b_project_autonomy`
6. `fase11c_next_work_proposals`
7. `fase11d_post_task_continuations`
8. `fase11e0_replan_checkpoint`
9. `fase11e1_versioned_replanning`
10. `fase11f2_hitl_controls`
11. `fase12b_2c_global_ai_configuration_scope`
12. `fase12b_4_ai_scope_default_reconciliation`
13. `fase12b_5_mysql_generated_column_compatibility`
14. `fase12b_6_system_role_authorization`

`MigrationRunner` mantiene history/checksum, locking y clasificación
fail-closed para DRIFT, UNKNOWN y estados parciales. El perfil
`current-dump` representa las 14 migraciones y el upgrade soportado desde el
fixture post-Fase10D adopta primero las cuatro migraciones históricas que ya
existían en ese punto.

### Generated columns

El target de producción queda reconciliado a:

- `ProjectAutonomyCycles.active_project_id_` -> **VIRTUAL**;
- `UserAIAgentConfigs.scope_owner_key` -> **VIRTUAL NOT NULL**.

No se usa `FOREIGN_KEY_CHECKS=0` para reconciliar estos contratos.

### Dump canónico

`adbbmis1_Cloud.sql` es el dump distribuible para una instalación limpia:

- no contiene `CREATE DATABASE` ni `USE`;
- hereda el `DB_NAME` elegido por el deployment;
- no inserta un usuario de aplicación;
- no inserta `UserPreferences` ni `UserPipelineFeatures` históricos;
- sí contiene el catálogo AI GLOBAL funcional;
- representa el schema final de las 14 migraciones.

`adbbmis1_Cloud-final.sql` **no** es el dump canónico. Es una fotografía de una
base de producción utilizada como evidencia de reconciliación y puede incluir
nombre de DB, AUTO_INCREMENT y datos/configuración del deployment.

## 5. GLOBAL/USER AI configuration y Planner

`UserAIAgentConfigs` deja de utilizar un propietario mágico para GLOBAL:

- GLOBAL -> `user_id_ IS NULL`;
- USER -> `user_id_ IS NOT NULL`;
- `scope_owner_key` hace cumplir la identidad funcional;
- un override USER gana para su `agent_key`;
- si no existe override, la configuración GLOBAL es la base efectiva.

El catálogo limpio incluye el agente GLOBAL `task_planner` con modelo de
deployment `amazon.nova-pro-v1:0` y la instrucción de Steps ejecutables sin
`plan`.

## 6. Autorización y provisioning

`Users.system_role` queda definido como:

```sql
ENUM('user','admin','superadmin') NOT NULL DEFAULT 'user'
```

No existe promoción automática del usuario con ID 1.

`AuthorizationService` resuelve permisos desde un usuario activo y su
`system_role`. Las capacidades administrativas críticas dejan de depender de
IDs mágicos.

CLI incorporado:

- `michat/bin/create_first_user.php`: sólo permite crear el primer usuario si
  `Users` está vacío y lo crea como `superadmin`;
- `michat/bin/create_user.php`: requiere actor activo, password verificado y
  permiso `system.roles.manage`;
- passwords de provisioning se reciben mediante variables de entorno, no argv.

`InitialUserProfile` crea el perfil canónico de preferencias/features. Los
nuevos usuarios heredan el catálogo AI GLOBAL dinámicamente y no reciben clones
por usuario.

Para upgrades de instalaciones que ya tenían usuarios antes de
`Users.system_role`, `michat/bin/bootstrap_superadmin.php` cubre el bootstrap
sin recurrir a un ID mágico: sólo funciona mientras existan cero superadmins,
requiere que el target sea un usuario activo que autentique su propia contraseña
y exige `MICHAT_BOOTSTRAP_CONFIRM=BOOTSTRAP_SUPERADMIN`. Una vez existe un
superadmin, ese camino queda cerrado.

El perfil mantiene `task_auto_execute=0` por defecto.

## 7. Reset administrativo

El antiguo endpoint web `michat/truncate.php` fue eliminado.

`michat/bin/reset_runtime_data.php`:

- es CLI-only;
- hace dry-run por defecto;
- el modo destructivo sólo acepta development/test;
- requiere `--confirm --hard`, token explícito y actor autorizado con
  `system.reset`;
- calcula orden de borrado compatible con FKs;
- usa `DELETE`, no `TRUNCATE`;
- no deshabilita foreign keys;
- preserva identidad/configuración/storage definidos;
- registra la operación en `AccessControl`.

## 8. Bootstrap y deployment

El bootstrap ya no fuerza el layout EC2.

Rutas configurables:

- `MICHAT_ENV_FILE`
- `MICHAT_VENDOR_AUTOLOAD`
- `MICHAT_CONFIG_FILE`
- `MICHAT_DB_BOOTSTRAP`

Si no están definidas, primero se intenta el layout portable del checkout y
después el layout EC2 que fue validado operacionalmente:

```text
/var/www/html/chat
/etc/michat.env
/var/www/db-s3.php
/var/www/Config-s3.php
/var/www/vendor/
```

Los archivos privados y secretos no forman parte del repositorio.

## 9. Validación

La rama contiene el workflow:

```text
.github/workflows/fase12b-validation.yml
```

Cubre:

- sintaxis de todo PHP;
- contratos PHP críticos;
- contratos JavaScript;
- guard contra `.env`, backups y material sensible versionado.

Durante esta closure audit, el run de GitHub Actions correspondiente a:

```text
e8554116a74ed4b15b7fe54f9e63effd8570a860
```

terminó **SUCCESS** después de corregir dos assertions estáticas frágiles del
harness de upgrade. No se modificó el comportamiento del migration runner para
hacer pasar esa validación; se corrigió la forma en que el test reconocía dos
casos que ya estaban cubiertos semánticamente por el harness MySQL.

## 10. Evidencia operacional EC2

En el deployment de rescate se ejecutó el Worker mediante systemd transient con:

- usuario/grupo `apache`;
- working directory `/var/www/html/chat`;
- `EnvironmentFile=/etc/michat.env`;
- `task_worker.php --once`.

Una Task limpia completó realmente el camino Worker -> Bedrock -> Task
`completed` al 100 %. Esa prueba permitió detectar los bugs de heartbeat,
Planner y procedencia del resultado que motivaron parte del hardening de 12B.

Esta evidencia **no significa que todos los commits reconstruidos de la rama
estén ya desplegados en EC2**. El deployment completo de la rama debe ocurrir
solamente después del merge y del procedimiento de release.

## 11. Pendiente externo / no bloqueante para el merge de código

### Certificación MySQL aislada

Los tests destructivos requieren:

```text
TASK_TEST_DB_HOST
TASK_TEST_DB_PORT
TASK_TEST_DB_USER
TASK_TEST_DB_PASSWORD
TASK_TEST_DB_ALLOW_DESTRUCTIVE=1
```

Deben apuntar exclusivamente a MySQL 8 de test. No usar HostGator production ni
una DB real con datos.

Sin esas credenciales el estado correcto es:

```text
SKIP / NOT CERTIFIED
```

y nunca PASS implícito.

### Browser/AWS final

La rama dispone de contratos estáticos y de evidencia operacional parcial real.
Una certificación de release puede repetir smoke browser autenticado y pruebas
AWS/MySQL en infraestructura aislada antes de etiquetar una versión estable.

### Worker persistente

El deployment productivo deberá supervisar `task_worker.php --loop` mediante
systemd/Supervisor con un `TASK_WORKER_ID` único. La instalación/activación del
servicio persistente es una operación de deployment, no una modificación del
schema.

## 12. Criterio para PR

La rama puede proponerse contra `main` cuando:

- el workflow del HEAD final esté verde;
- no haya secretos ni archivos backup versionados;
- README y `estado-actual.md` describan este mismo contrato;
- el diff siga 0 commits detrás de `main`;
- la certificación MySQL externa pendiente continúe expresada como pendiente y
  no como PASS.

El merge no debe borrar la evidencia de rescate ni sustituir
`adbbmis1_Cloud.sql` por la fotografía de producción.
