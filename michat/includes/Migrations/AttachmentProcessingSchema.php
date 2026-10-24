<?php
declare(strict_types=1);
require_once __DIR__.'/SchemaMigrationRepository.php';
require_once __DIR__.'/SqlMigrationExecutor.php';

/** Only the two additive fase14 tables; never runs historical migrations or alters existing data. */
final class AttachmentProcessingSchema
{
    private const TABLES=[
        'AttachmentProcessingJobs'=>'id_,user_id_,session_id_,files3_id,revision_hash,source_etag,phase,status,checkpoint_json,error_message,attempts,available_at,trace_id,created_at,updated_at',
        'AttachmentProcessingCosts'=>'id_,operation_key,job_id,user_id_,session_id_,files3_id,service,operation,units_json,estimated_cost_usd,pricing_state,price_source,outcome,created_at',
    ];
    public function __construct(private mysqli $db){}
    public function ready():bool
    {
        foreach(self::TABLES as $table=>$columns){
            try{if($this->db->query("SELECT $columns FROM `$table` LIMIT 0")===false)return false;}
            catch(mysqli_sql_exception $e){if(in_array($e->getCode(),[1146,1054],true))return false;throw $e;}
        }
        return true;
    }
    /** @return array{ready:bool,created:list<string>} */
    public function ensure(bool $verifyExisting=false,bool $lockAlreadyHeld=false):array
    {
        if(!$verifyExisting&&$this->ready())return ['ready'=>true,'created'=>[]];
        $database=(string)$this->db->query('SELECT DATABASE() n')->fetch_assoc()['n'];
        if($database==='')throw new RuntimeException('No hay base de datos seleccionada');
        $repository=new SchemaMigrationRepository($this->db,$database);
        if(!$lockAlreadyHeld)$repository->acquireLock(10);
        try{
            $path=dirname(__DIR__,2).'/sql/fase14_attachment_processing.sql';
            $sql=(string)file_get_contents($path);$checksum=hash('sha256',$sql);
            $history=$repository->fetchHistory();$record=$history['fase14_attachment_processing']??null;
            if($record&&($record['filename']!=='fase14_attachment_processing.sql'||!hash_equals($record['checksum_sha256'],$checksum)))throw new RuntimeException('DRIFT: fase14_attachment_processing; no se modificaron tablas');
            $definitions=[];
            foreach(SqlMigrationExecutor::splitStatements($sql) as $statement){
                $statement=trim((string)preg_replace('/^\s*--[^\n]*(?:\n|$)/m','',$statement));
                if(!preg_match('/^CREATE TABLE (AttachmentProcessingJobs|AttachmentProcessingCosts)\s*\(/D',$statement,$m)||isset($definitions[$m[1]]))throw new RuntimeException('Definición aditiva de fase14 inválida');
                $definitions[$m[1]]=$statement;
            }
            if(array_keys($definitions)!==array_keys(self::TABLES))throw new RuntimeException('Tablas de fase14 no autorizadas');
            // Validate all present structures before creating any missing table.
            $present=[];
            foreach($definitions as $table=>$definition){
                $present[$table]=$this->exists($table);
                if($present[$table])$this->verify($table,$definition);
                elseif($record)throw new RuntimeException('El historial registra fase14 pero falta '.$table.'; se requiere revisar la integridad de la BD');
            }
            $created=[];
            foreach($definitions as $table=>$definition)if(!$present[$table]){
                if($this->db->query($definition)===false)throw new RuntimeException('No se pudo crear '.$table);
                $this->verify($table,$definition);$created[]=$table;
            }
            // Preserve installations with no initialized migration history: never fake a historical baseline.
            if(!$record&&$history!==[])$repository->insertHistory(['migration_id'=>'fase14_attachment_processing','filename'=>'fase14_attachment_processing.sql','checksum_sha256'=>$checksum],0,'applied','fase14-auto-additive-v1');
            return ['ready'=>true,'created'=>$created];
        }finally{if(!$lockAlreadyHeld)$repository->releaseLock();}
    }
    private function exists(string $table):bool
    {
        $q=$this->db->prepare('SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->bind_param('s',$table);$q->execute();$yes=(int)$q->get_result()->fetch_assoc()['n']===1;$q->close();return $yes;
    }
    private function verify(string $table,string $definition):void
    {
        $temporary='michat_f14_expected_'.bin2hex(random_bytes(6));
        $sql=preg_replace('/^CREATE TABLE '.preg_quote($table,'/').'\b/','CREATE TEMPORARY TABLE `'.$temporary.'`',$definition,1);
        $this->db->query($sql);
        try{
            $columns=fn(string $name)=>$this->db->query("SHOW FULL COLUMNS FROM `$name`")->fetch_all(MYSQLI_ASSOC);
            $indexes=function(string $name):array{
                $rows=$this->db->query("SHOW INDEX FROM `$name`")->fetch_all(MYSQLI_ASSOC);$out=[];
                foreach($rows as $row){$key=$row['Key_name'].':'.$row['Seq_in_index'];$out[$key]=array_intersect_key($row,array_flip(['Key_name','Non_unique','Seq_in_index','Column_name','Sub_part','Index_type','Collation']));}
                ksort($out);return $out;
            };
            // Privileges in SHOW FULL COLUMNS depend on grants, not on schema identity.
            $normalize=static function(array $rows):array{
                foreach($rows as &$row){
                    unset($row['Privileges']);
                    // MySQL exposes DEFAULT_GENERATED differently for persistent and temporary tables.
                    // The actual default value and ON UPDATE expression are still compared separately.
                    $row['Extra']=strtoupper(trim((string)$row['Extra']))==='NULL'?'':$row['Extra'];
                    $row['Extra']=trim((string)preg_replace('/\s+/',' ',str_replace('DEFAULT_GENERATED','',(string)$row['Extra'])));
                }
                return $rows;
            };
            $actual=$normalize($columns($table));$expected=$normalize($columns($temporary));
            if($actual!==$expected){
                $position=0;while(isset($actual[$position],$expected[$position])&&$actual[$position]===$expected[$position])$position++;
                throw new RuntimeException('Esquema incompatible de '.$table.'; columna '.json_encode(['actual'=>$actual[$position]??null,'expected'=>$expected[$position]??null]).'; no se modificó la estructura existente');
            }
            if($indexes($table)!==$indexes($temporary))throw new RuntimeException('Índices incompatibles de '.$table.'; no se modificó la estructura existente');
            $q=$this->db->prepare('SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->bind_param('s',$table);$q->execute();$row=$q->get_result()->fetch_assoc();$q->close();
            if(($row['ENGINE']??'')!=='InnoDB'||($row['TABLE_COLLATION']??'')!=='utf8mb4_unicode_ci')throw new RuntimeException('Motor o cotejamiento incompatible de '.$table);
        }finally{$this->db->query("DROP TEMPORARY TABLE IF EXISTS `$temporary`");}
    }
}
