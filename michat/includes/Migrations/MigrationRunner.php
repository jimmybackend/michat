<?php
declare(strict_types=1);

final class MigrationRunner
{
    public const VERSION='fase12b6-v1';

    public function __construct(
        private MigrationCatalog $catalog,
        private SchemaMigrationRepository $repository,
        private SqlMigrationExecutor $executor
    ) {}

    /** @return array{global:string,rows:list<array<string,string>>,ok:bool} */
    public function status(): array
    {
        try {$migrations=$this->catalog->all();}
        catch(Throwable $e){return ['global'=>'INVALID CATALOG','rows'=>[['state'=>'INVALID CATALOG','detail'=>$e->getMessage()]],'ok'=>false];}
        try {$this->repository->acquireLock(0);}
        catch(Throwable $e){return ['global'=>'LOCKED','rows'=>[['state'=>'LOCKED','detail'=>$e->getMessage()]],'ok'=>false];}
        try {
            if(!$this->repository->historyTableExists())return ['global'=>'UNINITIALIZED','rows'=>array_map(fn($m)=>['migration_id'=>$m['migration_id'],'filename'=>$m['filename'],'state'=>'PENDING'],$migrations),'ok'=>true];
            return $this->classify($migrations,$this->repository->fetchHistory());
        } finally {$this->repository->releaseLock();}
    }

    /** @return list<string> */
    public function apply(): array
    {
        $this->repository->acquireLock(10);
        try {
            $migrations=$this->catalog->all();
            $this->repository->ensureHistoryTable();
            $status=$this->classify($migrations,$this->repository->fetchHistory());
            if(!$status['ok'])throw new RuntimeException($this->classificationError($status));
            $applied=[];
            foreach($status['rows'] as $index=>$row){
                if($row['state']!=='PENDING')continue;$migration=$migrations[$index];$start=hrtime(true);
                $this->assertPendingPreState($migration['migration_id']);
                try {$this->executor->executeFile($migration['path']);}
                catch(Throwable $e){throw new RuntimeException('MIGRATION FAILED / PARTIAL OR UNKNOWN STATE: '.$migration['migration_id'].': '.$e->getMessage(),0,$e);}
                $elapsed=max(0,(int)round((hrtime(true)-$start)/1_000_000));
                try {$this->repository->insertHistory($migration,$elapsed,'applied',self::VERSION);}
                catch(Throwable $e){throw new RuntimeException('HISTORY WRITE FAILED: POST-STATE WITHOUT HISTORY; OPERATOR RECONCILIATION REQUIRED for '.$migration['migration_id'],0,$e);}
                $applied[]=$migration['migration_id'];
            }
            return $applied;
        } finally {$this->repository->releaseLock();}
    }

    public function adoptExisting(string $profile): void
    {
        if($profile!=='post-fase10d')throw new InvalidArgumentException('Unknown adoption profile');
        $this->recordProfile($profile,4,'adopted');
    }

    public function baseline(string $profile): void
    {
        if($profile!=='current-dump')throw new InvalidArgumentException('Unknown baseline profile');
        $this->recordProfile($profile,14,'clean_baseline');
    }

    private function recordProfile(string $profile,int $count,string $mode):void
    {
        $this->repository->acquireLock(10);
        try {
            $migrations=$this->catalog->all();
            if($this->repository->historyTableExists()&&$this->repository->fetchHistory()!==[])throw new RuntimeException('PROFILE REFUSED: migration history is not empty');
            $this->verifyProfile($profile);
            $this->repository->ensureHistoryTable();
            $db=$this->repository->db();$db->begin_transaction();
            try {for($i=0;$i<$count;$i++)$this->repository->insertHistory($migrations[$i],0,$mode,self::VERSION);$db->commit();}
            catch(Throwable $e){$db->rollback();throw $e;}
        } finally {$this->repository->releaseLock();}
    }

    /** @param list<array<string,string>> $migrations @param array<string,array<string,mixed>> $history */
    private function classify(array $migrations,array $history):array
    {
        $rows=[];$known=[];$ok=true;
        foreach($migrations as $migration){$id=$migration['migration_id'];$known[$id]=true;$state='PENDING';
            if(isset($history[$id])){$record=$history[$id];$state=((string)$record['filename']===$migration['filename']&&(string)$record['checksum_sha256']===$migration['checksum_sha256'])?'APPLIED':'DRIFT';if($state==='DRIFT')$ok=false;unset($history[$id]);}
            $rows[]=['migration_id'=>$id,'filename'=>$migration['filename'],'state'=>$state];
        }
        foreach($history as $id=>$record){$rows[]=['migration_id'=>(string)$id,'filename'=>(string)$record['filename'],'state'=>'UNKNOWN'];$ok=false;}
        $global=$ok?(array_filter($rows,fn($r)=>$r['state']==='PENDING')?'PENDING':'APPLIED'):(array_filter($rows,fn($r)=>$r['state']==='DRIFT')?'DRIFT':'UNKNOWN');
        return ['global'=>$global,'rows'=>$rows,'ok'=>$ok];
    }

    private function classificationError(array $status):string
    {
        foreach($status['rows'] as $row)if($row['state']==='DRIFT')return 'DRIFT DETECTED: fail closed';
        return 'UNKNOWN HISTORY: fail closed';
    }

    private function verifyProfile(string $profile):void
    {
        $baseTables=['Tasks','TaskSteps','TaskExecutions','TaskDependencies','TaskEvents','TaskArtifacts','TaskRecurrenceRules','TaskRecurrenceOccurrences'];
        foreach($baseTables as $table)$this->requireTable($table,true);
        $this->requireColumnContains('ToolCalls','tool','code_edit');
        $this->requireColumnContains('TaskArtifacts','tool_call_identity','generated');
        $this->requireIndex('Tasks','uq_tasks_public_id',['public_id'],true);
        $this->requireIndex('TaskArtifacts','uq_task_artifacts_identity',['execution_id_','tool_call_identity','relation','resource_type','resource_id'],true);
        $this->requireIndex('TaskRecurrenceOccurrences','uq_task_recurrence_occurrence',['rule_id_','logical_occurrence_at'],true);
        $this->requireForeignKey('TaskArtifacts','execution_id_','TaskExecutions','id_','CASCADE');
        if($profile==='post-fase10d'){
            foreach(['ProjectAutonomyPolicies','ProjectAutonomyCycles','ProjectAutonomyReservations','NextWorkProposals','ProjectAutonomyCycleTasks','PostTaskContinuations','TaskReplanRequests','TaskPlanRevisions','TaskPlanRevisionSteps'] as $table)$this->requireTable($table,false);
            if($this->columnExists('Tasks','mode'))throw new RuntimeException('PROFILE MISMATCH: Fase 11 Tasks.mode is already present');
            if($this->columnExists('UserAIAgentConfigs','scope')||$this->columnExists('UserAIAgentConfigs','scope_owner_key'))throw new RuntimeException('PROFILE MISMATCH: later GLOBAL/USER AI schema is already present');
            return;
        }
        foreach(['ProjectAutonomyPolicies','ProjectAutonomyCycles','ProjectAutonomyReservations','NextWorkProposals','ProjectAutonomyCycleTasks','PostTaskContinuations','TaskReplanRequests','TaskPlanRevisions','TaskPlanRevisionSteps','UserAIAgentConfigs'] as $table)$this->requireTable($table,true);
        foreach([['NextWorkProposals','decision_accounted'],['PostTaskContinuations','answer'],['PostTaskContinuations','answered_at'],['PostTaskContinuations','answered_by_user_id_'],['TaskReplanRequests','revision_id_'],['Tasks','mode']] as [$table,$column])$this->requireColumnContains($table,$column,'');
        $this->requireColumnContains('UserAIAgentConfigs','scope',"enum('global','user')");
        $this->requireNullable('UserAIAgentConfigs','scope',false);
        $this->requireColumnDefaultAbsent('UserAIAgentConfigs','scope');
        $this->requireColumnContains('UserAIAgentConfigs','scope_owner_key','virtual generated');
        $this->requireNullable('UserAIAgentConfigs','scope_owner_key',false);
        $this->requireNullable('UserAIAgentConfigs','user_id_',true);
        $this->requireIndex('UserAIAgentConfigs','uq_uac_scope_owner_agent',['scope','scope_owner_key','agent_key'],true);
        $this->requireForeignKey('UserAIAgentConfigs','user_id_','Users','id','CASCADE');
        $this->requireConstraintAbsent('UserAIAgentConfigs','chk_uac_scope_owner');
        $this->requireColumnContains('ProjectAutonomyCycles','active_project_id_','virtual generated');
        $this->requireColumnContains('Users','system_role',"enum('user','admin','superadmin')");
        $this->requireNullable('Users','system_role',false);
        $this->requireColumnDefaultEquals('Users','system_role','user');
        $this->requireGlobalAgent('task_planner','amazon.nova-pro-v1:0');
        $this->requireIndex('ProjectAutonomyCycles','uq_project_autonomy_cycle_active',['active_project_id_'],true);
        $this->requireIndex('NextWorkProposals','uq_next_work_proposal_dedupe',['autonomy_cycle_id_','dedupe_key'],true);
        $this->requireIndex('PostTaskContinuations','uq_post_task_continuation_logical',['autonomy_cycle_id_','source_task_id_'],true);
        $this->requireIndex('TaskPlanRevisions','uq_task_plan_revision_number',['task_id_','revision_number'],true);
        $this->requireForeignKey('PostTaskContinuations','answered_by_user_id_','Users','id','RESTRICT');
    }

    private function requireTable(string $table,bool $present):void
    {if($this->tableExists($table)!==$present)throw new RuntimeException('PROFILE MISMATCH: table '.$table.' presence is invalid');}
    private function tableExists(string $table):bool
    {$s=$this->repository->db()->prepare('SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND TABLE_TYPE=\'BASE TABLE\'');$s->bind_param('s',$table);$s->execute();$actual=(int)$s->get_result()->fetch_assoc()['c']===1;$s->close();return$actual;}
    private function columnExists(string $table,string $column):bool
    {$s=$this->repository->db()->prepare('SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$s->bind_param('ss',$table,$column);$s->execute();$v=(int)$s->get_result()->fetch_assoc()['c']===1;$s->close();return$v;}
    private function requireColumnContains(string $table,string $column,string $needle):void
    {$s=$this->repository->db()->prepare('SELECT COLUMN_TYPE,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$s->bind_param('ss',$table,$column);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();$text=strtolower(implode(' ',array_values($row?:[])));if(!$row||($needle!==''&&!str_contains($text,strtolower($needle))))throw new RuntimeException("PROFILE MISMATCH: column {$table}.{$column}");}
    private function requireNullable(string $table,string $column,bool $nullable):void
    {$s=$this->repository->db()->prepare('SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$s->bind_param('ss',$table,$column);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();if(!$row||(($row['IS_NULLABLE']==='YES')!==$nullable))throw new RuntimeException("PROFILE MISMATCH: nullability {$table}.{$column}");}
    private function requireColumnDefaultAbsent(string $table,string $column):void
    {$s=$this->repository->db()->prepare('SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$s->bind_param('ss',$table,$column);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();if(!$row||$row['COLUMN_DEFAULT']!==null)throw new RuntimeException("PROFILE MISMATCH: default {$table}.{$column}");}
    private function requireIndex(string $table,string $name,array $columns,bool $unique):void
    {$s=$this->repository->db()->prepare('SELECT NON_UNIQUE,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX');$s->bind_param('ss',$table,$name);$s->execute();$r=$s->get_result();$actual=[];$nonUnique=null;while($row=$r->fetch_assoc()){$actual[]=$row['COLUMN_NAME'];$nonUnique=(int)$row['NON_UNIQUE'];}$s->close();if($actual!==$columns||($unique&&$nonUnique!==0))throw new RuntimeException("PROFILE MISMATCH: index {$table}.{$name}");}
    private function requireForeignKey(string $table,string $column,string $refTable,string $refColumn,string $deleteRule):void
    {$s=$this->repository->db()->prepare('SELECT COUNT(*) c FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.COLUMN_NAME=? AND k.REFERENCED_TABLE_NAME=? AND k.REFERENCED_COLUMN_NAME=? AND r.DELETE_RULE=?');$s->bind_param('sssss',$table,$column,$refTable,$refColumn,$deleteRule);$s->execute();$v=(int)$s->get_result()->fetch_assoc()['c'];$s->close();if($v!==1)throw new RuntimeException("PROFILE MISMATCH: foreign key {$table}.{$column}");}
    private function requireConstraint(string $table,string $name,string $type):void
    {$s=$this->repository->db()->prepare('SELECT COUNT(*) c FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=? AND CONSTRAINT_TYPE=?');$s->bind_param('sss',$table,$name,$type);$s->execute();$v=(int)$s->get_result()->fetch_assoc()['c'];$s->close();if($v!==1)throw new RuntimeException("PROFILE MISMATCH: constraint {$table}.{$name}");}
    private function requireConstraintAbsent(string $table,string $name):void
    {$s=$this->repository->db()->prepare('SELECT COUNT(*) c FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');$s->bind_param('ss',$table,$name);$s->execute();$v=(int)$s->get_result()->fetch_assoc()['c'];$s->close();if($v!==0)throw new RuntimeException("PROFILE MISMATCH: unexpected constraint {$table}.{$name}");}
    private function requireColumnDefaultEquals(string $table,string $column,string $expected):void
    {$s=$this->repository->db()->prepare('SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$s->bind_param('ss',$table,$column);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();if(!$row||(string)$row['COLUMN_DEFAULT']!==$expected)throw new RuntimeException("PROFILE MISMATCH: default {$table}.{$column}");}
    private function requireGlobalAgent(string $agentKey,string $modelId):void
    {$s=$this->repository->db()->prepare("SELECT system_instruction FROM UserAIAgentConfigs WHERE scope='global' AND user_id_ IS NULL AND agent_key=? AND model_id=? AND is_active=1 LIMIT 1");$s->bind_param('ss',$agentKey,$modelId);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();if(!$row||str_contains((string)$row['system_instruction'],'plan, model'))throw new RuntimeException("PROFILE MISMATCH: GLOBAL agent {$agentKey}");}

    private function assertPendingPreState(string $id):void
    {
        $postStatePresent=match($id){
            'fase8_1_task_orchestrator'=>array_reduce(['Tasks','TaskSteps','TaskExecutions','TaskDependencies','TaskEvents'],fn($found,$table)=>$found||$this->tableExists($table),false),
            'fase8_6d_3d_toolcalls_code_edit'=>$this->columnContains('ToolCalls','tool','code_edit'),
            'fase8_7b_task_artifacts'=>$this->tableExists('TaskArtifacts'),
            'fase10d_task_recurrence'=>$this->tableExists('TaskRecurrenceRules')||$this->tableExists('TaskRecurrenceOccurrences'),
            'fase11b_project_autonomy'=>array_reduce(['ProjectAutonomyPolicies','ProjectAutonomyCycles','ProjectAutonomyReservations'],fn($found,$table)=>$found||$this->tableExists($table),false),
            'fase11c_next_work_proposals'=>$this->tableExists('NextWorkProposals'),
            'fase11d_post_task_continuations'=>$this->columnExists('NextWorkProposals','decision_accounted')||$this->tableExists('ProjectAutonomyCycleTasks')||$this->tableExists('PostTaskContinuations'),
            'fase11e0_replan_checkpoint'=>$this->tableExists('TaskReplanRequests'),
            'fase11e1_versioned_replanning'=>$this->columnExists('Tasks','mode')||$this->columnExists('TaskReplanRequests','revision_id_')||$this->tableExists('TaskPlanRevisions')||$this->tableExists('TaskPlanRevisionSteps'),
            'fase11f2_hitl_controls'=>$this->columnExists('PostTaskContinuations','answer')||$this->columnExists('PostTaskContinuations','answered_by_user_id_'),
            'fase12b_2c_global_ai_configuration_scope'=>$this->columnExists('UserAIAgentConfigs','scope')||$this->columnExists('UserAIAgentConfigs','scope_owner_key'),
            'fase12b_4_ai_scope_default_reconciliation'=>false,
            'fase12b_5_mysql_generated_column_compatibility'=>false,
            'fase12b_6_system_role_authorization'=>false,
            default=>true,
        };
        if($postStatePresent)throw new RuntimeException('PARTIAL/UNKNOWN STATE: pending migration has target structures; OPERATOR RECONCILIATION REQUIRED for '.$id);
    }

    private function columnContains(string $table,string $column,string $needle):bool
    {$s=$this->repository->db()->prepare('SELECT COLUMN_TYPE,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$s->bind_param('ss',$table,$column);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();return$row!==null&&str_contains(strtolower(implode(' ',array_values($row))),strtolower($needle));}
}
