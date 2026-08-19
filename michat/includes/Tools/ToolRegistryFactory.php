<?php
declare(strict_types=1);

/** Single production allow-list for HTTP, model tool-use, and explicit tool steps. */
final class ToolRegistryFactory
{
    public function __construct(private mysqli $db) {}
    public function create(): ToolRegistry
    {
        $r=new ToolRegistry();
        $r->register('grep',fn(array$i)=>$this->read($i,'grep'),'read_only');
        $r->register('view',fn(array$i)=>$this->read($i,'view'),'read_only');
        $r->register('search',fn(array$i)=>$this->read($i,'search'),'read_only');
        $r->register('str_replace',fn(array$i)=>$this->write($i,'str_replace'),'non_idempotent');
        $r->register('code_edit',fn(array$i)=>$this->write($i,'code_edit'),'non_idempotent');
        return $r;
    }
    private function scope(array $input): array
    {
        $c=(array)($input['context']??[]);$a=(array)($input['arguments']??$input);
        $project=(int)($c['project_id']??0);$user=(int)($c['user_id']??0);
        if($project<1||$user<1)throw new TaskValidationException('tool_scope_invalid');
        $s=$this->db->prepare("SELECT 1 FROM Projects WHERE id_=? AND user_id_=? AND status<>'deleted'");$s->bind_param('ii',$project,$user);$s->execute();$ok=(bool)$s->get_result()->fetch_row();$s->close();
        if(!$ok)throw new TaskValidationException('tool_scope_forbidden');return[$project,$a,$c];
    }
    private function read(array $input,string $tool):ToolExecutionResult
    {
        [$project,$a,$c]=$this->scope($input);$started=microtime(true);$data=[];
        if($tool==='view'){$id=(int)($a['chunk_id']??0);$s=$this->db->prepare('SELECT sc.content,sc.name,sc.start_line,sc.end_line,ps.filename FROM SourceChunks sc JOIN ProjectSources ps ON ps.id_=sc.source_id_ WHERE sc.id_=? AND sc.project_id_=?');$s->bind_param('ii',$id,$project);}
        else{$term=trim((string)($a[$tool==='grep'?'pattern':'query']??''));if($term==='')throw new TaskValidationException('tool_argument_invalid');$like='%'.$term.'%';$s=$this->db->prepare('SELECT sc.id_ chunk_id,ps.filename,sc.name,LEFT(sc.content,1200) content,sc.start_line,sc.end_line FROM SourceChunks sc JOIN ProjectSources ps ON ps.id_=sc.source_id_ WHERE sc.project_id_=? AND (sc.content LIKE ? OR ps.filename LIKE ?) LIMIT 30');$s->bind_param('iss',$project,$like,$like);}
        $s->execute();$res=$s->get_result();while($row=$res->fetch_assoc())$data[]=$row;$s->close();$result=new ToolExecutionResult($tool.' returned '.count($data).' result(s).',[],['results'=>$data]);$this->log($c,$tool,$a,$result,$started);return$result;
    }
    private function write(array $input,string $tool):ToolExecutionResult
    {
        [$project,$a,$c]=$this->scope($input);$started=microtime(true);
        // Legacy write implementations require browser session/cookies. The shared runtime rejects
        // that unsafe transport instead of impersonating a user or performing an arbitrary call.
        $result=new ToolExecutionResult($tool.' requires the server-side file service adapter.',[],[],false,'error');
        $this->log($c,$tool,$a,$result,$started);return$result;
    }
    private function log(array$c,string$tool,array$args,ToolExecutionResult$r,float$started):void
    {
        $session=(int)($c['session_id']??0);if($session<1)return;$project=isset($c['project_id'])?(int)$c['project_id']:null;$json=json_encode($args,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$summary=mb_substr($r->summary,0,4000);$status=$r->success?'ok':'error';$ms=(int)round((microtime(true)-$started)*1000);
        $s=$this->db->prepare('INSERT INTO ToolCalls(session_id_,project_id_,message_id_,tool,params,result,status,duration_ms) VALUES(?,?,NULL,?,?,?,?,?)');$s->bind_param('iissssi',$session,$project,$tool,$json,$summary,$status,$ms);$s->execute();$s->close();
    }
}
