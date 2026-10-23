<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
if(session_status()===PHP_SESSION_NONE)session_start();
function jexit(array $d,int $c=200):void{http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function esc_like(string $s):string{return str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$s);}

try{
    require_once __DIR__.'/includes/Chat/ChatEndpointBootstrap.php';
    require_once __DIR__.'/includes/Chat/ChatIdentity.php';
    require_once __DIR__.'/includes/Chat/SessionLifecycleService.php';
    $db=ChatEndpointBootstrap::mysqli(__DIR__);
    $userId=ChatIdentity::resolveUserId($db);
    if($userId<=0)jexit(['ok'=>false,'error'=>'Sesión de usuario no válida'],401);
    if(isset($_GET['user_id'])&&is_numeric($_GET['user_id'])&&(int)$_GET['user_id']!==$userId)jexit(['ok'=>false,'error'=>'user_id no coincide con la sesión autenticada'],403);

    $lifecycle=new SessionLifecycleService($db);
    $cleaned=$lifecycle->cleanupStaleEmpty($userId,900,50);

    $q=trim((string)($_GET['q']??''));
    // Tres vistas sin eliminar físicamente ninguna conversación.
    // Mantener el parámetro archived por compatibilidad con clientes antiguos.
    $view=(string)($_GET['status']??(((string)($_GET['archived']??'0'))==='1'?'archived':'open'));
    if(!in_array($view,['open','archived','closed'],true))jexit(['ok'=>false,'error'=>'status inválido'],400);
    $where=['cs.user_id_=?','cs.status=?'];
    $types='is';$params=[$userId,$view];
    if($q!==''){
        if(ctype_digit($q)){$where[]="(cs.id_=? OR cs.title LIKE CONCAT('%',?,'%'))";$types.='is';$params[]=(int)$q;$params[]=esc_like($q);}
        else{$where[]="cs.title LIKE CONCAT('%',?,'%')";$types.='s';$params[]=esc_like($q);}
    }
    $offset=max(0,min(1000000,(int)($_GET['offset']??0)));
    $types.='i';$params[]=$offset;
    $sql="SELECT cs.id_,cs.user_id_,cs.project_id_,cs.title,cs.model_id,cs.provider,cs.status,cs.meta,cs.created_at,cs.updated_at,
                 (SELECT COUNT(*) FROM ChatMessages cm WHERE cm.session_id_=cs.id_) AS message_count,
                 EXISTS(SELECT 1 FROM SessionContextBlocks scb WHERE scb.session_id_=cs.id_ LIMIT 1) AS has_context_blocks,
                 EXISTS(
                    SELECT 1 FROM FileS3 f
                    WHERE f.user_id_=cs.user_id_
                      AND f.Found=1
                      AND f.Ruta LIKE CONCAT('Data/Chat/Uploads/', cs.user_id_, '/%/', cs.id_, '/%')
                    LIMIT 1
                 ) AS has_files
          FROM ChatSessions cs WHERE ".implode(' AND ',$where)." ORDER BY cs.updated_at DESC LIMIT 201 OFFSET ?";
    $stmt=$db->prepare($sql);if(!$stmt)throw new RuntimeException($db->error);$stmt->bind_param($types,...$params);$stmt->execute();$res=$stmt->get_result();$sessions=[];
    while($row=$res->fetch_assoc()){
        $meta=[];if(is_string($row['meta'])&&trim($row['meta'])!==''){$d=json_decode($row['meta'],true);if(is_array($d))$meta=$d;}
        $branch=is_array($meta['branch']??null)?$meta['branch']:null;
        $messageCount=(int)$row['message_count'];$hasBlocks=(bool)$row['has_context_blocks'];$hasFiles=(bool)$row['has_files'];
        $sessions[]=[
            'id'=>(int)$row['id_'],'user_id'=>(int)$row['user_id_'],'project_id'=>$row['project_id_']!==null?(int)$row['project_id_']:null,
            'title'=>(string)$row['title'],'model_id'=>(string)$row['model_id'],'provider'=>$row['provider']!==null?(string)$row['provider']:null,
            'status'=>(string)$row['status'],'archived'=>(string)$row['status']==='archived','deleted'=>(string)$row['status']==='closed','created_at'=>(string)$row['created_at'],'updated_at'=>(string)$row['updated_at'],
            'message_count'=>$messageCount,'is_empty'=>$messageCount===0&&!$hasBlocks&&!$hasFiles,'has_files'=>$hasFiles,'is_branch'=>$branch!==null,
            'branch'=>$branch,
        ];
    }
    $stmt->close();
    $hasMore=count($sessions)>200;
    if($hasMore)array_pop($sessions);
    jexit(['ok'=>true,'cleanup_deleted'=>$cleaned,'sessions'=>$sessions,'has_more'=>$hasMore]);
}catch(Throwable $e){jexit(['ok'=>false,'error'=>$e->getMessage()],500);}
