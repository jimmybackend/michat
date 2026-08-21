<?php
declare(strict_types=1);

final class ChatTaskBridge
{
    public function __construct(private TaskOrchestrator $orchestrator) {}
    public static function idempotencyKey(int $userId,int $sessionId,string $requestId):string{return 'chat:'.hash('sha256',$userId."\0".$sessionId."\0".$requestId);}
    public static function title(string $question,int $max=120):string{$n=trim((string)preg_replace('/\s+/u',' ',$question));return mb_strlen($n)<=$max?$n:rtrim(mb_substr($n,0,max(1,$max-1))).'…';}
    public static function summary(string $answer,int $max=240):string{return self::title($answer,$max);}
    public static function sanitizeError(Throwable $e):string{$m=preg_replace('/\s+/u',' ',trim($e->getMessage()))?:'Error del pipeline de chat';$m=preg_replace('/(password|secret|token|authorization|aws_access_key_id)\s*[:=]\s*\S+/i','$1=[redacted]',$m);return mb_substr($m,0,500);}

    public function prepareTurn(int $userId,int $sessionId,?int $projectId,int $messageId,string $requestId,string $question,bool $autoExecute,array $input=[]):ChatTaskContext
    {
        if($requestId===''||$messageId<=0)throw new InvalidArgumentException('chat_task_context_invalid');
        return $this->orchestrator->prepareChatTurn($userId,$sessionId,$projectId,$messageId,self::idempotencyKey($userId,$sessionId,$requestId),self::title($question),$question,$autoExecute,$input);
    }
    public function beginExecution(ChatTaskContext $prepared,int $userId,string $traceId,string $modelId):ChatTaskContext{return $this->orchestrator->beginChatExecution($prepared,$userId,$traceId,$modelId);}
    public function resumeApproved(string $publicId,int $userId,string $traceId,string $modelId):ChatTaskContext{return $this->orchestrator->beginApprovedChatExecution($publicId,$userId,$traceId,$modelId);}
    /** @return array{outcome:'resume_started'|'resume_recovered'|'already_resumed',context:ChatTaskContext,lease_token:?string} */
    public function resumeApprovedToolTask(string$publicId,int$userId,string$traceId,string$modelId):array{return$this->orchestrator->resumeApprovedToolTask($publicId,$userId,$traceId,$modelId);}
    public function completeTurn(ChatTaskContext$c,int$userId,int$resultMessageId,string$answer,string$modelId):void{$this->orchestrator->completeChatTurn($c,$userId,$resultMessageId,self::summary($answer),$modelId);}
    public function failTurn(ChatTaskContext$c,int$userId,Throwable$error):void{$this->orchestrator->failChatTurn($c,$userId,self::sanitizeError($error));}
}
