<?php
/**
 * Tests de chat/includes/EditEngine.php con un cliente de Bedrock simulado.
 *
 * Aquí NO se llama a AWS: se inyecta un stub que devuelve respuestas fijadas,
 * para poder ejercitar la interpretación de la respuesta del modelo (parseo del
 * JSON, corte por max_tokens, marcadores de elisión, presupuesto de tokens).
 */

declare(strict_types=1);

require_once __DIR__ . '/../chat/includes/EditEngine.php';

/** Stub que imita la interfaz converse() del BedrockRuntimeClient. */
final class FakeBedrock {
    /** @var array<int,array> Respuestas a devolver, en orden. */
    private array $respuestas;
    /** @var array<int,array> Parámetros recibidos, para inspeccionarlos. */
    public array $llamadas = [];

    public function __construct(array $respuestas) { $this->respuestas = $respuestas; }

    public function converse(array $params): array {
        $this->llamadas[] = $params;
        $r = array_shift($this->respuestas) ?? ['text' => '', 'stop' => 'end_turn'];
        return [
            'output' => ['message' => ['content' => [['text' => $r['text']]]]],
            'stopReason' => $r['stop'] ?? 'end_turn',
            'usage' => ['inputTokens' => $r['in'] ?? 10, 'outputTokens' => $r['out'] ?? 20],
        ];
    }
}

$archivo = "<?php\nclass A {\n    public function uno() { return 1; }\n}\n";

// =====================================================================
t_section('requestAnchoredEdit — interpretación de la respuesta');
// =====================================================================

$bedrock = new FakeBedrock([
    ['text' => '{"old_string":"return 1;","new_string":"return 2;"}'],
]);
$r = requestAnchoredEdit($bedrock, 'modelo.x', 'A.php', $archivo, 'cambia a 2', '', null);
t_ok($r['ok'], 'acepta un JSON válido');
t_eq('return 1;', $r['old_string'], 'extrae old_string');
t_eq('return 2;', $r['new_string'], 'extrae new_string');
t_ok($r['input_tokens'] === 10 && $r['output_tokens'] === 20, 'propaga el consumo de tokens');
t_ok($r['duration_ms'] >= 0, 'mide la duración real de la llamada');

$bedrock = new FakeBedrock([
    ['text' => "Claro, aquí tienes:\n```json\n{\"old_string\":\"a\",\"new_string\":\"b\"}\n```\n¡Espero que sirva!"],
]);
$r = requestAnchoredEdit($bedrock, 'modelo.x', 'A.php', $archivo, 'x', '', null);
t_ok($r['ok'], 'tolera JSON envuelto en fence y prosa');

$bedrock = new FakeBedrock([['text' => 'no pienso responder en JSON']]);
$r = requestAnchoredEdit($bedrock, 'modelo.x', 'A.php', $archivo, 'x', '', null);
t_ok(!$r['ok'], 'rechaza una respuesta que no es JSON');
t_ok(str_contains($r['error'], 'JSON'), 'el error menciona el JSON para poder devolvérselo al modelo');

$bedrock = new FakeBedrock([['text' => '{"old_string":"a"}']]);
$r = requestAnchoredEdit($bedrock, 'modelo.x', 'A.php', $archivo, 'x', '', null);
t_ok(!$r['ok'], 'rechaza un JSON al que le falta new_string');

$bedrock = new FakeBedrock([['text' => '{"old_string":123,"new_string":"b"}']]);
$r = requestAnchoredEdit($bedrock, 'modelo.x', 'A.php', $archivo, 'x', '', null);
t_ok(!$r['ok'], 'rechaza old_string que no es una cadena');

// El caso que antes disparaba una escalada carísima hasta Opus.
$bedrock = new FakeBedrock([['text' => '{"old_string":"a","new_str', 'stop' => 'max_tokens']]);
$r = requestAnchoredEdit($bedrock, 'modelo.x', 'A.php', $archivo, 'x', '', null);
t_ok(!$r['ok'], 'rechaza una respuesta cortada por max_tokens');
t_eq('max_tokens', $r['stop_reason'], 'reporta el stop_reason para no atribuirlo al modelo');
t_ok(str_contains($r['error'], 'tokens'), 'el error identifica el corte por tokens');

// =====================================================================
t_section('requestAnchoredEdit — construcción del prompt');
// =====================================================================

$bedrock = new FakeBedrock([['text' => '{"old_string":"a","new_string":"b"}']]);
requestAnchoredEdit($bedrock, 'modelo.x', 'A.php', $archivo, 'mi instruccion', '', 'FALLO ANTERIOR: el ancla aparecía 3 veces');
$prompt = $bedrock->llamadas[0]['messages'][0]['content'][0]['text'];
t_ok(str_contains($prompt, $archivo), 'el prompt incluye el contenido del archivo');
t_ok(str_contains($prompt, 'mi instruccion'), 'el prompt incluye la instrucción');
t_ok(str_contains($prompt, 'el ancla aparecía 3 veces'), 'el prompt reinyecta el error del intento anterior');
t_ok(str_contains($bedrock->llamadas[0]['system'][0]['text'], 'EXACTLY ONE'), 'el system prompt exige que el ancla sea única');

$bedrock = new FakeBedrock([['text' => '{"old_string":"a","new_string":"b"}']]);
requestAnchoredEdit($bedrock, 'amazon.nova-pro-v1:0', 'A.php', $archivo, 'x', '', null);
t_ok($bedrock->llamadas[0]['inferenceConfig']['maxTokens'] <= 5000, 'respeta el techo de tokens de Nova');

// =====================================================================
t_section('requestFullRewrite — guards obligatorios del nivel 3');
// =====================================================================

$bedrock = new FakeBedrock([['text' => "<?php\nclass A {\n    public function uno() { return 2; }\n}\n"]]);
$r = requestFullRewrite($bedrock, 'modelo.x', 'A.php', $archivo, 'cambia a 2', '');
t_ok($r['ok'], 'acepta una reescritura completa válida');
t_ok(str_contains($r['content'], 'return 2;'), 'devuelve el contenido reescrito');

$bedrock = new FakeBedrock([['text' => "<?php\nclass A {\n    // ... resto del código sin cambios\n}"]]);
$r = requestFullRewrite($bedrock, 'modelo.x', 'A.php', $archivo, 'cambia a 2', '');
t_ok(!$r['ok'], 'descarta una reescritura abreviada con marcador de elisión');
t_ok(str_contains($r['error'], 'abrevi'), 'el error explica que se abrevió el archivo');

// =====================================================================
t_section('requestFullRewrite — truncamiento: reintentar antes de escalar');
// =====================================================================
// Escalar de modelo por un corte de max_tokens no arregla nada: el siguiente se
// corta igual, porque el problema es el techo de salida y no la capacidad. Y
// además cuesta más. Se reintenta con el MISMO modelo y el doble de tokens.

$bedrock = new FakeBedrock([
    ['text' => "<?php\nclass A {\n  public function un", 'stop' => 'max_tokens'],
    ['text' => "<?php\nclass A {\n    public function uno() { return 2; }\n}\n"],
]);
$r = requestFullRewrite($bedrock, 'modelo.x', 'A.php', $archivo, 'x', '');
t_ok($r['ok'], 'tras un corte por max_tokens reintenta y acepta el segundo resultado');
t_eq(2, count($bedrock->llamadas), 'hizo exactamente dos llamadas');
t_eq('modelo.x', $bedrock->llamadas[1]['modelId'], 'el reintento usa el MISMO modelo, no escala');
$presupuesto1 = $bedrock->llamadas[0]['inferenceConfig']['maxTokens'];
$presupuesto2 = $bedrock->llamadas[1]['inferenceConfig']['maxTokens'];
t_ok($presupuesto2 > $presupuesto1, 'el reintento pide más tokens que el intento anterior');
t_eq(1, $r['truncation_retries'], 'informa de cuántos reintentos por truncamiento hubo');

// Si el modelo ya estaba en su techo, reintentar daría exactamente lo mismo:
// ahí sí toca rendirse y dejar que la escalera escale.
$bedrock = new FakeBedrock(array_fill(0, 12, ['text' => 'cortado', 'stop' => 'max_tokens']));
$r = requestFullRewrite($bedrock, 'modelo.x', 'A.php', $archivo, 'x', '');
t_ok(!$r['ok'], 'se rinde cuando el truncamiento persiste hasta el techo del modelo');
t_ok(str_contains($r['error'], 'techo'), 'el error explica que se agotó el techo del modelo');
t_ok(count($bedrock->llamadas) < 12, 'no reintenta indefinidamente (' . count($bedrock->llamadas) . ' llamadas)');

// =====================================================================
t_section('doubledTokenBudget — duplica hasta el techo, luego se rinde');
// =====================================================================

t_eq(2048, doubledTokenBudget(1024, 'anthropic.claude-3-5-haiku-x'), 'duplica dentro del margen');
t_eq(8192, doubledTokenBudget(5000, 'anthropic.claude-3-5-haiku-x'), 'el doble se recorta al techo del modelo');
t_eq(0, doubledTokenBudget(8192, 'anthropic.claude-3-5-haiku-x'), 'devuelve 0 cuando ya está en el techo');
t_eq(0, doubledTokenBudget(99999, 'amazon.nova-micro-v1:0'), 'devuelve 0 si se pidió por encima del techo');

$bedrock = new FakeBedrock([['text' => '   ']]);
$r = requestFullRewrite($bedrock, 'modelo.x', 'A.php', $archivo, 'x', '');
t_ok(!$r['ok'], 'descarta una reescritura vacía');

$conBackticks = "```php\n<?php\n\$sql = 'SELECT `id` FROM `usuarios`';\n\$js = `plantilla \${x}`;\n```";
$bedrock = new FakeBedrock([['text' => $conBackticks]]);
$r = requestFullRewrite($bedrock, 'modelo.x', 'A.php', $archivo, 'x', '');
t_ok($r['ok'], 'acepta código con backticks internos');
t_ok(str_contains($r['content'], '`id`'), 'no destruye los identificadores MySQL al quitar el fence');
t_ok(str_contains($r['content'], '`plantilla ${x}`'), 'no destruye los template literals al quitar el fence');
t_ok(!str_starts_with($r['content'], '```'), 'sí retira el fence exterior');

// =====================================================================
t_section('requestFullRewrite — presupuesto de tokens según tamaño');
// =====================================================================

$grande = str_repeat("// una linea de codigo de relleno\n", 200); // ~6800 chars
$bedrock = new FakeBedrock([['text' => $grande]]);
requestFullRewrite($bedrock, 'anthropic.claude-sonnet-4-5-20250929-v1:0', 'A.php', $grande, 'x', '');
$budgetGrande = $bedrock->llamadas[0]['inferenceConfig']['maxTokens'];

$bedrock = new FakeBedrock([['text' => 'x']]);
requestFullRewrite($bedrock, 'anthropic.claude-sonnet-4-5-20250929-v1:0', 'A.php', "<?php\n", 'x', '');
$budgetPequeno = $bedrock->llamadas[0]['inferenceConfig']['maxTokens'];

t_ok($budgetGrande > $budgetPequeno, 'un archivo grande recibe más presupuesto de salida que uno pequeño');
t_ok($budgetGrande >= estimateTokens($grande), 'el presupuesto alcanza para reescribir el archivo entero');
t_ok($budgetPequeno >= 1024, 'hay un presupuesto mínimo para archivos diminutos');

$bedrock = new FakeBedrock([['text' => $grande]]);
requestFullRewrite($bedrock, 'amazon.nova-pro-v1:0', 'A.php', $grande, 'x', '');
t_ok($bedrock->llamadas[0]['inferenceConfig']['maxTokens'] <= 5000, 'el presupuesto nunca supera el techo del modelo');

// =====================================================================
t_section('Cascada completa — simulación del recorrido de niveles');
// =====================================================================

// Nivel 2: el ancla ambigua se resuelve devolviéndole el conteo real al modelo.
$repetido = "foo();\nbar();\nfoo();\n";
$intento1 = applyUniqueEdit($repetido, 'foo();', 'baz();');
t_ok(!$intento1['ok'], 'intento 1: el ancla corta es ambigua');
$feedback = $intento1['error'];
t_ok(str_contains($feedback, '2 veces'), 'el feedback lleva el conteo real de coincidencias');
$intento2 = applyUniqueEdit($repetido, "bar();\nfoo();", "bar();\nbaz();");
t_ok($intento2['ok'], 'intento 2: con el ancla ampliada ya es única');

// Nivel 4: el archivo grande falla en vez de reescribirse a ciegas.
$archivoGrande = str_repeat("linea();\n", 500);
t_ok(countLines($archivoGrande) >= 300, 'un archivo de 500 líneas supera el límite de reescritura completa');
$archivoChico = str_repeat("linea();\n", 50);
t_ok(countLines($archivoChico) < 300, 'un archivo de 50 líneas sí admite reescritura completa');
