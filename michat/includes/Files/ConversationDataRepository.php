<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Memory/DocumentAccessRepository.php';

/** Read-only catalog. Relationships come from chat bindings and generated-file provenance. */
final class ConversationDataRepository
{
    public function __construct(private mysqli $db) {}

    public function listing(int $user,int $sessionId,string $scope='session',int $offset=0): array
    {
        $access=new DocumentAccessRepository($this->db);
        $session=$access->session($user,$sessionId);
        if(!$session)throw new DocumentAccessDenied('Conversación no disponible');
        if(!in_array($scope,['session','project'],true))throw new InvalidArgumentException('Alcance inválido');
        $sessions=[$sessionId=>$session];$project=(int)$session['project_id_'];$warnings=[];
        if($scope==='project'){
            $q=$this->db->prepare("SELECT id_ FROM Projects WHERE id_=? AND user_id_=? AND status<>'deleted'");
            $q->bind_param('ii',$project,$user);$q->execute();$p=$q->get_result()->fetch_assoc();$q->close();
            if(!$p)throw new DocumentAccessDenied('Proyecto no disponible');
            $q=$this->db->prepare("SELECT id_,project_id_,meta,status FROM ChatSessions WHERE project_id_=? AND user_id_=? AND status<>'closed' ORDER BY id_ LIMIT 501");
            $q->bind_param('ii',$project,$user);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
            if(count($rows)>500){$warnings[]='Se muestran hasta 500 conversaciones del proyecto. Abre una conversación concreta para consultar las restantes.';array_pop($rows);}
            $sessions=[];foreach($rows as $row)$sessions[(int)$row['id_']]=$row;
        }
        $files=[];$sources=[];
        foreach($sessions as $sid=>$context){
            $attached=$access->conversationFiles($user,$sid);
            if(count($attached)>=100)$warnings['catalog']='El catálogo de adjuntos conserva su límite actual por conversación; la lista puede ser parcial.';
            foreach($attached as $file){
                $id=(int)$file['id_'];$sources[$id]=true;
                $original=$access->original($file);if($original)$sources[(int)$original['id_']]=true;
                $files[$id]=$this->item($file,'attachment',$sid);
            }
        }
        // Scan only the caller's registered outputs. No S3 listing, extraction or AI invocation.
        $sourceIds=$sources?implode(',',array_map('intval',array_keys($sources))):'0';
        $sessionIds=$sessions?implode(',',array_map('intval',array_keys($sessions))):'0';
        $safeJson="IF(JSON_VALID(Metadatos),Metadatos,'{}')";
        $q=$this->db->prepare("SELECT * FROM FileS3 WHERE user_id_=? AND Found=1 AND (
            JSON_UNQUOTE(JSON_EXTRACT($safeJson,'$.michat_generated.source_file_id')) IN ($sourceIds)
            OR JSON_UNQUOTE(JSON_EXTRACT($safeJson,'$.michat_generated.session_id')) IN ($sessionIds)) ORDER BY id_");
        $q->bind_param('i',$user);$q->execute();$result=$q->get_result();
        while($file=$result->fetch_assoc()){
            $ref=(json_decode((string)$file['Metadatos'],true)?:[])['michat_generated']??[];
            if(($ref['format']??'')!=='michat.generated-file'||($ref['version']??0)!==1||(int)($ref['owner_id']??0)!==$user)continue;
            $id=(int)$file['id_'];$sid=(int)($ref['session_id']??0);$kind=$ref['role']??'';
            if($kind==='derivative'){
                if(!isset($sources[(int)($ref['source_file_id']??0)]))continue;
            }elseif($kind==='file'){
                if(!isset($sessions[$sid])||DocumentAccessRepository::detached($sessions[$sid],$id))continue;
            }else continue;
            if(!$access->file($user,$id))continue;
            if(!isset($files[$id]))$files[$id]=$this->item($file,$kind==='derivative'?'result':'generated',$sid);
        }
        $q->close();ksort($files,SORT_NUMERIC);$all=array_values($files);$offset=max(0,$offset);
        return ['ok'=>true,'session_id'=>$sessionId,'project_id'=>$project?:null,'scope'=>$scope,'total'=>count($all),
            'files'=>array_slice($all,$offset,50),'next_offset'=>$offset+50<count($all)?$offset+50:null,'warnings'=>array_values(array_unique($warnings))];
    }

    public function requireFile(int $user,int $session,string $scope,int $id): array
    {
        // Recompute live bindings at delivery time; the modal's cached list grants nothing.
        $offset=0;
        do{$page=$this->listing($user,$session,$scope,$offset);
            foreach($page['files'] as $file)if($file['id']===$id)return $file;
            $offset=$page['next_offset'];
        }while($offset!==null);
        throw new DocumentAccessDenied('Archivo fuera del alcance o acceso revocado');
    }

    private function item(array $file,string $role,int $session): array
    {
        $ref=(json_decode((string)$file['Metadatos'],true)?:[])['michat_generated']??[];
        return ['id'=>(int)$file['id_'],'name'=>(string)$file['Nombre'],'key'=>idx_build_s3_key($file),'size_bytes'=>(int)$file['Tamano'],
            'role'=>$role,'kind'=>$ref['kind']??null,'source_file_id'=>$ref['source_file_id']??null,
            'session_id'=>($file['knowledge_origin']??'')==='project'?null:($session?:null),'origin'=>$file['knowledge_origin']??'session'];
    }
}
