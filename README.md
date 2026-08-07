# michat

Chat con asistente de código sobre proyectos almacenados en S3.

## Base de datos

### `schema.sql` — estructura únicamente

`schema.sql`, en la raíz, es la **única fuente de verdad** del esquema: 23
tablas, 30 claves foráneas, **cero datos y cero credenciales**. Cualquier otro
`.sql` del repo o del historial está obsoleto y no debe usarse para deducir el
esquema.

Regla que no se negocia: nunca lleva `INSERT`.

```bash
grep -c "^INSERT INTO" schema.sql   # tiene que devolver 0
```

Se carga **solo contra una base vacía ya creada** — no incluye `CREATE DATABASE`
ni `DROP TABLE`, así que aplicarlo sobre una base con tablas falla:

```bash
mysql -u USUARIO -p BASEDEDATOS < schema.sql
```

Para regenerarlo desde producción:

```bash
mysqldump --no-data --skip-comments --skip-add-drop-table \
          --single-transaction -u USUARIO -p BASEDEDATOS > schema.sql
```

Los volcados **con datos** están ignorados por `.gitignore`
(`*.dump.sql`, `*-data.sql`, `backup*.sql`, …). No los fuerces con `git add -f`.

### CI y hook de pre-commit

`.github/workflows/tests.yml` ejecuta `php -l` sobre todo el PHP y
`php tests/run.php` en cada push y cada PR. Eso es lo obligatorio.

El hook de `.githooks/` hace lo mismo antes de commitear, más bloquear volcados
con datos, pero **solo protege a quien lo activa**:

```bash
git config core.hooksPath .githooks
```

### `migrations/` — el historial

`schema.sql` refleja el **estado actual**; `migrations/` guarda **cómo se llegó
hasta ahí**. Ninguna migración se ejecuta desde PHP: se aplican a mano y luego se
regenera `schema.sql`. Ver `migrations/README.md`.

### `chat/includes/Schema.php` — constantes espejo de los ENUM

El código nunca escribe literales sueltos en las columnas ENUM (`phase`, `tool`,
`status`, …): usa las constantes de `Schema`. El test
`tests/test_schema_constants.php` las compara contra el volcado, así que una
divergencia rompe la suite en vez de fallar en silencio en producción.

## Tests

No hay `composer.json` ni PHPUnit: las dependencias viven en el servidor, fuera
del webroot. El runner es propio y corre con PHP a secas.

```bash
php tests/run.php
```

Solo se prueban funciones puras. Nada de la suite toca MySQL, S3 ni Bedrock.
