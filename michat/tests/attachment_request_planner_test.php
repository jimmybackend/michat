<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/Drive/AttachmentRequestService.php';
$n=0;function requestCheck(bool $ok,string $label):void{global $n;$n++;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$files=[['id_'=>1,'Nombre'=>'uno.php'],['id_'=>2,'Nombre'=>'dos.php']];
requestCheck(!AttachmentRequestService::plan('Hola',$files)['requested'],'Idle attachments do not activate processing');
requestCheck(!AttachmentRequestService::plan('Subí el archivo uno.php',$files)['requested'],'Merely naming an upload is not a content request');
$p=AttachmentRequestService::plan('Busca "exit" en "uno.php"',$files);
requestCheck($p['strategy']==='literal'&&$p['term']==='exit'&&count($p['files'])===1,'Quoted literal and target are separated without an AI planner');
requestCheck(AttachmentRequestService::plan('Analiza "inexistente.php"',[$files[0]])['strategy']==='clarify','Unknown filename cannot fall back to another available file');
requestCheck(AttachmentRequestService::plan('Resume el documento',$files)['strategy']==='clarify','Multiple unspecified attachments need clarification');
requestCheck(AttachmentRequestService::plan('Resúmelo',$files,2)['files'][0]['id_']===2,'Anaphoric follow-up can focus the last requested file');
requestCheck(AttachmentRequestService::plan('Lista las clases y métodos de "uno.php"',$files)['strategy']==='structure','Structure selects a parser instead of an embedding search');
requestCheck(AttachmentRequestService::plan('Dónde está el archivo "uno.php"; muestra su ruta',$files)['strategy']==='metadata','Metadata questions need no extraction');
requestCheck(count(AttachmentRequestService::plan('Compara ambos archivos',$files)['files'])===2,'Explicit multi-file comparison targets both accessible attachments');
$rows=[];for($i=1;$i<=41;$i++)$rows[]=['id_'=>$i,'content_preview'=>'Parte '.$i,'source_ids'=>json_encode(['chunk'=>$i,'locator'=>['line_start'=>$i,'line_end'=>$i]])];
$sample=AttachmentRequestService::select($rows,'overview','','txt');
requestCheck(count($sample['rows'])===8&&$sample['rows'][7]['id_']===41&&$sample['partial']&&str_contains($sample['message'],'no una lectura exhaustiva'),'Large overview samples across the document and declares partial coverage');
$search=AttachmentRequestService::select($rows,'literal','Parte 41','txt');
requestCheck(count($search['rows'])===1&&$search['rows'][0]['id_']===41&&str_contains($search['rows'][0]['content_preview'],'L41:'),'Literal search scans the last chunk, not only the first candidates');
$php="<?php\n// function fake() {}\n\$text='class Fake {}';\nclass Real {\n public function run() {}\n}\n";
$structure=AttachmentRequestService::select([['id_'=>1,'content_preview'=>$php,'source_ids'=>json_encode(['locator'=>['line_start'=>1]])]],'structure','','php');
$text=$structure['rows'][0]['content_preview'];
requestCheck(str_contains($text,'Real')&&str_contains($text,'run · línea 5')&&!str_contains($text,'Fake')&&!str_contains($text,'fake'),'PHP tokenizer ignores fake declarations in comments and strings');
$invalid=false;try{AttachmentRequestService::select([['id_'=>1,'content_preview'=>'<?php class A {}','source_ids'=>json_encode(['locator'=>['line_start'=>2147483647]])]],'structure','','php');}catch(DocumentProcessingException $e){$invalid=$e->reason==='invalid_locator';}
requestCheck($invalid,'Untrusted cached locators cannot allocate an unbounded reconstructed source');
$automatic='Analiza el archivo "cleanupec2s3_tmp.txt". Explica de qué trata, qué contiene, dónde se encuentra cada dato relevante, y cita el nombre del archivo y los fragmentos. Si no está indexado o falta contexto, indícalo sin inventar.';
$plan=AttachmentRequestService::plan($automatic,[['id_'=>3,'Nombre'=>'cleanupec2s3_tmp.txt']]);
requestCheck($plan['strategy']==='overview'&&$plan['term']==='','The existing automatic question explains the document instead of searching for its own instructions');
requestCheck(AttachmentRequestService::plan('Busca errores en "uno.php"',$files)['strategy']==='review','Searching for errors requests a review rather than grepping for the word errors');
requestCheck(AttachmentRequestService::plan('Busca "errores" en "uno.php"',$files)['strategy']==='literal','A quoted search for errors remains a literal search');
requestCheck(AttachmentRequestService::plan('Explica dónde se  encuentra cada dato de "uno.php"',$files)['strategy']==='overview','Variable whitespace in a location clause never becomes an imperative search');
echo "OK $n attachment request planning checks\n";
