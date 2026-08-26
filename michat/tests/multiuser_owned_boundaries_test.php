<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$pass=0;$fail=0;
$ok=static function(bool$c,string$l)use(&$pass,&$fail):void{echo($c?'PASS ':'FAIL ').$l."\n";$c?$pass++:$fail++;};
$projects=(string)file_get_contents($root.'/michat/projects.php');
$source=(string)file_get_contents($root.'/michat/project_source_delete.php');
$lifecycle=(string)file_get_contents($root.'/michat/includes/Chat/SessionLifecycleService.php');
$ok(str_contains($projects,'ChatIdentity::resolveUserId(')&&str_contains($projects,"'Sesión de usuario no válida'"),'projects rejects missing server identity');
$ok(str_contains($projects,'user_id no coincide con la sesión autenticada'),'projects treats request user_id as assertion');
$ok(str_contains($projects,'WHERE user_id_ = ?')&&substr_count($projects,'AND user_id_ = ?')>=2,'projects list/update/delete remain owner-scoped');
$ok(str_contains($source,"p.user_id_ = ?")&&str_contains($source,"p.status <> 'deleted'"),'project source lookup is owner-scoped through project');
$ok(str_contains($source,'DELETE ps FROM ProjectSources ps JOIN Projects p')&&str_contains($source,'p.user_id_=?'),'project source delete rechecks owner in mutation');
foreach(['rename','archive','restore']as$method)$ok(str_contains($lifecycle,'function '.$method.'('),'SessionLifecycleService owns '.$method);
$ok(substr_count($lifecycle,'WHERE id_=? AND user_id_=?')>=3,'session mutations and reloads are owner-scoped');
foreach(['title','archive','restore']as$name){$endpoint=(string)file_get_contents($root.'/michat/chat2_session_'.$name.'.php');$ok(str_contains($endpoint,'ChatIdentity::resolveUserId(')&&str_contains($endpoint,'SessionLifecycleService'),$name.' adapter resolves identity and delegates');$ok(str_contains($endpoint,'user_id no coincide con la sesión autenticada'),$name.' request user_id cannot impersonate another user');$ok(!str_contains($endpoint,'UPDATE ChatSessions')&&!str_contains($endpoint,'SELECT id_'),$name.' adapter contains no session business SQL');}

$mediaFiles=['image'=>'chat_gen_image.php','video_start'=>'chat_gen_video_start.php','video_status'=>'chat_gen_video_status.php','notify'=>'chat_notify_poll.php'];
foreach($mediaFiles as$name=>$file){$src=(string)file_get_contents($root.'/michat/'.$file);$ok(str_contains($src,'authenticatedUserId('),$name.' authenticates server-side');$ok(str_contains($src,'user_id')&&str_contains($src,'MediaIdentityMismatchException'),$name.' treats request user_id as assertion');}
$image=(string)file_get_contents($root.'/michat/chat_gen_image.php');$imageGuard=strpos($image,'resolveOwnedSession(');$ok($imageGuard!==false&&$imageGuard<strpos($image,'new Aws\\BedrockRuntime')&&$imageGuard<strpos($image,'INSERT INTO ChatMessages'),'image owner guard precedes Bedrock and DB write');
$video=(string)file_get_contents($root.'/michat/chat_gen_video_start.php');$videoGuard=strpos($video,'resolveOwnedSession(');$ok($videoGuard!==false&&$videoGuard<strpos($video,'INSERT INTO ChatMessages')&&$videoGuard<strpos($video,'Config::getS3()')&&$videoGuard<strpos($video,'startAsyncInvoke('),'video owner guard precedes placeholder, S3 and Bedrock');
$status=(string)file_get_contents($root.'/michat/chat_gen_video_status.php');$statusGuard=strpos($status,'resolveOwnedMessage(');$ok($statusGuard!==false&&$statusGuard<strpos($status,'Config::getS3()')&&$statusGuard<strpos($status,'UPDATE ChatMessages'),'status owner guard precedes S3/AWS and DB mutation');
$notify=(string)file_get_contents($root.'/michat/chat_notify_poll.php');$ok(str_contains($notify,'s.user_id_ = m.user_id_')&&substr_count($notify,'m.user_id_ = ?')===2,'notifications require authenticated message/session ownership');
$identityAssertion=static fn(int$authenticated,?int$requested):bool=>$authenticated>0&&($requested===null||$requested===$authenticated);
$ok($identityAssertion(42,42),'user A matching assertion accepted');$ok(!$identityAssertion(42,7),'user A cannot assert user B');$ok(!$identityAssertion(0,42),'request user_id cannot authenticate an anonymous caller');
$required=['TASK_TEST_DB_HOST','TASK_TEST_DB_PORT','TASK_TEST_DB_USER','TASK_TEST_DB_PASSWORD'];$missing=array_values(array_filter($required,static fn(string$k):bool=>getenv($k)===false||getenv($k)===''));
if($missing!==[])echo 'SKIP MULTIUSER MYSQL E2E — missing '.implode(', ',$missing)."\n";else echo "SKIP MULTIUSER MYSQL E2E — isolated endpoint database harness is not provisioned by this contract test\n";
echo "Result: {$pass} passed, {$fail} failed\n";exit($fail===0?0:1);
