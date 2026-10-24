<?php
declare(strict_types=1);
if(!getenv('DOCUMENT_TEST_DB_HOST')){echo "SKIP multimodal processing: isolated DB missing\n";exit;}
// Reuse the real temporary schema, revision-aware S3 fixture and permission
// checks; no uploaded user files or live AWS resources are used.
require __DIR__.'/drive_chat_working_copy_mysql_test.php';
require_once __DIR__.'/fixtures/arcadecloud_xlsx.php';
require_once dirname(__DIR__).'/includes/Drive/AttachmentProcessingService.php';
foreach(explode(';',file_get_contents(dirname(__DIR__).'/sql/fase14_attachment_processing.sql')) as $sql)
 if(trim($sql)!=='')$db->query(str_replace('CREATE TABLE ','CREATE TEMPORARY TABLE ',$sql));
foreach(['embedding_main'=>'amazon.titan-embed-text-v2:0','smart_memory_general'=>'amazon.nova-lite-v1:0','smart_memory_code'=>'amazon.nova-lite-v1:0','attachment_semantic_prompt'=>'amazon.nova-lite-v1:0'] as $key=>$model){
 $q=$db->prepare("INSERT INTO UserAIAgentConfigs(scope,agent_key,display_name,model_id,is_active,system_instruction,user_prompt_template) VALUES('global',?,?,?,1,'Use evidence and cite locations','File {{filename}}: {{content}}') ON DUPLICATE KEY UPDATE model_id=VALUES(model_id),is_active=1,system_instruction=VALUES(system_instruction),user_prompt_template=VALUES(user_prompt_template)");
 $q->bind_param('sss',$key,$key,$model);$q->execute();$q->close();
}
final class MultiTranscribeMissing extends RuntimeException {public function getAwsErrorCode():string{return 'NotFoundException';}}
$modelClient=new class{
 public int $embeds=0,$summaries=0;public bool $fail=false;
 public function invokeModel(array $p):ArrayAccess{$this->embeds++;if($this->fail)throw new RuntimeException('Temporary embedding provider failure');return documentSdkResult(['body'=>json_encode(['embedding'=>array_fill(0,1024,0.05),'inputTextTokenCount'=>8])]);}
 public function converse(array $p):ArrayAccess{$this->summaries++;return documentSdkResult(['output'=>['message'=>['content'=>[['text'=>'Resumen verificado: importe 88317.']]]],'usage'=>['inputTokens'=>25,'outputTokens'=>10],'stopReason'=>'end_turn']);}
};
$textract=new class{
 public bool $complete=false;public array $tokens=[];
 public function startDocumentTextDetection(array $p):ArrayAccess{$this->tokens[]=$p['ClientRequestToken'];return documentSdkResult(['JobId'=>'ocr-fixture']);}
 public function getDocumentTextDetection(array $p):ArrayAccess{return documentSdkResult($this->complete?['JobStatus'=>'SUCCEEDED','DocumentMetadata'=>['Pages'=>1],'Blocks'=>[['BlockType'=>'LINE','Page'=>1,'Text'=>'OCR_VERIFICABLE_4417']]]:['JobStatus'=>'IN_PROGRESS']);}
};
$transcribe=new class{
 public array $jobs=[],$starts=[];public bool $complete=false;
 public function getTranscriptionJob(array $p):ArrayAccess{
  $job=$this->jobs[$p['TranscriptionJobName']]??throw new MultiTranscribeMissing();
  if($this->complete&&isset($job['_output'])){
   $raw=json_encode(['jobName'=>$p['TranscriptionJobName'],'results'=>['audio_segments'=>[['start_time'=>2,'end_time'=>20,'transcript'=>'Importe transcrito 88317']]]]);
   Config::$s3->objects[$job['_output']]=['bytes'=>$raw,'etag'=>'"result-v1"'];$job['TranscriptionJobStatus']='COMPLETED';
  }
  return documentSdkResult(['TranscriptionJob'=>$job]);
 }
 public function startTranscriptionJob(array $p):ArrayAccess{$this->starts[]=$p;$job=['Media'=>$p['Media'],'TranscriptionJobStatus'=>'IN_PROGRESS','_output'=>$p['OutputKey']];$this->jobs[$p['TranscriptionJobName']]=$job;return documentSdkResult(['TranscriptionJob'=>$job]);}
};
$factory=static fn(string $service)=>match($service){'S3'=>Config::$s3,'BedrockRuntime'=>$modelClient,'Transcribe'=>$transcribe,'Textract'=>$textract,default=>throw new RuntimeException('Unexpected client '.$service)};
$prices=tempnam(sys_get_temp_dir(),'synthetic-prices-');file_put_contents($prices,json_encode(['source_id'=>'synthetic fixture, not production prices','rates'=>['s3.head_request'=>0.000001,'s3.get_request'=>0.000001,'s3.put_request'=>0.000001,'bedrock.amazon_titan_embed_text_v2_0.input_token'=>0.000001,'bedrock.amazon_nova_lite_v1_0.input_token'=>0.000001,'bedrock.amazon_nova_lite_v1_0.output_token'=>0.000002,'transcribe.standard_batch_second'=>0.0004]]));putenv('MICHAT_PROCESSING_PRICES_PATH='.$prices);
$checks=0;$check=static function(bool $ok,string $label)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";};
$add=static function(int $id,string $name,string $key,string $body)use($db){
 Config::$s3->objects[$key]=['bytes'=>$body,'etag'=>'"original-'.hash('sha256',$body).'"'];$ruta=dirname($key).'/';$size=strlen($body);
 $q=$db->prepare("INSERT INTO FileS3(id_,user_id_,Nombre,Ruta,Encriptado,Tamano,Found,AccessType) VALUES(?,1,?,?,?,?,1,'normal')");$q->bind_param('isssi',$id,$name,$ruta,$key,$size);$q->execute();$q->close();
};
$service=new AttachmentProcessingService($db,$factory);
$finish=static function(int $jobId)use($db,$factory):array{
 for($i=0;$i<40;$i++){$db->query('UPDATE AttachmentProcessingJobs SET available_at=UTC_TIMESTAMP() WHERE id_='.(int)$jobId);$job=(new AttachmentProcessingService($db,$factory))->tick(1,$jobId);if(in_array($job['status'],['ready','partial','failed','blocked'],true))return $job;}
 throw new RuntimeException('Processing did not terminate');
};
try{
 $add(7000,'Libro.xlsx','Data/source/book.xlsx',arcadecloudXlsxFixture());$copy=$attach->attach(1,1,7000);$copyId=(int)$copy['id_'];$copyKey=idx_build_s3_key($copy);
 $job=$service->enqueue(1,1,$copyId,'multimodal-fixture-trace-0001');$again=$service->enqueue(1,1,$copyId);
 $check($job['id_']===$again['id_'],'Complex processing job is idempotent for the exact revision');
 $job=$service->tick(1,(int)$job['id_']);$check($job['phase']==='index'&&isset(Config::$s3->objects['Data/source/book.xlsx.michat-u1.content.v1.json']),'Real XLSX extraction checkpoints JSON beside original');
 $job=$service->tick(1,(int)$job['id_']);$check($job['phase']==='embeddings','Literal chunks persist before embedding work');
 $modelClient->fail=true;$failed=$service->tick(1,(int)$job['id_']);$check($failed['status']==='retry'&&$failed['phase']==='embeddings','Failed vectors retain a recoverable phase and never report ready');
 $modelClient->fail=false;$job=$finish((int)$job['id_']);$check($job['status']==='ready','Restarted workbook processing completes embeddings, semantics and source JSON');
 $rows=$db->query("SELECT content_preview,embedding_json FROM SessionContextBlocks WHERE s3_path='".$db->real_escape_string($copyKey)."' AND block_type='file_chunk'")->fetch_all(MYSQLI_ASSOC);
 $check(str_contains(implode(' ',array_column($rows,'content_preview')),'G7: 88317')&&count(json_decode($rows[0]['embedding_json'],true))===1024,'Actual workbook cell evidence and completed vector dimensions survive');
 $check(isset(Config::$s3->objects[$copyKey.'.michat-u1.content.v1.json']),'Derived JSON is also saved beside the chat working copy');
 $before=$modelClient->embeds;$copy2=$attach->attach(1,2,7000);$job2=$service->enqueue(1,2,(int)$copy2['id_']);$done=$finish((int)$job2['id_']);
 $check($done['status']==='ready'&&$modelClient->embeds===$before,'Second conversation reuses revision/model-bound cached vectors');
 $add(7100,'Nueva.wav','Data/source/new.wav','RIFF fixture WAV bytes');$audio=$attach->attach(1,1,7100);$audioId=(int)$audio['id_'];$audioJob=$service->enqueue(1,1,$audioId);
 $waiting=$service->tick(1,(int)$audioJob['id_']);$check($waiting['status']==='waiting'&&count($transcribe->starts)===1,'Missing transcript starts AWS once and enters a durable wait');
 $db->query('UPDATE AttachmentProcessingJobs SET available_at=UTC_TIMESTAMP() WHERE id_='.(int)$audioJob['id_']);
 $waiting=(new AttachmentProcessingService($db,$factory))->tick(1,(int)$audioJob['id_']);
 $check($waiting['status']==='waiting'&&count($transcribe->starts)===1,'Worker restart polls the same provider job without resubmitting media');
 $transcribe->complete=true;$done=$finish((int)$audioJob['id_']);$check($done['status']==='ready','AWS completion resumes waiting work through final RAG preparation');
 $check(isset(Config::$s3->objects[idx_build_s3_key($audio).'.transcript.json']),'Raw provider transcript is copied beside the chat media');
 $r=$db->query("SELECT source_ids FROM SessionContextBlocks WHERE s3_path='".$db->real_escape_string(idx_build_s3_key($audio))."' AND block_type='file_chunk' LIMIT 1")->fetch_assoc();$m=json_decode($r['source_ids'],true);
 $check(($m['evidence_kind']??'')==='audio_transcript'&&($m['locator']['timestamp_start_seconds']??null)===2,'AWS transcript timestamps become verifiable chunk locators');
 $bundle=$builder->build(new MemoryRoute(['intent'=>'general']),['stage'=>'respond','user_id'=>1,'session_id'=>1,'query_text'=>'Analiza el archivo "Nueva.wav"','pipeline_features'=>$features]);
 $check(str_contains(AttachmentPromptGrounding::append('',$bundle),'no se escuchó directamente'),'Final prompt discloses JSON transcription instead of direct audio or video analysis');
 $coverage=AttachmentPromptGrounding::transcriptCoverage($bundle->selectedItems('attachments'));
 $check(($coverage[0]['status']??'')==='all_extracted_text'&&$coverage[0]['selected_chunks']===1,'One-chunk audio reaches the final prompt as all available transcribed text, not an invented incomplete beginning');
 $startCount=count($transcribe->starts);$repeat=$builder->build(new MemoryRoute(['intent'=>'general']),['stage'=>'respond','user_id'=>1,'session_id'=>1,'query_text'=>'Analiza el archivo "Nueva.wav"','pipeline_features'=>$features]);
 $check(count($transcribe->starts)===$startCount&&(AttachmentPromptGrounding::transcriptCoverage($repeat->selectedItems('attachments'))[0]['status']??'')==='all_extracted_text','Re-reading the audio reuses verified transcript evidence without resubmitting media to AWS');
 $retrieved=(new AttachmentContextRepository($db,null))->retrieve(1,1,'Analiza el archivo "Nueva.wav"','rag',null,null);
 $audioItems=array_values(array_filter($retrieved['items'],static fn($item)=>(int)($item->metadata['files3_id']??0)===$audioId));
 $check($audioItems&&($audioItems[0]->metadata['evidence_kind']??'')==='audio_transcript'&&($audioItems[0]->metadata['chunk_total']??0)===1,'Hybrid retrieval preserves transcript provenance and real chunk counts for grounding');
 $cost=$db->query('SELECT SUM(estimated_cost_usd) total,SUM(estimated_cost_usd IS NULL) unknown FROM AttachmentProcessingCosts')->fetch_assoc();
 $check((float)$cost['total']>0&&(int)$cost['unknown']>0,'Ledger preserves configured estimates and explicit unknown prices/outcomes');
 $job=$service->tick(1,(int)$audioJob['id_']);
 $check((int)$db->query("SELECT COUNT(*) n FROM AttachmentProcessingCosts WHERE operation='transcription_result'")->fetch_assoc()['n']===1,'A completed transcription is attributed once');
 $denied=false;try{$service->job(2,(int)$audioJob['id_']);}catch(Throwable){$denied=true;}$check($denied,'Another user cannot inspect or advance a processing job');
 $db->query("UPDATE FileS3 SET AccessType='secure' WHERE id_=7100");$denied=false;try{$service->job(1,(int)$audioJob['id_']);}catch(Throwable){$denied=true;}$check($denied,'Revoked original access invalidates derived multimedia job access');
 $add(7200,'Reutilizada.mp4','Data/source/old.mp4','fixture old mp4');
 $oldRaw=json_encode(['jobName'=>'arcade-existing','results'=>['audio_segments'=>[['start_time'=>10,'end_time'=>30,'transcript'=>'Transcripción ya disponible en ArcadeCloud 88317']]]]);
 $add(7201,'Reutilizada.json','Data/source/old.json',$oldRaw);
 $oldMeta=json_encode(['origen_encriptado'=>'Data/source/old.mp4','servicio'=>'Amazon Transcribe','status'=>'COMPLETED']);$q=$db->prepare('UPDATE FileS3 SET Metadatos=? WHERE id_=7201');$q->bind_param('s',$oldMeta);$q->execute();$q->close();
 $transcribe->jobs['arcade-existing']=['Media'=>['MediaFileUri'=>'s3://private-bucket/Data/source/old.mp4'],'StartTime'=>new DateTimeImmutable('2026-10-21T00:00:00Z'),'TranscriptionJobStatus'=>'COMPLETED'];
 $before=count($transcribe->starts);$old=$attach->attach(1,1,7200);$oldJob=$service->enqueue(1,1,(int)$old['id_']);$oldDone=$finish((int)$oldJob['id_']);
 $check($oldDone['status']==='ready'&&count($transcribe->starts)===$before,'ArcadeCloud canonical transcript is owner/source/freshness verified and reused without resubmission');
 $check((int)$db->query("SELECT COUNT(*) n FROM AttachmentProcessingCosts WHERE operation='transcription_result'")->fetch_assoc()['n']===1,'Existing ArcadeCloud transcription is not charged again in MiChat');
 // Real tiny PNG bytes exercise the existing bounded vision reader.
 $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j1ioAAAAASUVORK5CYII=');
 $add(7300,'Imagen.png','Data/source/image.png',$png);$image=$attach->attach(1,1,7300);$before=$modelClient->summaries;$imageJob=$service->enqueue(1,1,(int)$image['id_']);$imageDone=$finish((int)$imageJob['id_']);
 $check($imageDone['status']==='ready'&&$modelClient->summaries===$before+1,'Image runs vision once and reuses its description as semantic analysis');
 $imageMeta=json_decode($db->query("SELECT source_ids FROM SessionContextBlocks WHERE s3_path='".$db->real_escape_string(idx_build_s3_key($image))."' AND block_type='file_chunk' LIMIT 1")->fetch_assoc()['source_ids'],true);
 $check(($imageMeta['evidence_kind']??'')==='visual_description'&&!isset($imageMeta['locator']['line_start']),'Generated image interpretation is never given invented original line numbers');
 $zipFixture=static function(array $parts):string{$tmp=tempnam(sys_get_temp_dir(),'document-fixture-');$z=new ZipArchive();$z->open($tmp,ZipArchive::OVERWRITE);foreach($parts as $name=>$content)$z->addFromString($name,$content);$z->close();$body=file_get_contents($tmp);unlink($tmp);return $body;};
 $docx=$zipFixture(['word/document.xml'=>'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.str_repeat('<w:p><w:r><w:t>Contenido repetido para probar lectura por fragmentos sin perder el final del documento.</w:t></w:r></w:p>',1000).'<w:p><w:r><w:t>CLAVE_FINAL_93217</w:t></w:r></w:p></w:body></w:document>']);
 $add(7400,'Documento.docx','Data/source/document.docx',$docx);$docFile=$attach->attach(1,1,7400);$docJob=$service->enqueue(1,1,(int)$docFile['id_']);$docDone=$finish((int)$docJob['id_']);
 $check($docDone['status']==='ready','DOCX completes the same resumable preparation pipeline');
 $docBundle=$builder->build(new MemoryRoute(['intent'=>'general']),['stage'=>'respond','user_id'=>1,'session_id'=>1,'query_text'=>'Qué dice el archivo "Documento.docx" sobre CLAVE_FINAL_93217','pipeline_features'=>$features]);
 $check(str_contains(AttachmentPromptGrounding::append('',$docBundle),'CLAVE_FINAL_93217')&&str_contains($docBundle->block('attachment_request_block'),'RAG híbrido'),'Hybrid retrieval finds evidence near the end instead of relying on overview samples');
 $ppsx=$zipFixture(['ppt/presentation.xml'=>'<p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><p:sldIdLst><p:sldId id="256" r:id="rId1"/></p:sldIdLst></p:presentation>','ppt/_rels/presentation.xml.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="slides/slide1.xml" Type="slide"/></Relationships>','ppt/slides/slide1.xml'=>'<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:p><a:r><a:t>Presentación 88317</a:t></a:r></a:p></p:sld>','ppt/slides/_rels/slide1.xml.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="note" Target="../notesSlides/notesSlide1.xml" Type="notesSlide"/></Relationships>','ppt/notesSlides/notesSlide1.xml'=>'<p:notes xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:p><a:r><a:t>NOTA_VERIFICABLE_771</a:t></a:r></a:p></p:notes>']);
 $add(7500,'Presentación.ppsx','Data/source/presentation.ppsx',$ppsx);$presentation=$attach->attach(1,1,7500);$presentationJob=$service->enqueue(1,1,(int)$presentation['id_']);$presentationDone=$finish((int)$presentationJob['id_']);
 $presentationRows=$db->query("SELECT content_preview,source_ids FROM SessionContextBlocks WHERE s3_path='".$db->real_escape_string(idx_build_s3_key($presentation))."' AND block_type='file_chunk'")->fetch_all(MYSQLI_ASSOC);
 $check($presentationDone['status']==='ready'&&str_contains(implode(' ',array_column($presentationRows,'content_preview')),'NOTA_VERIFICABLE_771'),'PPSX preparation includes notes, not only visible slide text');
 $check((json_decode($presentationRows[0]['source_ids'],true)['locator']['slide']??0)===1,'Presentation chunks retain the verified original slide position');
 // A minimal image-only PDF has no digital text; the provider double supplies OCR.
 $objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << >> /Contents 4 0 R >>',"<< /Length 0 >>\nstream\n\nendstream"];
 $pdf="%PDF-1.4\n";$offsets=[];foreach($objects as $i=>$object){$offsets[]=strlen($pdf);$pdf.=($i+1)." 0 obj\n".$object."\nendobj\n";}$xref=strlen($pdf);$pdf.="xref\n0 5\n0000000000 65535 f \n";foreach($offsets as $offset)$pdf.=sprintf('%010d 00000 n ',$offset)."\n";$pdf.="trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
 $add(7600,'Escaneado.pdf','Data/source/scan.pdf',$pdf);$scan=$attach->attach(1,1,7600);$scanJob=$service->enqueue(1,1,(int)$scan['id_']);$scanWaiting=$service->tick(1,(int)$scanJob['id_']);
 $check($scanWaiting['status']==='waiting','PDF without digital text checkpoints a real asynchronous OCR wait');
 $textract->complete=true;$scanDone=$finish((int)$scanJob['id_']);$scanRows=$db->query("SELECT content_preview,source_ids FROM SessionContextBlocks WHERE s3_path='".$db->real_escape_string(idx_build_s3_key($scan))."' AND block_type='file_chunk'")->fetch_all(MYSQLI_ASSOC);
 $check($scanDone['status']==='ready'&&str_contains(implode(' ',array_column($scanRows,'content_preview')),'OCR_VERIFICABLE_4417'),'Completed OCR result proceeds through chunking, embeddings and semantics');
 $check((json_decode($scanRows[0]['source_ids'],true)['locator']['page']??0)===1&&count(array_unique($textract->tokens))===1,'OCR page locator and idempotency token survive polling/restarts');
 $q=$db->prepare("UPDATE UserAIAgentConfigs SET is_active=0 WHERE agent_key='embedding_main'");$q->execute();$q->close();
 $add(7700,'Bloqueado.xlsx','Data/source/blocked.xlsx',arcadecloudXlsxFixture());$blockedFile=$attach->attach(1,1,7700);$blockedJob=$service->enqueue(1,1,(int)$blockedFile['id_']);$blocked=$finish((int)$blockedJob['id_']);
 $check($blocked['status']==='blocked','Missing embedding configuration is explicit and cannot masquerade as completed RAG');
 $db->query("UPDATE UserAIAgentConfigs SET is_active=1 WHERE agent_key='embedding_main'");$service->enqueue(1,1,(int)$blockedFile['id_']);$recovered=$finish((int)$blockedJob['id_']);
 $check($recovered['status']==='ready','A new explicit request resumes the blocked phase after configuration is corrected');
 echo "OK $checks multimodal processing checks\n";
}finally{unlink($prices);putenv('MICHAT_PROCESSING_PRICES_PATH');}
