<?php
/**
 * index_session_file.php
 * Indexa un adjunto de sesión en file_chunk y encola embeddings usando embedding_main.
 */
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/S3Manager.php';
require_once __DIR__ . '/includes/ai_agent_runtime.php';
require_once __DIR__ . '/includes/session_file_extractor.php';

function jexit($arr, $code=200): void {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function current_user_id(): int {
    foreach (['user_id_','user_id','id_usuario','id_user','id'] as $k) {
        if (isset($_SESSION[$k]) && ctype_digit((string)$_SESSION[$k])) return (int)$_SESSION[$k];
    }
    return 0;
}
function delete_old_file_chunks(mysqli $db, int $sessionId, string $s3Key): int {
    $ids=[];
    $s=$db->prepare("SELECT id_ FROM SessionContextBlocks WHERE session_id_=? AND s3_path=? AND block_type='file_chunk'");
    if (!$s) throw new RuntimeException('No se pudo consultar chunks previos: '.$db->error);
    $s->bind_param('is',$sessionId,$s3Key); $s->execute(); $r=$s->get_result();
    while($row=$r->fetch_assoc()) $ids[]=(int)$row['id_'];
    $s->close();
    if ($ids) {
        $ph=implode(',',array_fill(0,count($ids),'?')); $types=str_repeat('i',count($ids));
        $d=$db->prepare("DELETE FROM EmbeddingJobs WHERE target_type='session_block' AND target_id IN ($ph)");
        if ($d) { $d->bind_param($types,...$ids); $d->execute(); $d->close(); }
    }
    $d=$db->prepare("DELETE FROM SessionContextBlocks WHERE session_id_=? AND s3_path=? AND block_type='file_chunk'");
    if (!$d) throw new RuntimeException('No se pudo eliminar chunks previos: '.$db->error);
    $d->bind_param('is',$sessionId,$s3Key); $d->execute(); $n=$d->affected_rows; $d->close();
    return $n;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jexit(['ok'=>false,'error'=>'Método no permitido'],405);
if (!isset($db_connection) || !($db_connection instanceof mysqli)) jexit(['ok'=>false,'error'=>'DB no disponible'],500);
$userId=current_user_id();
if ($userId<=0) jexit(['ok'=>false,'error'=>'Sesión inválida'],401);
$fileId=(int)($_POST['file_id']??0); $sessionId=(int)($_POST['session_id']??0);
if ($fileId<=0 || $sessionId<=0) jexit(['ok'=>false,'error'=>'file_id y session_id son obligatorios'],400);

$s=$db_connection->prepare("SELECT id_ FROM ChatSessions WHERE id_=? AND user_id_=? LIMIT 1");
$s->bind_param('ii',$sessionId,$userId); $s->execute(); $ok=$s->get_result()->num_rows>0; $s->close();
if(!$ok) jexit(['ok'=>false,'error'=>'La sesión no existe o no es tuya'],403);

$s=$db_connection->prepare("SELECT id_,Nombre,Encriptado,Ruta,Found FROM FileS3 WHERE id_=? AND user_id_=? AND Found=1 LIMIT 1");
$s->bind_param('ii',$fileId,$userId); $s->execute(); $file=$s->get_result()->fetch_assoc(); $s->close();
if(!$file) jexit(['ok'=>false,'error'=>'El archivo no existe o no es tuyo'],403);
if(!idx_file_belongs_to_chat_session($file,$userId,$sessionId)) jexit(['ok'=>false,'error'=>'El archivo no pertenece a esta sesión de chat'],403);

try {
    aiRuntimeLoad($db_connection,$userId);
    $ex=idx_extract_files3_text($file, IDX_MAX_EXTRACTED_CHARS);
    $content=(string)$ex['content']; $ext=(string)$ex['ext']; $s3Key=(string)$ex['s3_key'];
    $chunks=in_array($ext,idx_text_extensions(),true)
        ? idx_chunk_text_preserve_lines($content,IDX_CHUNK_MAX_LEN)
        : idx_chunk_text_smart($content,IDX_CHUNK_MAX_LEN);
    if(!$chunks) throw new RuntimeException('El archivo no generó chunks indexables.');

    $embeddingActive=aiAgentActive('embedding_main',false);
    $embeddingModel=$embeddingActive ? aiAgentModel('embedding_main','') : '';
    if($embeddingActive && $embeddingModel==='') throw new RuntimeException("embedding_main está activo pero no tiene model_id");

    $db_connection->begin_transaction();
    try {
        $removed=delete_old_file_chunks($db_connection,$sessionId,$s3Key);
        $queued=0;
        foreach($chunks as $i=>$chunkText){
            $meta=json_encode([
                'filename'=>$file['Nombre'],'files3_id'=>$fileId,'chunk'=>$i+1,'total'=>count($chunks),
                'extractor'=>$ex['extractor'],'ext'=>$ext,'truncated'=>(bool)$ex['truncated']
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $tokens=(int)ceil(mb_strlen($chunkText)/4);
            $ins=$db_connection->prepare("INSERT INTO SessionContextBlocks (session_id_,block_type,content_preview,s3_path,is_locked,source_ids,token_count) VALUES (?,'file_chunk',?,?,1,?,?)");
            if(!$ins) throw new RuntimeException('INSERT file_chunk: '.$db_connection->error);
            $ins->bind_param('isssi',$sessionId,$chunkText,$s3Key,$meta,$tokens);
            if(!$ins->execute()){ $e=$ins->error; $ins->close(); throw new RuntimeException('INSERT file_chunk: '.$e); }
            $blockId=(int)$ins->insert_id; $ins->close();
            if($embeddingActive){
                $job=$db_connection->prepare("INSERT INTO EmbeddingJobs (target_type,target_id,model_id,status,attempts) VALUES ('session_block',?,?,'pending',0) ON DUPLICATE KEY UPDATE status='pending',attempts=0,error_message=NULL,updated_at=NOW()");
                if(!$job) throw new RuntimeException('INSERT EmbeddingJobs: '.$db_connection->error);
                $job->bind_param('is',$blockId,$embeddingModel); $job->execute(); $job->close(); $queued++;
            }
        }
        $db_connection->commit();
    } catch(Throwable $e){ $db_connection->rollback(); throw $e; }

    jexit([
        'ok'=>true,
        'mensaje'=>'Archivo procesado ('.$ex['extractor'].') y dividido en '.count($chunks).' chunk(s).'.($embeddingActive?' Embeddings encolados con '.$embeddingModel.'.':' embedding_main está desactivado; se guardaron los chunks sin vectorizar.'),
        'chunks'=>count($chunks),'extractor'=>$ex['extractor'],'truncated'=>(bool)$ex['truncated'],
        'embedding_queued'=>$embeddingActive,'embedding_model'=>$embeddingModel?:null,
        'semantic_preserved'=>true
    ]);
} catch(Throwable $e){
    jexit(['ok'=>false,'error'=>$e->getMessage()],500);
}
