<?php
declare(strict_types=1);
require_once __DIR__.'/DriveAnalysisService.php';
require_once dirname(__DIR__).'/Memory/AttachmentEvidence.php';

/** Deterministic request planning; attaching never invokes this content path. */
final class AttachmentRequestService
{
    public function __construct(private mysqli $db) {}

    public static function plan(string $query,array $files,int $lastRequested=0): array
    {
        $named=array_values(array_filter($files,static fn($f)=>mb_stripos($query,(string)$f['Nombre'])!==false));
        $question=(bool)preg_match('/[¿?]|\b(?:cu[aá]l|cu[aá]nt[oa]s?|qui[eé]n|d[oó]nde|c[oó]mo|qu[eé])\b/iu',$query);
        $action=(bool)preg_match('/transcri|describe|anal[ií]z|exp[líi]c|res[uú]m|qu[eé] (?:dice|hace|contiene)|de qu[eé] trata|busc|grep|encuentra|localiz|lista|clases|m[eé]todos|funciones|compar|diferenci|revisa|corrig|errores|mejoras|refactor/iu',$query);
        $metadata=(bool)preg_match('/(?:cu[aá]l|qu[eé]|d[oó]nde|dime|muestra).*(?:nombre|tama[ñn]o|ruta|fecha|tipo|ubicaci[oó]n)/iu',$query)&&!$action;
        $reference=(bool)preg_match('/audio|video|imagen|archivo|documento|adjunto|c[oó]digo|pdf|excel|presentaci|este|ese|sus? |tiene|hace|[aá]l?o\b/iu',$query);
        if(!$action&&!$metadata&&!($question&&($named||count($files)===1))) return ['requested'=>false,'files'=>[],'strategy'=>'none'];
        if(!$named&&preg_match('/[^\s"“”]+\.[a-z][a-z0-9]{0,7}\b/iu',$query)) return ['requested'=>true,'files'=>[],'strategy'=>'clarify','message'=>'El archivo solicitado no está entre los adjuntos accesibles de esta conversación. Comprueba su nombre y permisos; no se procesó otro archivo.'];
        $targets=$named;
        if(!$targets&&$reference&&count($files)===1) $targets=$files;
        if(!$targets&&preg_match('/compar|ambos|todos (?:los )?(?:archivos|adjuntos|documentos)/iu',$query)) $targets=$files;
        if(!$targets&&$lastRequested&&preg_match('/(?:sus? |este|ese|tiene|hace|mejorarlo|corr[ií]gelo|res[uú]melo)/iu',$query)) {
            $targets=array_values(array_filter($files,static fn($f)=>(int)$f['id_']===$lastRequested));
        }
        if(!$targets) return ['requested'=>(bool)($reference&&$files),'files'=>[],'strategy'=>'clarify',
            'message'=>'Indica el nombre del archivo que quieres consultar. Hay varios adjuntos o la referencia no identifica uno; no se procesó contenido.'];
        if(count($targets)>8) return ['requested'=>true,'files'=>[],'strategy'=>'clarify','message'=>'Selecciona hasta ocho archivos por petición; no se procesó contenido.'];
        // Choose from request verbs, not words inside quoted filenames/terms.
        // “dónde se encuentra cada dato” is a location clause in an explanation,
        // not the imperative “encuentra TEXT”. Never grep the prompt itself.
        $intent=$query;
        foreach($files as $file) $intent=str_ireplace((string)$file['Nombre'],'',$intent);
        $intent=(string)preg_replace('/["“][^"”]*["”]|\x27[^\x27]*\x27/u','',$intent);
        $intent=(string)preg_replace('/\bse\s+encuentra\b/iu','se ubica',$intent);
        $reviewIntent=(bool)preg_match('/\bbusca(?:r)?\s+(?:errores|fallos|problemas|vulnerabilidades|contradicciones)\b/iu',$intent);
        $literal=!$reviewIntent&&(bool)preg_match('/\b(?:grep|busca(?:r)?|localiza(?:r)?)\b|(?<!se )\bencuentra\b|b[uú]squeda literal/iu',$intent);
        $strategy=$metadata?'metadata':($literal?'literal':(preg_match('/\blista\b|\bclases\b|m[eé]todos|\bfunciones\b/iu',$intent)?'structure':($reviewIntent||preg_match('/completo|todo el|exhaustiv|cada (?:l[ií]nea|p[aá]gina)|compar|revisa|errores|refactor/iu',$intent)?'review':'overview')));
        $term='';
        if($strategy==='literal') {
            preg_match_all('/["“]([^"”]+)["”]|\x27([^\x27]+)\x27/u',$query,$quotes,PREG_SET_ORDER);
            $names=array_map(static fn($f)=>mb_strtolower((string)$f['Nombre']),$files);
            foreach($quotes as $quote) { $value=$quote[1]!==''?$quote[1]:($quote[2]??'');if(!in_array(mb_strtolower($value),$names,true)){$term=$value;break;} }
            if($term===''&&preg_match('/(?:grep|busca(?:r)?|encuentra|localiza)\s+(?:la funci[oó]n\s+|el texto\s+|la palabra\s+)?(.+?)(?:\s+en\s+(?:el|los|este|ese|["“])|$)/iu',$query,$match)) $term=trim($match[1]," \t\n\r\0\x0B.?!\"'");
            if($term===''||mb_strlen($term)>200) return ['requested'=>true,'files'=>[],'strategy'=>'clarify','message'=>'Escribe entre comillas el texto literal que quieres buscar (hasta 200 caracteres). No se procesó contenido.'];
        }
        return ['requested'=>true,'files'=>$targets,'strategy'=>$strategy,'term'=>$term];
    }

    public function prepare(int $user,int $session,string $query,array $sessionMeta,?callable $progress=null): array
    {
        $access=new DocumentAccessRepository($this->db);
        $files=$access->conversationFiles($user,$session);
        $plan=self::plan($query,$files,(int)($sessionMeta['last_requested_attachment_id']??0));
        $result=['items'=>[],'telemetry'=>['requested'=>$plan['requested'],'strategy'=>$plan['strategy'],'files'=>[],'message'=>$plan['message']??'']];
        if(!$plan['requested']) return $result;
        self::notify($progress,'plan','completed','Acción elegida: '.(['literal'=>'búsqueda literal','structure'=>'estructura del código','overview'=>'explicación general','review'=>'revisión del contenido','metadata'=>'consulta de metadatos','clarify'=>'identificar el archivo'][$plan['strategy']]??$plan['strategy']),['strategy'=>$plan['strategy'],'files'=>array_column($plan['files'],'Nombre')]);
        if(!$plan['files']) {self::notify($progress,'clarification','waiting','La petición necesita identificar el archivo',['message'=>$plan['message']??'']);return $result;}
        $queryVector=null;
        $budget=48000;$chars=0;$fileBudget=intdiv($budget,max(1,count($plan['files'])));
        foreach($plan['files'] as $target) {
            $id=(int)$target['id_'];
            if($plan['strategy']==='metadata') {
                self::notify($progress,'file_'.$id.'_metadata','completed','Metadatos del archivo disponibles',['filename'=>$target['Nombre']]);
                $result['telemetry']['message'].="\n".json_encode(array_intersect_key($target,array_flip(['id_','Nombre','Ruta','Tamano','Fecha','knowledge_origin','knowledge_project_id'])),JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
                continue;
            }
            $complexReady=false;
            require_once __DIR__.'/AttachmentProcessingService.php';
            if(AttachmentProcessingService::complex($target['Nombre'])){
                $processing=new AttachmentProcessingService($this->db);
                if(!$processing->available()&&AttachmentProcessingService::media($target['Nombre']))throw new RuntimeException('La transcripción requiere aplicar fase14_attachment_processing; no se intentó leer el audio como texto.');
                if($processing->available()){
                    try{$job=$processing->enqueue($user,$session,$id);}
                    catch(Throwable $e){$failure=FileKnowledgeState::reportFailure($e,'source_verification',['user_id'=>$user,'session_id'=>$session,'file_id'=>$id]);$result['telemetry']['message'].="\n".$target['Nombre'].': '.$failure['message'];continue;}
                    if(!in_array($job['status'],['ready','partial'],true)){
                        $result['telemetry']['message'].="\n".$target['Nombre'].': tarea #'.$job['id_'].' — '.AttachmentProcessingService::label($job['phase']).'. La respuesta espera el resultado; no hay que reenviar el archivo.';
                        $result['telemetry']['processing_jobs'][]=AttachmentProcessingService::publicJob($job);continue;
                    }
                    $complexReady=true;
                }
            }
            $fileProgress=static function(string $key,string $status,string $title,array $details=[])use($progress,$id,$target){self::notify($progress,'file_'.$id.'_'.$key,$status,$title,['file_id'=>$id,'filename'=>$target['Nombre']]+$details);};
            $prepared=DriveAnalysisService::production($this->db)->analyze($user,$session,$id,false,false,$fileProgress);
            if(empty($prepared['ok'])) {$result['telemetry']['message'].="\n".$target['Nombre'].': '.$prepared['error'];continue;}
            $file=$access->requireFile($user,$session,$id);$source=(new AnalysisSidecarStore(Config::getS3(),Config::getBucket()))->identity($file);
            $q=$this->db->prepare("SELECT id_,content_preview,source_ids FROM SessionContextBlocks WHERE session_id_=? AND s3_path=? AND block_type='file_chunk' ORDER BY id_");
            $q->bind_param('is',$session,$source['key']);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
            $rows=array_values(array_filter($rows,static function($r)use($id,$source){$m=json_decode($r['source_ids'],true)?:[];return (int)($m['files3_id']??0)===$id&&($m['source_etag']??'')===$source['etag'];}));
            $fileProgress('selecting','started','Ejecutando la acción sobre el contenido',['strategy'=>$plan['strategy'],'extracted_chunks'=>count($rows)]);
            $selection=self::select($rows,$plan['strategy'],$plan['term']??'',strtolower(pathinfo($file['Nombre'],PATHINFO_EXTENSION)));
            if($complexReady&&$plan['strategy']==='overview'&&preg_match('/sobre|acerca|importe|cu[aá]nt|celda|f[oó]rmula|referencia/iu',$query)){
                if($queryVector===null){$queryVector=[];if(function_exists('generateTitanEmbedding')&&class_exists('QueryEmbeddingProvider'))$queryVector=(new QueryEmbeddingProvider($this->db,Config::getBedrockRuntime(),$session,null))->get($query);}
                $found=(new AttachmentContextRepository($this->db,null))->retrieve($user,$session,$query,'rag',$queryVector?:null,null);
                $byId=array_column($rows,null,'id_');$relevant=[];
                foreach($found['items'] as $item)if(($item->metadata['files3_id']??0)===$id&&isset($byId[$item->sourceId]))$relevant[]=$byId[$item->sourceId];
                if($relevant)$selection=['rows'=>$relevant,'matches'=>0,'partial'=>count($relevant)<count($rows),'message'=>'RAG híbrido: '.count($relevant).' fragmentos relevantes de '.count($rows).' extraídos; la respuesta usa esa selección.'];
            }
            $partial=($prepared['coverage']??'')==='partial';$sent=0;$fileChars=0;
            foreach($selection['rows'] as $row) {
                $content=(string)$row['content_preview'];$meta=json_decode($row['source_ids'],true)?:[];
                $citation=AttachmentEvidence::citation($row);
                if($chars+mb_strlen($content)+mb_strlen($citation)>$budget||$fileChars+mb_strlen($content)+mb_strlen($citation)>$fileBudget) break;
                $chars+=mb_strlen($content)+mb_strlen($citation);$fileChars+=mb_strlen($content)+mb_strlen($citation);$sent++;
                $result['items'][]=new ContextItem('SessionContextBlocks',(int)$row['id_'],'file_chunk','session_attachment',$content,1.0,null,
                    ['mode'=>'always','files3_id'=>$id,'source_etag'=>$source['etag'],'filename'=>$file['Nombre'],'citation'=>$citation,'locator'=>$meta['locator']??[],'evidence_kind'=>$meta['evidence_kind']??'document_text','chunk_ordinal'=>(int)($meta['chunk']??0),'chunk_total'=>(int)($meta['total']??0),'content_partial'=>in_array($plan['strategy'],['literal','structure'],true),'knowledge_origin'=>$target['knowledge_origin']??'session','project_id'=>$target['knowledge_project_id']??null,'strategy'=>$plan['strategy'],'literal_no_match'=>$plan['strategy']==='literal'&&$selection['matches']===0,'truncated'=>$partial]);
            }
            // Vision is only invoked after an explicit request. Its existing
            // description is evidence of a generated interpretation, not raw OCR.
            if(!$rows&&!empty($prepared['summary'])) {
                $summary=(string)$prepared['summary'];
                if($chars+mb_strlen($summary)<=$budget) {$chars+=mb_strlen($summary);$sent=1;$result['items'][]=new ContextItem('FileS3',$id,'file','session_attachment',$summary,1.0,null,['mode'=>'always','files3_id'=>$id,'source_etag'=>$source['etag'],'filename'=>$file['Nombre'],'citation'=>$file['Nombre'].' · descripción visual generada; no es texto literal']);}
            }
            $result['telemetry']['files'][]=['filename'=>$file['Nombre'],'strategy'=>$plan['strategy'],'extracted_chunks'=>count($rows),'sent_items'=>$sent,'extraction_partial'=>$partial,'selection_partial'=>$selection['partial']||$sent<count($selection['rows']),'matches'=>$selection['matches']];
            $result['telemetry']['message'].="\n".$file['Nombre'].': '.$selection['message'].($partial?' La extracción es parcial.':'').($sent<count($selection['rows'])?' El presupuesto de contexto omitió resultados; no es una revisión completa.':'');
            $fileProgress('context_ready','completed','Contenido preparado para responder',['strategy'=>$plan['strategy'],'extracted_chunks'=>count($rows),'sent_items'=>$sent,'matches'=>$selection['matches'],'partial'=>$partial||$selection['partial']||$sent<count($selection['rows'])]);
            $q=$this->db->prepare("UPDATE ChatSessions SET meta=JSON_SET(CASE WHEN JSON_VALID(meta) THEN meta ELSE '{}' END,'$.last_requested_attachment_id',?) WHERE id_=? AND user_id_=? AND status='open'");
            $q->bind_param('iii',$id,$session,$user);$q->execute();$q->close();
        }
        return $result;
    }

    private static function notify(?callable $progress,string $key,string $status,string $title,array $details=[]): void
    {
        if(!$progress)return;
        try{$progress($key,$status,$title,$details);}catch(Throwable $e){error_log('ATTACHMENT_PROGRESS '.get_class($e));}
    }

    public static function select(array $rows,string $strategy,string $term,string $ext): array
    {
        $out=[];$matches=0;$partial=false;
        if($strategy==='literal') {
            foreach($rows as $row) {
                $lines=explode("\n",$row['content_preview']);$hits=[];
                foreach($lines as $i=>$line) if(mb_stripos($line,$term)!==false){$matches++;foreach(range(max(0,$i-1),min(count($lines)-1,$i+1)) as $j)$hits[$j]=$lines[$j];}
                if($hits) {ksort($hits);$meta=json_decode($row['source_ids'],true)?:[];$base=(int)($meta['locator']['line_start']??1);$parts=[];foreach($hits as $i=>$line)$parts[]=(isset($meta['locator']['line_start'])?'L'.($base+$i).': ':'').$line;$row['content_preview']=implode("\n",$parts);$out[]=$row;}
            }
            if(!$out&&$rows){$row=$rows[0];$row['content_preview']='Búsqueda literal '.json_encode($term,JSON_UNESCAPED_UNICODE).': cero coincidencias en los fragmentos extraídos. No implica ausencia en contenido no extraído ni en texto partido entre fragmentos.';$out[]=$row;}
            $message='Contenido extraído e índice disponible. Búsqueda literal sobre '.count($rows).' fragmentos extraídos: '.$matches.' líneas coincidentes. No se ejecutó código ni una expresión del usuario.';
        } elseif($strategy==='structure'&&$ext==='php'&&$rows) {
            // Reconstruct original line positions, including lines split across chunks.
            $lines=[];foreach($rows as $row){$meta=json_decode($row['source_ids'],true)?:[];$base=(int)($meta['locator']['line_start']??1);$parts=explode("\n",$row['content_preview']);if($base<1||$base+count($parts)>200002)throw new DocumentProcessingException('structure','invalid_locator','Los localizadores del código exceden el presupuesto seguro; reintenta la extracción.');foreach($parts as $i=>$line){$n=$base+$i;$lines[$n]=($lines[$n]??'').$line;}}
            ksort($lines);$text='';$next=1;foreach($lines as $n=>$line){$text.=str_repeat("\n",max(0,$n-$next)).$line."\n";$next=$n+1;}
            $tokens=token_get_all($text);$symbols=[];
            foreach($tokens as $i=>$token) {
                if(!is_array($token)||!in_array($token[0],[T_CLASS,T_INTERFACE,T_TRAIT,T_ENUM,T_FUNCTION],true))continue;
                for($j=$i+1;$j<count($tokens);$j++){$t=$tokens[$j];if(is_array($t)&&in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT,T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG,T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG],true))continue;if($t==='&')continue;if(is_array($t)&&$t[0]===T_STRING)$symbols[]=['line'=>$token[2],'text'=>(in_array($token[0],[T_CLASS,T_INTERFACE,T_TRAIT,T_ENUM],true)?'Tipo':'Función/método').' '.$t[1].' · línea '.$token[2]];break;}
            }
            $matches=count($symbols);
            foreach($rows as $row) {
                $meta=json_decode($row['source_ids'],true)?:[];$loc=$meta['locator']??[];$local=[];
                foreach($symbols as $i=>$symbol) if($symbol['line']>=(int)($loc['line_start']??1)&&$symbol['line']<=(int)($loc['line_end']??PHP_INT_MAX)){$local[]=$symbol['text'];unset($symbols[$i]);}
                if($local){$row['content_preview']="Estructura PHP obtenida con token_get_all, sin ejecutar el archivo.\n".implode("\n",$local);$out[]=$row;}
            }
            if(!$out){$row=$rows[0];$row['content_preview']='No se encontraron declaraciones con nombre en el texto extraído. No se ejecutó código.';$out[]=$row;}
            $message='Estructura de '.count($rows).' fragmentos: '.$matches.' declaraciones con nombre. Las líneas de cada declaración se indican en el listado.';
        } else {
            $cap=$strategy==='review'?24:8;$count=count($rows);$partial=$count>$cap;
            if($count<=$cap)$out=$rows;
            else for($i=0;$i<$cap;$i++)$out[]=$rows[(int)floor($i*($count-1)/($cap-1))];
            $message=($strategy==='review'?'Revisión':'Vista general').': '.count($out).' de '.$count.' fragmentos enviados.'.($partial?' Es una selección distribuida, no una lectura exhaustiva.':'');
            if($strategy==='structure')$message.=' Para este lenguaje se envían fragmentos; no hay un parser estructural especializado.';
        }
        return ['rows'=>$out,'matches'=>$matches,'partial'=>$partial,'message'=>$message];
    }
}
