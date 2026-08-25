<?php
declare(strict_types=1);
/** Normalized result of exactly one Bedrock Converse transport call. */
final class BedrockConverseResult{
 /** @param list<array<string,mixed>> $toolUses @param array<string,int> $usage @param array<string,mixed> $outputMessage */
 public function __construct(public readonly string$text,public readonly array$toolUses,public readonly string$stopReason,public readonly array$usage,public readonly array$outputMessage){}
}
