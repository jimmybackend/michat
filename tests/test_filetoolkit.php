<?php
/**
 * Tests de chat/includes/FileToolkit.php
 *
 * Cubre el requisito de Fase 1: sanitizeRelativePath debe rechazar path
 * traversal antes de que la ruta llegue a construir una key de S3.
 */

declare(strict_types=1);

require_once __DIR__ . '/../chat/includes/FileToolkit.php';

// =====================================================================
t_section('sanitizeRelativePath — entradas válidas');
// =====================================================================

t_eq('ejemplo.php', sanitizeRelativePath('ejemplo.php'), 'archivo simple en la raíz');
t_eq('src/models/User.php', sanitizeRelativePath('src/models/User.php'), 'ruta anidada');
t_eq('ejemplo.php', sanitizeRelativePath('  ejemplo.php  '), 'recorta espacios');
t_eq('a/b.php', sanitizeRelativePath('a//b.php'), 'colapsa barras dobles');
t_eq('a/b.php', sanitizeRelativePath('a///////b.php'), 'colapsa muchas barras');
t_eq('a/b.php', sanitizeRelativePath('./a/./b.php'), 'descarta segmentos "."');
t_eq('a/b.php', sanitizeRelativePath('a/b.php/'), 'ignora la barra final');
t_eq('..hidden.php', sanitizeRelativePath('..hidden.php'), 'un nombre que empieza con .. no es traversal');
t_eq('....', sanitizeRelativePath('....'), 'cuatro puntos es un nombre literal, no traversal');
t_eq('mi archivo.php', sanitizeRelativePath('mi archivo.php'), 'permite espacios internos');
t_eq('acción.php', sanitizeRelativePath('acción.php'), 'permite UTF-8');

// =====================================================================
t_section('sanitizeRelativePath — path traversal (debe rechazar)');
// =====================================================================

t_throws(fn() => sanitizeRelativePath('../secreto.php'), 'rechaza ../ al inicio', InvalidArgumentException::class);
t_throws(fn() => sanitizeRelativePath('a/../../secreto.php'), 'rechaza ../ en medio', InvalidArgumentException::class);
t_throws(fn() => sanitizeRelativePath('a/b/..'), 'rechaza .. al final', InvalidArgumentException::class);
t_throws(fn() => sanitizeRelativePath('..'), 'rechaza .. suelto', InvalidArgumentException::class);
t_throws(fn() => sanitizeRelativePath('a/..//../x.php'), 'rechaza .. tras colapsar barras', InvalidArgumentException::class);
t_throws(fn() => sanitizeRelativePath('./../x.php'), 'rechaza .. precedido de .', InvalidArgumentException::class);

// =====================================================================
t_section('sanitizeRelativePath — rutas absolutas y bypass (debe rechazar)');
// =====================================================================

t_throws(fn() => sanitizeRelativePath('/etc/passwd'), 'rechaza ruta absoluta POSIX', InvalidArgumentException::class);
t_throws(fn() => sanitizeRelativePath('C:/Windows/System32/x.php'), 'rechaza unidad de Windows', InvalidArgumentException::class);
t_throws(fn() => sanitizeRelativePath('c:x.php'), 'rechaza unidad de Windows sin barra', InvalidArgumentException::class);
t_throws(fn() => sanitizeRelativePath('a\\..\\b.php'), 'rechaza backslashes', InvalidArgumentException::class);
t_throws(fn() => sanitizeRelativePath("a\0.php"), 'rechaza byte nulo', InvalidArgumentException::class);
t_throws(fn() => sanitizeRelativePath("a\n.php"), 'rechaza salto de línea', InvalidArgumentException::class);
t_throws(fn() => sanitizeRelativePath("a\t.php"), 'rechaza tabulador', InvalidArgumentException::class);
t_throws(fn() => sanitizeRelativePath(''), 'rechaza cadena vacía', InvalidArgumentException::class);
t_throws(fn() => sanitizeRelativePath('   '), 'rechaza solo espacios', InvalidArgumentException::class);
t_throws(fn() => sanitizeRelativePath('///'), 'rechaza solo barras', InvalidArgumentException::class);
t_throws(fn() => sanitizeRelativePath('./.'), 'rechaza ruta que se normaliza a vacío', InvalidArgumentException::class);

// =====================================================================
t_section('sanitizeRelativePath — lista blanca de extensiones');
// =====================================================================

$allowed = ['php', 'js', 'py'];
t_eq('x.php', sanitizeRelativePath('x.php', $allowed), 'acepta extensión en la lista');
t_eq('x.PHP', sanitizeRelativePath('x.PHP', $allowed), 'la comparación de extensión ignora mayúsculas');
t_throws(fn() => sanitizeRelativePath('x.exe', $allowed), 'rechaza extensión fuera de la lista', InvalidArgumentException::class);
t_throws(fn() => sanitizeRelativePath('sinextension', $allowed), 'rechaza archivo sin extensión', InvalidArgumentException::class);
t_no_throw(fn() => sanitizeRelativePath('x.loquesea'), 'lista vacía acepta cualquier extensión');

// =====================================================================
t_section('buildProjectS3Key — contención dentro del prefijo');
// =====================================================================

$prefix = 'Data/Chat/Uploads/42/2026/08/06/mi-proyecto/';

t_eq(
    'Data/Chat/Uploads/42/2026/08/06/mi-proyecto/ejemplo.php',
    buildProjectS3Key($prefix, 'ejemplo.php'),
    'construye la key con el prefijo'
);
t_eq(
    'Data/Chat/Uploads/42/2026/08/06/mi-proyecto/src/User.php',
    buildProjectS3Key($prefix, 'src/User.php'),
    'construye la key con subcarpeta'
);
t_eq(
    'Data/Chat/Uploads/42/2026/08/06/mi-proyecto/ejemplo.php',
    buildProjectS3Key('Data/Chat/Uploads/42/2026/08/06/mi-proyecto', 'ejemplo.php'),
    'un prefijo sin barra final da el mismo resultado'
);

// El caso que importa: un usuario intentando salir a la carpeta de otro.
t_throws(
    fn() => buildProjectS3Key($prefix, '../../../../../../../99/2026/08/06/otro/robado.php'),
    'no se puede escapar al prefijo de otro usuario',
    InvalidArgumentException::class
);
t_throws(fn() => buildProjectS3Key($prefix, '/etc/passwd'), 'no acepta ruta absoluta', InvalidArgumentException::class);
t_throws(fn() => buildProjectS3Key('', 'x.php'), 'rechaza root_prefix vacío', InvalidArgumentException::class);
t_throws(fn() => buildProjectS3Key('   ', 'x.php'), 'rechaza root_prefix en blanco', InvalidArgumentException::class);
t_throws(fn() => buildProjectS3Key("a\0b/", 'x.php'), 'rechaza byte nulo en root_prefix', InvalidArgumentException::class);

// Toda key generada debe empezar por el prefijo, sin excepción.
$casos = ['a.php', 'a/b.php', 'a//b.php', './a.php', 'a/b/c/d/e.php'];
$todasContenidas = true;
foreach ($casos as $caso) {
    if (strncmp(buildProjectS3Key($prefix, $caso), $prefix, strlen($prefix)) !== 0) {
        $todasContenidas = false;
        break;
    }
}
t_ok($todasContenidas, 'toda key válida queda contenida en el prefijo');

// =====================================================================
t_section('normalizeInstruction — claves de caché');
// =====================================================================

t_eq('crea un archivo', normalizeInstruction('  Crea un archivo  '), 'recorta y pasa a minúsculas');
t_eq('crea un archivo', normalizeInstruction("Crea\n\tun    archivo"), 'colapsa cualquier espacio en blanco');
t_eq(
    normalizeInstruction('Crea Un Archivo'),
    normalizeInstruction('crea un archivo'),
    'dos formas de la misma instrucción dan la misma clave'
);
t_ok(
    normalizeInstruction('borra el archivo') !== normalizeInstruction('crea el archivo'),
    'instrucciones distintas dan claves distintas'
);
t_eq('añade la función login', normalizeInstruction('Añade la función LOGIN'), 'minúsculas con acentos UTF-8');
