<?php
declare(strict_types=1);
if(!getenv('DOCUMENT_TEST_DB_HOST')){echo "SKIP conversation detach: isolated DB missing\n";exit;}
require __DIR__.'/drive_chat_working_copy_mysql_test.php';
require_once dirname(__DIR__).'/includes/Drive/ConversationAttachmentService.php';
$links=new ConversationAttachmentService($db);
$fileRows=$db->query('SELECT * FROM FileS3 ORDER BY id_')->fetch_all(MYSQLI_ASSOC);$objects=Config::$s3->objects;
$links->change(1,5,$lazyId,false);
$access=new DocumentAccessRepository($db);
$denied=false;try{$access->requireFile(1,5,$lazyId);}catch(Throwable){$denied=true;}
workCheck($denied,'Removing a native-path working copy revokes conversation evidence');
workCheck(!array_filter($access->conversationFiles(1,5),static fn($f)=>(int)$f['id_']===$lazyId),'Upload path cannot resurrect a detached attachment in the catalog');
workCheck(!(new AttachmentContextRepository($db,null))->retrieve(1,5,'41837','always',null,null)['items'],'Stored chunks cannot bypass a conversation detach');
$links->change(1,5,$lazyId,false);
workCheck($objects===Config::$s3->objects&&$fileRows===$db->query('SELECT * FROM FileS3 ORDER BY id_')->fetch_all(MYSQLI_ASSOC),'Repeated detach preserves originals, copies, sidecars, versions and FileS3 metadata');
$copies=count(Config::$s3->copies);$restored=$attach->attach(1,5,5000);
workCheck((int)$restored['id_']===$lazyId&&count(Config::$s3->copies)===$copies&&$access->requireFile(1,5,$lazyId)!==null,'Explicit reattachment restores the same working copy without copying again');
$db->query("UPDATE FileS3 SET AccessType='secure' WHERE id_=5000");
workCheck($links->change(1,5,$lazyId,false)['ok'],'Revoked original does not prevent removing its stale conversation binding');
$db->query("UPDATE FileS3 SET AccessType='normal' WHERE id_=5000");
$denied=false;try{$links->change(2,5,$lazyId,false);}catch(Throwable){$denied=true;}
workCheck($denied,'Another user cannot alter attachment bindings');
$denied=false;try{$links->change(1,5,3823,false);}catch(Throwable){$denied=true;}
workCheck($denied,'Arbitrary owned files outside this conversation cannot be detached by ID');
echo "OK reversible conversation detach\n";
