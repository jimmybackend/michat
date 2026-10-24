<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Memory/DocumentAccessRepository.php';

/** Catalog an object only after its S3 write/completion succeeds. Never guesses the user. */
final class GeneratedFileCatalog
{
    public function __construct(private mysqli $db) {}

    public function derived(array $source,string $key,int $bytes,string $kind): int
    {
        $user=(int)($source['owner_id']??0);$sourceId=(int)($source['file_id']??0);
        $file=(new DocumentAccessRepository($this->db))->file($user,$sourceId);
        if(!$file||idx_build_s3_key($file)!==($source['key']??'')||$key===idx_build_s3_key($file))throw new DocumentAccessDenied('Origen del archivo derivado no autorizado');
        return $this->register($user,$key,$bytes,'application/json',basename($key),[
            'format'=>'michat.generated-file','version'=>1,'role'=>'derivative','kind'=>$kind,
            'owner_id'=>$user,'source_file_id'=>$sourceId,'source_key'=>$source['key'],'source_etag'=>$source['etag']??'',
        ]);
    }

    public function created(int $user,string $key,int $bytes,string $mime,string $name,string $kind,int $session=0): int
    {
        if($session>0&&!(new DocumentAccessRepository($this->db))->session($user,$session))throw new DocumentAccessDenied('Conversación del archivo generado no autorizada');
        $id=$this->register($user,$key,$bytes,$mime,$name,[
            'format'=>'michat.generated-file','version'=>1,'role'=>'file','kind'=>$kind,'owner_id'=>$user,'session_id'=>$session,
        ]);
        $this->folders($user,$key);return $id;
    }

    private function folders(int $user,string $key): void
    {
        $parts=explode('/',$key);array_pop($parts);$prefix='';$parent=null;
        foreach($parts as $name){
            $prefix.=$name.'/';
            $q=$this->db->prepare("INSERT INTO S3Folders(user_id_,Prefix,Nombre,ParentPrefix,Found,AccessType) VALUES(?,?,?,?,1,'normal') ON DUPLICATE KEY UPDATE id_=LAST_INSERT_ID(id_)");
            $q->bind_param('isss',$user,$prefix,$name,$parent);$q->execute();$id=(int)$q->insert_id;$q->close();
            $q=$this->db->prepare('SELECT Prefix FROM S3Folders WHERE id_=? AND user_id_=?');$q->bind_param('ii',$id,$user);$q->execute();$row=$q->get_result()->fetch_assoc();$q->close();
            if(!$row||$row['Prefix']!==$prefix)throw new RuntimeException('Colisión de prefijo en S3Folders; no se truncó la ruta');
            $q=$this->db->prepare('UPDATE S3Folders SET Found=1 WHERE id_=? AND user_id_=?');$q->bind_param('ii',$id,$user);$q->execute();$q->close();
            $parent=$prefix;
        }
    }

    private function register(int $user,string $key,int $bytes,string $mime,string $name,array $provenance): int
    {
        if($user<1||$bytes<0||$key===''||str_starts_with($key,'/')||str_ends_with($key,'/')||preg_match('/[\x00-\x1f\x7f]/',$key))throw new InvalidArgumentException('Identidad de archivo generado inválida');
        $slash=strrpos($key,'/');$path=$slash===false?'':substr($key,0,$slash+1);$base=$slash===false?$key:substr($key,$slash+1);
        // FileS3 supports both full Encriptado keys and Ruta + basename. Never truncate a real key.
        $stored=mb_strlen($key)<=255?$key:$base;
        if(mb_strlen($path)>256||mb_strlen($stored)>255)throw new RuntimeException('La clave generada excede la capacidad actual de Ruta/Encriptado en FileS3; no se truncó');
        if(trim($name)===''||mb_strlen($name)>255)$name=$base;
        if(mb_strlen($name)>255)throw new RuntimeException('El nombre generado excede 255 caracteres');
        $lock='michat_catalog:'.substr(hash('sha256',$user."\0".$key),0,48);
        $q=$this->db->prepare('SELECT GET_LOCK(?,5) held');$q->bind_param('s',$lock);$q->execute();$held=(int)$q->get_result()->fetch_assoc()['held']===1;$q->close();
        if(!$held)throw new RuntimeException('El archivo generado está siendo registrado; reintenta');
        try {
            $q=$this->db->prepare('SELECT id_,user_id_,Nombre,Encriptado,Ruta,Metadatos FROM FileS3 WHERE Encriptado=? OR Encriptado=? OR CONCAT(TRIM(TRAILING "/" FROM Ruta),"/",Encriptado)=?');
            $q->bind_param('sss',$key,$base,$key);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();$row=null;
            foreach($rows as $candidate)if(idx_build_s3_key($candidate)===$key){
                if((int)$candidate['user_id_']!==$user)throw new DocumentAccessDenied('El objeto ya pertenece a otro usuario del catálogo');
                if($row)throw new RuntimeException('Identidad duplicada en FileS3; se requiere revisar el catálogo');$row=$candidate;
            }
            if(!$row){
                $initial=json_encode(['michat_generated'=>$provenance,'mime_type'=>$mime],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
                $q=$this->db->prepare("INSERT INTO FileS3(user_id_,Nombre,Ruta,Encriptado,Tamano,Metadatos,Found,AccessType) VALUES(?,?,?,?,?,?,1,'normal') ON DUPLICATE KEY UPDATE id_=LAST_INSERT_ID(id_)");
                $q->bind_param('isssis',$user,$name,$path,$stored,$bytes,$initial);$q->execute();$id=(int)$q->insert_id;$q->close();
                $q=$this->db->prepare('SELECT id_,Nombre,Encriptado,Ruta,Metadatos FROM FileS3 WHERE id_=? AND user_id_=?');
                $q->bind_param('ii',$id,$user);$q->execute();$row=$q->get_result()->fetch_assoc();$q->close();
                if(!$row||idx_build_s3_key($row)!==$key)throw new RuntimeException('La clave colisiona con otro registro FileS3; no se modificó el registro ajeno');
            }
            $meta=trim((string)($row['Metadatos']??''))===''?[]:json_decode($row['Metadatos'],true,64,JSON_THROW_ON_ERROR);
            if(!is_array($meta)||($meta!==[]&&array_is_list($meta)))throw new RuntimeException('Metadatos existentes incompatibles; no se sobrescribieron');
            $meta['michat_generated']=$provenance;$meta['mime_type']=$mime;
            $json=json_encode($meta,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$id=(int)$row['id_'];
            // Existing visible name, protection and custom metadata survive both retries and sync-created rows.
            $q=$this->db->prepare('UPDATE FileS3 SET Tamano=?,Metadatos=?,Found=1 WHERE id_=? AND user_id_=?');
            $q->bind_param('isii',$bytes,$json,$id,$user);$q->execute();$q->close();return $id;
        } finally {$q=$this->db->prepare('SELECT RELEASE_LOCK(?)');$q->bind_param('s',$lock);$q->execute();$q->close();}
    }
}
