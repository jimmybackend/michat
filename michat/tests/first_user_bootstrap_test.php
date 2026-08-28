<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$auth=(string)file_get_contents($root.'/michat/includes/Auth/AuthorizationService.php');
$profile=(string)file_get_contents($root.'/michat/includes/Admin/InitialUserProfile.php');
$provision=(string)file_get_contents($root.'/michat/includes/Admin/UserProvisioningService.php');
$first=(string)file_get_contents($root.'/michat/bin/create_first_user.php');
$user=(string)file_get_contents($root.'/michat/bin/create_user.php');
$reset=(string)file_get_contents($root.'/michat/bin/reset_runtime_data.php');
$chat=(string)file_get_contents($root.'/michat/chat.php');
$advanced=(string)file_get_contents($root.'/michat/includes/preferences/advanced.php');
$administration=(string)file_get_contents($root.'/michat/includes/preferences/administration.php');
$passed=0;$failed=0;$check=static function(bool$ok,string$label)use(&$passed,&$failed){echo($ok?'PASS ':'FAIL ').$label."\n";$ok?$passed++:$failed++;};

$check(str_contains($first,"PHP_SAPI!=='cli'")&&str_contains($user,"PHP_SAPI!=='cli'")&&str_contains($reset,"PHP_SAPI!=='cli'"),'provisioning and reset adapters are CLI-only');
$check(str_contains($provision,"SELECT COUNT(*) c FROM Users")&&str_contains($provision,"'superadmin'")&&str_contains($provision,'michat:first_user_bootstrap'),'first-user bootstrap is locked and only applies to an empty Users table');
$check(str_contains($provision,'begin_transaction')&&str_contains($provision,'rollback')&&str_contains($provision,'initialProfile->apply'),'user creation and canonical profile share a transaction');
$check(str_contains($first,"getenv('MICHAT_NEW_USER_PASSWORD')")&&!preg_match('/--password/', $first),'first-user password is not accepted on the command line');
$check(str_contains($user,"getenv('MICHAT_ACTOR_PASSWORD')")&&str_contains($user,"getenv('MICHAT_NEW_USER_PASSWORD')")&&!preg_match('/--(?:actor-)?password/', $user),'ordinary provisioning keeps both passwords out of argv');
$check(str_contains($user,"system-role:")&&str_contains($provision,"'system.roles.manage'"),'ordinary user creation requires explicit system role and role-management permission');
$check(str_contains($auth,"userstatus']!=='Activo'")&&str_contains($auth,'password_verify'),'actor authentication requires an active account and verified password');

$features=[
'prompt_compiler'=>0,'memory_router'=>1,'procedural_memory_read'=>1,'project_memory_read'=>1,'session_memory_read'=>1,
'question_memory_read'=>1,'project_rag'=>1,'attachment_rag'=>1,'context_ranking'=>1,'memory_backfill'=>1,
'project_tools'=>1,'memory_writer'=>1,'task_orchestrator'=>1,'task_auto_execute'=>0,'task_async_execute'=>1,'task_planner'=>1,
];
foreach($features as$key=>$enabled)$check(str_contains($profile,"'{$key}'=>{$enabled}"),'initial profile feature '.$key.'='.$enabled);
foreach(["'amazon.nova-micro-v1:0'","42","0.00","200","300","0.100","'session'","20","5","'theme-light'"]as$value)$check(str_contains($profile,$value),'initial preferences include '.$value);
$check(!str_contains($profile,'INSERT INTO UserAIAgentConfigs'),'new users inherit GLOBAL AI configuration instead of cloning it');

$check(str_contains($reset,"'SchemaMigrations'")&&str_contains($reset,"'AccessControl'")&&str_contains($reset,"'FileS3'")&&str_contains($reset,"'S3Folders'"),'runtime reset preserves durable identity/config/storage tables');
$check(!preg_match('/\bTRUNCATE\b/i',$reset)&&!str_contains($reset,'FOREIGN_KEY_CHECKS'),'runtime reset never disables FKs or truncates tables');
$check(str_contains($reset,'hard_reset_requires_development_or_test_environment')&&str_contains($reset,"'system.reset'")&&str_contains($reset,'runtime_data_reset'),'destructive reset is dev/test-only, authorized and audited');
$check(!is_file($root.'/michat/truncate.php'),'legacy destructive HTTP endpoint is removed');
$check(!str_contains($chat,'mostrarTruncate')&&!str_contains($advanced,'adminTruncateTables')&&!str_contains($administration,'adminTruncateTables'),'web UI exposes no runtime reset control');
$check(!preg_match('/(?:user_id|userId)[^\n]{0,50}(?:===|==)\s*1/',$auth.$provision.$first.$user.$reset.$chat),'administration contains no magic user-id privilege');

echo"Result: {$passed} passed, {$failed} failed\n";exit($failed?1:0);
