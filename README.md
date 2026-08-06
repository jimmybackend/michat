# michat

Chat con asistente de código sobre proyectos almacenados en S3.

## Base de datos

### El volcado de la raíz es **estructura únicamente**

El volcado de estructura de la raíz (`adbbmis1_Cloud.sql`, a renombrar como
`schema.sql`) es la **única fuente de verdad** del esquema. Contiene **solo
estructura: cero datos y cero credenciales**. Cualquier otro `.sql` del repo o
del historial está obsoleto y no debe usarse para deducir el esquema.

Regla que no se negocia: nunca lleva `INSERT`.

```bash
grep -c "^INSERT INTO" adbbmis1_Cloud.sql   # tiene que devolver 0
```

Para regenerarlo desde producción:

```bash
mysqldump --no-data --skip-comments --skip-add-drop-table \
          --single-transaction -u USUARIO -p BASEDEDATOS > schema.sql
```

Los volcados **con datos** están ignorados por `.gitignore`
(`*.dump.sql`, `*-data.sql`, `backup*.sql`, …). No los fuerces con `git add -f`.

### Hook de pre-commit

Hay un hook que bloquea el commit si el volcado trae datos, si alguien intenta
colar un volcado con filas, o si los tests están rojos. Actívalo una vez por
clon:

```bash
git config core.hooksPath .githooks
```

### `migrations/` — el historial

El volcado refleja el **estado actual**; `migrations/` guarda **cómo se llegó
hasta ahí**. Ninguna migración se ejecuta desde PHP: se aplican a mano y luego se
regenera el volcado. Ver `migrations/README.md`.

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
