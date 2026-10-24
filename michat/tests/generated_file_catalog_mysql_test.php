<?php
declare(strict_types=1);
if(!getenv('DOCUMENT_TEST_DB_HOST')){echo "SKIP generated file catalog: isolated DB missing\n";exit;}
require __DIR__.'/multimodal_processing_mysql_test.php';
require_once dirname(__DIR__).'/includes/Files/FileDeliveryService.php';
preg_match('/CREATE TABLE IF NOT EXISTS `S3Folders` \(.*?\) ENGINE=.*?;/s',$schema,$m);
$db->query(str_replace(['CREATE TABLE IF NOT EXISTS','utf8mb4_0900_ai_ci'],['CREATE TEMPORARY TABLE','utf8mb4_unicode_ci'],$m[0]));
$catalog=new GeneratedFileCatalog($db);
$lookup=static function(string $key)use($db):array{
    return array_values(array_filter($db->query('SELECT * FROM FileS3')->fetch_all(MYSQLI_ASSOC),static fn($row)=>idx_build_s3_key($row)===$key));
};
$jsonKeys=array_keys(array_filter(Config::$s3->objects,static fn($v,$key)=>str_contains($key,'.michat-u')||str_ends_with($key,'.transcript.json'),ARRAY_FILTER_USE_BOTH));
workCheck(count($jsonKeys)>10,'Real multimodal fixture creates transcription, content and analysis outputs');
foreach($jsonKeys as $jsonKey){
    $rows=$lookup($jsonKey);
    workCheck(count($rows)===1&&(int)$rows[0]['user_id_']===1&&(int)$rows[0]['Found']===1&&(int)$rows[0]['Tamano']===strlen(Config::$s3->objects[$jsonKey]['bytes']),'Created JSON is immediately cataloged once with exact bytes and owner: '.basename($jsonKey));
}
$files=(new DocumentAccessRepository($db))->conversationFiles(1,1);
workCheck(!array_filter($files,static fn($f)=>(json_decode((string)$f['Metadatos'],true)['michat_generated']['role']??'')==='derivative'),'JSON catalog rows do not become automatic chat attachments');
$sample=$jsonKeys[0];
workCheck((bool)(new MetadataSearchService($db))->search(1,basename($sample),false)['files'],'New JSON is searchable from the DB without a folder sync');
workCheck(!(new MetadataSearchService($db))->search(2,basename($sample),false)['files'],'Another user cannot discover the generated JSON');

// Existing ready jobs repair their known JSON bindings, without another transcription or embeddings.
$beforeStarts=count($transcribe->starts);$beforeEmbeds=$modelClient->embeds;
$db->query("UPDATE FileS3 SET AccessType='normal' WHERE id_=7100");
$db->query("DELETE FROM FileS3 WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(Metadatos,'$.michat_generated.source_file_id')) AS UNSIGNED)=7100");
$service->enqueue(1,1,$audioId);
$analysis->analyze(1,1,$audioId,false,false);
$repaired=$db->query("SELECT Metadatos FROM FileS3 WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(Metadatos,'$.michat_generated.source_file_id')) AS UNSIGNED)=7100")->fetch_all(MYSQLI_ASSOC);
$kinds=array_map(static fn($r)=>json_decode($r['Metadatos'],true)['michat_generated']['kind'],$repaired);
workCheck(in_array('content',$kinds,true)&&in_array('transcription',$kinds,true)&&in_array('analysis',$kinds,true),'Reusing a ready audio job registers existing source JSON without syncing its folder');
workCheck(count($transcribe->starts)===$beforeStarts&&$modelClient->embeds===$beforeEmbeds,'Catalog repair never resubmits provider work');

// Simulated unknown S3 outcome: the object was saved but the writer lost the response.
$sourceKey='Data/registry/source.txt';Config::$s3->objects[$sourceKey]=['bytes'=>'Texto original','etag'=>'"registry-source-v1"'];
$db->query("INSERT INTO FileS3(id_,user_id_,Nombre,Ruta,Encriptado,Tamano,Found) VALUES(9990,1,'source.txt','Data/registry/','source.txt',14,1)");
$sourceFile=(new DocumentAccessRepository($db))->file(1,9990);
$uncertain=new class(Config::$s3){
    public function __construct(private object $inner){}
    public function __call(string $name,array $args):mixed{$result=$this->inner->$name(...$args);if($name==='putObject')throw new RuntimeException('Lost response after successful S3 write');return $result;}
};
$store=new AnalysisSidecarStore($uncertain,Config::getBucket(),$db);$state=$store->read($sourceFile);
$failed=false;try{$store->write($sourceFile,$state,['summary'=>'Resumen español con ñ y acentos','chunks'=>[]]);}catch(Throwable){$failed=true;}
$sidecar=AnalysisSidecarStore::key($sourceFile);
workCheck($failed&&isset(Config::$s3->objects[$sidecar])&&!$lookup($sidecar),'Unknown write result does not invent a successful catalog insertion');
$writes=count(Config::$s3->puts);$store=new AnalysisSidecarStore(Config::$s3,Config::getBucket(),$db);$store->read($sourceFile);
$registered=$lookup($sidecar)[0];
workCheck(count($lookup($sidecar))===1&&count(Config::$s3->puts)===$writes,'Verified read repairs the catalog after interruption without rewriting the JSON');
workCheck((int)$registered['Tamano']===strlen(Config::$s3->objects[$sidecar]['bytes']),'Catalog records UTF-8 byte size rather than character count');
$db->query("UPDATE FileS3 SET Nombre='Mi análisis conservado.json',Metadatos=JSON_SET(Metadatos,'$.custom','keep'),AccessType='secure' WHERE id_=".(int)$registered['id_']);
$store->read($sourceFile);$again=$lookup($sidecar)[0];
workCheck($again['id_']===$registered['id_']&&$again['Nombre']==='Mi análisis conservado.json'&&$again['AccessType']==='secure'&&(json_decode($again['Metadatos'],true)['custom']??'')==='keep','Retry preserves ID, visible name, protection and unrelated metadata');
$db->query("UPDATE FileS3 SET AccessType='normal' WHERE id_=".(int)$registered['id_']);
$jsonCopy=$attach->attach(1,2,(int)$registered['id_']);
$db->query("UPDATE FileS3 SET AccessType='secure' WHERE id_=9990");
$access=new DocumentAccessRepository($db);
workCheck($access->file(1,(int)$registered['id_'])===null&&$access->file(1,(int)$jsonCopy['id_'])===null,'Revoked original blocks its cataloged JSON and a copy of that JSON');
$denied=false;try{(new FileDeliveryService($db,Config::$s3,Config::getBucket()))->resolve(1,$sidecar,[]);}catch(FileDeliveryException){$denied=true;}
workCheck($denied,'Direct JSON download cannot bypass source revocation');
$db->query("UPDATE FileS3 SET AccessType='normal' WHERE id_=9990");

$mediaKey='Data/Chat/GenerationsImages/1/1/catalog-fixture.png';Config::$s3->objects[$mediaKey]=['bytes'=>'PNG fixture','etag'=>'"media-v1"'];
$imageId=$catalog->created(1,$mediaKey,11,'image/png','Imagen generada.png','generated_image',1);
$againId=$catalog->created(1,$mediaKey,11,'image/png','Another name.png','generated_image',1);
workCheck($imageId===$againId&&count($lookup($mediaKey))===1,'Generated media registration is idempotent');
workCheck((int)$db->query("SELECT COUNT(*) n FROM S3Folders WHERE user_id_=1 AND Prefix='Data/Chat/GenerationsImages/1/1/' AND Found=1")->fetch_assoc()['n']===1,'Generated media folders are registered without writing S3 folder markers');
$denied=false;try{$catalog->created(2,$mediaKey,11,'image/png','Ajeno.png','generated_image',3);}catch(DocumentAccessDenied){$denied=true;}
workCheck($denied&&count($lookup($mediaKey))===1,'Registration cannot assign an existing object to another user');
$denied=false;try{$catalog->created(2,'Data2/new.png',1,'image/png','new.png','generated_image',1);}catch(DocumentAccessDenied){$denied=true;}
workCheck($denied,'User cannot create media under another user conversation');
$db->query("INSERT INTO FileS3(id_,user_id_,Nombre,Ruta,Encriptado,Tamano,Found) VALUES(9970,2,'privado.txt','Data2/','privado.txt',5,1)");
Config::$s3->objects['Data2/privado.txt']=['bytes'=>'Texto','etag'=>'"user2-v1"'];
$user2File=(new DocumentAccessRepository($db))->file(2,9970);$user2Store=new AnalysisSidecarStore(Config::$s3,Config::getBucket(),$db);
$user2Store->write($user2File,$user2Store->read($user2File),['summary'=>'Análisis privado del usuario 2']);
$user2Rows=$lookup(AnalysisSidecarStore::key($user2File));
workCheck(count($user2Rows)===1&&(int)$user2Rows[0]['user_id_']===2&&(new DocumentAccessRepository($db))->file(1,(int)$user2Rows[0]['id_'])===null,'A second MiChat user gets derived files in their own DB ownership, never a default user');
$longKey='Data/'.str_repeat('folder',15).'/'.str_repeat('a',180).'.json';
Config::$s3->objects[$longKey]=['bytes'=>'{}','etag'=>'"long-v1"'];
$longId=$catalog->created(1,$longKey,2,'application/json',basename($longKey),'generated_file',1);
workCheck((int)$lookup($longKey)[0]['id_']===$longId&&$lookup($longKey)[0]['Encriptado']===basename($longKey),'Long full keys use the real Ruta plus basename representation without truncating keys');
echo "OK generated file catalog: immediate visibility, retry, owner isolation and permission inheritance\n";
