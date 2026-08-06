<?php
/**
 * Tests del motor de edición (Fase 1): edición por ancla única, guard de
 * reducción de tamaño, detección de elisión y limpieza de markdown.
 */

declare(strict_types=1);

require_once __DIR__ . '/../chat/includes/FileToolkit.php';

// =====================================================================
t_section('applyUniqueEdit — unicidad del ancla');
// =====================================================================

$archivo = <<<'PHP'
<?php
class Usuario {
    public function login(string $email): bool {
        return $this->verificar($email);
    }

    public function logout(): void {
        session_destroy();
    }
}
PHP;

$r = applyUniqueEdit($archivo, 'public function logout(): void {', 'public function logout(): bool {');
t_ok($r['ok'], 'aplica la edición cuando el ancla es única');
t_eq(1, $r['count'], 'reporta 1 coincidencia');
t_ok(str_contains((string)$r['content'], 'public function logout(): bool {'), 'el contenido nuevo está presente');
t_ok(str_contains((string)$r['content'], 'public function login(string $email): bool {'), 'no toca el resto del archivo');

$r = applyUniqueEdit($archivo, 'public function inexistente()', 'x');
t_ok(!$r['ok'], 'rechaza un ancla que no existe');
t_eq(0, $r['count'], 'reporta 0 coincidencias');
t_eq(null, $r['content'], 'no devuelve contenido cuando falla');

// El caso central: un ancla ambigua no debe adivinar cuál de las dos era.
$repetido = "foo();\nbar();\nfoo();\n";
$r = applyUniqueEdit($repetido, 'foo();', 'baz();');
t_ok(!$r['ok'], 'rechaza un ancla que aparece 2 veces');
t_eq(2, $r['count'], 'reporta el conteo real para que el modelo amplíe el ancla');
t_ok(str_contains($r['error'], '2 veces'), 'el mensaje de error lleva el conteo real');

$r = applyUniqueEdit($repetido, "bar();\nfoo();", "bar();\nbaz();");
t_ok($r['ok'], 'ampliar el ancla con una línea vecina la vuelve única');
t_eq("foo();\nbar();\nbaz();\n", $r['content'], 'sustituye exactamente la segunda ocurrencia');

$r = applyUniqueEdit($archivo, '', 'x');
t_ok(!$r['ok'], 'rechaza old_string vacío');

// Una sola ocurrencia debe reemplazarse aunque el texto nuevo la contenga.
$r = applyUniqueEdit("valor = 1;\n", 'valor = 1;', 'valor = 1; // comentado');
t_eq("valor = 1; // comentado\n", $r['content'], 'no entra en bucle si new_string contiene a old_string');

// =====================================================================
t_section('containsElisionMarker — sin falsos positivos en código real');
// =====================================================================

t_ok(containsElisionMarker('// ... resto del código sin cambios'), 'detecta elisión en español');
t_ok(containsElisionMarker('// ... rest of the file unchanged'), 'detecta elisión en inglés');
t_ok(containsElisionMarker('# ... resto'), 'detecta elisión con # (Python/shell)');
t_ok(containsElisionMarker('/* ... remaining methods */'), 'detecta elisión en bloque /* */');
t_ok(containsElisionMarker('    // (... el resto igual)'), 'detecta elisión entre paréntesis e indentada');
t_ok(containsElisionMarker('<!-- ... unchanged -->'), 'detecta elisión en HTML');
t_ok(containsElisionMarker("class A {\n  metodo() {}\n  // ... omitted\n}"), 'detecta elisión en medio de un archivo');

// Estos DEBEN pasar: son sintaxis legítima que empieza por tres puntos.
t_ok(!containsElisionMarker('const {a, ...rest} = obj;'), 'no marca el rest/spread de JavaScript');
t_ok(!containsElisionMarker("const {\n  a,\n  ...rest\n} = obj;"), 'no marca el rest de JS a principio de línea');
t_ok(!containsElisionMarker('function f(int ...$args) {}'), 'no marca los parámetros variádicos de PHP');
t_ok(!containsElisionMarker('const copia = [...anterior];'), 'no marca el spread de arrays aunque la variable se llame "anterior"');
t_ok(!containsElisionMarker('$total = array_sum([...$previous]);'), 'no marca el spread de PHP con variable "previous"');
t_ok(!containsElisionMarker('// Esta función elimina el resto de la lista'), 'no marca prosa sin tres puntos');
t_ok(!containsElisionMarker('<?php echo "hola";'), 'no marca código normal');

// =====================================================================
t_section('detectSuspiciousShrink — guard de pérdida de código');
// =====================================================================

$original = str_repeat("linea de codigo;\n", 100); // ~1700 bytes
$mitad    = str_repeat("linea de codigo;\n", 20);  // ~20% del original
$casiIgual = str_repeat("linea de codigo;\n", 95);

t_ok(detectSuspiciousShrink($original, $mitad, 'añade validación de email'),
     'marca una reducción al 20% con instrucción que no pide borrar');
t_ok(!detectSuspiciousShrink($original, $casiIgual, 'añade validación de email'),
     'no marca una reducción pequeña');
t_ok(!detectSuspiciousShrink($original, $mitad, 'elimina los métodos obsoletos'),
     'no marca si la instrucción dice "elimina"');
t_ok(!detectSuspiciousShrink($original, $mitad, 'Borra la clase Legacy'),
     'no marca si la instrucción dice "Borra" (ignora mayúsculas)');
t_ok(!detectSuspiciousShrink($original, $mitad, 'remove the deprecated helpers'),
     'no marca con palabra de borrado en inglés');
t_ok(!detectSuspiciousShrink($original, $mitad, 'simplifica esta clase'),
     'no marca cuando se pide simplificar');
t_ok(!detectSuspiciousShrink('', 'contenido nuevo', 'crea el archivo'),
     'no marca cuando no había contenido previo (creación)');
t_ok(!detectSuspiciousShrink($original, $original . 'extra', 'añade un método'),
     'no marca cuando el archivo crece');
t_ok(detectSuspiciousShrink($original, '', 'añade un método'),
     'marca el caso extremo de contenido vacío');

// =====================================================================
t_section('cleanMarkdown — no debe corromper código');
// =====================================================================

t_eq('<?php echo 1;', cleanMarkdown("```php\n<?php echo 1;\n```"), 'quita el fence con lenguaje');
t_eq('<?php echo 1;', cleanMarkdown("```\n<?php echo 1;\n```"), 'quita el fence sin lenguaje');
t_eq('<?php echo 1;', cleanMarkdown('<?php echo 1;'), 'deja intacto el código sin fence');
t_eq('<?php echo 1;', cleanMarkdown("  ```php\n<?php echo 1;\n```  "), 'tolera espacios alrededor del fence');

// La regresión que motivó el arreglo: /m destruía estos dos casos.
$js = 'const saludo = `hola ${nombre}`;';
t_eq($js, cleanMarkdown($js), 'preserva los template literals de JavaScript');
t_eq($js, cleanMarkdown("```js\n{$js}\n```"), 'preserva los template literals dentro de un fence');

$sql = 'SELECT `id`, `nombre` FROM `usuarios`;';
t_eq($sql, cleanMarkdown($sql), 'preserva los identificadores entrecomillados de MySQL');
t_eq($sql, cleanMarkdown("```sql\n{$sql}\n```"), 'preserva los identificadores de MySQL dentro de un fence');

$multi = "const a = `x`;\nconst b = `y`;";
t_eq($multi, cleanMarkdown($multi), 'preserva backticks en varias líneas seguidas');

// =====================================================================
t_section('extractJsonObject — respuestas del modelo');
// =====================================================================

$esperado = ['old_string' => 'a', 'new_string' => 'b'];
t_eq($esperado, extractJsonObject('{"old_string":"a","new_string":"b"}'), 'JSON pelado');
t_eq($esperado, extractJsonObject("```json\n{\"old_string\":\"a\",\"new_string\":\"b\"}\n```"), 'JSON en fence');
t_eq($esperado, extractJsonObject("Aquí tienes:\n{\"old_string\":\"a\",\"new_string\":\"b\"}\nEspero que sirva."), 'JSON rodeado de prosa');
t_eq(null, extractJsonObject('esto no es json'), 'devuelve null si no hay JSON');
t_eq(null, extractJsonObject(''), 'devuelve null con cadena vacía');

$conSaltos = extractJsonObject('{"old_string":"line1\nline2","new_string":"x"}');
t_eq("line1\nline2", $conSaltos['old_string'], 'preserva los saltos de línea escapados dentro del JSON');

// =====================================================================
t_section('assertAllowedFileType — política de tipos');
// =====================================================================

t_no_throw(fn() => assertAllowedFileType('src/User.php'), 'acepta .php');
t_no_throw(fn() => assertAllowedFileType('app.tsx'), 'acepta .tsx');
t_no_throw(fn() => assertAllowedFileType('script.PY'), 'la extensión ignora mayúsculas');
t_no_throw(fn() => assertAllowedFileType('Dockerfile'), 'acepta Dockerfile sin extensión');
t_no_throw(fn() => assertAllowedFileType('.gitignore'), 'acepta .gitignore');
t_no_throw(fn() => assertAllowedFileType('deploy/Makefile'), 'acepta Makefile en subcarpeta');
t_throws(fn() => assertAllowedFileType('virus.exe'), 'rechaza .exe', InvalidArgumentException::class);
t_throws(fn() => assertAllowedFileType('imagen.png'), 'rechaza binarios', InvalidArgumentException::class);
t_throws(fn() => assertAllowedFileType('cualquiercosa'), 'rechaza sin extensión no listado', InvalidArgumentException::class);
t_no_throw(fn() => assertAllowedFileType('x.raro', ['raro']), 'acepta lista de extensiones personalizada');
t_throws(fn() => assertAllowedFileType('x.php', ['raro']), 'la lista personalizada excluye lo no listado', InvalidArgumentException::class);

// =====================================================================
t_section('s3CopySource — codificación de CopySource');
// =====================================================================

t_eq('mibucket/a/b/c.php', s3CopySource('mibucket', 'a/b/c.php'), 'preserva las barras como separadores');
t_eq('mibucket/a/mi%20archivo.php', s3CopySource('mibucket', 'a/mi archivo.php'), 'codifica los espacios sin tocar las barras');
t_eq('mibucket/a/b.php', s3CopySource('mibucket', '/a/b.php'), 'ignora la barra inicial');
t_ok(!str_contains(s3CopySource('b', 'x/y/z.php'), '%2F'), 'nunca produce %2F (el bug del urlencode global)');

// =====================================================================
t_section('estimateTokens / countLines / maxOutputTokensFor');
// =====================================================================

t_ok(estimateTokens('') === 0, 'cadena vacía son 0 tokens');
t_ok(estimateTokens(str_repeat('a', 320)) === 100, '320 caracteres ≈ 100 tokens');
t_ok(estimateTokens(str_repeat('a', 3200)) > estimateTokens(str_repeat('a', 320)), 'más texto, más tokens');
t_eq(0, countLines(''), 'cadena vacía son 0 líneas');
t_eq(1, countLines('una linea'), 'una línea sin salto');
t_eq(3, countLines("a\nb\nc"), 'tres líneas');
t_ok(maxOutputTokensFor('amazon.nova-pro-v1:0') === 5000, 'techo de Nova');
t_ok(maxOutputTokensFor('anthropic.claude-3-5-haiku-20241022-v1:0') === 8192, 'techo de Haiku');
t_ok(maxOutputTokensFor('modelo.desconocido') === 4096, 'techo por defecto para modelo desconocido');
