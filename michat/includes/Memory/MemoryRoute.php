<?php

declare(strict_types=1);

/**
 * Value object de la decisión producida por MemoryContextRouter.
 * Mantiene compatibilidad con el array de Fase 1, pero expone una API estable
 * para que ContextBuilder no conozca detalles del Router.
 */
final class MemoryRoute
{
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function intent(): string
    {
        return (string)($this->data['intent'] ?? 'general');
    }

    public function mode(): string
    {
        return (string)($this->data['mode'] ?? 'none');
    }

    public function uses(string $flag): bool
    {
        return !empty($this->data[$flag]);
    }

    /** @return string[] */
    public function projectContextTypes(): array
    {
        $allowed = ['rule','decision','fact','style','todo','note'];
        $types = array_map('strval', (array)($this->data['project_context_types'] ?? []));
        return array_values(array_unique(array_intersect($allowed, $types)));
    }

    /**
     * Tipos procedurales específicamente relacionados con la intención actual.
     * La memoria procedural de política global sigue siendo independiente.
     *
     * @return string[]
     */
    public function answerProceduralTypes(): array
    {
        return match ($this->intent()) {
            'preference' => ['preference','pattern','workflow'],
            'rule'       => ['rule','correction'],
            default      => [],
        };
    }

    public function shouldUseQuestionMemory(bool $userEnabled, int $projectContextItems, string $stage): bool
    {
        if (!$userEnabled || $stage !== 'respond') return false;

        if ($this->uses('use_question_memory')) return true;

        return $this->uses('question_memory_fallback') && $projectContextItems === 0;
    }

    /**
     * Plan declarativo de fuentes. Fase 3 lo consume para recuperación y ranking
     * sin acoplar el Router a los repositorios.
     *
     * @return array<int,array<string,mixed>>
     */
    public function contextRequests(string $stage = 'respond'): array
    {
        $stage = $stage === 'compile' ? 'compile' : 'respond';
        $requests = [];

        if ($stage === 'respond' && $this->uses('use_policy_procedural_memory')) {
            $requests[] = [
                'source' => 'procedural',
                'purpose' => 'policy',
                'types' => ['preference','rule','pattern','correction','workflow'],
                'required' => true,
            ];
        }

        if ($this->uses('use_answer_procedural_memory')) {
            $requests[] = [
                'source' => 'procedural',
                'purpose' => 'answer',
                'types' => $this->answerProceduralTypes(),
                'required' => false,
            ];
        }

        if ($this->uses('use_project_context')) {
            $requests[] = [
                'source' => 'project_context',
                'purpose' => 'typed_memory',
                'types' => $this->projectContextTypes(),
                'required' => false,
            ];
        }

        if ($this->uses('use_session_context')) {
            $requests[] = [
                'source' => 'session',
                'purpose' => 'conversation',
                'types' => ['summary','level_0','level_1','level_2','level_3'],
                'required' => false,
            ];
        }

        if ($stage === 'respond' && $this->uses('use_project_rag')) {
            $requests[] = [
                'source' => 'project_rag',
                'purpose' => 'code_knowledge',
                'types' => ['source_chunk'],
                'required' => false,
            ];
        }

        if ($stage === 'respond' && $this->uses('use_attachment_context')) {
            $requests[] = [
                'source' => 'attachments',
                'purpose' => 'session_files',
                'types' => ['file','file_chunk'],
                'required' => false,
            ];
        }

        if ($stage === 'respond' && ($this->uses('use_question_memory') || $this->uses('question_memory_fallback'))) {
            $requests[] = [
                'source' => 'question_memory',
                'purpose' => $this->uses('use_question_memory') ? 'direct' : 'fallback',
                'types' => ['level_0_qa'],
                'required' => false,
            ];
        }

        return $requests;
    }
}
