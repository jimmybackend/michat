<?php
/**
 * EditEngine.php
 *
 * Interacción con el modelo para producir una edición. Recibe el cliente de
 * Bedrock inyectado, así que se puede ejercitar en tests con un stub sin
 * credenciales de AWS (ver tests/test_edit_engine_calls.php).
 *
 * No toca la base de datos ni S3: solo construye el prompt, llama al modelo e
 * interpreta la respuesta. Persistir el resultado es responsabilidad de
 * code_edit.php.
 */

require_once __DIR__ . '/FileToolkit.php';

// ========================================================================
// MOTOR DE EDICIÓN POR ANCLA (apply_edit)
// ========================================================================
// Sustituye al patrón SCOUT → EXTRACT → EDIT → REASSEMBLE, que se eliminó.
// Aquel localizaba el bloque a editar por número de línea, pero el modelo
// nunca veía los números de línea del archivo, el rango devuelto no se
// validaba, y reassembleFile() concatenaba TODO lo que el modelo hubiera
// escrito (incluida su prosa) porque solo filtraba las líneas que contenían
// los marcadores en vez de extraer el bloque entre ellos.
//
// El esquema de ToolCalls ya contemplaba 'str_replace' y no edición
// posicional, así que esto vuelve al diseño original del sistema.

/**
 * Pide al modelo una edición en forma de {old_string, new_string}.
 *
 * @param string|null $feedback Error del intento anterior, para que el modelo corrija.
 * @return array{ok:bool,old_string:string,new_string:string,error:string,stop_reason:string,input_tokens:int,output_tokens:int,duration_ms:int}
 */
function requestAnchoredEdit(
    $bedrock, string $modelId, string $filename, string $fileContent,
    string $instruction, string $relatedContext, ?string $feedback
): array {
    $system = "You are a surgical code editor. You return ONE edit as strict JSON, nothing else.

OUTPUT FORMAT (no markdown, no prose, no explanation):
{\"old_string\": \"<exact text to find>\", \"new_string\": \"<text to replace it with>\"}

RULES:
1. old_string MUST be copied VERBATIM from the file, including exact indentation and line breaks.
2. old_string MUST match EXACTLY ONE place in the file. Include enough surrounding lines to make it unique.
3. old_string MUST NOT be the entire file. Anchor on the smallest unique region that needs to change.
4. new_string is the full replacement for old_string, already modified.
5. To INSERT code, make old_string an existing unique line and new_string that same line plus the new code.
6. If the change needs a new import/use statement, anchor on the existing import block and include it there.
7. Preserve the file's existing style, indentation and naming.";

    $userPrompt = "FILE: {$filename}\n\n--- FILE CONTENT ---\n{$fileContent}\n--- END FILE CONTENT ---\n"
        . $relatedContext
        . "\n\nINSTRUCTION:\n{$instruction}";

    if ($feedback !== null && $feedback !== '') {
        $userPrompt .= "\n\n⚠️ YOUR PREVIOUS ATTEMPT FAILED:\n{$feedback}\n"
                     . "Return a corrected JSON edit. Remember old_string must match EXACTLY ONE place in the file.";
    }

    $t0 = hrtime(true);
    $res = $bedrock->converse([
        'modelId' => $modelId,
        'messages' => [['role' => 'user', 'content' => [['text' => $userPrompt]]]],
        'system' => [['text' => $system]],
        'inferenceConfig' => ['maxTokens' => min(4000, maxOutputTokensFor($modelId)), 'temperature' => 0.1, 'topP' => 0.9]
    ]);
    $durationMs = (int) round((hrtime(true) - $t0) / 1e6);

    $raw = '';
    foreach (($res['output']['message']['content'] ?? []) as $block) {
        if (isset($block['text'])) $raw .= $block['text'];
    }

    $out = [
        'ok' => false, 'old_string' => '', 'new_string' => '', 'error' => '',
        'stop_reason'   => (string) ($res['stopReason'] ?? ''),
        'input_tokens'  => (int) ($res['usage']['inputTokens'] ?? 0),
        'output_tokens' => (int) ($res['usage']['outputTokens'] ?? 0),
        'duration_ms'   => $durationMs,
    ];

    // Si el modelo cortó por presupuesto de tokens el JSON viene partido: no
    // tiene sentido intentar parsearlo ni escalar de modelo por ello.
    if ($out['stop_reason'] === 'max_tokens') {
        $out['error'] = 'La respuesta se cortó por límite de tokens antes de completar el JSON.';
        return $out;
    }

    $parsed = extractJsonObject($raw);
    if ($parsed === null) {
        $out['error'] = 'La respuesta no era un objeto JSON válido. Devuelve solo {"old_string": "...", "new_string": "..."}.';
        return $out;
    }
    if (!array_key_exists('old_string', $parsed) || !array_key_exists('new_string', $parsed)) {
        $out['error'] = 'Al JSON le faltan las claves old_string y/o new_string.';
        return $out;
    }
    if (!is_string($parsed['old_string']) || !is_string($parsed['new_string'])) {
        $out['error'] = 'old_string y new_string deben ser cadenas de texto.';
        return $out;
    }

    $out['ok'] = true;
    $out['old_string'] = $parsed['old_string'];
    $out['new_string'] = $parsed['new_string'];
    return $out;
}

/**
 * Nivel 3 de la cascada: reescritura del archivo completo.
 *
 * Solo se usa cuando la edición por ancla ya falló y el archivo es pequeño.
 * Reescribir a ciegas es peligroso, así que el resultado se descarta salvo que
 * pase TRES verificaciones: que el modelo no cortara por tokens, que no haya
 * abreviado con "... resto sin cambios", y que no haya encogido de forma
 * sospechosa.
 *
 * @return array{ok:bool,content:string,error:string,stop_reason:string,input_tokens:int,output_tokens:int,duration_ms:int}
 */
function requestFullRewrite(
    $bedrock, string $modelId, string $filename, string $fileContent,
    string $instruction, string $relatedContext
): array {
    $system = "You are an expert software engineer rewriting a complete source file.

RULES:
1. Return ONLY the raw file content. No markdown fences, no explanation.
2. Return the ENTIRE file, from its first line to its last.
3. NEVER abbreviate. Do not write '// ... rest of the file unchanged' or similar. Every line must be present.
4. Preserve every function, class, constant and variable that the instruction does not explicitly ask you to remove.
5. Preserve the existing style, indentation and naming.";

    $userPrompt = "FILE: {$filename}\n\n--- CURRENT CONTENT ---\n{$fileContent}\n--- END CURRENT CONTENT ---\n"
        . $relatedContext
        . "\n\nINSTRUCTION:\n{$instruction}\n\nReturn the complete rewritten file.";

    // El techo se calcula desde el tamaño real del archivo: si dejamos el valor
    // fijo de antes (2000), cualquier archivo mediano se truncaba y el fallo se
    // atribuía al modelo, disparando una escalada carísima.
    $budget = min((int) ceil(estimateTokens($fileContent) * 2.5), maxOutputTokensFor($modelId));
    $budget = max($budget, 1024);

    // Reintento por truncamiento: si la respuesta se corta por `max_tokens`,
    // escalar de modelo no arregla nada —el siguiente se cortará igual, porque
    // el problema es el techo, no la capacidad— y además cuesta más. Se
    // reintenta con el MISMO modelo y el doble de presupuesto, hasta agotar el
    // techo del modelo. Solo entonces se devuelve el fallo y la escalera escala.
    $intentos = 0;
    $reintentosPorTruncamiento = 0;

    while (true) {
        $intentos++;
        $t0 = hrtime(true);
        $res = $bedrock->converse([
            'modelId' => $modelId,
            'messages' => [['role' => 'user', 'content' => [['text' => $userPrompt]]]],
            'system' => [['text' => $system]],
            'inferenceConfig' => ['maxTokens' => $budget, 'temperature' => 0.2, 'topP' => 0.9]
        ]);
        $durationMs = (int) round((hrtime(true) - $t0) / 1e6);

        $raw = '';
        foreach (($res['output']['message']['content'] ?? []) as $block) {
            if (isset($block['text'])) $raw .= $block['text'];
        }

        $out = [
            'ok' => false, 'content' => '', 'error' => '',
            'stop_reason'   => (string) ($res['stopReason'] ?? ''),
            'input_tokens'  => (int) ($res['usage']['inputTokens'] ?? 0),
            'output_tokens' => (int) ($res['usage']['outputTokens'] ?? 0),
            'duration_ms'   => $durationMs,
            'token_budget'  => $budget,
            'truncation_retries' => $reintentosPorTruncamiento,
        ];

        if ($out['stop_reason'] !== 'max_tokens') {
            break;
        }

        $nuevoBudget = doubledTokenBudget($budget, $modelId);
        if ($nuevoBudget === 0) {
            // Ya estábamos en el techo del modelo: reintentar daría lo mismo.
            $out['error'] = 'La reescritura se cortó por límite de tokens y el modelo ya estaba en su techo ('
                          . $budget . '): el resultado se descarta entero.';
            return $out;
        }

        $budget = $nuevoBudget;
        $reintentosPorTruncamiento++;
    }

    $content = cleanMarkdown($raw);
    if (trim($content) === '') {
        $out['error'] = 'La reescritura devolvió contenido vacío.';
        return $out;
    }
    if (containsElisionMarker($content)) {
        $out['error'] = 'La reescritura abrevió el archivo con un marcador tipo "... resto sin cambios".';
        return $out;
    }

    $out['ok'] = true;
    $out['content'] = $content;
    return $out;
}

