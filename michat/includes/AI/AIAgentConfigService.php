<?php
declare(strict_types=1);

final class AIAgentConfigService
{
    public function __construct(private AIAgentConfigRepository $repository) {}
    public function listGlobals(string $group=''):array{return$this->repository->listGlobals($group);}
    public function saveGlobal(?int$id,array$data):array
    {
        $this->authorizeGlobalWrite();
        $duplicate=$this->repository->findGlobalByKey((string)$data['agent_key']);
        if($duplicate&&($id===null||(int)$duplicate['id_']!==$id))throw new DomainException('agent_key_global_duplicate');
        if($id!==null){if(!$this->repository->findGlobalById($id))throw new OutOfBoundsException('global_agent_not_found');$this->repository->updateGlobal($id,$data);return['action'=>'updated','id_'=>$id];}
        return['action'=>'created','id_'=>$this->repository->insertGlobal($data)];
    }
    public function deleteGlobal(int$id):array{$this->authorizeGlobalWrite();return$this->repository->deleteGlobal($id)??throw new OutOfBoundsException('global_agent_not_found');}
    public function upsertUserOverride(int$userId,string$key,string$modelId,int$active,?array$extra=null):array{return$this->repository->upsertUserOverrideFromGlobal($userId,$key,$modelId,$active,$extra);}
    private function authorizeGlobalWrite():void{if(!ChatIdentity::canManageGlobalAiConfiguration())throw new RuntimeException('global_ai_write_forbidden');}
}
