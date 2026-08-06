<?php
/**
 * FileToolkit.php
 *
 * Helpers PUROS (sin BD, sin S3, sin sesión, sin red) compartidos por los
 * endpoints que manipulan archivos de proyecto: code_edit.php y los futuros
 * file_*.php / rollback_edit.php.
 *
 * REGLA DE ESTE ARCHIVO: todo lo que viva aquí debe ser testeable llamándolo
 * directamente desde tests/ sin arrancar app_bootstrap.php. Si una función
 * necesita $db, $s3 o $_SESSION, NO va aquí.
 *
 * Fase 0 del refactor: andamiaje. No cambia el comportamiento de nada.
 */

// =====================================================================
// next_id() — helper compartido TEMPORAL
// =====================================================================
// Se declara aquí, guardado con function_exists(), por dos motivos:
//
//  1. ProjectIndexer.php la LLAMA pero no la declaraba: dependía de que el
//     archivo que lo incluyera (hoy solo code_edit.php) la hubiera declarado
//     antes. Cualquier endpoint nuevo que incluyera ProjectIndexer sin
//     declararla daba fatal error. Al requerir FileToolkit desde
//     ProjectIndexer, esa dependencia oculta desaparece.
//
//  2. El guard evita colisión con las declaraciones que ya existen en
//     code_edit.php, tools.php, projects.php, index_project_sources.php y
//     bedrock_chat2.php. PHP declara las funciones de nivel superior al
//     compilar el archivo, antes de ejecutar sus require(), así que cuando
//     esto corre la función del llamador ya existe y aquí no se redeclara.
//
// @deprecated Fase 2 del refactor la elimina: TODAS las tablas del esquema ya
// tienen AUTO_INCREMENT (verificado en adbbmis1_Cloud.sql), así que este
// SELECT MAX(id)+1 no solo es innecesario, además produce duplicate key bajo
// concurrencia. El reemplazo es omitir la columna id_ en el INSERT y leer
// $db->insert_id.
if (!function_exists('next_id')) {
    function next_id(mysqli $db, string $table, string $col): int {
        $table = preg_replace('/[^A-Za-z0-9_]+/', '', $table);
        $col   = preg_replace('/[^A-Za-z0-9_]+/', '', $col);
        $rs = $db->query("SELECT COALESCE(MAX($col), 0) + 1 AS nxt FROM $table");
        if (!$rs) return 1;
        $row = $rs->fetch_assoc();
        return (int)($row['nxt'] ?? 1);
    }
}

// =====================================================================
// sanitizeRelativePath()
// =====================================================================
/**
 * Valida y normaliza una ruta RELATIVA de archivo dentro de un proyecto.
 *
 * Rechaza (lanzando InvalidArgumentException) en vez de "limpiar
 * silenciosamente": si la entrada es hostil queremos un error visible, no una
 * ruta distinta a la que el usuario pidió.
 *
 * Concretamente rechaza: bytes nulos, caracteres de control, backslashes,
 * rutas absolutas (POSIX y unidad de Windows), y cualquier segmento "..".
 * Normaliza: recorta espacios, colapsa "//" repetidos y descarta segmentos ".".
 *
 * @param string   $path              Ruta relativa cruda (ej. "src/models/User.php")
 * @param string[] $allowedExtensions Lista blanca de extensiones SIN punto.
 *                                    Vacía = se acepta cualquier extensión.
 * @return string Ruta relativa normalizada, nunca con "/" inicial ni final.
 * @throws InvalidArgumentException
 */
function sanitizeRelativePath(string $path, array $allowedExtensions = []): string {
    if (strpos($path, "\0") !== false) {
        throw new InvalidArgumentException('Ruta inválida: contiene un byte nulo.');
    }

    $path = trim($path);
    if ($path === '') {
        throw new InvalidArgumentException('Ruta inválida: vacía.');
    }

    // Los caracteres de control rompen las keys de S3 y las cabeceras HTTP.
    if (preg_match('/[\x00-\x1F\x7F]/', $path)) {
        throw new InvalidArgumentException('Ruta inválida: contiene caracteres de control.');
    }

    // Un backslash aquí no es un separador legítimo, es un intento de saltarse
    // la validación asumiendo semántica Windows. Se rechaza, no se traduce.
    if (strpos($path, '\\') !== false) {
        throw new InvalidArgumentException('Ruta inválida: los backslashes no están permitidos.');
    }

    if ($path[0] === '/') {
        throw new InvalidArgumentException('Ruta inválida: debe ser relativa, no absoluta.');
    }

    // Unidad de Windows ("C:/algo", "c:algo").
    if (preg_match('#^[A-Za-z]:#', $path)) {
        throw new InvalidArgumentException('Ruta inválida: debe ser relativa, no absoluta.');
    }

    $segments = [];
    foreach (explode('/', $path) as $segment) {
        // "" colapsa las barras dobles; "." es un no-op posicional.
        if ($segment === '' || $segment === '.') {
            continue;
        }
        // Se RECHAZA en vez de resolver: resolver ".." permitiría construir una
        // ruta que sale del prefijo del proyecto por caminos no obvios.
        if ($segment === '..') {
            throw new InvalidArgumentException('Ruta inválida: no se permite ".." (path traversal).');
        }
        $segments[] = $segment;
    }

    if (empty($segments)) {
        throw new InvalidArgumentException('Ruta inválida: no queda ningún segmento tras normalizar.');
    }

    $clean = implode('/', $segments);

    if ($allowedExtensions !== []) {
        $ext = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
        $allowed = array_map('strtolower', $allowedExtensions);
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            throw new InvalidArgumentException(
                'Extensión no permitida: ' . ($ext === '' ? '(sin extensión)' : $ext)
            );
        }
    }

    return $clean;
}

// =====================================================================
// buildProjectS3Key()
// =====================================================================
/**
 * Construye la key de S3 de un archivo del proyecto a partir del root_prefix
 * y una ruta relativa, garantizando que el resultado queda DENTRO del prefijo.
 *
 * El root_prefix ya incluye el user_id (ver projects.php), así que contener la
 * key dentro del prefijo es lo que impide que un usuario alcance archivos de
 * otro.
 *
 * @throws InvalidArgumentException
 */
function buildProjectS3Key(string $rootPrefix, string $relativePath, array $allowedExtensions = []): string {
    if (strpos($rootPrefix, "\0") !== false) {
        throw new InvalidArgumentException('root_prefix inválido: contiene un byte nulo.');
    }

    $base = rtrim(trim($rootPrefix), '/');
    if ($base === '') {
        throw new InvalidArgumentException('root_prefix inválido: vacío.');
    }

    $relative = sanitizeRelativePath($relativePath, $allowedExtensions);
    $key = $base . '/' . $relative;

    // Verificación defensiva: aunque sanitizeRelativePath ya garantiza que no
    // hay "..", se comprueba el resultado final. Si algún día se relaja la
    // función de arriba, este chequeo sigue siendo la última línea de defensa.
    $expectedPrefix = $base . '/';
    if (strncmp($key, $expectedPrefix, strlen($expectedPrefix)) !== 0) {
        throw new InvalidArgumentException('La key resultante se sale del prefijo del proyecto.');
    }
    if (strpos($key, '/../') !== false || substr($key, -3) === '/..') {
        throw new InvalidArgumentException('La key resultante contiene path traversal.');
    }

    return $key;
}

// =====================================================================
// normalizeInstruction()
// =====================================================================
/**
 * Normaliza una instrucción en lenguaje natural para usarla como parte de una
 * clave de caché (Fase 4).
 *
 * Deliberadamente NO hace normalización semántica: solo recorta, colapsa
 * espacios en blanco y pasa a minúsculas. Dos instrucciones que significan lo
 * mismo pero se escriben distinto deben producir claves distintas — un acierto
 * de caché falso serviría el resultado de otra edición.
 */
function normalizeInstruction(string $instruction): string {
    $collapsed = preg_replace('/\s+/u', ' ', trim($instruction));
    if ($collapsed === null) {
        // preg_replace devuelve null ante entrada UTF-8 inválida.
        $collapsed = trim($instruction);
    }
    return mb_strtolower($collapsed, 'UTF-8');
}
