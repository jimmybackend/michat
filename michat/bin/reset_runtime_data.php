<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}

require_once dirname(__DIR__).'/app_bootstrap.php';
require_once dirname(__DIR__).'/includes/Auth/AuthorizationService.php';

const RESET_CONFIRM_TOKEN='RESET_RUNTIME_DATA';
const RESET_PRESERVE=[
    'Users','UserAIAgentConfigs','UserPreferences','UserPipelineFeatures',
    'SchemaMigrations','AccessControl','FileS3','S3Folders',
];

$options=getopt('',['dry-run','confirm','hard','actor-email:','help']);
if(isset($options['help'])){
    fwrite(STDOUT,"Dry run: php michat/bin/reset_runtime_data.php --dry-run\n");
    fwrite(STDOUT,"Destructive development/test reset: APP_ENV=development MICHAT_RESET_CONFIRM=".RESET_CONFIRM_TOKEN." MICHAT_ACTOR_PASSWORD='...' php michat/bin/reset_runtime_data.php --confirm --hard --actor-email=...\n");
    exit(0);
}
$confirm=isset($options['confirm']);
$dryRun=isset($options['dry-run'])||!$confirm;
if($confirm&&isset($options['dry-run'])){fwrite(STDERR,"Choose --dry-run or --confirm, not both.\n");exit(2);}

$identifier=static function(string$name):string{
    if(preg_match('/^[A-Za-z0-9_]+$/D',$name)!==1)throw new RuntimeException('unsafe_table_identifier');
    return chr(96).$name.chr(96);
};

try{
    $schema=(string)$db_connection->query('SELECT DATABASE() db')->fetch_assoc()['db'];
    if($schema==='')throw new RuntimeException('database_not_selected');

    $stmt=$db_connection->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
    $stmt->bind_param('s',$schema);$stmt->execute();
    $tables=array_map(static fn($r)=>(string)$r['TABLE_NAME'],$stmt->get_result()->fetch_all(MYSQLI_ASSOC));$stmt->close();
    $preserve=array_values(array_intersect(RESET_PRESERVE,$tables));
    $reset=array_values(array_diff($tables,$preserve));
    $resetSet=array_fill_keys($reset,true);$preserveSet=array_fill_keys($preserve,true);

    $stmt=$db_connection->prepare(
        "SELECT TABLE_NAME child_table,REFERENCED_TABLE_NAME parent_table
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE CONSTRAINT_SCHEMA=? AND REFERENCED_TABLE_NAME IS NOT NULL"
    );
    $stmt->bind_param('s',$schema);$stmt->execute();$edges=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();

    $incoming=array_fill_keys($reset,0);$parents=[];
    foreach($edges as$edge){
        $child=(string)$edge['child_table'];$parent=(string)$edge['parent_table'];
        if($child===$parent)continue;
        if(isset($preserveSet[$child])&&isset($resetSet[$parent]))throw new RuntimeException("preserved_table_references_reset_table: {$child} -> {$parent}");
        if(isset($resetSet[$child])&&isset($resetSet[$parent])){
            $parents[$child][]=$parent;
            $incoming[$parent]++;
        }
    }
    $queue=[];foreach($incoming as$table=>$count)if($count===0)$queue[]=$table;sort($queue,SORT_STRING);
    $order=[];
    while($queue!==[]){
        $table=array_shift($queue);$order[]=$table;
        foreach($parents[$table]??[] as$parent){
            $incoming[$parent]--;
            if($incoming[$parent]===0){$queue[]=$parent;sort($queue,SORT_STRING);}
        }
    }
    if(count($order)!==count($reset))throw new RuntimeException('foreign_key_cycle_requires_manual_review');

    fwrite(STDOUT,"Database: {$schema}\n");
    fwrite(STDOUT,"Preserved: ".implode(', ',$preserve)."\n");
    fwrite(STDOUT,"Reset order (".count($order)." tables):\n");
    foreach($order as$table)fwrite(STDOUT," - {$table}\n");
    if($dryRun){fwrite(STDOUT,"DRY RUN — no rows changed.\n");exit(0);}

    if(!isset($options['hard']))throw new RuntimeException('confirm_requires_hard_flag');
    $environment=strtolower(trim((string)(getenv('APP_ENV')?:getenv('MICHAT_ENV')?:'')));
    if(!in_array($environment,['development','dev','test','testing'],true))throw new RuntimeException('hard_reset_requires_development_or_test_environment');
    if((string)getenv('MICHAT_RESET_CONFIRM')!==RESET_CONFIRM_TOKEN)throw new RuntimeException('reset_confirmation_token_invalid');

    $actorEmail=trim((string)($options['actor-email']??''));
    $actorPassword=(string)(getenv('MICHAT_ACTOR_PASSWORD')?:'');
    $auth=new AuthorizationService($db_connection);
    $actor=$auth->authenticateActiveUser($actorEmail,$actorPassword);
    $auth->assertAllowed((int)$actor['id'],'system.reset');

    $lock='michat:runtime_reset';
    $lockStmt=$db_connection->prepare('SELECT GET_LOCK(?,10) acquired');$lockStmt->bind_param('s',$lock);$lockStmt->execute();
    $locked=(int)($lockStmt->get_result()->fetch_assoc()['acquired']??0)===1;$lockStmt->close();
    if(!$locked)throw new RuntimeException('runtime_reset_locked');

    try{
        $db_connection->begin_transaction();
        try{
            foreach($order as$table)$db_connection->query('DELETE FROM '.$identifier($table));
            $action='Otro';$ip=null;
            $details=json_encode([
                'event'=>'runtime_data_reset',
                'actor_user_id'=>(int)$actor['id'],
                'environment'=>$environment,
                'deleted_tables'=>$order,
                'preserved_tables'=>$preserve,
            ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $audit=$db_connection->prepare('INSERT INTO AccessControl(user_id,date_time,action,ip_address,action_details) VALUES(?,NOW(),?,?,?)');
            $actorId=(int)$actor['id'];$audit->bind_param('isss',$actorId,$action,$ip,$details);$audit->execute();$audit->close();
            $db_connection->commit();
        }catch(Throwable$e){$db_connection->rollback();throw$e;}
    }finally{
        $unlock=$db_connection->prepare('SELECT RELEASE_LOCK(?)');if($unlock){$unlock->bind_param('s',$lock);$unlock->execute();$unlock->close();}
    }
    fwrite(STDOUT,"Runtime data reset completed and audited.\n");
    exit(0);
}catch(Throwable$e){
    fwrite(STDERR,"Runtime reset failed: ".$e->getMessage()."\n");
    exit(1);
}
