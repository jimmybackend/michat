<?php
declare(strict_types=1);
if (session_status()===PHP_SESSION_NONE) session_start();
@set_time_limit(600);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$stage='request_validation';
try {
    require_once __DIR__.'/app_bootstrap.php';
    require_once __DIR__.'/includes/Chat/ChatIdentity.php';
    require_once __DIR__.'/includes/Drive/DriveAnalysisService.php';
    $method=$_SERVER['REQUEST_METHOD'] ?? '';
    $input=$method==='GET'?$_GET:$_POST;
    $user=ChatIdentity::resolveUserId($db_connection);$session=(int)($input['session_id'] ?? 0);$file=(int)($input['file_id'] ?? 0);
    if ($method==='GET' && ($input['action'] ?? '')==='download') {
        $ownedFile=(new DocumentAccessRepository($db_connection))->requireFile($user,$session,$file);
        $origin=(new DocumentAccessRepository($db_connection))->original($ownedFile);
        $state=(new AnalysisSidecarStore(Config::getS3(),Config::getBucket()))->read($origin?:$ownedFile);
        if (!$state['analysis']) throw new RuntimeException('Aún no hay un análisis de esta revisión');
        header("Content-Disposition: attachment; filename=\"michat-analysis-$file.json\"; filename*=UTF-8''".rawurlencode($ownedFile['Nombre'].'.michat-analysis.v1.json'));
        header('X-Content-Type-Options: nosniff');
        echo json_encode($state['document'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;
    }
    if ($method!=='POST') throw new InvalidArgumentException('Método no permitido');
    $access=new DocumentAccessRepository($db_connection);$owned=$access->session($user,$session);
    if (!$owned || $owned['status']!=='open' || !isset($_POST['csrf'],$_SESSION['document_link_csrf'])
        || !hash_equals($_SESSION['document_link_csrf'],(string)$_POST['csrf'])) {
        http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Acceso denegado']);exit;
    }
    $attach=($_POST['action']??'')==='attach';
    if($attach) {
        $stage='working_copy';
        require_once __DIR__.'/includes/Drive/DriveChatAttachmentService.php';
        $working=(new DriveChatAttachmentService($db_connection,Config::getS3(),Config::getBucket()))->attach($user,$session,$file);
        $file=(int)$working['id_'];
    }
    $result=DriveAnalysisService::production($db_connection)->analyze($user,$session,$file,!$attach);
    if($attach) $result['attachment']=['file_id'=>$file,'filename'=>$working['Nombre'],'s3_key'=>idx_build_s3_key($working)];
    if (empty($result['ok'])) http_response_code(!empty($result['pending'])?202:422);
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(422);
    $state=class_exists('FileKnowledgeState')?FileKnowledgeState::reportFailure($e,$stage,['user_id'=>$user??0,'session_id'=>$session??0,'file_id'=>$file??0]):['message'=>'No se pudo preparar el archivo; comprueba la configuración del servidor.'];
    echo json_encode(['ok'=>false,'error'=>$state['message'],'stage'=>$stage,'error_id'=>$state['error_id']??null],JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE);
}
