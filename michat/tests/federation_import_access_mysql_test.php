<?php
declare(strict_types=1);
if(!getenv('DOCUMENT_TEST_DB_HOST')){echo "SKIP federation import access: isolated DB missing\n";exit;}
require __DIR__.'/drive_chat_working_copy_mysql_test.php';
require_once dirname(__DIR__).'/includes/Files/FileDeliveryService.php';
preg_match_all('/CREATE TABLE IF NOT EXISTS.*?\) ENGINE=.*?;/s',file_get_contents(__DIR__.'/fixtures/federation_import_schema.sql'),$tables);
foreach($tables[0] as $sql) {
    if(trim($sql)!=='')$db->query(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE',$sql));
}
$db->query("INSERT INTO ChatSessions(id_,user_id_,title,model_id,meta) VALUES(20,1,'Compartido','fixture','{}')");
$meta=json_encode(['federationcloud'=>['source'=>'share_received','share_id'=>'far_12345678901234567890','resource_id'=>'resource-fixture','remote_node_id'=>'node-fixture']],JSON_THROW_ON_ERROR);
$q=$db->prepare("INSERT INTO FileS3(id_,user_id_,Nombre,Ruta,Encriptado,Tamano,Found,Metadatos) VALUES(9300,1,'Compartido.txt','Data/','shared.txt',120,1,?)");$q->bind_param('s',$meta);$q->execute();$q->close();
$db->query("INSERT INTO FederatedResources(ResourceId,OriginNodeId,Title,ContentId,OriginUrl,FederationUrl) VALUES('resource-fixture','node-fixture','Compartido','content-v1','https://origin.invalid/','https://origin.invalid/federationcloud/')");
$db->query("INSERT INTO FederationShares(ShareId,UserId,Direction,ResourceId,RemoteNodeId,Title,DecisionJson,ExpiresAt,LocalFileId,LocalS3Key,ImportedContentId) VALUES('far_12345678901234567890',1,'received','resource-fixture','node-fixture','Compartido','{}',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY),9300,'Data/shared.txt','content-v1')");
Config::$s3->objects['Data/shared.txt']=['bytes'=>"Contenido autorizado.\nDato compartido 77123",'etag'=>'"shared-v1"'];
$access=new DocumentAccessRepository($db);
workCheck($access->file(1,9300)!==null&&$access->file(2,9300)===null,'Imported share requires actual recipient ownership and current federation grant');
$catalog=(new MetadataSearchService($db))->search(1,'Compartido.txt',false);
workCheck(count($catalog['files'])===1,'Authorized imported share is discoverable through metadata without content analysis');
$copy=$attach->attach(1,20,9300);$copyId=(int)$copy['id_'];
$ready=$analysis->analyze(1,20,$copyId,false,false);
workCheck($ready['ok']&&str_contains((new AttachmentContextRepository($db,null))->retrieve(1,20,'77123','always',null,null)['context'],'77123'),'Imported share uses private working copy and cited evidence engine');
foreach([
    "UPDATE FederationShares SET Status='revoked'",
    "UPDATE FederationShares SET ExpiresAt=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND)",
    "UPDATE FederationShares SET UserId=2",
    "UPDATE FederationShares SET LocalS3Key='Data/wrong.txt'",
    "UPDATE FederatedResources SET Tombstoned=1",
    "UPDATE FederatedResources SET ContentId='content-v2'",
    "UPDATE FederatedResources SET OriginNodeId='other-node'"
] as $change) {
    $db->query($change);
    workCheck($access->file(1,9300)===null&&$access->file(1,$copyId)===null,'Live grant/resource change invalidates original and working copy: '.$change);
    workCheck(!(new AttachmentContextRepository($db,null))->retrieve(1,20,'77123','always',null,null)['items'],'Cached chunks cannot bypass the changed grant');
    workCheck(!(new MetadataSearchService($db))->search(1,'Compartido.txt',false)['files'],'Metadata catalog excludes inaccessible import');
    $denied=false;try{(new FileDeliveryService($db,Config::$s3,Config::getBucket()))->resolve(1,'Data/shared.txt',[]);}catch(FileDeliveryException){$denied=true;}
    workCheck($denied,'Direct download resolver enforces the same grant');
    $db->query("UPDATE FederationShares SET Status='active',UserId=1,ExpiresAt=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY),LocalS3Key='Data/shared.txt'");
    $db->query("UPDATE FederatedResources SET Tombstoned=0,ContentId='content-v1',OriginNodeId='node-fixture'");
}
workCheck(count(Config::$s3->copies)>=1&&Config::$s3->objects['Data/shared.txt']['bytes']==="Contenido autorizado.\nDato compartido 77123",'Revocation performs no destructive cleanup of imported source');
$db->query('DROP TEMPORARY TABLE FederationShares');
workCheck($access->file(1,9300)===null&&$access->file(1,$copyId)===null,'Missing federation schema denies imports without creating or repairing shared tables');
echo "OK federation import live permission checks\n";
