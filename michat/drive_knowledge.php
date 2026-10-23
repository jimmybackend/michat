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
$session = $access->session($user,$sessionId);
if (!$session) driveKnowledgeExit(['ok'=>false,'error'=>'Acceso denegado'],403);
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method === 'GET') {
    // This token only authorizes linking, never uploading, reading or sharing arbitrary keys.
    if (empty($_SESSION['document_link_csrf'])) $_SESSION['document_link_csrf'] = bin2hex(random_bytes(24));
    $query = mb_substr(trim((string)($_GET['q'] ?? '')),0,100);
    $search = '%' . str_replace(['!','%','_'], ['!!','!%','!_'], $query) . '%';
    $s = $db_connection->prepare("SELECT id_,Nombre,Ruta,Tamano FROM FileS3 WHERE user_id_=? AND Found=1 AND AccessType<>'secure' AND Nombre LIKE ? ESCAPE '!' ORDER BY id_ DESC LIMIT 40");
    $s->bind_param('is',$user,$search); $s->execute(); $r = $s->get_result(); $files=[];
    while ($row=$r->fetch_assoc()) {
        $ext=strtolower(pathinfo($row['Nombre'],PATHINFO_EXTENSION));
        if (in_array($ext,array_merge(idx_text_extensions(),idx_extractable_extensions(),['jpg','jpeg','png','gif','webp']),true)) $files[]=$row;
    }
    $s->close();
    driveKnowledgeExit(['ok'=>true,'files'=>$files,'csrf'=>$_SESSION['document_link_csrf'],'limited'=>true]);
}
if ($method !== 'POST') driveKnowledgeExit(['ok'=>false,'error'=>'Método no permitido'],405);
if (!isset($_POST['csrf'],$_SESSION['document_link_csrf']) || !hash_equals($_SESSION['document_link_csrf'],(string)$_POST['csrf'])) driveKnowledgeExit(['ok'=>false,'error'=>'Token inválido'],403);
if ($session['status'] !== 'open') driveKnowledgeExit(['ok'=>false,'error'=>'La conversación está archivada'],409);
$fileId=(int)($_POST['file_id'] ?? 0);
if (!$access->file($user,$fileId)) driveKnowledgeExit(['ok'=>false,'error'=>'Archivo no autorizado o bloqueado'],403);
try {
    $db_connection->begin_transaction();
    $s=$db_connection->prepare('SELECT meta FROM ChatSessions WHERE id_=? AND user_id_=? FOR UPDATE');
    $s->bind_param('ii',$sessionId,$user);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
    if (!$row) throw new RuntimeException('Acceso revocado');
    $meta=json_decode((string)$row['meta'],true) ?: [];
    $ids=array_values(array_unique(array_map('intval',(array)($meta['knowledge_file_ids'] ?? []))));
    if (($_POST['action'] ?? 'link') === 'unlink') $ids=array_values(array_diff($ids,[$fileId]));
    elseif (!in_array($fileId,$ids,true)) {
        if (count($ids)>=100) throw new RuntimeException('Máximo 100 documentos vinculados por conversación');
        $ids[]=$fileId;
    }
    $meta['knowledge_file_ids']=$ids;
    $encoded=json_encode($meta,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $s=$db_connection->prepare('UPDATE ChatSessions SET meta=? WHERE id_=? AND user_id_=?');
    $s->bind_param('sii',$encoded,$sessionId,$user);$s->execute();$s->close();
    $db_connection->commit();
    driveKnowledgeExit(['ok'=>true,'linked_file_ids'=>$ids,'indexed'=>false]);
} catch (Throwable $e) {
    $db_connection->rollback();error_log('drive_knowledge: '.$e->getMessage());
    driveKnowledgeExit(['ok'=>false,'error'=>'No se pudo actualizar el vínculo'],500);
}
