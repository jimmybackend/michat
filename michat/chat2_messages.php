<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
if(session_status()===PHP_SESSION_NONE)session_start();
function jexit(array $d,int $c=200):void{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}

try{
    require_once __DIR__.'/includes/Chat/ChatEndpointBootstrap.php';
    require_once __DIR__.'/includes/Chat/ChatIdentity.php';
    require_once __DIR__.'/includes/Memory/ConversationScope.php';
    require_once __DIR__.'/includes/Memory/ConversationScopeResolver.php';
    $db=ChatEndpointBootstrap::mysqli(__DIR__);
    $userId=ChatIdentity::resolveUserId($db);
    if($userId<=0)jexit(['ok'=>false,'error'=>'Sesión de usuario no válida'],401);
    if(isset($_GET['user_id'])&&is_numeric($_GET['user_id'])&&(int)$_GET['user_id']!==$userId&&!ChatIdentity::isAdminLike())jexit(['ok'=>false,'error'=>'user_id no coincide con la sesión autenticada'],403);

    $sessionId=(int)($_GET['session_id']??0);if($sessionId<=0)jexit(['ok'=>false,'error'=>'session_id inválido'],400);
    $limit=max(1,min(2000,(int)($_GET['limit']??300)));

    $stmt=$db->prepare("SELECT id_,user_id_,project_id_,title,status,model_id,provider,meta,context_summary,created_at,updated_at FROM ChatSessions WHERE id_=? LIMIT 1");
    if(!$stmt)throw new RuntimeException($db->error);$stmt->bind_param('i',$sessionId);$stmt->execute();$session=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$session)jexit(['ok'=>false,'error'=>'Sesión no encontrada'],404);
    $owner=(int)$session['user_id_'];if($owner!==$userId&&!ChatIdentity::isAdminLike())jexit(['ok'=>false,'error'=>'No tienes permisos para ver esta sesión'],403);

    $sql="SELECT cm.id_,cm.session_id_,cm.user_id_,cm.role,cm.content_type,cm.content,cm.s3_key,cm.mime_type,cm.size_bytes,cm.thumb_s3_key,cm.duration_ms,
                 cm.model_id,cm.stop_reason,cm.prompt_tokens,cm.completion_tokens,cm.latency_ms,cm.meta,cm.is_primordial,cm.phase,cm.parent_msg_id,cm.created_at
          FROM ChatMessages cm
          WHERE cm.session_id_=? AND NOT EXISTS(
              SELECT 1 FROM ChatMessages cm2 WHERE cm2.session_id_=cm.session_id_ AND cm2.role='system' AND cm2.phase='compile'
                AND cm2.parent_msg_id=cm.parent_msg_id AND cm.phase='respond' AND cm.role='system'
          ) ORDER BY cm.id_ ASC LIMIT {$limit}";
    $stmt=$db->prepare($sql);if(!$stmt)throw new RuntimeException($db->error);$stmt->bind_param('i',$sessionId);$stmt->execute();$res=$stmt->get_result();$messages=[];
    while($m=$res->fetch_assoc())$messages[]=[
        'id'=>(int)$m['id_'],'session_id'=>(int)$m['session_id_'],'user_id'=>(int)$m['user_id_'],'role'=>(string)$m['role'],'content_type'=>(string)$m['content_type'],'content'=>(string)$m['content'],
        's3_key'=>$m['s3_key']!==null?(string)$m['s3_key']:null,'mime_type'=>$m['mime_type']!==null?(string)$m['mime_type']:null,'size_bytes'=>$m['size_bytes']!==null?(int)$m['size_bytes']:null,
        'thumb_s3_key'=>$m['thumb_s3_key']!==null?(string)$m['thumb_s3_key']:null,'duration_ms'=>$m['duration_ms']!==null?(int)$m['duration_ms']:null,'model_id'=>$m['model_id']!==null?(string)$m['model_id']:null,
        'stop_reason'=>$m['stop_reason']!==null?(string)$m['stop_reason']:null,'prompt_tokens'=>$m['prompt_tokens']!==null?(int)$m['prompt_tokens']:null,'completion_tokens'=>$m['completion_tokens']!==null?(int)$m['completion_tokens']:null,
        'latency_ms'=>$m['latency_ms']!==null?(int)$m['latency_ms']:null,'meta'=>$m['meta']!==null?(string)$m['meta']:null,'is_primordial'=>(int)$m['is_primordial'],'phase'=>$m['phase']!==null?(string)$m['phase']:null,
        'parent_msg_id'=>$m['parent_msg_id']!==null?(int)$m['parent_msg_id']:null,'created_at'=>(string)$m['created_at']
    ];
    $stmt->close();

    $projectContext=null;$projectId=(int)($session['project_id_']??0);
    if($projectId>0){
        $stmt=$db->prepare("SELECT pc.type,pc.title,pc.content FROM ProjectContext pc INNER JOIN Projects p ON p.id_=pc.project_id_ WHERE pc.project_id_=? AND p.user_id_=? AND p.status<>'deleted' ORDER BY pc.created_at ASC");
        if($stmt){$stmt->bind_param('ii',$projectId,$owner);$stmt->execute();$r=$stmt->get_result();$items=[];while($c=$r->fetch_assoc())$items[]=['type'=>$c['type'],'title'=>$c['title'],'content'=>$c['content']];$stmt->close();if($items)$projectContext=$items;}
    }

    $scope=(new ConversationScopeResolver($db))->resolve($owner,$sessionId);
    $meta=[];if(is_string($session['meta'])&&trim($session['meta'])!==''){$d=json_decode($session['meta'],true);if(is_array($d))$meta=$d;}
    jexit(['ok'=>true,'session'=>[
        'id'=>(int)$session['id_'],'user_id'=>$owner,'project_id'=>$projectId>0?$projectId:null,'title'=>(string)$session['title'],'status'=>(string)$session['status'],
        'model_id'=>(string)$session['model_id'],'provider'=>$session['provider']!==null?(string)$session['provider']:null,'context_summary'=>!empty($session['context_summary'])?(string)$session['context_summary']:null,
        'project_context'=>$projectContext,'branch'=>is_array($meta['branch']??null)?$meta['branch']:null,'memory_scope'=>$scope->toArray(),'created_at'=>(string)$session['created_at'],'updated_at'=>(string)$session['updated_at']
    ],'messages'=>$messages]);
}catch(Throwable $e){jexit(['ok'=>false,'error'=>$e->getMessage()],500);}
