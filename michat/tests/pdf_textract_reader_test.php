<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/Documents/PdfTextractReader.php';
$checks=0;function pdfCheck(bool $ok,string $label):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$client=new class {
 public array $starts=[],$gets=[],$responses=[];
 public function startDocumentTextDetection(array $r):array{$this->starts[]=$r;return ['JobId'=>'same-job'];}
 public function getDocumentTextDetection(array $r):array{$this->gets[]=$r;return array_shift($this->responses) ?? throw new RuntimeException('Unexpected extra OCR request');}
};
$reader=new PdfTextractReader($client);$head=['ETag'=>'"rev1"','VersionId'=>'version-1'];
$client->responses=[['JobStatus'=>'IN_PROGRESS']];$pending=false;try{$reader->read('bucket','file.pdf',$head,1000000);}catch(PdfOcrPendingException){$pending=true;}
pdfCheck($pending&&count($client->gets)===1,'Pending OCR returns promptly without blocking or false completion');
$client->responses=[['JobStatus'=>'SUCCEEDED','DocumentMetadata'=>['Pages'=>2],'Blocks'=>[['BlockType'=>'LINE','Page'=>1,'Text'=>'Primera página']],'NextToken'=>'next'],['JobStatus'=>'SUCCEEDED','DocumentMetadata'=>['Pages'=>2],'Blocks'=>[['BlockType'=>'LINE','Page'=>2,'Text'=>'Importe exacto 41837']]]];
$text=$reader->read('bucket','file.pdf',$head,1000000);
pdfCheck(str_contains($text,"[Página 2]\nImporte exacto 41837"),'Multipage OCR preserves actual page numbers');
pdfCheck($client->gets[2]['NextToken']==='next'&&count($client->gets)===3,'All result pages retrieved through NextToken');
pdfCheck($client->starts[0]['ClientRequestToken']===$client->starts[1]['ClientRequestToken'],'Retry reuses a stable OCR token for the same source revision');
pdfCheck($client->starts[0]['DocumentLocation']['S3Object']['Version']==='version-1','OCR pins the S3 object version when available');
$client->responses=[['JobStatus'=>'PARTIAL_SUCCESS','DocumentMetadata'=>['Pages'=>2],'Blocks'=>[['BlockType'=>'LINE','Page'=>1,'Text'=>'Texto parcial']]]];
$text=$reader->read('bucket','file.pdf',['ETag'=>'"rev2"'],1000000);
pdfCheck($client->starts[2]['ClientRequestToken']!==$client->starts[1]['ClientRequestToken'],'A different original revision gets a different OCR token');
pdfCheck(str_contains($text,'CONTENIDO NO EXTRAÍDO')&&str_contains($text,'[Página 2]'),'Partial success and missing pages remain explicit');
$client->responses=[['JobStatus'=>'FAILED']];$failed=false;try{$reader->read('bucket','file.pdf',$head,1000000);}catch(RuntimeException){$failed=true;}pdfCheck($failed,'Failed OCR never returns an indexable prefix');
$client->responses=[['JobStatus'=>'SUCCEEDED','Blocks'=>[['BlockType'=>'LINE','Page'=>0,'Text'=>'Unknown page']]]];$failed=false;try{$reader->read('bucket','file.pdf',$head,1000000);}catch(RuntimeException){$failed=true;}pdfCheck($failed,'Unverifiable page number is rejected');
$client->responses=[['JobStatus'=>'SUCCEEDED','Blocks'=>[['BlockType'=>'LINE','Page'=>1,'Text'=>'a']],'NextToken'=>'loop'],['JobStatus'=>'SUCCEEDED','Blocks'=>[],'NextToken'=>'loop']];$failed=false;try{$reader->read('bucket','file.pdf',$head,1000000);}catch(RuntimeException){$failed=true;}pdfCheck($failed,'Repeated pagination tokens cannot loop or silently omit results');
$client->responses=[['JobStatus'=>'SUCCEEDED','Blocks'=>[['BlockType'=>'LINE','Page'=>1,'Text'=>str_repeat('x',8388609)]]]];$failed=false;try{$reader->read('bucket','file.pdf',$head,1000000);}catch(RuntimeException){$failed=true;}pdfCheck($failed,'Oversized OCR fails without silent text truncation');
echo "PDF async OCR: $checks checks passed\n";
