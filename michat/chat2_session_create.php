<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

function jexit(array $data,int $code=200):void{http_response_code($code);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}

function resolveSessionModel(mysqli $db, int $userId, string $postedModel): string
{
    $postedModel = trim($postedModel);
    if ($postedModel !== '') return $postedModel;

    // 1) Configuración chat_main específica del usuario.
    $stmt = $db->prepare("SELECT model_id FROM UserAIAgentConfigs WHERE user_id_=? AND agent_key='chat_main' AND is_active=1 ORDER BY id_ DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        if ($stmt->execute()) {
            $row = $stmt->get_result()->fetch_assoc();
            $model = trim((string)($row['model_id'] ?? ''));
            $stmt->close();
            if ($model !== '') return $model;
        } else {
            $stmt->close();
        }
    }

    // 2) Preferencia persistida del usuario.
    $stmt = $db->prepare("SELECT model_id FROM UserPreferences WHERE user_id_=? ORDER BY id_ DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        if ($stmt->execute()) {
            $row = $stmt->get_result()->fetch_assoc();
            $model = trim((string)($row['model_id'] ?? ''));
            $stmt->close();
            if ($model !== '') return $model;
        } else {
            $stmt->close();
        }
    }

    // 3) Configuración global existente (usuario 1) como fallback de aplicación.
    $globalUserId = 1;
    $stmt = $db->prepare("SELECT model_id FROM UserAIAgentConfigs WHERE user_id_=? AND agent_key='chat_main' AND is_active=1 ORDER BY id_ DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $globalUserId);
        if ($stmt->execute()) {
            $row = $stmt->get_result()->fetch_assoc();
            $model = trim((string)($row['model_id'] ?? ''));
            $stmt->close();
            if ($model !== '') return $model;
        } else {
            $stmt->close();
        }
    }

    // Coincide con el DEFAULT de UserPreferences y evita romper clientes antiguos.
    return 'amazon.nova-micro-v1:0';
}

try{
    require_once __DIR__.'/includes/Chat/ChatEndpointBootstrap.php';
    require_once __DIR__.'/includes/Chat/ChatIdentity.php';
    require_once __DIR__.'/includes/Chat/SessionLifecycleService.php';
    $db_connection=ChatEndpointBootstrap::mysqli(__DIR__);
    $userId=ChatIdentity::resolveUserId($db_connection);
    if($userId<=0) jexit(['ok'=>false,'error'=>'Sesión de usuario no válida'],401);

    // Si el frontend manda user_id, sólo se acepta si coincide con la sesión real.
    if(isset($_POST['user_id']) && is_numeric($_POST['user_id']) && (int)$_POST['user_id']!==$userId){
        jexit(['ok'=>false,'error'=>'user_id no coincide con la sesión autenticada'],403);
    }

    $title=trim((string)($_POST['title']??'Nueva conversación'));
    $model=resolveSessionModel($db_connection,$userId,(string)($_POST['model']??''));
    $projectId=(isset($_POST['project_id'])&&$_POST['project_id']!=='')?(int)$_POST['project_id']:null;
    $parentMessageId=(isset($_POST['parent_message_id'])&&is_numeric($_POST['parent_message_id']))?(int)$_POST['parent_message_id']:null;

    $service=new SessionLifecycleService($db_connection);
    // Limpieza defensiva de abandonos antiguos. La sesión recién creada no existe aún.
    $cleaned=$service->cleanupStaleEmpty($userId,900,50);
    $session=$service->create($userId,$title,$model,$projectId,$parentMessageId);
    jexit(['ok'=>true,'cleanup_deleted'=>$cleaned]+$session);
}catch(InvalidArgumentException $e){jexit(['ok'=>false,'error'=>$e->getMessage()],400);}
catch(Throwable $e){jexit(['ok'=>false,'error'=>$e->getMessage()],500);}
