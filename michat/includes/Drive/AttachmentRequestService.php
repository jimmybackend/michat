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
        $action=(bool)preg_match('/anal[ií]z|exp[líi]c|res[uú]m|qu[eé] (?:dice|hace|contiene)|de qu[eé] trata|busc|grep|encuentra|localiz|lista|clases|m[eé]todos|funciones|compar|diferenci|revisa|corrig|errores|mejoras|refactor/iu',$query);
        $metadata=(bool)preg_match('/(?:cu[aá]l|qu[eé]|d[oó]nde|dime|muestra).*(?:nombre|tama[ñn]o|ruta|fecha|tipo|ubicaci[oó]n)/iu',$query)&&!$action;
        $reference=(bool)preg_match('/archivo|documento|adjunto|c[oó]digo|pdf|excel|presentaci|este|ese|sus? |tiene|hace|[aá]l?o\b/iu',$query);
        if(!$action&&!$metadata) return ['requested'=>false,'files'=>[],'strategy'=>'none'];
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
        $strategy=$metadata?'metadata':(preg_match('/grep|busca|encuentra|localiz/iu',$query)?'literal':(preg_match('/lista|clases|m[eé]todos|funciones/iu',$query)?'structure':(preg_match('/completo|todo el|exhaustiv|cada (?:l[ií]nea|p[aá]gina)|compar|revisa|errores|refactor/iu',$query)?'review':'overview')));
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

    public function prepare(int $user,int $session,string $query,array $sessionMeta): array
    {
        $ids=array_slice(array_map('intval',(array)($sessionMeta['knowledge_file_ids']??[])),0,100);
        $extra=$ids?' OR id_ IN ('.implode(',',$ids).')':'';$pattern='Data/Chat/Uploads/'.$user.'/%/'.$session.'/';
        $q=$this->db->prepare("SELECT id_,Nombre,Ruta,Encriptado,Tamano,Fecha FROM FileS3 WHERE user_id_=? AND Found=1 AND AccessType<>'secure' AND (Ruta LIKE ?$extra) ORDER BY id_ LIMIT 101");
        $q->bind_param('is',$user,$pattern);$q->execute();$files=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        $access=new DocumentAccessRepository($this->db);
        $files=array_values(array_filter($files,static function($f)use($access,$user,$session){try{$access->requireFile($user,$session,(int)$f['id_']);return true;}catch(Throwable){return false;}}));
        $plan=self::plan($query,$files,(int)($sessionMeta['last_requested_attachment_id']??0));
        $result=['items'=>[],'telemetry'=>['requested'=>$plan['requested'],'strategy'=>$plan['strategy'],'files'=>[],'message'=>$plan['message']??'']];
        if(!$plan['requested']||!$plan['files']) return $result;
        $budget=48000;$chars=0;$fileBudget=intdiv($budget,max(1,count($plan['files'])));
        foreach($plan['files'] as $target) {
            $id=(int)$target['id_'];
            if($plan['strategy']==='metadata') {
                $result['telemetry']['message'].="\n".json_encode($target,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
                continue;
            }
            $prepared=DriveAnalysisService::production($this->db)->analyze($user,$session,$id,false,false);
            if(empty($prepared['ok'])) {$result['telemetry']['message'].="\n".$target['Nombre'].': '.$prepared['error'];continue;}
            $file=$access->requireFile($user,$session,$id);$source=(new AnalysisSidecarStore(Config::getS3(),Config::getBucket()))->identity($file);
            $q=$this->db->prepare("SELECT id_,content_preview,source_ids FROM SessionContextBlocks WHERE session_id_=? AND s3_path=? AND block_type='file_chunk' ORDER BY id_");
            $q->bind_param('is',$session,$source['key']);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
            $rows=array_values(array_filter($rows,static function($r)use($id,$source){$m=json_decode($r['source_ids'],true)?:[];return (int)($m['files3_id']??0)===$id&&($m['source_etag']??'')===$source['etag'];}));
            $selection=self::select($rows,$plan['strategy'],$plan['term']??'',strtolower(pathinfo($file['Nombre'],PATHINFO_EXTENSION)));
            $partial=($prepared['coverage']??'')==='partial';$sent=0;$fileChars=0;
            foreach($selection['rows'] as $row) {
                $content=(string)$row['content_preview'];$meta=json_decode($row['source_ids'],true)?:[];
                $citation=AttachmentEvidence::citation($row);
                if($chars+mb_strlen($content)+mb_strlen($citation)>$budget||$fileChars+mb_strlen($content)+mb_strlen($citation)>$fileBudget) break;
                $chars+=mb_strlen($content)+mb_strlen($citation);$fileChars+=mb_strlen($content)+mb_strlen($citation);$sent++;
                $result['items'][]=new ContextItem('SessionContextBlocks',(int)$row['id_'],'file_chunk','session_attachment',$content,1.0,null,
                    ['mode'=>'always','files3_id'=>$id,'source_etag'=>$source['etag'],'filename'=>$file['Nombre'],'citation'=>$citation,'locator'=>$meta['locator']??[],'strategy'=>$plan['strategy'],'truncated'=>$partial]);
            }
            // Vision is only invoked after an explicit request. Its existing
            // description is evidence of a generated interpretation, not raw OCR.
            if(!$rows&&!empty($prepared['summary'])) {
                $summary=(string)$prepared['summary'];
                if($chars+mb_strlen($summary)<=$budget) {$chars+=mb_strlen($summary);$sent=1;$result['items'][]=new ContextItem('FileS3',$id,'file','session_attachment',$summary,1.0,null,['mode'=>'always','files3_id'=>$id,'source_etag'=>$source['etag'],'filename'=>$file['Nombre'],'citation'=>$file['Nombre'].' · descripción visual generada; no es texto literal']);}
            }
            $result['telemetry']['files'][]=['filename'=>$file['Nombre'],'strategy'=>$plan['strategy'],'extracted_chunks'=>count($rows),'sent_items'=>$sent,'extraction_partial'=>$partial,'selection_partial'=>$selection['partial']||$sent<count($selection['rows']),'matches'=>$selection['matches']];
            $result['telemetry']['message'].="\n".$file['Nombre'].': '.$selection['message'].($partial?' La extracción es parcial.':'').($sent<count($selection['rows'])?' El presupuesto de contexto omitió resultados; no es una revisión completa.':'');
            $q=$this->db->prepare("UPDATE ChatSessions SET meta=JSON_SET(CASE WHEN JSON_VALID(meta) THEN meta ELSE '{}' END,'$.last_requested_attachment_id',?) WHERE id_=? AND user_id_=? AND status='open'");
            $q->bind_param('iii',$id,$session,$user);$q->execute();$q->close();
        }
        return $result;
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
            $message='Búsqueda literal sobre '.count($rows).' fragmentos extraídos: '.$matches.' líneas coincidentes. No se ejecutó código ni una expresión del usuario.';
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
