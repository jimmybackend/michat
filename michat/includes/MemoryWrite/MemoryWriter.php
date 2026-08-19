<?php

declare(strict_types=1);

final class MemoryWriter
{
    private const VERSION = 'phase4.1-v1';
    private mysqli $db;
    private MemoryExtractor $extractor;
    private MemoryWriteRepository $repository;

    public function __construct(mysqli $db, $bedrock)
    {
        $this->db = $db;
        $this->extractor = new MemoryExtractor($bedrock);
        $this->repository = new MemoryWriteRepository($db);
    }

    /**
     * @param array<string,mixed> $route
     * @param array<int,string> $successfulTools
     */
    public function write(
        int $userId,
        int $sessionId,
        int $projectId,
        int $questionId,
        int $answerId,
        string $question,
        string $answer,
        array $route,
        array $successfulTools = []
    ): MemoryWriteResult {
        $result = new MemoryWriteResult();

        if (!$this->repository->schemaReady()) {
            $result->status = 'skipped';
            $result->reason = 'schema_missing_memory_write_events';
            return $result;
        }
        if ($questionId <= 0 || $answerId <= 0 || trim($question) === '' || trim($answer) === '') {
            $result->reason = 'missing_qa';
            return $result;
        }

        $gate = $this->gate($question, $answer, $route, $projectId, $successfulTools);
        $intent = (string)($route['intent'] ?? 'general');
        $event = $this->repository->beginEvent($userId, $sessionId, $projectId, $questionId, $answerId, $intent, self::VERSION);
        $result->eventId = $event['id'];
        if ($event['existing']) {
            $result->status = 'skipped';
            $result->reason = 'already_processed';
            return $result;
        }

        if (!$gate['project'] && !$gate['procedural']) {
            $result->status = 'skipped';
            $result->reason = (string)$gate['reason'];
            $this->repository->finishEvent($result->eventId, 'skipped', $result->reason, '', [], [], $result->usage);
            return $result;
        }

        try {
            $candidates = [];
            if ($gate['project']) {
                $candidates = array_merge($candidates, $this->extractor->extractProject($question, $answer, $route));
            }
            if ($gate['procedural']) {
                $candidates = array_merge($candidates, $this->extractor->extractProcedural($question, $answer, $route));
            }

            $result->candidates = $this->dedupeCandidates($candidates);
            $result->modelId = $this->extractor->modelId();
            $result->usage = $this->extractor->usage();
            $result->writes = $this->repository->persist($userId, $sessionId, $projectId, $result->candidates);
            $result->status = 'completed';
            $result->reason = $result->candidates ? 'memory_consolidated' : 'no_reusable_memory_detected';
            $this->repository->finishEvent(
                $result->eventId,
                'completed',
                $result->reason,
                $result->modelId,
                $result->candidates,
                $result->writes,
                $result->usage,
                $result->errors
            );
        } catch (Throwable $e) {
            $result->status = 'error';
            $result->reason = 'memory_writer_failed';
            $result->errors[] = $e->getMessage();
            try {
                $this->repository->finishEvent($result->eventId, 'error', $result->reason, $result->modelId, $result->candidates, $result->writes, $result->usage, $result->errors);
            } catch (Throwable $_) {}
        }

        return $result;
    }

    /** @param array<string,mixed> $route @param array<int,string> $successfulTools @return array{project:bool,procedural:bool,reason:string} */
    private function gate(string $question, string $answer, array $route, int $projectId, array $successfulTools): array
    {
        $q = $this->normalize($question);
        $a = $this->normalize($answer);
        $intent = (string)($route['intent'] ?? 'general');

        if (preg_match('/^(hola|gracias|ok|okay|vale|si|no|perfecto|entendido)[.! ]*$/u', $q)) {
            return ['project'=>false,'procedural'=>false,'reason'=>'trivial_turn'];
        }
        if (preg_match('/^\s*[0-9+\-*\/(). x]+\s*$/u', $q)) {
            return ['project'=>false,'procedural'=>false,'reason'=>'trivial_calculation'];
        }
        if (str_contains($a, 'no pude contactar bedrock') || str_contains($a, 'error generando')) {
            return ['project'=>false,'procedural'=>false,'reason'=>'assistant_failure'];
        }

        // El Writer sólo persiste cuando el USUARIO está estableciendo algo.
        // Consultas como "¿qué decidimos?" o "¿qué preferencias tengo?"
        // recuperan memoria, pero no deben crear otra memoria a partir de la respuesta.
        $procedural = (bool)preg_match(
            '/\b(prefiero|quiero que|a partir de ahora|siempre (?:usa|haz|quiero|debes)|nunca (?:uses|hagas|quiero)|no uses|no hagas|debes|debe ser|primero .{0,80} luego|eso esta mal|te dije|corrige|mi regla es|regla:|forma de trabajar)\b/u',
            $q
        );

        $projectExplicit = (bool)preg_match(
            '/\b(?:decidimos|acordamos|definimos|fijamos)\s+(?:usar|que|el|la|los|las|un|una)|\b(?:queda definido|usaremos|vamos a usar|usa|utiliza|implementa|aplica|debe quedar|configuramos|configura|cambia|establece|fija|regla:|pendiente:|todo:|arquitectura:)\b/u',
            $q
        );

        $toolGrounded = !empty($successfulTools);
        // Dentro de proyecto, una preferencia/regla explícita también merece una
        // representación ProjectContext (style/rule) para compartirla sólo allí.
        $project = $projectId > 0 && ($projectExplicit || $procedural || (!empty($route['use_project_tools']) && $toolGrounded));

        $reason = 'no_explicit_reusable_memory';
        if ($project && $procedural) $reason = 'project_and_procedural_candidate';
        elseif ($project) $reason = 'project_candidate';
        elseif ($projectId <= 0 && $procedural) $reason = 'session_scoped_procedural_candidate';
        elseif ($procedural) $reason = 'procedural_candidate';
        elseif (!empty($route['use_project_tools']) && !$toolGrounded) $reason = 'code_turn_without_tool_evidence';

        return ['project'=>$project,'procedural'=>$procedural,'reason'=>$reason];
    }

    /** @param MemoryWriteCandidate[] $items @return MemoryWriteCandidate[] */
    private function dedupeCandidates(array $items): array
    {
        $out = [];
        $seen = [];
        foreach ($items as $candidate) {
            $key = $candidate->target . '|' . $candidate->type . '|' . $this->normalize($candidate->title . ' ' . $candidate->content);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $candidate;
        }
        return $out;
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = strtr($text, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }
}
