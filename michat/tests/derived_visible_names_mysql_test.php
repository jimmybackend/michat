<?php
declare(strict_types=1);
if(!getenv('DOCUMENT_TEST_DB_HOST')){echo "SKIP derived visible names: isolated DB missing\n";exit;}
require __DIR__.'/generated_file_catalog_mysql_test.php';
require_once dirname(__DIR__).'/includes/Files/ConversationDataRepository.php';
$all=$db->query("SELECT * FROM FileS3 WHERE user_id_=1 AND Metadatos LIKE '%michat_generated%'")->fetch_all(MYSQLI_ASSOC);
$sample=null;
foreach($all as $row){
    $ref=json_decode($row['Metadatos'],true)['michat_generated']??[];
    if(($ref['role']??'')!=='derivative'||!isset($ref['display_name']))continue;
    $source=(new DocumentAccessRepository($db))->file(1,(int)$ref['source_file_id']);
    if(!$source)continue;
    workCheck($row['Nombre']===GeneratedFileCatalog::derivativeName($source['Nombre'],$ref['kind']),'Registered JSON shows original visible name and operation: '.$ref['kind']);
    if((int)$ref['source_file_id']===7100&&$ref['kind']==='content')$sample=$row;
}
workCheck($sample!==null,'Audio fixture supplies a real source-linked JSON');
$id=(int)$sample['id_'];$key=idx_build_s3_key($sample);$legacy=basename($key);
$q=$db->prepare("UPDATE FileS3 SET Nombre=?,Metadatos=JSON_REMOVE(Metadatos,'$.michat_generated.display_name') WHERE id_=?");$q->bind_param('si',$legacy,$id);$q->execute();$q->close();
$before=Config::$s3->objects;$puts=count(Config::$s3->puts);$gets=count(Config::$s3->gets);$heads=count(Config::$s3->heads);
$panel=(new ConversationDataRepository($db))->listing(1,1);
$visible=array_values(array_filter($panel['files'],static fn($f)=>$f['id']===$id))[0];
$stored=$db->query('SELECT * FROM FileS3 WHERE id_='.$id)->fetch_assoc();
workCheck($visible['name']===$stored['Nombre']&&str_ends_with($visible['name'],' — Contenido.json')&&$visible['name']!==$legacy,'Opening the panel repairs a legacy technical name in FileS3');
workCheck($stored['Encriptado']===$sample['Encriptado']&&$stored['Ruta']===$sample['Ruta']&&$stored['Tamano']===$sample['Tamano'],'Name repair preserves encrypted key, path and size');
workCheck(Config::$s3->objects===$before&&count(Config::$s3->puts)===$puts&&count(Config::$s3->gets)===$gets&&count(Config::$s3->heads)===$heads,'Name repair needs no S3 access, copy or reprocessing');
$db->query("UPDATE FileS3 SET Nombre='Audio de audiencia.mp3' WHERE id_=7100");
$search=(new MetadataSearchService($db))->search(1,'',false);
$catalog->repairDerivedName(1,$stored);
$renamed=$db->query('SELECT * FROM FileS3 WHERE id_='.$id)->fetch_assoc();
workCheck($renamed['Nombre']==='Audio de audiencia.mp3 — Contenido.json','Automatic derivative names follow a renamed original');
$delivery=(new FileDeliveryService($db,Config::$s3,Config::getBucket()))->resolve(1,$key,[]);
workCheck($delivery['name']===$renamed['Nombre']&&$delivery['key']===$key,'Downloads use the readable name and unchanged S3 key');
$db->query("UPDATE FileS3 SET Nombre='Mi resultado privado.json' WHERE id_=".$id);
$custom=$catalog->repairDerivedName(1,$renamed);
$sourceFile=(new DocumentAccessRepository($db))->file(1,7100);$source=(new AnalysisSidecarStore(Config::$s3,Config::getBucket(),$db))->identity($sourceFile);
$catalog->derived($source,$key,(int)$stored['Tamano'],'content');
workCheck($custom['Nombre']==='Mi resultado privado.json'&&$db->query('SELECT Nombre FROM FileS3 WHERE id_='.$id)->fetch_assoc()['Nombre']==='Mi resultado privado.json','Both repair and processing retries preserve manual names');
$protected=$db->query('SELECT * FROM FileS3 WHERE id_='.$id)->fetch_assoc();
$db->query("UPDATE FileS3 SET Nombre='".$db->real_escape_string($legacy)."',AccessType='secure' WHERE id_=".$id);
$catalog->repairDerivedName(1,$protected);
workCheck($db->query('SELECT Nombre FROM FileS3 WHERE id_='.$id)->fetch_assoc()['Nombre']===$legacy,'Repair does not touch protected or unauthorized outputs');
workCheck(GeneratedFileCatalog::derivativeName('legal actual.mp3','transcription')==='legal actual.mp3 — Transcripción.json','Audio transcription has the expected readable label');
workCheck(mb_strlen(GeneratedFileCatalog::derivativeName(str_repeat('á',255).'.pdf','analysis'))===255&&str_ends_with(GeneratedFileCatalog::derivativeName(str_repeat('á',255).'.pdf','analysis'),' — Análisis.json'),'Long Unicode visible names keep the JSON operation suffix within the real column limit');
echo "OK derived visible names: new and legacy JSON, original rename, custom labels, downloads, unchanged S3\n";
