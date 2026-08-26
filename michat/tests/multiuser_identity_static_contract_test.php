<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$passed=0;$failed=0;
$check=static function(bool$c,string$l)use(&$passed,&$failed):void{echo($c?'PASS ':'FAIL ').$l."\n";$c?$passed++:$failed++;};
$aiFiles=['michat/chat.php','michat/chat2_session_create.php','michat/chat_save_edit.php','michat/ai_agent_configurator.php','michat/get_ai_agents.php','michat/save_ai_agent.php','michat/delete_ai_agent.php','michat/includes/ai_agent_runtime.php'];
$forbidden=['/user_id_\s*(?:=|IN\s*\()\s*1\b/i','/\$userId\s*(?:===|!==|==|!=)\s*1\b/','/globalUserId\s*=\s*1\b/i','/DEFAULT_USER_ID/'];
foreach($aiFiles as$file){$src=(string)file_get_contents($root.'/'.$file);$found=false;foreach($forbidden as$p)if(preg_match($p,$src)){$found=true;break;}$check(!$found,$file.' has no magic identity/global owner');}
$migration=(string)file_get_contents($root.'/michat/sql/fase12b_2c_global_ai_configuration_scope.sql');
$check(str_contains($migration,"user_id_ = IF(user_id_ = 1, NULL, user_id_)"),'legacy migration is the sole approved owner-1 transformation');
$hardened=['projects.php','project_source_delete.php','chat2_session_title.php','chat2_session_archive.php','chat2_session_restore.php'];
foreach($hardened as$file){
 $src=(string)file_get_contents($root.'/michat/'.$file);
 $check(str_contains($src,'ChatIdentity::resolveUserId('),$file.' resolves identity server-side');
 $check(!preg_match('/\$user_id\s*=\s*1\b|DEFAULT_USER_ID/',$src),$file.' has no default owner');
 $check(!preg_match('/\$user_id\s*=\s*\$_(?:GET|POST)/',$src),$file.' never derives identity from request');
}
$check(!str_contains((string)file_get_contents($root.'/Config-s3.php'),'DEFAULT_USER_ID'),'Config-s3.php no longer defines a default identity');
$media=['chat_gen_image.php','chat_gen_video_start.php','chat_gen_video_status.php','chat_notify_poll.php'];
foreach($media as$file){$src=(string)file_get_contents($root.'/michat/'.$file);$check(str_contains($src,'AuthenticatedMediaScope'),$file.' reuses authenticated media scope');$check(!preg_match('/\$user_id\s*=\s*1\b|DEFAULT_USER_ID|\$userId\s*(?:===|!==)\s*1\b/',$src),$file.' has no magic identity');$check(!preg_match('/\$user_id\s*=\s*\$_(?:GET|POST|REQUEST)/',$src),$file.' never derives identity from request');}
$scanRoots=[$root.'/michat',$root.'/Config-s3.php'];$findings=[];
$scan=static function(string$path)use(&$scan,&$findings,$root):void{
 if(is_dir($path)){foreach(scandir($path)?:[]as$name)if($name!=='.'&&$name!=='..'&&!in_array($name,['tests','sql','doc','vendor'],true))$scan($path.'/'.$name);return;}
 if(!str_ends_with($path,'.php'))return;$source=(string)file_get_contents($path);$code='';foreach(token_get_all($source)as$token){if(is_array($token)&&in_array($token[0],[T_COMMENT,T_DOC_COMMENT],true))continue;$code.=is_array($token)?$token[1]:$token;}
 foreach(['/\$user_id\s*=\s*1\b/i','/user_id_\s*(?:=|IN\s*\()\s*1\b/i','/\$userId\s*(?:===|!==|==|!=)\s*1\b/','/DEFAULT_USER_ID/','/globalUserId/i','/ownerUserId\s*=\s*1\b/i','/\$user_id\s*=\s*\$_(?:GET|POST|REQUEST)/']as$pattern)if(preg_match($pattern,$code)){$findings[]=str_replace($root.'/','',$path).':'.$pattern;break;}
};foreach($scanRoots as$scanRoot)$scan($scanRoot);$check($findings===[],'repository-wide productive identity scan has zero findings'.($findings?' — '.implode(', ',$findings):''));
$repo=(string)file_get_contents($root.'/michat/includes/AI/AIAgentConfigRepository.php');
$check(substr_count($repo,'UserAIAgentConfigs')>=1,'single AI persistence repository exists');
foreach(['michat/save_ai_agent.php','michat/delete_ai_agent.php']as$file)$check(!str_contains((string)file_get_contents($root.'/'.$file),'UserAIAgentConfigs'),$file.' is a SQL-free adapter');
$check(count(glob($root.'/michat/includes/ai_agent_runtime*.php')?:[])===1,'ai_agent_runtime.php remains the single effective loader');
echo "Repository-wide multiuser runtime status: CLEAN\nResult: {$passed} passed, {$failed} failed\n";exit($failed===0?0:1);
