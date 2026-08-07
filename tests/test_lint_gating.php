<?php
/**
 * test_lint_gating.php
 *
 * Fija la regla que cambia en la Fase 3:
 *
 *   SOLO el gate de sintaxis (Nivel 1) bloquea y reintenta.
 *   PHPStan y Semgrep son advisory: sus hallazgos van a warnings[] y no
 *   disparan escalada de modelo.
 *
 * Sin esta separación el sistema pagaba Opus por un aviso de estilo. Y con
 * `node --check` aplicado a TypeScript, pagaba Opus por un fallo que ningún
 * modelo podía arreglar, porque el verificador no entendía el lenguaje.
 */

declare(strict_types=1);

require_once __DIR__ . '/../chat/includes/FileToolkit.php';

// =====================================================================
t_section('syntaxCheckPlan — TypeScript sin tsc local no es un fallo');
// =====================================================================

// EL BUG: node --check sobre .ts/.tsx/.jsx falla SIEMPRE. Node no parsea
// TypeScript ni JSX. Como un fallo de sintaxis dispara el reintento, cada
// edición de un .ts recorría la escalera entera hasta Opus para fallar igual.
$sinHerramientas = ['tsc' => false, 'tsconfig' => false];

foreach (['componente.ts', 'componente.tsx', 'componente.jsx'] as $archivo) {
    $plan = syntaxCheckPlan($archivo, $sinHerramientas);
    t_eq('none', $plan['checker'], "{$archivo} sin tsc local: no se verifica, no se falla");
    t_ok(strpos($plan['reason'], 'tsc') !== false, "{$archivo}: la razón menciona tsc");
}

// Nunca se elige node para estos: es justo el bug que se corrige.
foreach (['a.ts', 'a.tsx', 'a.jsx'] as $archivo) {
    foreach ([['tsc' => false, 'tsconfig' => false], ['tsc' => true, 'tsconfig' => true]] as $tools) {
        t_ok(syntaxCheckPlan($archivo, $tools)['checker'] !== 'node',
             "{$archivo} nunca se verifica con node --check");
    }
}

// Con tsc local Y tsconfig.json sí se verifica.
$plan = syntaxCheckPlan('componente.ts', ['tsc' => true, 'tsconfig' => true]);
t_eq('tsc', $plan['checker'], '.ts con tsc local y tsconfig.json sí se verifica');

// Con tsc pero sin tsconfig no: tsc aplicaría sus valores por defecto y
// marcaría errores que el proyecto no tiene.
$plan = syntaxCheckPlan('componente.ts', ['tsc' => true, 'tsconfig' => false]);
t_eq('none', $plan['checker'], '.ts con tsc pero sin tsconfig.json: mejor no verificar');
t_ok(strpos($plan['reason'], 'tsconfig') !== false, 'la razón señala el tsconfig que falta');

// =====================================================================
t_section('syntaxCheckPlan — lenguajes que sí tienen verificador');
// =====================================================================

t_eq('php',    syntaxCheckPlan('a.php', [])['checker'],  'php -> php -l');
t_eq('node',   syntaxCheckPlan('a.js', [])['checker'],   'js -> node --check');
t_eq('node',   syntaxCheckPlan('a.mjs', [])['checker'],  'mjs -> node --check');
t_eq('python', syntaxCheckPlan('a.py', [])['checker'],   'py -> py_compile');
t_eq('sql-heuristic', syntaxCheckPlan('a.sql', [])['checker'], 'sql -> heurística');

// Un lenguaje desconocido no se inventa un fallo.
t_eq('none', syntaxCheckPlan('LEEME.md', [])['checker'], 'markdown no tiene verificador y no falla');
t_eq('none', syntaxCheckPlan('sin_extension', [])['checker'], 'sin extensión tampoco');

// =====================================================================
t_section('El plan nunca depende de npx');
// =====================================================================
// npx descarga el paquete de la red en cada invocación. En un servidor sin
// salida a internet eso no falla rápido: se queda colgado hasta el timeout, y
// el timeout lo paga el usuario esperando.
$fuente = (string) file_get_contents(__DIR__ . '/../chat/code_edit.php');
$fuenteSinComentarios = preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $fuente);
t_ok(strpos($fuenteSinComentarios, 'npx') === false, 'code_edit.php no invoca npx en ninguna parte');

// =====================================================================
t_section('PHPStan y Semgrep son advisory, no bloquean');
// =====================================================================

// lintCode() es el gate. Su contrato es devolver solo estos tipos: si volviera
// a devolver 'advanced_lint', un hallazgo de PHPStan tiraría el resultado y
// dispararía la escalada, que es exactamente lo que se quitó.
$gate = substr($fuente, strpos($fuente, 'function lintCode('));
$gate = substr($gate, 0, strpos($gate, 'function advisoryAnalysis('));
t_ok(strpos($gate, 'phpstan') === false, 'el gate de sintaxis no ejecuta PHPStan');
t_ok(strpos($gate, 'semgrep') === false, 'el gate de sintaxis no ejecuta Semgrep');
t_ok(strpos($gate, 'advanced_lint') === false, 'el gate ya no devuelve el tipo advanced_lint');

// Y el advisory vive fuera del bucle de la escalera: se ejecuta una sola vez,
// sobre el resultado ganador, después de que la escalera haya terminado.
$posGanador  = strpos($fuente, 'advisoryAnalysis($newContent');
$posEscalera = strpos($fuente, '// ===== 13. Evaluar resultado final =====');
t_ok($posGanador !== false, 'el análisis advisory se ejecuta sobre el contenido ganador');
t_ok($posGanador > $posEscalera, 'y se ejecuta DESPUÉS de la escalera, no dentro de ella');

// Sus hallazgos salen como warnings, no como error.
$advisory = substr($fuente, strpos($fuente, 'function advisoryAnalysis('));
$advisory = substr($advisory, 0, 4000);
t_ok(strpos($advisory, "'code'    => 'phpstan'") !== false, 'PHPStan produce un warning con code=phpstan');
t_ok(strpos($advisory, "'code'    => 'semgrep'") !== false, 'Semgrep produce un warning con code=semgrep');
t_ok(strpos($advisory, '--level=0') !== false, 'PHPStan corre en nivel 0, no en el 5 que marcaba cada clase como desconocida');

// Sin comentarios: el bloque explica POR QUÉ se quitó --config auto, y
// mencionarlo en una explicación no es usarlo.
$advisorySinComentarios = preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $advisory);
t_ok(strpos($advisorySinComentarios, '--config auto') === false,
     'Semgrep no usa --config auto (descargaba reglas por red en cada llamada)');
t_ok(strpos($advisorySinComentarios, '--config ') !== false,
     'Semgrep sí recibe un --config con el ruleset local');

// =====================================================================
t_section('Los temporales del lint se borran siempre');
// =====================================================================
// tempnam() CREA un archivo; al concatenarle la extensión se escribía en otro
// distinto y el primero no lo borraba nadie. Cada intento dejaba basura en
// /tmp, y hay hasta cinco intentos por edición.
$conTemp = substr($fuente, strpos($fuente, 'function withTempSource('));
$conTemp = substr($conTemp, 0, strpos($conTemp, 'function localToolAvailability('));
t_ok(strpos($conTemp, 'finally') !== false, 'la limpieza del temporal está en un finally');
t_ok(substr_count($conTemp, 'unlink') >= 2, 'se borran los DOS archivos: el de tempnam y el que lleva la extensión');
