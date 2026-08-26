<?php
declare(strict_types=1);

final class AIAgentConfigRepository
{
    public const COLUMNS = 'id_, scope, user_id_, agent_key, agent_group, display_name, description, model_id, fallback_model_id, model_ladder_json, system_instruction, user_prompt_template, temperature, max_tokens_prompt, max_tokens_output, top_p, seed, max_attempts, extra_config, token_usage_phase, is_active, sort_order, created_at, updated_at';

    public function __construct(private mysqli $db) {}

    public function listGlobals(string $group = ''): array
    {
        $sql='SELECT '.self::COLUMNS." FROM UserAIAgentConfigs WHERE scope='global' AND user_id_ IS NULL";
        if($group!==''){$sql.=' AND agent_group=?';$s=$this->prepare($sql.' ORDER BY id_');$s->bind_param('s',$group);}else{$s=$this->prepare($sql.' ORDER BY id_');}
        $this->execute($s);$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return$rows;
    }

    public function findGlobalById(int $id): ?array { return $this->one("SELECT ".self::COLUMNS." FROM UserAIAgentConfigs WHERE id_=? AND scope='global' AND user_id_ IS NULL",'i',[$id]); }
    public function findGlobalByKey(string $key): ?array { return $this->one("SELECT ".self::COLUMNS." FROM UserAIAgentConfigs WHERE agent_key=? AND scope='global' AND user_id_ IS NULL",'s',[$key]); }
    public function findUserOverride(int $userId,string $key): ?array { return $this->one("SELECT ".self::COLUMNS." FROM UserAIAgentConfigs WHERE scope='user' AND user_id_=? AND agent_key=?",'is',[$userId,$key]); }

    public function insertGlobal(array $d): int
    {
        $sql="INSERT INTO UserAIAgentConfigs(scope,user_id_,agent_key,agent_group,display_name,description,model_id,fallback_model_id,model_ladder_json,system_instruction,user_prompt_template,temperature,max_tokens_prompt,max_tokens_output,top_p,seed,max_attempts,extra_config,token_usage_phase,is_active,sort_order) VALUES('global',NULL,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $s=$this->prepare($sql);$this->bindConfig($s,$d);$this->execute($s);$id=(int)$this->db->insert_id;$s->close();return$id;
    }

    public function updateGlobal(int $id,array $d): bool
    {
        $sql="UPDATE UserAIAgentConfigs SET agent_key=?,agent_group=?,display_name=?,description=?,model_id=?,fallback_model_id=?,model_ladder_json=?,system_instruction=?,user_prompt_template=?,temperature=?,max_tokens_prompt=?,max_tokens_output=?,top_p=?,seed=?,max_attempts=?,extra_config=?,token_usage_phase=?,is_active=?,sort_order=?,updated_at=CURRENT_TIMESTAMP WHERE id_=? AND scope='global' AND user_id_ IS NULL";
        $s=$this->prepare($sql);$v=$this->configValues($d);$v[]=$id;$s->bind_param('sssssssssdiidiissiii',...$v);$this->execute($s);$ok=$s->affected_rows<=1;$s->close();return$ok;
    }

    public function deleteGlobal(int $id): ?array
    {
        $row=$this->findGlobalById($id);if(!$row)return null;
        $s=$this->prepare("DELETE FROM UserAIAgentConfigs WHERE id_=? AND scope='global' AND user_id_ IS NULL");$s->bind_param('i',$id);$this->execute($s);$ok=$s->affected_rows===1;$s->close();return$ok?$row:null;
    }

    public function upsertUserOverrideFromGlobal(int $userId,string $key,string $modelId,int $active,?array $extraPatch=null): array
    {
        if($userId<=0)throw new InvalidArgumentException('user_id inválido');
        $global=$this->findGlobalByKey($key);if(!$global)throw new RuntimeException("No existe la configuración global '{$key}'.");
        $current=$this->findUserOverride($userId,$key);
        $extraRaw=($current??$global)['extra_config']??null;
        $extraJson=$extraRaw===null?null:(string)$extraRaw;
        if($extraPatch!==null){$extra=json_decode((string)$extraRaw,true);if(!is_array($extra))$extra=[];$extraJson=json_encode(array_replace($extra,$extraPatch),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
        $sql="INSERT INTO UserAIAgentConfigs(scope,user_id_,agent_key,agent_group,display_name,description,model_id,fallback_model_id,model_ladder_json,system_instruction,user_prompt_template,temperature,max_tokens_prompt,max_tokens_output,top_p,seed,max_attempts,extra_config,token_usage_phase,is_active,sort_order) SELECT 'user',?,agent_key,agent_group,display_name,description,?,fallback_model_id,model_ladder_json,system_instruction,user_prompt_template,temperature,max_tokens_prompt,max_tokens_output,top_p,seed,max_attempts,?,token_usage_phase,?,sort_order FROM UserAIAgentConfigs WHERE scope='global' AND user_id_ IS NULL AND agent_key=? ON DUPLICATE KEY UPDATE model_id=VALUES(model_id),extra_config=VALUES(extra_config),is_active=VALUES(is_active),updated_at=CURRENT_TIMESTAMP";
        $s=$this->prepare($sql);$s->bind_param('issis',$userId,$modelId,$extraJson,$active,$key);$this->execute($s);$s->close();
        return $this->findUserOverride($userId,$key)??throw new RuntimeException('No se pudo persistir el override.');
    }

    private function bindConfig(mysqli_stmt $s,array $d):void{$v=$this->configValues($d);$s->bind_param('sssssssssdiidiissii',...$v);}
    private function configValues(array$d):array{return[$d['agent_key'],$d['agent_group'],$d['display_name'],$d['description'],$d['model_id'],$d['fallback_model_id'],$d['model_ladder_json'],$d['system_instruction'],$d['user_prompt_template'],$d['temperature'],$d['max_tokens_prompt'],$d['max_tokens_output'],$d['top_p'],$d['seed'],$d['max_attempts'],$d['extra_config'],$d['token_usage_phase'],$d['is_active'],$d['sort_order']];}
    private function one(string$sql,string$types,array$values):?array{$s=$this->prepare($sql.' LIMIT 1');$s->bind_param($types,...$values);$this->execute($s);$r=$s->get_result()->fetch_assoc();$s->close();return$r?:null;}
    private function prepare(string$sql):mysqli_stmt{$s=$this->db->prepare($sql);if(!$s)throw new RuntimeException('database_error');return$s;}
    private function execute(mysqli_stmt$s):void
    {
        try{$ok=$s->execute();}
        catch(mysqli_sql_exception$e){if($e->getCode()===1062)throw new DomainException('agent_config_duplicate',0,$e);throw$e;}
        if(!$ok){if($s->errno===1062)throw new DomainException('agent_config_duplicate');throw new RuntimeException('database_error: '.$s->error);}
    }
}
