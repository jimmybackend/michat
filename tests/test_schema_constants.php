<?php
/**
 * test_schema_constants.php
 *
 * Compara las constantes de chat/includes/Schema.php contra los ENUM reales
 * declarados en el volcado de estructura de la raíz.
 *
 * Existe para que una divergencia entre el código y la base rompa la suite en
 * lugar de fallar en silencio en producción — que es exactamente lo que pasó
 * cuando el Scout se etiquetaba como 'lint_fix': MySQL aceptaba el literal
 * porque casualmente existía en el ENUM, y las métricas de coste mintieron
 * durante semanas sin que nada fallara.
 */

declare(strict_types=1);

require_once __DIR__ . '/../chat/includes/Schema.php';

// ---------------------------------------------------------------------
// Localizar el volcado de estructura.
//
// El nombre definitivo es schema.sql (lo coloca el humano en la raíz). Hasta
// que llegue, el volcado vigente del repo es adbbmis1_Cloud.sql, que tiene el
// mismo contenido. Se prueba contra el que exista, con preferencia por
// schema.sql, para que el día que aparezca la suite cambie sola.
// ---------------------------------------------------------------------
$root = dirname(__DIR__);
$schemaPath = null;
foreach (['schema.sql', 'adbbmis1_Cloud.sql'] as $candidate) {
    if (is_file($root . '/' . $candidate)) {
        $schemaPath = $root . '/' . $candidate;
        break;
    }
}

t_section('Schema.php — espejo de los ENUM de la base');

if ($schemaPath === null) {
    t_fail('existe un volcado de estructura en la raíz',
           'no se encontró ni schema.sql ni adbbmis1_Cloud.sql');
    return;
}

$schemaFile = basename($schemaPath);
$schema = (string) file_get_contents($schemaPath);
t_pass("volcado de estructura localizado ({$schemaFile})");

// ---------------------------------------------------------------------
// Helpers de parseo
// ---------------------------------------------------------------------

/** Cuerpo del CREATE TABLE de una tabla, o null si no existe. */
function schemaTableBody(string $schema, string $table): ?string {
    $pattern = '/CREATE TABLE `' . preg_quote($table, '/') . '` \((.*?)\n\) ENGINE=/s';
    return preg_match($pattern, $schema, $m) ? $m[1] : null;
}

/** Valores del ENUM de una columna, en orden. null si no es ENUM. */
function schemaEnumValues(string $tableBody, string $column): ?array {
    $pattern = '/`' . preg_quote($column, '/') . '` enum\(([^)]*)\)/i';
    if (!preg_match($pattern, $tableBody, $m)) {
        return null;
    }
    preg_match_all("/'((?:[^']|'')*)'/", $m[1], $vals);
    return array_map(static fn(string $v): string => str_replace("''", "'", $v), $vals[1]);
}

// ---------------------------------------------------------------------
// 1. El volcado es estructura: cero datos.
// ---------------------------------------------------------------------
t_eq(0, preg_match_all('/^INSERT INTO/m', $schema),
     "{$schemaFile} no contiene datos (0 INSERT INTO)");

// ---------------------------------------------------------------------
// 2. Cada ENUM del contrato coincide EXACTAMENTE con su constante:
//    mismos valores y mismo orden.
// ---------------------------------------------------------------------
t_section('ENUM de la base == constantes de Schema');

foreach (Schema::ENUM_CONTRACT as [$table, $column, $constant]) {
    $body = schemaTableBody($schema, $table);
    if ($body === null) {
        t_fail("`{$table}` existe en {$schemaFile}", "contrato Schema::{$constant}");
        continue;
    }

    $dbValues = schemaEnumValues($body, $column);
    if ($dbValues === null) {
        t_fail("`{$table}`.`{$column}` es un ENUM", "contrato Schema::{$constant}");
        continue;
    }

    /** @var array $phpValues */
    $phpValues = constant('Schema::' . $constant);

    if ($dbValues === $phpValues) {
        t_pass("`{$table}`.`{$column}` == Schema::{$constant} (" . count($dbValues) . ' valores)');
        continue;
    }

    $missing = array_values(array_diff($dbValues, $phpValues));
    $extra   = array_values(array_diff($phpValues, $dbValues));
    $detail  = [];
    if ($missing) $detail[] = 'faltan en PHP: ' . implode(', ', $missing);
    if ($extra)   $detail[] = 'sobran en PHP: ' . implode(', ', $extra);
    if (!$detail) $detail[] = 'mismo conjunto, distinto orden';

    t_fail("`{$table}`.`{$column}` == Schema::{$constant}", implode(' | ', $detail));
}

// ---------------------------------------------------------------------
// 3. El mapeo de operaciones no puede apuntar a herramientas inexistentes.
//    El ENUM de ToolCalls.tool NO se amplía: toda operación interna nueva se
//    traduce a una de las 15 que ya existen.
// ---------------------------------------------------------------------
t_section('OPERATION_TO_TOOL — mapeo obligatorio');

$mapeoValido = true;
foreach (Schema::OPERATION_TO_TOOL as $operation => $tool) {
    if (!in_array($tool, Schema::TOOLS, true)) {
        t_fail("OPERATION_TO_TOOL['{$operation}'] apunta a un valor del ENUM",
               "'{$tool}' no está en ToolCalls.tool");
        $mapeoValido = false;
    }
}
if ($mapeoValido) {
    t_pass('todas las operaciones apuntan a valores válidos de ToolCalls.tool');
}

// El mapeo de la Tarea B.2, entrada por entrada. Si alguien decide que
// apply_edit ahora se registra como write_file, que sea una decisión visible
// y no un literal cambiado de paso.
$mapeoObligatorio = [
    'apply_edit'   => Schema::TOOL_STR_REPLACE,
    'full_rewrite' => Schema::TOOL_WRITE_FILE,
    'create_file'  => Schema::TOOL_CREATE_FILE,
    'read'         => Schema::TOOL_VIEW,
    'delete'       => Schema::TOOL_DELETE_FILE,
    'lint'         => Schema::TOOL_LINT,
    'dry_run'      => Schema::TOOL_PREVIEW_DIFF,
    'rollback'     => Schema::TOOL_RESTORE_VERSION,
];
foreach ($mapeoObligatorio as $operation => $expected) {
    t_eq($expected, Schema::toolForOperation($operation),
         "'{$operation}' se registra como '{$expected}'");
}

// ---------------------------------------------------------------------
// 4. Los validadores rechazan lo que no está en el ENUM. Si dejaran pasar un
//    literal inventado, volveríamos al bug del Scout.
// ---------------------------------------------------------------------
t_section('Validadores — fallan ruidosamente');

t_throws(static fn() => Schema::phase('scoutt'),
    'phase() rechaza una fase inexistente', InvalidArgumentException::class);
t_no_throw(static fn() => Schema::phase(Schema::PHASE_SCOUT),
    'phase() acepta una fase válida');

t_throws(static fn() => Schema::tool('apply_edit'),
    'tool() rechaza una operación interna que no es del ENUM', InvalidArgumentException::class);
t_no_throw(static fn() => Schema::tool(Schema::TOOL_STR_REPLACE),
    'tool() acepta un valor del ENUM');

t_throws(static fn() => Schema::toolForOperation('operacion_inventada'),
    'toolForOperation() rechaza una operación sin mapeo', InvalidArgumentException::class);

t_throws(static fn() => Schema::fileVersionStatus('stable'),
    'fileVersionStatus() rechaza "stable" (es is_stable, no status)', InvalidArgumentException::class);
t_no_throw(static fn() => Schema::fileVersionStatus(Schema::FV_COMMITTED),
    'fileVersionStatus() acepta "committed"');

t_throws(static fn() => Schema::sourceStatus('indexado'),
    'sourceStatus() rechaza un valor mal escrito', InvalidArgumentException::class);

// ---------------------------------------------------------------------
// 5. Las columnas GENERATED siguen siendo generadas.
//    El código nunca las escribe; si dejaran de ser generadas, los INSERT que
//    las omiten empezarían a meter NULL sin que nada avisara.
// ---------------------------------------------------------------------
t_section('Columnas GENERATED — nunca se escriben');

foreach ([['ProjectSources', 's3_key_hash'], ['S3Folders', 'PrefixHash'], ['ToolCalls', 'params_hash']] as [$table, $column]) {
    $body = schemaTableBody($schema, $table);
    $esGenerada = $body !== null
        && preg_match('/`' . preg_quote($column, '/') . '`[^,]*GENERATED ALWAYS AS/i', $body) === 1;
    t_ok($esGenerada, "`{$table}`.`{$column}` sigue siendo GENERATED");
}

// ---------------------------------------------------------------------
// 6. Trampas del esquema en las que el código ya se apoya.
// ---------------------------------------------------------------------
t_section('Trampas del esquema');

$usersBody = schemaTableBody($schema, 'Users');
t_ok($usersBody !== null && preg_match('/^\s*`id` int/m', $usersBody) === 1,
     'Users usa `id` (no `id_`): es la única tabla así');

$toolCallsBody = schemaTableBody($schema, 'ToolCalls');
t_ok($toolCallsBody !== null && strpos($toolCallsBody, '`project_id_`') !== false,
     'ToolCalls tiene project_id_');
t_ok($toolCallsBody !== null && strpos($toolCallsBody, '`target_path`') !== false,
     'ToolCalls tiene target_path');
t_ok(strpos($schema, 'idx_tc_loop_detect') !== false,
     'existe idx_tc_loop_detect para la detección de bucles');

$projectsBody = schemaTableBody($schema, 'Projects');
t_ok($projectsBody !== null && strpos($projectsBody, '`index_gen`') !== false,
     'Projects.index_gen es columna real, no vive en meta');

$fvBody = schemaTableBody($schema, 'FileVersions');
t_ok($fvBody !== null && strpos($fvBody, '`is_stable`') !== false && strpos($fvBody, '`status`') !== false,
     'FileVersions conserva is_stable Y status: son conceptos distintos');

t_ok(strpos($schema, 'uq_files3_user_key') !== false,
     'FileS3 tiene uq_files3_user_key (user_id_, Encriptado)');
