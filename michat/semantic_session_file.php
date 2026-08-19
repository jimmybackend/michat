<?php
/**
 * semantic_session_file.php
 * Crea/actualiza el resumen semántico de un adjunto.
 * El modelo se REUTILIZA de smart_memory_general/smart_memory_code.
 * La instrucción específica vive en attachment_semantic_prompt (text_block).
 * El embedding se encola y lo procesa process_embedding_queue.php con embedding_main.
 */
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/app_bootstrap.php';
require_once __DIR__.'/S3Manager.php';
require_once __DIR__.'/includes/ai_agent_runtime.php';
require_once __DIR__.'/includes/session_file_extractor.php';

function jexit($a,$c=200):void{http_response_code($c);echo json_encode($a,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function sem_uid():int{foreach(['user_id_','user_id','id_usuario','id_user','id'] as $k)if(isset($_SESSION[$k])&&ctype_digit((string)$_SESSION[$k]))return(int)$_SESSION[$k];return 0;}
function sem_looks_code(string $name,string $text):bool{
    $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
    if(in_array($ext,['php','phtml','inc','js','mjs','cjs','jsx','ts','tsx','css','scss','less','html','htm','json','xml','yaml','yml','sql','sh','bash','zsh','py','rb','java','c','h','cpp','hpp','cs','go','rs','swift','kt','kts','vue'],true)) return true;
    return preg_match('/\b(function|class|const|let|var|import|export|return|SELECT\s+.+\s+FROM|INSERT\s+INTO|<\?php|=>|endpoint|query|database|c[oó]digo|script|variable|api)\b/i',$text)===1;
}
function sem_existing_index_text(mysqli $db,int $sessionId,string $s3Key,int $maxChars):string{
    $s=$db->prepare("SELECT content_preview FROM SessionContextBlocks WHERE session_id_=? AND s3_path=? AND block_type='file_chunk' ORDER BY created_at ASC,id_ ASC");
    if(!$s)return''; $s->bind_param('is',$sessionId,$s3Key);$s->execute();$r=$s->get_result();$out='';
    while($row=$r->fetch_assoc()){ $part=trim((string)$row['content_preview']); if($part==='')continue; if(mb_strlen($out)+mb_strlen($part)+2>$maxChars){$left=$maxChars-mb_strlen($out);if($left>0)$out.="\n\n".mb_substr($part,0,$left);break;} $out.=($out===''?'':"\n\n").$part; }
    $s->close(); return trim($out);
}
function sem_log(mysqli $db,int $sessionId,string $model,int $in,int $out):void{
    try{$phase='summarize';$zero=0.0;$dur=0;$s=$db->prepare("INSERT INTO TokenUsage (session_id_,message_id_,phase,model_id,input_tokens,output_tokens,estimated_cost_usd,duration_ms) VALUES (?,NULL,?,?,?,?,?,?)");if($s){$s->bind_param('issiidi',$sessionId,$phase,$model,$in,$out,$zero,$dur);$s->execute();$s->close();}}catch(Throwable $e){error_log('semantic token log: '.$e->getMessage());}
}

if(($_SERVER['REQUEST_METHOD']??'')!=='POST')jexit(['ok'=>false,'error'=>'Método no permitido'],405);
if(!isset($db_connection)||!($db_connection instanceof mysqli))jexit(['ok'=>false,'error'=>'DB no disponible'],500);
$userId=sem_uid(); if($userId<=0)jexit(['ok'=>false,'error'=>'Sesión inválida'],401);
$fileId=(int)($_POST['file_id']??0);$sessionId=(int)($_POST['session_id']??0);
if($fileId<=0||$sessionId<=0)jexit(['ok'=>false,'error'=>'file_id y session_id son obligatorios'],400);
$s=$db_connection->prepare("SELECT id_ FROM ChatSessions WHERE id_=? AND user_id_=? LIMIT 1");$s->bind_param('ii',$sessionId,$userId);$s->execute();$ok=$s->get_result()->num_rows>0;$s->close();if(!$ok)jexit(['ok'=>false,'error'=>'La sesión no existe o no es tuya'],403);
$s=$db_connection->prepare("SELECT id_,Nombre,Encriptado,Ruta,Found FROM FileS3 WHERE id_=? AND user_id_=? AND Found=1 LIMIT 1");$s->bind_param('ii',$fileId,$userId);$s->execute();$file=$s->get_result()->fetch_assoc();$s->close();if(!$file)jexit(['ok'=>false,'error'=>'El archivo no existe o no es tuyo'],403);
if(!idx_file_belongs_to_chat_session($file,$userId,$sessionId))jexit(['ok'=>false,'error'=>'El archivo no pertenece a esta sesión de chat'],403);

try{
    aiRuntimeLoad($db_connection,$userId);
    if(!aiAgentConfig('attachment_semantic_prompt')) throw new RuntimeException("Falta attachment_semantic_prompt. Ejecuta sql/attachment_semantic_prompt.sql");
    if(!aiAgentActive('attachment_semantic_prompt',false)) jexit(['ok'=>false,'error'=>'La semántica de adjuntos está desactivada (attachment_semantic_prompt.is_active=0)'],409);

    $s3Key=idx_build_s3_key($file);
    $maxChars=max(2000,(int)aiAgentExtra('attachment_semantic_prompt','max_content_chars',24000));
    $content=sem_existing_index_text($db_connection,$sessionId,$s3Key,$maxChars);
    $extractor='indexed_chunks'; $truncated=false;
    if($content===''){
        $ex=idx_extract_files3_text($file,$maxChars);$content=(string)$ex['content'];$extractor=(string)$ex['extractor'];$truncated=(bool)$ex['truncated'];$s3Key=(string)$ex['s3_key'];
    }

    $agentKey=sem_looks_code((string)$file['Nombre'],$content)?'smart_memory_code':'smart_memory_general';
    if(!aiAgentConfig($agentKey)) throw new RuntimeException("Falta {$agentKey} en UserAIAgentConfigs");
    if(!aiAgentActive($agentKey,false)) jexit(['ok'=>false,'error'=>"{$agentKey} está desactivado; no se generó semántica"],409);
    $modelId=aiAgentModel($agentKey,''); if($modelId==='') throw new RuntimeException("{$agentKey} no tiene model_id");
    $system=aiAgentInstruction('attachment_semantic_prompt','');$tpl=aiAgentUserTemplate('attachment_semantic_prompt','');
    if($system===''||$tpl==='') throw new RuntimeException('attachment_semantic_prompt no tiene system_instruction/user_prompt_template');
    $prompt=aiRenderTemplate($tpl,['filename'=>(string)$file['Nombre'],'content'=>mb_substr($content,0,$maxChars)]);

    $region=(class_exists('Config')&&defined('Config::REGION')&&Config::REGION)?Config::REGION:'us-east-1';
    $ak=getenv('AWS_ACCESS_KEY_ID')?: (defined('Config::ACCESS_KEY')?Config::ACCESS_KEY:'');
    $sk=getenv('AWS_SECRET_ACCESS_KEY')?: (defined('Config::SECRET_KEY')?Config::SECRET_KEY:'');
    if(!$ak||!$sk)throw new RuntimeException('Faltan credenciales AWS');
    $bedrock=new Aws\BedrockRuntime\BedrockRuntimeClient(['region'=>$region,'version'=>'latest','credentials'=>['key'=>$ak,'secret'=>$sk],'http'=>['connect_timeout'=>10,'timeout'=>120]]);
    $infer=[
        'maxTokens'=>max(100,(int)aiAgentExtra('attachment_semantic_prompt','max_tokens',800)),
        'temperature'=>(float)aiAgentExtra('attachment_semantic_prompt','temperature',aiAgentValue($agentKey,'temperature',0.2)),
        'topP'=>(float)aiAgentExtra('attachment_semantic_prompt','top_p',aiAgentValue($agentKey,'top_p',0.9)),
    ];
    $seed=max(0,(int)aiAgentValue($agentKey,'seed',0));if($seed>0)$infer['seed']=$seed;
    $res=$bedrock->converse(['modelId'=>$modelId,'messages'=>[['role'=>'user','content'=>[['text'=>$prompt]]]],'system'=>[['text'=>$system]],'inferenceConfig'=>$infer]);
    $summary='';foreach(($res['output']['message']['content']??[])as$b)if(isset($b['text']))$summary.=$b['text'];$summary=trim($summary);
    if($summary==='')throw new RuntimeException('La IA no devolvió resumen semántico');
    $in=(int)($res['usage']['inputTokens']??0);$out=(int)($res['usage']['outputTokens']??0);

    $embeddingActive=aiAgentActive('embedding_main',false);$embeddingModel=$embeddingActive?aiAgentModel('embedding_main',''):'';
    if($embeddingActive&&$embeddingModel==='')throw new RuntimeException('embedding_main está activo pero no tiene model_id');

    $db_connection->begin_transaction();
    try{
        $oldIds=[];$q=$db_connection->prepare("SELECT id_ FROM SessionContextBlocks WHERE session_id_=? AND s3_path=? AND block_type='file'");$q->bind_param('is',$sessionId,$s3Key);$q->execute();$rr=$q->get_result();while($x=$rr->fetch_assoc())$oldIds[]=(int)$x['id_'];$q->close();
        if($oldIds){$ph=implode(',',array_fill(0,count($oldIds),'?'));$types=str_repeat('i',count($oldIds));$d=$db_connection->prepare("DELETE FROM EmbeddingJobs WHERE target_type='session_block' AND target_id IN ($ph)");if($d){$d->bind_param($types,...$oldIds);$d->execute();$d->close();}}
        $d=$db_connection->prepare("DELETE FROM SessionContextBlocks WHERE session_id_=? AND s3_path=? AND block_type='file'");$d->bind_param('is',$sessionId,$s3Key);$d->execute();$d->close();
        $meta=json_encode(['filename'=>$file['Nombre'],'files3_id'=>$fileId,'type'=>'semantic_summary','semantic_model'=>$modelId,'semantic_agent'=>$agentKey,'extractor'=>$extractor,'truncated'=>$truncated],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $tokens=(int)ceil(mb_strlen($summary)/4);
        $ins=$db_connection->prepare("INSERT INTO SessionContextBlocks (session_id_,block_type,content_preview,s3_path,is_locked,source_ids,token_count) VALUES (?,'file',?,?,1,?,?)");
        $ins->bind_param('isssi',$sessionId,$summary,$s3Key,$meta,$tokens);if(!$ins->execute())throw new RuntimeException($ins->error);$blockId=(int)$ins->insert_id;$ins->close();
        $queued=false;if($embeddingActive){$j=$db_connection->prepare("INSERT INTO EmbeddingJobs (target_type,target_id,model_id,status,attempts) VALUES ('session_block',?,?,'pending',0) ON DUPLICATE KEY UPDATE status='pending',attempts=0,error_message=NULL,updated_at=NOW()");$j->bind_param('is',$blockId,$embeddingModel);$j->execute();$j->close();$queued=true;}
        $db_connection->commit();
    }catch(Throwable$e){$db_connection->rollback();throw$e;}
    sem_log($db_connection,$sessionId,$modelId,$in,$out);
    jexit(['ok'=>true,'mensaje'=>'Semántica creada con '.$modelId.'.'.($queued?' Embedding encolado con '.$embeddingModel.'.':' embedding_main está desactivado; el resumen quedó sin vectorizar.'),'resumen'=>mb_substr($summary,0,300),'semantic_model'=>$modelId,'semantic_agent'=>$agentKey,'embedding_queued'=>$queued,'embedding_model'=>$embeddingModel?:null,'extractor'=>$extractor]);
}catch(Throwable$e){jexit(['ok'=>false,'error'=>$e->getMessage()],500);}
