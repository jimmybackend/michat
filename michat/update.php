<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if(session_status()===PHP_SESSION_NONE)session_start();
require_once __DIR__.'/app_bootstrap.php';
require_once __DIR__.'/includes/Chat/ChatIdentity.php';
require_once __DIR__.'/includes/Auth/AuthorizationService.php';
require_once __DIR__.'/includes/Security/CsrfGuard.php';
require_once __DIR__.'/includes/Admin/MiChatUpdaterService.php';
function updaterResponse(int $code,array $payload): never {http_response_code($code);echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if(!isset($db_connection)||!$db_connection instanceof mysqli)updaterResponse(503,['ok'=>false,'error'=>'DB no disponible']);
try {
  $userId=ChatIdentity::resolveUserId($db_connection);
  if($userId<1)updaterResponse(401,['ok'=>false,'error'=>'No autenticado']);
  $auth=new AuthorizationService($db_connection);
  if($auth->roleForActiveUser($userId)!=='superadmin')updaterResponse(403,['ok'=>false,'error'=>'Sólo superadmin puede actualizar']);
  $method=(string)($_SERVER['REQUEST_METHOD']??'GET');
  if($method==='GET')updaterResponse(200,(new MiChatUpdaterService())->execute('check'));
  if($method!=='POST')updaterResponse(405,['ok'=>false,'error'=>'Método no permitido']);
  CsrfGuard::assertSessionToken((string)($_POST['csrf_token']??''));
  $action=(string)($_POST['action']??'');
  if(!in_array($action,['apply','apply-stash'],true))updaterResponse(422,['ok'=>false,'error'=>'Acción no permitida']);
  $password=(string)($_POST['current_password']??'');
  $stmt=$db_connection->prepare("SELECT password FROM Users WHERE id=? AND system_role='superadmin' AND userstatus='Activo' LIMIT 1");
  if(!$stmt)throw new RuntimeException('No se pudo validar la cuenta.');
  $stmt->bind_param('i',$userId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
  if(!$row||$password===''||!password_verify($password,(string)$row['password']))updaterResponse(403,['ok'=>false,'error'=>'Contraseña incorrecta']);
  $result=(new MiChatUpdaterService())->execute($action);
  $detail='MiChat web updater: '.substr((string)($result['previous_commit']??''),0,12).' -> '.substr((string)($result['local_commit']??''),0,12);
  try {
    $ip=substr((string)($_SERVER['REMOTE_ADDR']??''),0,45);$event='Otro';
    $audit=$db_connection->prepare('INSERT INTO AccessControl (user_id,date_time,action,ip_address,action_details) VALUES (?,NOW(),?,?,?)');
    if($audit){$audit->bind_param('isss',$userId,$event,$ip,$detail);$audit->execute();$audit->close();}
  } catch(Throwable $e){error_log('MICHAT_UPDATER_AUDIT: '.$e->getMessage());}
  updaterResponse(200,$result);
} catch(Throwable $e) {
  error_log('MICHAT_UPDATER: '.$e->getMessage());
  updaterResponse(503,['ok'=>false,'error'=>'No se pudo ejecutar la actualización: '.substr($e->getMessage(),0,300)]);
}
