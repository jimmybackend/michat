<?php
declare(strict_types=1);

final class ChatTaskBridge
{
    public function __construct(private TaskOrchestrator $orchestrator) {}

    public static function idempotencyKey(int $userId, int $sessionId, string $requestId): string
    {
        return 'chat:' . hash('sha256', $userId . "\0" . $sessionId . "\0" . $requestId);
    }

    public static function title(string $question, int $max = 120): string
    {
        $normalized = trim((string)preg_replace('/\s+/u', ' ', $question));
        if (mb_strlen($normalized) <= $max) return $normalized;
        return rtrim(mb_substr($normalized, 0, max(1, $max - 1))) . '…';
    }

    public static function summary(string $answer, int $max = 240): string
    {
        return self::title($answer, $max);
    }

    public static function sanitizeError(Throwable $error): string
    {
        $message = preg_replace('/\s+/u', ' ', trim($error->getMessage())) ?: 'Error del pipeline de chat';
        $message = preg_replace('/(password|secret|token|authorization|aws_access_key_id)\s*[:=]\s*\S+/i', '$1=[redacted]', $message);
        return mb_substr($message, 0, 500);
    }

    public function beginTurn(int$userId,int$sessionId,?int$projectId,int$originMessageId,string$requestId,string$question,string$traceId,string$modelId):ChatTaskContext
    {
        if ($requestId === '' || $originMessageId <= 0 || $traceId === '') throw new InvalidArgumentException('chat_task_context_invalid');
        return $this->orchestrator->beginChatTurn($userId,$sessionId,$projectId,$originMessageId,self::idempotencyKey($userId,$sessionId,$requestId),self::title($question),$question,$traceId,$modelId);
    }
    public function completeTurn(ChatTaskContext$c,int$userId,int$resultMessageId,string$answer,string$modelId):void{$this->orchestrator->completeChatTurn($c,$userId,$resultMessageId,self::summary($answer),$modelId);}
    public function failTurn(ChatTaskContext$c,int$userId,Throwable$error):void{$this->orchestrator->failChatTurn($c,$userId,self::sanitizeError($error));}
}
