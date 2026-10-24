<?php
// Compatibility endpoint: withdraw publication, preserving the original.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if(session_status()===PHP_SESSION_NONE)session_start();
require_once __DIR__.'/app_bootstrap.php';
require_once __DIR__.'/includes/Chat/ChatIdentity.php';
require_once __DIR__.'/includes/ProjectSourceRemovalService.php';
function jexit(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')jexit(['ok'=>false,'error'=>'Método no permitido'],405);
$user=ChatIdentity::resolveUserId($db_connection);
if($user<1)jexit(['ok'=>false,'error'=>'Sesión inválida'],401);
if(isset($_POST['user_id'])&&(int)$_POST['user_id']!==$user)jexit(['ok'=>false,'error'=>'user_id no coincide con la sesión autenticada'],403);
if(!isset($_POST['csrf'],$_SESSION['document_link_csrf'])||!hash_equals($_SESSION['document_link_csrf'],(string)$_POST['csrf']))jexit(['ok'=>false,'error'=>'Token inválido'],403);
try{jexit((new ProjectSourceRemovalService($db_connection))->withdraw($user,(int)($_POST['source_id']??0)));}
catch(Throwable $e){error_log('PROJECT_SOURCE_WITHDRAW '.$e->getMessage());jexit(['ok'=>false,'error'=>'No se pudo retirar la fuente. Comprueba sus permisos.'],422);}
