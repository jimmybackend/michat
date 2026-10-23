<?php
declare(strict_types=1);

/** External effects are explicit so queue/transaction behaviour is testable without AWS. */
interface DocumentKnowledgeRuntime
{
    public function describe(array $file): array;
    public function extract(array $file): array;
    public function embeddingModel(int $userId): string;
    public function embed(int $userId, string $content, string $expectedModel): array;
}
