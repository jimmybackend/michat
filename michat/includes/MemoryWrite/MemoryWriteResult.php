<?php

declare(strict_types=1);

final class MemoryWriteResult
{
    public string $status = 'skipped';
    public ?int $eventId = null;
    public string $reason = '';
    public string $modelId = '';
    /** @var MemoryWriteCandidate[] */
    public array $candidates = [];
    /** @var array<int,array<string,mixed>> */
    public array $writes = [];
    /** @var array<string,int> */
    public array $usage = ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0];
    /** @var array<int,string> */
    public array $errors = [];

    /** @return array<string,mixed> */
    public function toArray(bool $withContent = true): array
    {
        $candidates = [];
        foreach ($this->candidates as $candidate) {
            $row = $candidate->toArray();
            if (!$withContent) {
                $row['content_preview'] = mb_substr($row['content'], 0, 220);
                unset($row['content']);
            }
            $candidates[] = $row;
        }

        return [
            'version' => 4,
            'status' => $this->status,
            'event_id' => $this->eventId,
            'reason' => $this->reason,
            'model_id' => $this->modelId,
            'candidate_count' => count($this->candidates),
            'write_count' => count(array_filter($this->writes, static fn(array $w): bool => in_array(($w['action'] ?? ''), ['inserted','updated','reinforced'], true))),
            'candidates' => $candidates,
            'writes' => $this->writes,
            'usage' => $this->usage,
            'errors' => $this->errors,
        ];
    }
}
