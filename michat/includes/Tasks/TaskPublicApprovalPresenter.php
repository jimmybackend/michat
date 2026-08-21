<?php
declare(strict_types=1);

/** Builds the only Tool-approval data that may cross the public Task API boundary. */
final class TaskPublicApprovalPresenter
{
    public function present(array $step): ?array
    {
        $checkpoint = json_decode((string)($step['checkpoint_json'] ?? ''), true);
        $approval = is_array($checkpoint) ? ($checkpoint['tool_approval'] ?? null) : null;
        $proposal = is_array($approval) ? ($approval['proposal'] ?? null) : null;
        if (!is_array($proposal)) return null;

        $fingerprint = (string)($proposal['fingerprint'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) return null;
        $decision = is_array($approval['decision'] ?? null) ? $approval['decision'] : null;
        return [
            'type' => 'tool',
            'status' => $decision === null ? 'pending' : (string)($decision['status'] ?? 'unknown'),
            'safe_summary' => $this->text($proposal['safe_summary'] ?? null, 500),
            'safe_target' => $this->text($proposal['safe_target'] ?? null, 500),
            'effect' => $this->text($proposal['effect'] ?? null, 64),
            'fingerprint' => $fingerprint,
        ];
    }

    private function text(mixed $value, int $limit): ?string
    {
        if (!is_string($value)) return null;
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
