<?php
declare(strict_types=1);

final class TaskReplanSnapshot
{
    public const MAX_BYTES = 24000;

    public function __construct(public readonly array $data)
    {
        $json = $this->json();
        if (strlen($json) > self::MAX_BYTES) throw new TaskValidationException('replan_snapshot_too_large');
    }

    public function json(): string
    {
        return json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
