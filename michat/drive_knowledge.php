<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/includes/Chat/ChatIdentity.php';
require_once __DIR__ . '/includes/Memory/DocumentAccessRepository.php';

function driveKnowledgeExit(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}
$user = ChatIdentity::resolveUserId($db_connection);
if ($user <= 0) driveKnowledgeExit(['ok'=>false,'error'=>'No autenticado'],401);
$access = new DocumentAccessRepository($db_connection);
$sessionId = (int)($_GET['session_id'] ?? $_POST['session_id'] ?? 0);
$session = $sessionId>0 ? $access->session($user,$sessionId) : null;
if ($sessionId>0 && !$session) driveKnowledgeExit(['ok'=>false,'error'=>'Acceso denegado'],403);
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (empty($_SESSION['document_link_csrf'])) $_SESSION['document_link_csrf'] = bin2hex(random_bytes(24));
$validCsrf = isset($_POST['csrf']) && hash_equals($_SESSION['document_link_csrf'],(string)$_POST['csrf']);
$action = (string)($_POST['action'] ?? '');
if ($method === 'GET' || ($method === 'POST' && $action === 'search_ai')) {
    try {
        require_once __DIR__.'/includes/Drive/MetadataSearchService.php';
        require_once __DIR__.'/includes/Drive/BedrockMetadataPlanner.php';
        $ai = $action === 'search_ai';
        if ($ai && (!$validCsrf || !$session)) driveKnowledgeExit(['ok'=>false,'error'=>'Selecciona una conversación y vuelve a abrir el navegador para buscar con IA.'],403);
        $request = $ai ? $_POST : $_GET;
        $q = mb_substr(trim((string)($request['q'] ?? '')),0,500);
        $cursor = max(0,(int)($request['cursor'] ?? 0));
        $explicit = ['extensions'=>array_filter(explode(',',strtolower((string)($request['extensions'] ?? '')))),
            'date_from'=>$request['date_from'] ?? null,'date_to'=>$request['date_to'] ?? null];
        $plan = (string)($request['plan'] ?? '');
        if ($plan !== '') {
            $saved = $_SESSION['drive_metadata_plan'] ?? [];
            if (($saved['user'] ?? 0)!==$user || !hash_equals((string)($saved['token'] ?? ''),$plan)) throw new InvalidArgumentException('La búsqueda expiró; vuelve a buscar.');
            $q='';$explicit=$saved['filters'];
        }
        $planner = $plan !== '' ? static fn()=> $saved['filters'] : new BedrockMetadataPlanner($db_connection,$user,$sessionId);
        // Reusing a server-held plan pages the DB results without another model call.
        $result = (new MetadataSearchService($db_connection,$planner))->search($user,$plan!==''?'cached':$q,$ai || $plan!=='',$explicit,$cursor);
        if ($ai) { $plan=bin2hex(random_bytes(18)); $_SESSION['drive_metadata_plan']=['user'=>$user,'token'=>$plan,'filters'=>$result['filters']]; }
        driveKnowledgeExit(['ok'=>true,'csrf'=>$_SESSION['document_link_csrf'],'plan'=>$plan]+$result);
    } catch (InvalidArgumentException $e) { driveKnowledgeExit(['ok'=>false,'error'=>$e->getMessage()],400); }
    catch (Throwable $e) { error_log('drive_search: '.$e->getMessage()); driveKnowledgeExit(['ok'=>false,'error'=>'No se pudo realizar la búsqueda. Revisa el modelo o utiliza búsqueda literal.'],503); }
}
if ($method !== 'POST') driveKnowledgeExit(['ok'=>false,'error'=>'Método no permitido'],405);
if (!$validCsrf) driveKnowledgeExit(['ok'=>false,'error'=>'Token inválido'],403);
if (!$session) driveKnowledgeExit(['ok'=>false,'error'=>'Selecciona una conversación'],400);
if ($session['status'] !== 'open') driveKnowledgeExit(['ok'=>false,'error'=>'La conversación está archivada'],409);
if (!in_array($action,['link','unlink'],true)) driveKnowledgeExit(['ok'=>false,'error'=>'Acción inválida'],400);
$fileId=(int)($_POST['file_id'] ?? 0);
try {
    require_once __DIR__.'/includes/Drive/ConversationAttachmentService.php';
    driveKnowledgeExit((new ConversationAttachmentService($db_connection))->change($user,$sessionId,$fileId,$action==='link'));
} catch (Throwable $e) {
    error_log('drive_knowledge: '.$e->getMessage());
    driveKnowledgeExit(['ok'=>false,'error'=>'No se pudo actualizar el vínculo'],422);
}
