<?php
declare(strict_types=1);
if (!getenv('DOCUMENT_TEST_DB_HOST')) { echo "SKIP project attachment engine: isolated DB missing\n"; exit; }
require __DIR__.'/multimodal_processing_mysql_test.php';
require_once dirname(__DIR__).'/includes/Drive/AttachmentProcessingService.php';
preg_match('/CREATE TABLE IF NOT EXISTS `ProjectSources` \(.*?\) ENGINE=.*?;/s',$schema,$m);
$db->query(str_replace(['CREATE TABLE IF NOT EXISTS','utf8mb4_0900_ai_ci'],['CREATE TEMPORARY TABLE','utf8mb4_unicode_ci'],$m[0]));
$db->query("INSERT INTO Projects(id_,user_id_,name,slug,root_prefix) VALUES(10,1,'Proyecto','project','Data/project/'),(20,2,'Ajeno','other','Data2/project/')");
$db->query("INSERT INTO ChatSessions(id_,user_id_,project_id_,title,model_id,meta) VALUES(10,1,10,'Proyecto A','fixture','{}'),(11,1,10,'Proyecto B','fixture','{}'),(12,1,NULL,'Libre','fixture','{}'),(13,1,20,'FK ajena','fixture','{}'),(14,2,20,'Otro usuario','fixture','{}')");
$projectKey='Data/project/informe.txt';
$projectText="Inicio del informe.\n".str_repeat("Relleno del documento del proyecto.\n",200)."IMPORTE_FINAL_74291\n";
Config::$s3->objects[$projectKey]=['bytes'=>$projectText,'etag'=>'"project-v1"'];
$db->query("INSERT INTO FileS3(id_,user_id_,Nombre,Ruta,Encriptado,Tamano,Found) VALUES(9000,1,'informe.txt','Data/project/','informe.txt',8000,1)");
$db->query("INSERT INTO ProjectSources(id_,project_id_,files3_id_,s3_key,filename) VALUES(900,10,9000,'Data/project/informe.txt','informe.txt')");
$access=new DocumentAccessRepository($db);
$before=count(Config::$s3->gets)+count(Config::$s3->heads)+count(Config::$s3->puts);
$catalog=$access->conversationFiles(1,10);
workCheck(count($catalog)===1 && $catalog[0]['knowledge_origin']==='project','Published project sources enter the common metadata catalog');
workCheck(count(Config::$s3->gets)+count(Config::$s3->heads)+count(Config::$s3->puts)===$before,'Catalog performs no S3 operations or content processing');
workCheck(!$access->conversationFiles(1,12)&&!$access->conversationFiles(1,13)&&!$access->conversationFiles(2,14),'Free sessions, foreign project FK and other owners cannot discover the source');
workCheck($access->requireFile(1,10,9000)['Nombre']==='informe.txt','Published source is accessible without adding a private session link');
$result=(new AttachmentRequestService($db))->prepare(1,10,'Busca "IMPORTE_FINAL_74291" en "informe.txt"',[]);
workCheck(count($result['items'])===1&&str_contains($result['items'][0]->content,'IMPORTE_FINAL_74291')&&str_contains($result['items'][0]->metadata['citation'],'líneas'),'Project question automatically extracts and locates evidence at the end');
workCheck(($result['items'][0]->metadata['knowledge_origin']??'')==='project','Evidence retains its project origin');
$gets=count(Config::$s3->gets);
$second=(new AttachmentRequestService($db))->prepare(1,11,'Busca "IMPORTE_FINAL_74291" en "informe.txt"',[]);
workCheck(count($second['items'])===1&&count(Config::$s3->gets)===$gets+1,'Second project conversation reads the verified sidecar rather than downloading source bytes');
$meta=json_decode($db->query('SELECT meta FROM ChatSessions WHERE id_=10')->fetch_assoc()['meta'],true);
workCheck(empty($meta['knowledge_file_ids']),'Project publication does not become a session attachment grant');
workCheck(!(new AttachmentContextRepository($db,null))->retrieve(1,12,'IMPORTE_FINAL_74291','always',null,null)['items'],'Project evidence never leaks to free conversation retrieval');
$db->query('DELETE FROM ProjectSources WHERE id_=900');
workCheck(!(new AttachmentContextRepository($db,null))->retrieve(1,10,'IMPORTE_FINAL_74291','always',null,null)['items'],'Removing project publication revokes cached session chunks');
$denied=false;try{$access->requireFile(1,10,9000);}catch(Throwable){$denied=true;}
workCheck($denied&&Config::$s3->objects[$projectKey]['bytes']===$projectText,'Withdrawal denies access while preserving original bytes');
$add(9200,'Proyecto.xlsx','Data/project/sheet.xlsx',arcadecloudXlsxFixture());
$db->query("INSERT INTO ProjectSources(id_,project_id_,files3_id_,s3_key,filename) VALUES(920,10,9200,'Data/project/sheet.xlsx','Proyecto.xlsx')");
$planned=$service->plan(1,10,'Analiza el archivo Proyecto.xlsx','project-multimodal-trace');
workCheck(count($planned)===1,'Project complex question enters the existing durable processing queue');
$ready=$finish((int)$planned[0]['id_']);
workCheck($ready['status']==='ready','Published XLSX completes extraction, located chunks, embeddings and semantics');
$embeds=$modelClient->embeds;
$next=$service->plan(1,11,'Analiza el archivo Proyecto.xlsx','project-multimodal-trace2');
$ready2=$finish((int)$next[0]['id_']);
workCheck($ready2['status']==='ready'&&$modelClient->embeds===$embeds,'Another authorized project chat reuses verified vectors for the same revision and model');
$db->query("UPDATE ChatSessions SET project_id_=NULL WHERE id_=10");
$denied=false;try{$service->job(1,(int)$planned[0]['id_']);}catch(Throwable){$denied=true;}
workCheck($denied,'Changing session project revokes access to the former project processing job');
echo "OK project source common-engine integration\n";
