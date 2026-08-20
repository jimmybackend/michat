<?php
declare(strict_types=1);

/** Single persistence boundary for physical Task tool executions. */
final class ToolCallRepository
{
    private const TOOLS=['grep','view','search','str_replace','code_edit'];
    private const STATUSES=['ok','error','timeout'];

    public function __construct(private mysqli $db) {}

    /** @param array<string,mixed> $context @param array<string,mixed> $arguments */
    public function record(array$context,string$tool,array$arguments,ToolExecutionResult$result,int$durationMs):int
    {
        return $this->persist($context,$tool,$arguments,$result->status,$result->summary,$result->data,$result->artifacts,$durationMs);
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $arguments */
    public function recordError(array$context,string$tool,array$arguments,Throwable$error,int$durationMs):int
    {
        $status=str_contains(strtolower($error->getMessage()),'timeout')?'timeout':'error';
        return $this->persist($context,$tool,$arguments,$status,$error->getMessage(),['error'=>$error->getMessage()],[],$durationMs);
    }

    private function persist(array$context,string$tool,array$arguments,string$status,string$summary,array$data,array$artifacts,int$durationMs):int
    {
        if(!in_array($tool,self::TOOLS,true))throw new TaskValidationException('tool_not_supported');
        $session=(int)($context['session_id']??0);$project=isset($context['project_id'])?(int)$context['project_id']:null;$message=isset($context['message_id'])?(int)$context['message_id']:null;
        if($session<1)throw new TaskValidationException('tool_call_session_invalid');
        if(!in_array($status,self::STATUSES,true))$status=$status==='ok'?'ok':'error';
        $params=$this->sanitize($arguments);$paramsJson=json_encode($params,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($paramsJson===false)$paramsJson='{}';
        $target=$this->targetPath($params,$data);$resultJson=json_encode(['summary'=>$summary,'data'=>$this->sanitize($data),'artifacts'=>$this->sanitize($artifacts)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($resultJson===false)$resultJson=json_encode(['summary'=>$summary]);
        $duration=max(0,$durationMs);
        $stmt=$this->db->prepare('INSERT INTO ToolCalls(session_id_,project_id_,message_id_,tool,params,target_path,result,status,duration_ms) VALUES(?,?,?,?,?,?,?,?,?)');
        if(!$stmt)throw new RuntimeException('database_error');$stmt->bind_param('iiisssssi',$session,$project,$message,$tool,$paramsJson,$target,$resultJson,$status,$duration);
        if(!$stmt->execute())throw new RuntimeException('database_error');$id=(int)$this->db->insert_id;$stmt->close();
        if($id<1)throw new RuntimeException('tool_call_id_invalid');return$id;
    }

    private function targetPath(array$params,array$data):?string
    {
        foreach(['file','filename','target_filename','path']as$key){$value=$data[$key]??$params[$key]??null;if(is_string($value)&&trim($value)!=='')return mb_substr(basename(str_replace('\\','/',$value)),0,1024);}
        $first=$data['results'][0]['filename']??null;if(is_string($first)&&trim($first)!=='')return mb_substr(basename(str_replace('\\','/',$first)),0,1024);
        return null;
    }

    /** @param mixed $value @return mixed */
    private function sanitize($value,string$key='')
    {
        if(preg_match('/(?:password|secret|credential|authorization|cookie|access_key|private_key)/i',$key))return'[redacted]';
        if(in_array($key,['user_id','session_id','project_id'],true))return'[server-owned]';
        if(is_array($value)){ $out=[];foreach($value as$k=>$v)$out[$k]=$this->sanitize($v,(string)$k);return$out; }
        if(is_string($value)&&mb_strlen($value)>12000)return mb_substr($value,0,12000).'[truncated]';
        return is_scalar($value)||$value===null?$value:(string)$value;
    }
}
