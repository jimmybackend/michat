<?php
declare(strict_types=1);
if(!getenv('DOCUMENT_TEST_DB_HOST')){echo "SKIP evidence request recovery: isolated DB missing\n";exit;}
require __DIR__.'/drive_chat_working_copy_mysql_test.php';
$db->query("UPDATE SessionContextBlocks SET source_ids=JSON_REMOVE(source_ids,'$.source_etag') WHERE session_id_=6");
$query='Analiza el archivo lazy.php';
$recovered=$builder->build(new MemoryRoute(['intent'=>'code','use_attachment_context'=>true]),['stage'=>'respond','user_id'=>1,'session_id'=>6,'query_text'=>$query,'pipeline_features'=>$features,'trace_id'=>'legacy-revision-recovery-0001']);
workCheck(count($recovered->selectedItems('attachments'))>0,'Pre-policy chat regenerates or imports revision-verified evidence instead of trusting legacy chunks');
$db->query("INSERT INTO ChatSessions(id_,user_id_,title,model_id,meta) VALUES(30,1,'Dato final','fixture','{\"attachment_processing_policy\":\"on_request\"}')");
$body=str_repeat("Texto de relleno sin datos relevantes para la consulta.\n",600)."\nLa CLAVE_FINAL_DE_ENTREGA es REF-73129.";
Config::$s3->objects['Data/final.txt']=['bytes'=>$body,'etag'=>'"final-v1"'];
$db->query("INSERT INTO FileS3(id_,user_id_,Nombre,Ruta,Encriptado,Tamano,Found) VALUES(9500,1,'final.txt','Data/','final.txt',40000,1)");
$last=$attach->attach(1,30,9500);
$session=(new DocumentAccessRepository($db))->session(1,30);$meta=json_decode($session['meta'],true);
$answer=(new AttachmentRequestService($db))->prepare(1,30,'¿Cuál es la CLAVE_FINAL_DE_ENTREGA en final.txt?',$meta);
workCheck(($answer['telemetry']['strategy']??'')==='evidence'&&count($answer['items'])>0,'Direct factual question selects evidence retrieval rather than a generic overview');
workCheck(str_contains(implode('\n',array_map(static fn($i)=>$i->content,$answer['items'])),'REF-73129'),'Factual retrieval finds the known value in the last document chunk');
workCheck(str_contains($answer['items'][0]->metadata['citation'],'líneas'),'Factual evidence keeps its actual locator');
$calls=count(Config::$s3->gets);
$unrelated=(new AttachmentRequestService($db))->prepare(1,30,'Explícame cómo preparar café',$meta);
workCheck(!$unrelated['items']&&count(Config::$s3->gets)===$calls,'Unrelated question never reads or incorporates attached content');
$files=[['id_'=>1,'Nombre'=>'sentencia.pdf']];
workCheck(AttachmentRequestService::plan('¿Cuál es la fecha de la sentencia en sentencia.pdf?',$files)['strategy']==='evidence','Document content dates are not confused with upload metadata');
workCheck(AttachmentRequestService::plan('Dime la fecha de subida de sentencia.pdf',$files)['strategy']==='metadata','Explicit upload date remains metadata-only');
echo "OK factual retrieval and legacy recovery\n";
