<?php

declare(strict_types=1);

final class MemoryExtractor
{
    private $bedrock;
    /** @var array<string,int> */
    private array $usage = ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0];
    private string $lastModelId = '';

    public function __construct($bedrock)
    {
        $this->bedrock = $bedrock;
    }

    /** @param array<string,mixed> $route @return MemoryWriteCandidate[] */
    public function extractProject(string $question, string $answer, array $route): array
    {
        if (!aiAgentActive('project_knowledge_extractor_prompt', true)) return [];

        $model = $this->selectModel($route);
        if ($model === '') return [];
        $this->lastModelId = $model;

        $blocks = "PREGUNTA DEL USUARIO:\n{$question}\n\nRESPUESTA DEL ASISTENTE:\n{$answer}";
        $rows = $this->invokeJsonArray(
            'project_knowledge_extractor_prompt',
            $model,
            ['blocks' => $blocks],
            6
        );

        $allowed = ['rule','decision','fact','style','todo'];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $type = strtolower(trim((string)($row['type'] ?? '')));
            if (!in_array($type, $allowed, true)) continue;
            $title = trim((string)($row['title'] ?? ''));
            $content = trim((string)($row['content'] ?? ''));
            if ($content === '') continue;
            if ($title === '') $title = $this->titleFromContent($content);
            $out[] = new MemoryWriteCandidate(
                'project_context',
                $type,
                mb_substr($title, 0, 255),
                mb_substr($content, 0, 2000),
                0.85,
                ['extractor' => 'project_knowledge_extractor_prompt']
            );
        }
        return $out;
    }

    /** @param array<string,mixed> $route @return MemoryWriteCandidate[] */
    public function extractProcedural(string $question, string $answer, array $route): array
    {
        if (!aiAgentActive('procedural_memory_extractor_prompt', true)) return [];

        $model = $this->selectModel($route);
        if ($model === '') return [];
        $this->lastModelId = $model;

        $conversation = "USUARIO:\n{$question}\n\nASISTENTE:\n{$answer}";
        $rows = $this->invokeJsonArray(
            'procedural_memory_extractor_prompt',
            $model,
            ['conversation' => $conversation],
            3
        );

        $allowed = ['rule','preference','correction','workflow','pattern'];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $type = strtolower(trim((string)($row['type'] ?? '')));
            $content = trim((string)($row['content'] ?? ''));
            if (!in_array($type, $allowed, true) || $content === '') continue;
            $out[] = new MemoryWriteCandidate(
                'procedural',
                $type,
                '',
                mb_substr($content, 0, 2000),
                0.90,
                ['extractor' => 'procedural_memory_extractor_prompt']
            );
        }
        return $out;
    }

    /** @return array<string,int> */
    public function usage(): array { return $this->usage; }
    public function modelId(): string { return $this->lastModelId; }

    /** @param array<string,string> $vars @return array<int,mixed> */
    private function invokeJsonArray(string $agentKey, string $modelId, array $vars, int $maxItems): array
    {
        $system = aiAgentInstruction($agentKey, 'Extrae únicamente memoria reutilizable y devuelve un array JSON válido.');
        if ($agentKey === 'project_knowledge_extractor_prompt') {
            $system .= "\nREGLA DE CONSOLIDACIÓN FASE 4: una sugerencia del asistente NO es automáticamente una decisión, regla o hecho del proyecto. Extrae decisiones/reglas sólo cuando el usuario las estableció explícitamente o cuando la respuesta confirma una operación real sobre el proyecto. Las preferencias explícitas del usuario dentro de un proyecto pueden guardarse como type=style. Si el turno sólo pregunta qué se había decidido o qué preferencias existen y no aporta una memoria nueva, no crees una memoria nueva. No inventes hechos ausentes.";
        }
        $userTemplate = aiAgentUserTemplate($agentKey, '{{blocks}}{{conversation}}');
        $userPrompt = aiRenderTemplate($userTemplate, $vars);

        $infer = [
            'maxTokens' => max(100, (int)aiAgentExtra($agentKey, 'max_tokens', $agentKey === 'procedural_memory_extractor_prompt' ? 500 : 1200)),
            'temperature' => (float)aiAgentExtra($agentKey, 'temperature', 0.1),
            'topP' => (float)aiAgentExtra($agentKey, 'top_p', 0.9),
        ];

        $res = $this->bedrock->converse([
            'modelId' => $modelId,
            'messages' => [['role' => 'user', 'content' => [['text' => $userPrompt]]]],
            'system' => [['text' => $system]],
            'inferenceConfig' => $infer,
        ]);

        $u = $res['usage'] ?? [];
        $this->usage['input_tokens'] += (int)($u['inputTokens'] ?? 0);
        $this->usage['output_tokens'] += (int)($u['outputTokens'] ?? 0);
        $this->usage['total_tokens'] += (int)($u['totalTokens'] ?? ((int)($u['inputTokens'] ?? 0) + (int)($u['outputTokens'] ?? 0)));

        $text = '';
        foreach (($res['output']['message']['content'] ?? []) as $block) {
            if (isset($block['text'])) $text .= (string)$block['text'];
        }

        $rows = $this->parseJsonArray($text);
        return array_slice($rows, 0, max(1, $maxItems));
    }

    /** @return array<int,mixed> */
    private function parseJsonArray(string $raw): array
    {
        $text = trim($raw);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/i', '', $text) ?? $text;
        $first = strpos($text, '[');
        $last = strrpos($text, ']');
        if ($first === false || $last === false || $last < $first) return [];
        $json = substr($text, $first, $last - $first + 1);
        $decoded = json_decode($json, true);
        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $route */
    private function selectModel(array $route): string
    {
        $code = (($route['intent'] ?? '') === 'code') || !empty($route['use_project_tools']);
        $primary = $code ? 'smart_memory_code' : 'smart_memory_general';
        $fallback = $code ? 'smart_memory_general' : 'smart_memory_code';

        if (aiAgentActive($primary, false) && aiAgentModel($primary, '') !== '') return aiAgentModel($primary, '');
        if (aiAgentActive($fallback, false) && aiAgentModel($fallback, '') !== '') return aiAgentModel($fallback, '');
        return '';
    }

    private function titleFromContent(string $content): string
    {
        $plain = preg_replace('/\s+/u', ' ', trim($content)) ?? trim($content);
        return mb_substr($plain, 0, 80);
    }
}
