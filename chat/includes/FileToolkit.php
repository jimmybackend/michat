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

// =====================================================================
// Política de tipos de archivo
// =====================================================================

/** Extensiones editables por defecto. Deliberadamente amplia: bloquear un
 *  archivo legítimo de un proyecto existente es peor que aceptarlo. */
const FT_DEFAULT_EXTENSIONS = [
    'php','phtml','inc','js','mjs','cjs','jsx','ts','tsx','vue','svelte',
    'py','rb','go','rs','java','kt','kts','scala','c','h','cpp','hpp','cc','hh',
    'cs','swift','m','mm','pl','lua','r','dart','ex','exs','erl','clj',
    'sql','sh','bash','zsh','fish','ps1','bat',
    'html','htm','css','scss','sass','less','styl','twig','blade',
    'json','yml','yaml','xml','toml','ini','cfg','conf','properties','env',
    'md','markdown','txt','rst','csv','tsv','graphql','gql','proto','tf','tfvars',
];

/** Archivos sin extensión que sí son editables (se comparan en minúsculas). */
const FT_DEFAULT_BASENAMES = [
    'dockerfile','makefile','jenkinsfile','procfile','rakefile','gemfile',
    'vagrantfile','.gitignore','.gitattributes','.dockerignore','.env',
    '.editorconfig','.eslintrc','.prettierrc','.babelrc','license','readme',
];

/**
 * Verifica que la ruta apunte a un tipo de archivo editable.
 *
 * Se mantiene separada de sanitizeRelativePath() a propósito: aquella resuelve
 * seguridad (path traversal), esta resuelve política (qué se puede editar). Un
 * archivo sin extensión no es inseguro, simplemente puede no estar permitido.
 *
 * @param string[]|null $extensions Sobrescribe FT_DEFAULT_EXTENSIONS
 * @param string[]|null $basenames  Sobrescribe FT_DEFAULT_BASENAMES
 * @throws InvalidArgumentException
 */
function assertAllowedFileType(string $relativePath, ?array $extensions = null, ?array $basenames = null): void {
    $extensions = array_map('strtolower', $extensions ?? FT_DEFAULT_EXTENSIONS);
    $basenames  = array_map('strtolower', $basenames  ?? FT_DEFAULT_BASENAMES);

    $base = strtolower(basename($relativePath));
    if (in_array($base, $basenames, true)) {
        return;
    }

    $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
    if ($ext !== '' && in_array($ext, $extensions, true)) {
        return;
    }

    throw new InvalidArgumentException(
        $ext === ''
            ? "Archivo sin extensión no permitido: {$base}"
            : "Extensión no permitida: .{$ext}"
    );
}

// =====================================================================
// cleanMarkdown()
// =====================================================================
/**
 * Quita el bloque de código markdown que envuelve una respuesta del modelo.
 *
 * La versión anterior usaba preg_replace('/^`+|`+$/m', ...): el flag /m aplica
 * la limpieza al inicio y fin de CADA LÍNEA, así que destruía template literals
 * de JavaScript (`hola ${x}`) e identificadores entrecomillados de MySQL
 * (`tabla`). Aquí solo se retira el fence que envuelve el bloque completo, y
 * jamás se toca un backtick interior.
 */
function cleanMarkdown(string $text): string {
    $trimmed = trim($text);

    // ```lang\n ... \n```  (el caso normal)
    if (preg_match('/^```[a-zA-Z0-9_+#.-]*[ \t]*\R(.*)\R[ \t]*```$/s', $trimmed, $m)) {
        return trim($m[1]);
    }
    // ```...``` en una sola línea
    if (preg_match('/^```[a-zA-Z0-9_+#.-]*[ \t]*(.*?)[ \t]*```$/s', $trimmed, $m)) {
        return trim($m[1]);
    }
    return $trimmed;
}

// =====================================================================
// extractJsonObject()
// =====================================================================
/**
 * Extrae un objeto JSON de la respuesta de un modelo, tolerando que venga
 * envuelto en un fence markdown o con prosa alrededor.
 *
 * @return array|null null si no se pudo decodificar un objeto.
 */
function extractJsonObject(string $text): ?array {
    $candidate = cleanMarkdown($text);

    $decoded = json_decode($candidate, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    // Reintento: recortar desde la primera { hasta la última } por si el modelo
    // añadió una frase antes o después del JSON.
    $start = strpos($candidate, '{');
    $end   = strrpos($candidate, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $decoded = json_decode(substr($candidate, $start, $end - $start + 1), true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return null;
}

// =====================================================================
// applyUniqueEdit()
// =====================================================================
/**
 * Aplica una edición por ancla: reemplaza $oldString por $newString sólo si
 * $oldString aparece EXACTAMENTE UNA VEZ en $content.
 *
 * Es el núcleo del modo de edición: si el ancla es ambigua (0 o >1
 * coincidencias) no se adivina cuál era la intención, se devuelve el conteo
 * real para que el modelo amplíe el ancla y reintente.
 *
 * @return array{ok:bool,count:int,content:?string,error:string}
 */
function applyUniqueEdit(string $content, string $oldString, string $newString): array {
    if ($oldString === '') {
        return ['ok' => false, 'count' => 0, 'content' => null, 'error' => 'old_string está vacío.'];
    }

    $count = substr_count($content, $oldString);

    if ($count === 0) {
        return ['ok' => false, 'count' => 0, 'content' => null,
                'error' => 'old_string no aparece en el archivo (0 coincidencias). Cópialo literalmente del archivo, con su indentación exacta.'];
    }
    if ($count > 1) {
        return ['ok' => false, 'count' => $count, 'content' => null,
                'error' => "old_string aparece {$count} veces en el archivo. Amplía el ancla incluyendo líneas vecinas hasta que sea única."];
    }

    // substr_replace en la posición exacta: deja explícito que se sustituye una
    // sola ocurrencia, sin depender del contador interno de str_replace.
    $pos = strpos($content, $oldString);
    $result = substr_replace($content, $newString, $pos, strlen($oldString));

    return ['ok' => true, 'count' => 1, 'content' => $result, 'error' => ''];
}

// =====================================================================
// containsElisionMarker()
// =====================================================================
/**
 * Detecta que el modelo abrevió el archivo con un marcador tipo
 * "// ... resto del código sin cambios" en vez de escribirlo entero.
 *
 * Escribir eso al archivo lo destruye, así que un resultado positivo invalida
 * la reescritura completa.
 *
 * El marcador de comentario es OBLIGATORIO en el patrón. Sin él habría falsos
 * positivos con sintaxis legítima que empieza por tres puntos: el rest/spread
 * de JavaScript (`const {a, ...rest} = obj`, que además suele ir a principio de
 * línea al desestructurar en varias líneas) y los parámetros variádicos de PHP
 * (`function f(int ...$args)`).
 */
function containsElisionMarker(string $code): bool {
    $pattern = '/(?:\/\/|\#|\/\*|\*|--|<!--)[ \t]*\(?[ \t]*\.{3}[ \t]*\)?[ \t]*'
             . '(rest|resto|remaining|unchanged|sin\s+cambios|el\s+resto|same\s+as|'
             . 'igual\s+que|previous|anterior|omitted|omitido|snip|truncad)/iu';
    return (bool) preg_match($pattern, $code);
}

// =====================================================================
// detectSuspiciousShrink()
// =====================================================================
/** Palabras que hacen legítimo que un archivo encoja. */
const FT_SHRINK_KEYWORDS = [
    'eliminar','elimina','borrar','borra','quitar','quita','remove','delete',
    'suprimir','suprime','vaciar','vacía','limpiar','limpia','simplificar',
    'simplifica','reducir','reduce','acortar','acorta','refactorizar','refactoriza',
    'extraer','extrae','dividir','divide','split','truncar','trunca',
];

/**
 * Señala que el contenido nuevo perdió demasiado tamaño respecto al original
 * sin que la instrucción pidiera borrar nada — el síntoma clásico de un modelo
 * que devolvió el archivo abreviado o truncado.
 */
function detectSuspiciousShrink(string $oldContent, string $newContent, string $instruction, float $threshold = 0.60): bool {
    $oldLen = strlen($oldContent);
    if ($oldLen === 0) {
        return false; // No había nada que perder.
    }
    if (strlen($newContent) >= $oldLen * $threshold) {
        return false;
    }

    $normalized = normalizeInstruction($instruction);
    foreach (FT_SHRINK_KEYWORDS as $keyword) {
        if (mb_strpos($normalized, $keyword) !== false) {
            return false; // El encogimiento era el objetivo.
        }
    }
    return true;
}

// =====================================================================
// Utilidades de tamaño y S3
// =====================================================================

/** Estimación aproximada de tokens. El código promedia ~3.2 caracteres/token. */
function estimateTokens(string $text): int {
    return (int) ceil(mb_strlen($text) / 3.2);
}

function countLines(string $text): int {
    if ($text === '') return 0;
    return substr_count($text, "\n") + 1;
}

/** Techo de tokens de salida por modelo, conservador. */
function maxOutputTokensFor(string $modelId): int {
    if (stripos($modelId, 'nova') !== false)   return 5000;
    if (stripos($modelId, 'haiku') !== false)  return 8192;
    if (stripos($modelId, 'sonnet') !== false) return 16000;
    if (stripos($modelId, 'opus') !== false)   return 16000;
    return 4096;
}

/**
 * Construye el valor de CopySource para S3.
 *
 * La versión anterior hacía urlencode($bucket.'/'.$key), que convierte cada '/'
 * en %2F y deja una CopySource sin estructura de ruta, así que la copia fallaba.
 * Aquí se codifica cada segmento por separado y se preservan los separadores.
 */
function s3CopySource(string $bucket, string $key): string {
    $segments = array_map('rawurlencode', explode('/', ltrim($key, '/')));
    return $bucket . '/' . implode('/', $segments);
}
