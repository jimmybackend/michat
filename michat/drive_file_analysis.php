<?php
declare(strict_types=1);
if (session_status()===PHP_SESSION_NONE) session_start();
@set_time_limit(600);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try {
    require_once __DIR__.'/app_bootstrap.php';
    require_once __DIR__.'/includes/Chat/ChatIdentity.php';
    require_once __DIR__.'/includes/Drive/DriveAnalysisService.php';
    $method=$_SERVER['REQUEST_METHOD'] ?? '';
    $input=$method==='GET'?$_GET:$_POST;
    $user=ChatIdentity::resolveUserId($db_connection);$session=(int)($input['session_id'] ?? 0);$file=(int)($input['file_id'] ?? 0);
    if ($method==='GET' && ($input['action'] ?? '')==='download') {
        $ownedFile=(new DocumentAccessRepository($db_connection))->requireFile($user,$session,$file);
        $state=(new AnalysisSidecarStore(Config::getS3(),Config::getBucket()))->read($ownedFile);
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
    echo json_encode(DriveAnalysisService::production($db_connection)->analyze($user,$session,$file),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('drive_analysis: '.$e->getMessage());http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'No se pudo completar o guardar el análisis. Revisa permisos, configuración del modelo y el registro del servidor; no se marcó como terminado.'],JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE);
}
