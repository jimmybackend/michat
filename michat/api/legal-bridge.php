<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function legalBridgeReply(array $data,int $status=200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

$bridgeStage = 'bootstrap';
try {
    // The standard bootstrap loads MICHAT_ENV_FILE or repository .env, not
    // /etc/michat.env unconditionally. Read the already-existing private
    // configuration here, honoring injected process environment precedence.
    require_once dirname(__DIR__).'/includes/Config/EnvironmentLoader.php';
    (new EnvironmentLoader())->loadIfPresent('/etc/michat.env');
    if (getenv('LEGAL_BRIDGE_ENABLED') !== 'true') {
        legalBridgeReply(['ok'=>false,'error'=>['code'=>'bridge_disabled']],503);
    }

    if (($_SERVER['REQUEST_METHOD']??'') !== 'POST') legalBridgeReply(['ok'=>false,'error'=>['code'=>'method_not_allowed']],405);
    $path=(string)(parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH) ?: '');
    if ($path !== '/api/legal-bridge.php' || str_contains((string)($_SERVER['REQUEST_URI']??''),'?')) {
        legalBridgeReply(['ok'=>false,'error'=>['code'=>'route_not_allowed']],404);
    }
    $tls = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    if (!$tls) legalBridgeReply(['ok'=>false,'error'=>['code'=>'https_required']],403);
    if ((int)($_SERVER['CONTENT_LENGTH']??0)>1048576) legalBridgeReply(['ok'=>false,'error'=>['code'=>'body_too_large']],413);
    $body=file_get_contents('php://input',false,null,0,1048577);
    if ($body===false || strlen($body)>1048576) legalBridgeReply(['ok'=>false,'error'=>['code'=>'body_too_large']],413);

    require_once dirname(__DIR__).'/app_bootstrap.php';
    require_once dirname(__DIR__).'/includes/LegalBridge/LegalBridgeAuth.php';
    require_once dirname(__DIR__).'/includes/LegalBridge/LegalBridgeSessionService.php';
    require_once dirname(__DIR__).'/includes/LegalBridge/LegalBridgeDocumentService.php';
    require_once dirname(__DIR__).'/includes/LegalBridge/LegalBridgeChatService.php';
    if (!isset($db_connection) || !($db_connection instanceof mysqli)) throw new LegalBridgeError('bridge_unavailable',503);
    $headers=[];
    foreach (['X-Legal-Key-Id','X-Legal-Timestamp','X-Legal-Nonce','X-Legal-Signature'] as $header) {
        $key='HTTP_'.strtoupper(str_replace('-','_',$header));
        $value=(string)($_SERVER[$key]??'');
        if (str_contains($value,',')) throw new LegalBridgeError('invalid_signature',401);
        $headers[strtolower($header)]=$value;
    }
    $bridgeStage = 'authentication';
    $identity=(new LegalBridgeAuth($db_connection))->verify('POST',$path,$body,$headers,$tls);
    $request=json_decode($body,true,32,JSON_THROW_ON_ERROR);
    if (!is_array($request) || array_is_list($request)) throw new LegalBridgeError('invalid_json',400);
    $op=(string)($request['operation']??'');
    $payload=$request['data']??[];
    if (!is_array($payload) || array_is_list($payload) && $payload!==[]) throw new LegalBridgeError('invalid_payload',400);
    $bridgeStage = 'service_init';
    $sessions=new LegalBridgeSessionService($db_connection);
    $userId=$identity['user_id'];$credential=$identity['credential_id'];
    $documents=new LegalBridgeDocumentService($db_connection,$sessions);

    $bridgeStage = 'operation_' . (in_array($op, ['health','session.open','chat.history','chat.activity','document.attach','document.status','chat.send','document.suggestions'], true) ? str_replace('.', '_', $op) : 'unknown');
    if ($op==='health') {
        legalBridgeReply(['ok'=>true,'enabled'=>true,'authenticated'=>true,'services'=>['database'=>'available']]);
    }
    if ($op==='session.open') {
        legalBridgeReply(['ok'=>true]+$sessions->open($credential,$userId,$payload));
    }
    if ($op==='chat.history' || $op==='chat.activity') {
        $sessionId=(int)($payload['session_id']??0);
        $cursor=(int)($payload['cursor']??0);
        $limit=(int)($payload['limit']??50);
        $result=$op==='chat.history'
            ? $sessions->history($credential,$userId,$sessionId,$cursor,$limit)
            : $sessions->activity($credential,$userId,$sessionId,$cursor,$limit);
        legalBridgeReply(['ok'=>true]+$result);
    }
    if ($op==='document.attach') {
        $out=$documents->attach($credential,$userId,(int)($payload['session_id']??0),$payload);
        legalBridgeReply(['ok'=>true]+$out,!empty($out['created'])?202:200);
    }
    if ($op==='document.status') {
        legalBridgeReply(['ok'=>true]+$documents->status($credential,$userId,(int)($payload['session_id']??0)));
    }
    if ($op==='chat.send' || $op==='document.suggestions') {
        $chat=new LegalBridgeChatService($db_connection,$sessions,$documents);
        $out=$op==='chat.send'
            ?$chat->send($credential,$userId,(int)($payload['session_id']??0),$payload)
            :$chat->suggestions($credential,$userId,(int)($payload['session_id']??0),$payload);
        legalBridgeReply(['ok'=>true]+$out);
    }
    throw new LegalBridgeError('unknown_operation',400);
} catch (LegalBridgeError $e) {
    legalBridgeReply(['ok'=>false,'error'=>['code'=>$e->getMessage()]],$e->httpStatus);
} catch (JsonException $e) {
    legalBridgeReply(['ok'=>false,'error'=>['code'=>'invalid_json']],400);
} catch (Throwable $e) {
    // No request bodies, secrets, exception details or document contents in logs.
    // Operational context only: no exception messages, request data, secrets or SQL.
    error_log('LEGAL_BRIDGE_FAILURE stage=' . $bridgeStage . ' type=' . get_class($e)
        . ' source=' . basename($e->getFile()) . ' line=' . $e->getLine());
    legalBridgeReply(['ok'=>false,'error'=>['code'=>'bridge_unavailable']],503);
}
