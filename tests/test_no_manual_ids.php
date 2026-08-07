<?php
/**
 * test_no_manual_ids.php
 *
 * Guard estático de la Fase 2.
 *
 * Las 23 tablas del esquema tienen AUTO_INCREMENT, así que calcular el id a
 * mano con SELECT MAX(id_)+1 no solo sobra: bajo concurrencia dos requests leen
 * el mismo máximo y el segundo INSERT muere con duplicate key. El fallo es
 * intermitente y solo aparece con carga, que es justo cuando peor viene.
 *
 * Estos tests son estáticos —leen el código fuente, no lo ejecutan— porque el
 * patrón puede volver por copiar y pegar de un endpoint viejo, y entonces
 * ninguna prueba funcional lo detectaría hasta producción.
 */

declare(strict_types=1);

$chatDir = dirname(__DIR__) . '/chat';

/** Todos los .php del proyecto, con su contenido. */
function phpSources(string $dir): array {
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $out[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }
    ksort($out);
    return $out;
}

$sources = phpSources($chatDir);

t_section('Fase 2 — ningún id se calcula a mano');

t_ok(count($sources) > 20, 'se encontraron los fuentes PHP del proyecto (' . count($sources) . ')');

// 1. next_id() no existe: ni declarada, ni llamada.
$conNextId = [];
foreach ($sources as $path => $code) {
    // Se ignoran los comentarios: varios archivos explican POR QUÉ se eliminó.
    $sinComentarios = preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $code);
    if (preg_match('/\bnext_id\s*\(/', $sinComentarios)) {
        $conNextId[] = basename($path);
    }
}
t_eq([], $conNextId, 'next_id() no se declara ni se llama en ninguna parte');

// 2. Nadie calcula el siguiente id con MAX(id_)+1.
$conMaxId = [];
foreach ($sources as $path => $code) {
    if (preg_match('/MAX\s*\(\s*`?id_?`?\s*\)\s*(?:,\s*0\s*\))?\s*\+\s*1/i', $code)) {
        $conMaxId[] = basename($path);
    }
}
t_eq([], $conMaxId, 'nadie usa SELECT MAX(id_)+1 para generar ids');

// 3. Ningún INSERT envía la columna id_ / id.
//    Se busca la lista de columnas del INSERT y se mira si la primera es un id.
$conIdEnInsert = [];
foreach ($sources as $path => $code) {
    if (preg_match_all('/INSERT\s+INTO\s+`?\w+`?\s*\(([^)]*)\)/is', $code, $m)) {
        foreach ($m[1] as $columnas) {
            $primera = strtolower(trim(explode(',', $columnas)[0], " \t\n\r`"));
            if ($primera === 'id_' || $primera === 'id') {
                $conIdEnInsert[] = basename($path);
                break;
            }
        }
    }
}
t_eq([], $conIdEnInsert, 'ningún INSERT envía la columna id_ (la pone AUTO_INCREMENT)');

// 4. Las columnas GENERATED tampoco se escriben nunca.
$columnasGeneradas = ['s3_key_hash', 'PrefixHash', 'params_hash'];
$escribenGenerada = [];
foreach ($sources as $path => $code) {
    if (preg_match_all('/INSERT\s+INTO\s+`?\w+`?\s*\(([^)]*)\)/is', $code, $m)) {
        foreach ($m[1] as $columnas) {
            foreach ($columnasGeneradas as $gen) {
                if (preg_match('/\b' . preg_quote($gen, '/') . '\b/i', $columnas)) {
                    $escribenGenerada[] = basename($path) . ':' . $gen;
                }
            }
        }
    }
}
t_eq([], $escribenGenerada, 'ningún INSERT escribe una columna GENERATED');

t_section('Fase 2 — versionado en S3');

$codeEdit = (string) file_get_contents($chatDir . '/code_edit.php');
// Sin comentarios: varios explican POR QUÉ se eliminó el .ver0, y mencionarlo
// en una explicación no es usarlo.
$codeEditSinComentarios = preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $codeEdit);

// El respaldo .ver0 se sobrescribía en cada edición: solo permitía volver una
// versión atrás. Lo sustituye la key versionada {key}.v{n}.
t_ok(strpos($codeEditSinComentarios, '.ver0') === false,
     'ya no se escribe ningún respaldo .ver0');
t_ok(strpos($codeEdit, "'.v' . \$nextVersion") !== false,
     'la key versionada se deriva de la canónica ({key}.v{n})');

// El putObject de la canónica tiene que ir DESPUÉS del commit: si va dentro de
// la transacción, un rollback deja S3 adelantado respecto a la base.
$posCommit    = strpos($codeEdit, '$db_connection->commit();');
$posCanonica  = strpos($codeEdit, '14c. Publicar en la key canónica');
t_ok($posCommit !== false && $posCanonica !== false && $posCanonica > $posCommit,
     'la publicación en la key canónica ocurre después del COMMIT');

t_section('Fase 2 — el lock no se escapa por un exit()');

// jexit() llama exit, y exit NO ejecuta bloques finally. Por eso el lock se
// suelta dentro de jexit() y en register_shutdown_function(), nunca con
// try/finally.
t_ok(preg_match('/function jexit\([^)]*\)\s*\{\s*releaseEditLock\(\);/', $codeEdit) === 1,
     'jexit() suelta el lock antes de terminar el request');
t_ok(strpos($codeEdit, "register_shutdown_function('releaseEditLock')") !== false,
     'hay un shutdown handler que cubre los fatal errors');
t_ok(strpos($codeEdit, 'IS_USED_LOCK') !== false,
     'se sueltan los locks heredados (escenario de conexión persistente)');
