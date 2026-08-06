# Migraciones de esquema

## Política

- **El volcado de estructura de la raíz refleja el estado actual.** Es la única
  fuente de verdad del esquema. Si quieres saber cómo es una tabla hoy, míralo
  ahí, no en estos archivos ni en el código.
- **`migrations/` es el historial.** Deja constancia de cómo se llegó al estado
  actual. Son archivos de registro, no un runner.
- **Ninguna migración se ejecuta desde PHP.** No hay código de aplicación que lea
  este directorio, y no debe haberlo: un endpoint capaz de ejecutar DDL es una
  vía de escalada de privilegios. El esquema se cambia a mano, en una ventana de
  mantenimiento, con un humano mirando.
- **Las migraciones aplicadas no se editan.** Si algo salió mal, se corrige con
  una migración nueva.

## Estado: CERRADO para este refactor

Las tres migraciones están **aplicadas y verificadas en producción**. El esquema
es final para este trabajo. Si crees que falta algo, dilo en el PR y espera
respuesta; no lo implementes.

| Archivo | Aplicada | Qué hizo |
|---|---|---|
| `001_indices_enums_toolcalls_filevers.sql` | 2026-08-06 | `ToolCalls.project_id_` + `target_path` + ENUM `tool` de 15 valores; `FileVersions.status` y trazabilidad (`sha256_*`, `bytes_*`, `model_used`, `error_message`); ENUM `phase` de 11 valores en `TokenUsage` y `ChatMessages`; tabla `PhaseCache`; presupuestos en `Projects`; índices de apoyo. |
| `002_params_hash_charset.sql` | 2026-08-06 | `ToolCalls.params_hash` (GENERATED VIRTUAL) + `idx_tc_loop_detect`; conversión a `utf8mb4` de `ChatMessages`, `FileS3` y `S3Folders`; sustitución del UNIQUE global de `FileS3.Encriptado` por `uq_files3_user_key (user_id_, Encriptado)`. |
| `003_index_gen_test_commands.sql` | 2026-08-06 | `Projects.index_gen`; tabla `ProjectTestCommands`. |

## Qué resolvió cada hallazgo previo

Las notas de la Fase 0 anticipaban cambios de esquema que las migraciones ya
cubrieron. Se dejan aquí resueltas para que nadie las vuelva a proponer:

- **Índice sobre `(project_id_, filename)`** — resuelto en 001
  (`idx_ps_project_filename`). La consulta de apertura de `code_edit.php` ya no
  escanea todas las fuentes del proyecto.
- **`FileS3.Encriptado` con UNIQUE duplicado** — resuelto en 002. Se verificó que
  ninguna consulta del repo busca por `Encriptado` sin filtrar por `user_id_`
  (la única, `FileS3Repository::updateSizeByEncriptado()`, sí lo filtra), así que
  quitar el global no rompió el aislamiento entre usuarios.
- **`Projects.root_prefix` no era UNIQUE** — resuelto en 001
  (`uq_projects_user_rootprefix (user_id_, root_prefix(255))`). Dos proyectos del
  mismo usuario ya no pueden compartir prefijo y pisarse los archivos.
- **Charset mixto** — resuelto en 002. `ChatMessages`, `FileS3` y `S3Folders`
  pasaron a `utf8mb4`. `Users`, `AccessControl` y `ChatSessions` se quedan en
  `utf8mb3` **a propósito**: sus columnas de texto real ya son `utf8mb4` a nivel
  de columna y el resto son identificadores ASCII.
- **`ToolCalls.tool` no contemplaba la operación principal** — resuelto en 001.
  El ENUM tiene 15 valores y **no se amplía**: las operaciones internas se
  traducen a uno de ellos mediante `Schema::OPERATION_TO_TOOL`.
  `apply_edit` se registra como `str_replace`, la reescritura completa como
  `write_file`.
- **`TokenUsage.phase` solo tenía 4 valores** — resuelto en 001. Son 11. Por eso
  ya no hay excusa para etiquetar el clasificador como `lint_fix` ni el RAG como
  `compile`; el reetiquetado del código es trabajo de la Fase 5.

## Trampas del esquema que el código debe respetar

- **`Users` es la única tabla cuya PK se llama `id`, no `id_`.** El resto usa
  `id_`, y la convención para referenciarla es una columna `user_id_`.
- **Las 23 tablas tienen `AUTO_INCREMENT`.** `next_id()` sobra: se omite `id_`
  en el INSERT y se lee `$db->insert_id`. (Su eliminación es trabajo de Fase 2.)
- **Columnas `GENERATED` — nunca se escriben ni se incluyen en un INSERT:**
  `ProjectSources.s3_key_hash`, `S3Folders.PrefixHash`, `ToolCalls.params_hash`.
- **`Projects.meta` no sirve para estado del servidor.** `projects.php`
  (`action=update`) lo sobrescribe con JSON del cliente. Por eso `index_gen` es
  columna real y los comandos de test viven en `ProjectTestCommands`.
- **`FileVersions.status` e `is_stable` son conceptos distintos.** `status` es el
  ciclo de vida de la escritura y lo pone el sistema; `is_stable` es "el humano
  marcó esta versión como la buena". `committed` ≠ `stable`.

## Procedimiento para una migración futura

1. Escribe el `.sql` con el siguiente número correlativo y una cabecera que
   explique el porqué, no solo el qué.
2. Si requiere verificación previa (buscar duplicados antes de crear un UNIQUE),
   deja la consulta de verificación comentada al inicio del archivo.
3. Aplícala a mano contra la base.
4. Regenera el volcado de estructura de la raíz:

   ```bash
   mysqldump --no-data --skip-comments --skip-add-drop-table \
             --single-transaction -u USUARIO -p BASEDEDATOS > schema.sql
   ```

5. Añade la cabecera `-- APLICADA EN PRODUCCIÓN: AAAA-MM-DD` y la fila
   correspondiente a la tabla de arriba.
6. Si tocaste un ENUM, actualiza `chat/includes/Schema.php` en el mismo commit.
   `tests/test_schema_constants.php` fallará si no lo haces.
