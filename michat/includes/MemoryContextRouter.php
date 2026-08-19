<?php

declare(strict_types=1);

/**
 * Decide QUÉ contexto consultar. Fase 4.1 añade una frontera explícita:
 * proyecto / chat libre / rama de chat libre.
 */
final class MemoryContextRouter
{
    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function route(string $query, array $context = []): array
    {
        $raw = trim($query);
        $q = $this->normalize($raw);
        $hasProject = !empty($context['has_project']) || (int)($context['project_id'] ?? 0) > 0;
        $scopeKind = (string)($context['scope_kind'] ?? ($hasProject ? 'project' : 'free'));
        $hasLineage = !$hasProject && (!empty($context['has_lineage']) || $scopeKind === 'branch');

        $scores = [
            'conversation' => 0,
            'preference' => 0,
            'decision' => 0,
            'fact' => 0,
            'rule' => 0,
            'todo' => 0,
            'code' => 0,
            'general' => 1,
        ];
        $signals = [];

        $this->scorePatterns($q, 'conversation', 4, [
            '/\bque (?:te )?he preguntado\b/u', '/\bde que hemos hablado\b/u',
            '/\bque hemos (?:hablado|tratado|conversado)\b/u', '/\bque recuerdas\b/u',
            '/\brecuerdas (?:cuando|que|lo que|el|la)\b/u', '/\bdonde nos quedamos\b/u',
            '/\ben que quedamos\b/u', '/\bque dijimos\b/u',
            '/\bresum(?:e|eme) (?:esta |la )?(?:conversacion|sesion|charla|chat)\b/u',
            '/\b(?:conversacion|sesion|chat) anterior\b/u', '/\bhistorial (?:de |del )?(?:chat|conversacion|preguntas)\b/u',
        ], $scores, $signals);

        $this->scorePatterns($q, 'preference', 4, [
            '/\bprefer(?:encia|encias|ir|iria|imos|ido)\b/u', '/\bcomo (?:me gusta|quiero que respondas|quiero trabajar)\b/u',
            '/\bque te (?:he )?dicho que (?:uses|utilices|hagas)\b/u', '/\bmi forma de (?:trabajar|responder|programar)\b/u',
            '/\bsiempre quiero\b/u', '/\bnunca quiero\b/u',
        ], $scores, $signals);

        $this->scorePatterns($q, 'decision', 4, [
            '/\bdecid(?:imos|i|iste|io|ieron|ido|ir|ision|isiones)\b/u', '/\bacord(?:amos|e|ado|ar)\b/u',
            '/\bquedamos en\b/u', '/\beleg(?:imos|i|ido|ir)\b/u', '/\bdefin(?:imos|i|ido|ir)\b/u',
            '/\bdetermin(?:amos|e|ado|ar)\b/u', '/\barquitectura acordada\b/u',
        ], $scores, $signals);

        $this->scorePatterns($q, 'rule', 4, [
            '/\bregla(?:s)?\b/u', '/\bnorma(?:s)?\b/u', '/\binstruccion(?:es)?\b/u', '/\bobligatorio\b/u',
            '/\brestriccion(?:es)?\b/u', '/\bsiempre (?:usa|usar|debe|debemos|quiero)\b/u',
            '/\bnunca (?:uses|usar|hagas|hacer|debe)\b/u', '/\ba partir de ahora\b/u',
            '/\bantes de .{0,120}\bdebemos\b/u', '/\bmantengamos como regla\b/u',
        ], $scores, $signals);

        $this->scorePatterns($q, 'todo', 4, [
            '/\bpendiente(?:s)?\b/u', '/\btarea(?:s)?\b/u', '/\bpor hacer\b/u', '/\bque falta\b/u',
            '/\bque nos falta\b/u', '/\bque sigue\b/u', '/\bsiguiente paso\b/u', '/\bproximo paso\b/u',
        ], $scores, $signals);

        // Código: distinguir hablar SOBRE una política de código de una operación real.
        $hasConcreteFile = (bool)preg_match('/\b[a-z0-9_.-]+\.(?:php|phtml|inc|js|mjs|cjs|ts|tsx|json|sql|html|css|py|java|go|rs|md)\b/u', $q);
        $codeNoun = (bool)preg_match('/\b(?:archivo|codigo|funcion|clase|metodo|tabla|columna|campo|consulta sql|endpoint|stack trace)\b/u', $q);
        $codeAction = (bool)preg_match('/\b(?:crea|crear|genera|generar|revisa|analiza|inspecciona|explica|diagnostica|implementa|implementar|modifica|modificar|edita|editar|cambia|cambiar|refactoriza|refactorizar|borra|borrar|elimina|eliminar|lee|leer|muestra|ver)\b/u', $q);
        $policyLanguage = (bool)preg_match('/\b(?:a partir de ahora|mantengamos como regla|mi regla|debemos|deberemos|siempre|nunca|antes de)\b/u', $q);
        $codePolicyOnly = $policyLanguage && !$hasConcreteFile && ($scores['rule'] >= 4 || $scores['preference'] >= 4);
        $codeOperation = !$codePolicyOnly && $codeAction && ($hasConcreteFile || ($codeNoun && (bool)preg_match('/\b(?:este|ese|el|la|un|una|del proyecto|en el proyecto)\b/u', $q)));

        if ($hasConcreteFile) {
            $scores['code'] += 5;
            $signals[] = 'code_file';
        } elseif ($codeNoun) {
            $scores['code'] += 2;
            $signals[] = 'code_topic';
        }
        if ($codeOperation) {
            $scores['code'] += 3;
            $signals[] = 'code_operation';
        }
        if ($codePolicyOnly) $signals[] = 'code_policy_only';

        $this->scorePatterns($q, 'fact', 3, [
            '/\b(?:hecho|hechos|dato|datos)\b/u',
            '/\b(?:cual|que) (?:es|era|fue) (?:el|la) (?:valor|ruta|puerto|ip|version|modelo|endpoint|nombre)\b/u',
            '/\b(?:configuracion|configurado|configuramos)\b/u', '/\b(?:puerto|direccion ip|hostname|ruta|version|valor exacto|credencial)\b/u',
            '/\bque sabemos (?:de|sobre)\b/u',
        ], $scores, $signals);

        if ($this->looksLikeFollowUp($q, $raw)) {
            $scores['conversation'] += 3;
            $signals[] = 'follow_up';
        }

        if (str_contains($q, 'memoria procedural')) {
            $scores['preference'] += 4;
            $scores['rule'] += 2;
            $signals[] = 'memoria_procedural';
        } elseif (preg_match('/\bmemoria\b/u', $q)) {
            $scores['conversation'] += 2;
            $signals[] = 'memoria_conversacional';
        }

        arsort($scores);
        $primaryIntent = (string)array_key_first($scores);
        $highestScore = (int)reset($scores);
        if ($highestScore <= 1) $primaryIntent = 'general';

        $projectTypes = [];
        if ($scores['decision'] >= 4) $projectTypes[] = 'decision';
        if ($scores['fact'] >= 3) $projectTypes[] = 'fact';
        if ($scores['rule'] >= 4) $projectTypes[] = 'rule';
        if ($scores['preference'] >= 4) $projectTypes[] = 'style';
        if ($scores['todo'] >= 4) $projectTypes[] = 'todo';
        $projectTypes = array_values(array_unique($projectTypes));

        $structuredIntentWanted = $scores['decision'] >= 4 || $scores['fact'] >= 3 || $scores['rule'] >= 4 || $scores['todo'] >= 4;
        $conversationWanted = $scores['conversation'] >= 3;
        // En chat libre una consulta estructurada debe mirar la sesión actual;
        // en una rama siempre heredamos el hilo explícito.
        if (!$hasProject && $structuredIntentWanted) $conversationWanted = true;
        if ($hasLineage) $conversationWanted = true;

        $projectMemoryWanted = $hasProject && !empty($projectTypes);
        $projectRagWanted = $hasProject && $codeOperation;
        $proceduralAnswerWanted = $scores['preference'] >= 4 || $scores['rule'] >= 4;
        $semanticFallback = $hasProject && $structuredIntentWanted;
        $useQuestionMemory = $conversationWanted || (!$hasProject && $structuredIntentWanted);

        $mode = 'none';
        $activeFamilies = array_filter([$conversationWanted, $projectMemoryWanted, $projectRagWanted, $proceduralAnswerWanted]);
        if (count($activeFamilies) > 1) $mode = 'mixed';
        elseif ($conversationWanted) $mode = 'conversation';
        elseif ($projectMemoryWanted || $projectRagWanted || $proceduralAnswerWanted) $mode = 'targeted';

        $confidence = min(0.99, max(0.35, 0.35 + ($highestScore * 0.10)));
        if ($primaryIntent === 'general') $confidence = 0.80;

        $executionLane = 'chat';
        if ($hasProject && $codeOperation) $executionLane = 'project_tools';
        elseif ($conversationWanted) $executionLane = $hasLineage ? 'branch_memory' : 'conversation_memory';
        elseif ($projectMemoryWanted || $proceduralAnswerWanted) $executionLane = 'structured_memory';

        $decisionSummary = match ($executionLane) {
            'project_tools' => 'Operación concreta de código: consultar evidencia real y permitir Tool Use.',
            'branch_memory' => 'Rama explícita: heredar sólo el linaje de la conversación padre.',
            'conversation_memory' => 'La pregunta depende de la conversación: usar sólo memoria permitida por su scope.',
            'structured_memory' => 'La pregunta requiere memoria estructurada específica.',
            default => 'Pregunta general: no cargar memoria semántica innecesaria.',
        };

        return [
            'version' => 4.1,
            'context_contract' => 'scoped_typed_ranked_context_v4_1',
            'query' => $raw,
            'intent' => $primaryIntent,
            'mode' => $mode,
            'confidence' => round($confidence, 2),
            'signals' => array_values(array_unique($signals)),
            'scores' => $scores,
            'has_project' => $hasProject,
            'scope_kind' => $hasProject ? 'project' : ($hasLineage ? 'branch' : 'free'),
            'has_lineage' => $hasLineage,
            'execution_lane' => $executionLane,
            'decision_summary' => $decisionSummary,
            'code_operation' => $codeOperation,
            'code_policy_only' => $codePolicyOnly,
            'use_project_tools' => $hasProject && $codeOperation,
            'use_policy_procedural_memory' => true,
            'use_answer_procedural_memory' => $proceduralAnswerWanted,
            'use_project_context' => $projectMemoryWanted,
            'project_context_types' => $projectTypes,
            'use_session_context' => $conversationWanted,
            'use_question_memory' => $useQuestionMemory,
            'question_memory_fallback' => $semanticFallback,
            'use_project_rag' => $projectRagWanted,
            'use_attachment_context' => true,
        ];
    }

    /** @param array<string,int> $scores @param string[] $signals */
    private function scorePatterns(string $query, string $bucket, int $weight, array $patterns, array &$scores, array &$signals): void
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $query)) {
                $scores[$bucket] += $weight;
                $signals[] = $bucket;
                return;
            }
        }
    }

    private function looksLikeFollowUp(string $normalized, string $raw): bool
    {
        if ($normalized === '') return false;
        if (preg_match('/^(?:y |entonces |ahora |pero |tambien |ademas |eso |esto |esa |ese |esa misma |ese mismo |lo anterior|sobre eso|respecto a eso)/u', $normalized)) return true;
        if (mb_strlen($raw, 'UTF-8') <= 90 && preg_match('/^(?:como|por que|porque|donde|cuando|cual|y que|y como)\b/u', $normalized)) {
            return (bool)preg_match('/\b(?:eso|esto|esa|ese|ellos|ellas|ahi|anterior|mismo|misma)\b/u', $normalized);
        }
        return false;
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = strtr($text, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }
}
