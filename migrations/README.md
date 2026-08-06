# Migraciones de esquema

## Política

1. Cada migración es un archivo `.sql` numerado en este directorio.
2. **Nunca se ejecutan desde PHP.** Se aplican a mano durante una ventana de
   mantenimiento. Ningún endpoint debe llamar a `ALTER TABLE`.
3. Cada archivo debe poder aplicarse una sola vez y dejar constancia de qué fase
   del refactor lo necesita.
4. Las que requieran verificación previa (buscar duplicados antes de crear un
   UNIQUE) llevan la consulta de verificación **comentada al inicio**. Ejecutar
   esa consulta primero; si devuelve filas, limpiar antes de seguir.

## Estado

| Archivo | Fase | Estado |
|---|---|---|
| `001_*.sql` | — | **Reservado.** La migración consolidada mencionada en la revisión (`migration_michat_001.sql`) no llegó al repo; el adjunto no estaba presente. Pendiente de recibirla. |
| `002_*.sql` | 2 | Pendiente — `FileVersions.status` + `last_error` |
| `003_*.sql` | 4 | Pendiente — tabla `PhaseCache`, presupuesto por sesión, extensión de `ToolCalls` |
| `004_*.sql` | 5 | Pendiente — extensión del ENUM `TokenUsage.phase` |

## Cambios de esquema acordados, por fase

### Fase 2 — versionado
- `FileVersions`: nueva columna `status ENUM('draft','committed','failed','rolled_back')`
  y `last_error TEXT`. **`is_stable` se conserva intacta**: significa "un humano
  marcó esta versión como la buena", que es un concepto distinto del estado del
  pipeline de escritura. `committed` ≠ `stable`.

### Fase 4 — idempotencia y detección de bucles
- Tabla `PhaseCache (cache_key CHAR(64) PK, phase, payload JSON, expires_at, created_at)`.
- Presupuesto en USD por sesión/proyecto.
- `ToolCalls`: el ENUM `tool` actual es
  `('grep','view','search','str_replace','list_dir','read_chunk','run_shell')`
  y **no contempla `code_edit`/`apply_edit`**, así que hoy no se puede registrar
  la operación principal del sistema. Hay que extenderlo y añadir `project_id_`
  y `target_path` para poder detectar repeticiones por archivo.

### Fase 5 — observabilidad
- `TokenUsage.phase` es `ENUM('compile','respond','lint_fix','embedding')`. Por eso
  el código etiqueta el clasificador como `lint_fix` y el RAG como `compile`: no
  había valores válidos. Extender con `scout`, `classify`, `plan`, `rag`, `edit`,
  `summarize` y reetiquetar cada llamada.

### Hallazgos de esquema (fase por confirmar)
- **Índice faltante:** `ProjectSources` no tiene índice sobre `filename`, y la
  consulta de apertura de `code_edit.php` filtra por `(project_id_, filename)`.
  Hoy cada lectura, edición y borrado hace scan de todas las fuentes del proyecto.
- **`FileS3.Encriptado` con UNIQUE duplicado:** existen a la vez el UNIQUE global
  `(Encriptado)` y el compuesto `(user_id_, Encriptado)`. Verificado en Fase 0:
  **no hay ninguna consulta en el repo que busque por `Encriptado` sin filtrar por
  `user_id_`** (la única, `FileS3Repository::updateSizeByEncriptado()`, sí lo
  filtra), así que quitar el global no rompe el aislamiento. Advertencia: las
  tablas `FileS3_RepairControl` / `FileS3_RepairLog` sugieren un proceso de
  reparación externo que no está en este repo y que podría depender de esa
  unicidad global. Confirmar antes de aplicar.
- **`Projects.root_prefix` no es UNIQUE:** `FileS3` y `S3Folders` se sincronizan
  por `(user_id_, Ruta, Nombre)` sin `project_id_`, así que dos proyectos del
  mismo usuario con el mismo `root_prefix` se pisan los archivos. Requiere
  verificar duplicados existentes antes de crear el índice.
- **Charset mixto:** `ChatMessages`, `FileS3` y `S3Folders` son `utf8mb3` (máximo
  3 bytes por carácter) y no pueden almacenar emojis. El código que este sistema
  edita los contiene en comentarios, así que hoy se truncan o dan error 1366 al
  pasar por `ChatMessages.content`.
