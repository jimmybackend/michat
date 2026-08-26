# Fase 12B.4: certificación MySQL externa

## Alcance y seguridad

Este procedimiento ejecuta tests destructivos **solo en una instancia MySQL de desarrollo/test**: crea y elimina bases temporales cuyo nombre comienza por `michat_test_12b4_`. No usar HostGator production, la DB real del usuario ni una instancia compartida con datos reales. `TASK_TEST_DB_ALLOW_DESTRUCTIVE=1` confirma que el operador verificó este aislamiento; no autoriza borrar nombres proporcionados por el operador.

La certificación es exclusivamente CLI. No existe ni debe crearse un endpoint HTTP que reciba credenciales o ejecute migrations/tests. Las credenciales solo se proporcionan mediante variables de entorno no versionadas.

## Contrato real de los harnesses

Los harnesses conectan primero sin seleccionar una DB, crean bases con prefijo `michat_test_12b4_` y 128 bits aleatorios, importan fixtures, ejecutan los contratos y eliminan en `finally` únicamente nombres registrados en memoria y validados por el mismo prefijo. No aceptan nombres de DB por HTTP o argumentos. Una terminación no capturable (`SIGKILL`, pérdida del host) puede dejar una DB temporal huérfana; el prefijo permite identificarla, pero el operador debe revisar su pertenencia a la ejecución antes de eliminarla manualmente.

Sin las cuatro credenciales, los harnesses individuales se omiten. Con credenciales pero sin `TASK_TEST_DB_ALLOW_DESTRUCTIVE=1`, los harnesses destructivos fallan cerrados. El runner externo trata cualquier SKIP obligatorio como fallo global.

## Requisitos

- PHP 8.x CLI.
- Extensión `mysqli`.
- Cliente `mysql` CLI (lo usa el contrato de compatibilidad).
- MySQL 8.0.16 o posterior; MariaDB no es aceptado.
- Checkout Git del mismo HEAD que se pretende certificar.
- Instancia exclusivamente de test donde la cuenta pueda crear y eliminar DBs temporales.

## Privilegios mínimos derivados

Los tests crean y destruyen schemas, tablas, índices, FKs, checks y procedures; importan datos y consultan `information_schema`. La cuenta necesita:

- a nivel servidor para los schemas temporales: `CREATE`, `DROP` (creación/eliminación de databases);
- sobre bases `michat_test_12b4_*`: `SELECT`, `INSERT`, `UPDATE`, `DELETE`, `CREATE`, `ALTER`, `DROP`, `INDEX`, `REFERENCES`, `CREATE ROUTINE`, `ALTER ROUTINE`, `EXECUTE`.

`GET_LOCK`/`RELEASE_LOCK` no requieren un privilegio administrativo adicional en MySQL 8. No se recomienda `GRANT ALL ON *.*`. Debido a que crear databases requiere privilegios amplios, la mitigación principal es una cuenta y una instancia efímeras dedicadas a test; el administrador debe limitar por patrón `michat_test_12b4_*` cuando su política MySQL lo permita.

## Ejecución local o servidor de pruebas

```bash
export TASK_TEST_DB_HOST='127.0.0.1'
export TASK_TEST_DB_PORT='3306'
export TASK_TEST_DB_USER='michat_test'
export TASK_TEST_DB_PASSWORD='...'
export TASK_TEST_DB_ALLOW_DESTRUCTIVE='1'
php michat/tests/run_external_mysql_certification.php
```

No guardar estas variables en `.env` versionado. El resultado aceptable es:

```text
EXTERNAL MYSQL CERTIFICATION = PASS
SCHEMA PARITY = PASS
```

`NOT RUNNABLE` indica configuración, autorización, conexión o versión inválida. `FAIL` incluye un test fallido o un SKIP obligatorio.

## EC2 development/test

En una EC2 temporal de development/test, instalar PHP CLI y `mysqli`, hacer checkout del mismo commit y proporcionar conectividad privada a un MySQL 8 de test. MySQL puede ser local, un RDS MySQL temporal o una instancia privada de development; no necesita residir en la misma EC2. Configurar las variables solo en la sesión del operador y ejecutar el mismo runner. No apuntar a RDS/HostGator production.

## cPanel y phpMyAdmin

### Inspección manual

En dos databases **nuevas y separadas de prueba**, un operador puede importar el dump clean y el fixture, y consultar tablas, columnas, índices, FKs, checks y `UserAIAgentConfigs` con:

`michat/tests/manual/fase12b4_schema_inspection.sql`

Debe reemplazar los dos nombres de schema explícitos. Las consultas no dependen de `DATABASE()` y son read-only. Conocer que HostGator ejecuta MySQL 8.0.46 solo demuestra compatibilidad nominal de versión, no E2E.

### Limitación

La inspección manual en phpMyAdmin **no certifica** MigrationRunner PHP, `GET_LOCK` contention, second apply, DRIFT ni UNKNOWN history. Por tanto:

```text
MANUAL PHPMYADMIN != FULL 12B.4 CERTIFICATION
```

No usar la DB real parcialmente actualizada ni mezclar aquí el error independiente `ProjectAutonomyCycles #1215 Cannot add foreign key constraint`.
