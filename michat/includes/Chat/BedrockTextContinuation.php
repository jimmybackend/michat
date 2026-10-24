<?php
declare(strict_types=1);
require_once __DIR__.'/BedrockConverseResult.php';
require_once __DIR__.'/BedrockConverseClient.php';

/** Bounded continuation of text only. Never executes tools or hides an unfinished answer. */
final class BedrockTextContinuation
{
    public const MAX_CONTINUATIONS=3;
    public static function complete(array $params,BedrockConverseResult $first,callable $next,?callable $onProgress=null):BedrockConverseResult
    {
        $result=$first;$text=$first->text;$usage=$first->usage;
        // Continuations contain the original evidence and prior output, with tools disabled.
        unset($params['toolConfig']);
        for($i=0;$i<self::MAX_CONTINUATIONS&&$result->stopReason==='max_tokens'&&!$result->toolUses&&trim($result->text)!=='';$i++){
            if(strlen($text)>160000)break;
            $params['messages'][]=['role'=>'assistant','content'=>[['text'=>$result->text]]];
            $params['messages'][]=['role'=>'user','content'=>[['text'=>'La respuesta se interrumpió por el límite de salida. Continúa exactamente desde el punto donde terminó, sin repetir lo anterior. Completa las secciones pendientes y las citas usando solamente la evidencia original. No solicites ni ejecutes herramientas.']]];
            $onProgress&&$onProgress($i+1);
            $part=$next($params);
            foreach(['prompt_tokens','completion_tokens','total_tokens'] as $key)$usage[$key]=($usage[$key]??0)+($part->usage[$key]??0);
            // A provider that returns a tool request despite disabled tools cannot be continued safely.
            if($part->toolUses||!in_array($part->stopReason,['max_tokens','end_turn','stop_sequence'],true)||trim($part->text)===''){
                $result=new BedrockConverseResult('' ,[],'max_tokens',$usage,[]);break;
            }
            $text.=$part->text;$result=$part;
        }
        if($result===$first)return $first;
        return new BedrockConverseResult($text,[],$result->stopReason,$usage,['role'=>'assistant','content'=>[['text'=>$text]]]);
    }
}
