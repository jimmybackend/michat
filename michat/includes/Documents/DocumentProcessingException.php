<?php
declare(strict_types=1);

/** Safe public cause and precise stage; the original exception stays in server logs. */
final class DocumentProcessingException extends RuntimeException
{
    public function __construct(public readonly string $stage, public readonly string $reason, string $message, ?Throwable $previous=null)
    { parent::__construct($message,0,$previous); }
}
