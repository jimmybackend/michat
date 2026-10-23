<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$html=(string)file_get_contents($root.'/chat.php');
$js=(string)file_get_contents($root.'/js/chat.js');
$upload=(string)file_get_contents($root.'/session_upload.php');
$builder=(string)file_get_contents($root.'/includes/Memory/ContextBuilder.php');
$service=(string)file_get_contents($root.'/includes/SessionAttachmentKnowledgeService.php');
$fail=[];
$check=static function(bool $condition,string $label)use(&$fail):void{if(!$condition)$fail[]=$label;};
$check(substr_count($html,'id="chat2Attach"')===1,'Clip inferior único para subir a S3');
$check(substr_count($html,'id="chatFilesOpen"')===1,'Cerebro superior abre conocimiento de archivos');
$check(str_contains($html,'id="chatFilesDrawer"')&&str_contains($html,'id="chatSessionFilesList"'),'Inspector lista archivos e índice');
$check(strpos($html,'id="chat2Attach"')<strpos($html,'id="chat2BtnGenImg"'),'Clip inferior antes de Imagen');
$check(str_contains($js,'async function uploadComposerFiles(')&&str_contains($js,"fetch('session_upload.php'"),'Subida directa a sesión');
$check(str_contains($js,"createSession('Nueva conversación (archivos)', {allowModelFallback: true})"),'Puede crear sesión al subir');
$check(str_contains($js,'data-action="semantic"')&&str_contains($js,'data-action="index"'),'Reintento de semántica y fragmentos por archivo');
$check(str_contains($js,'data-action="ask"')&&str_contains($js,'data-action="compare"'),'Preguntar y comparar archivos desde cerebro');
$check(str_contains($js,'data-action="version"')&&str_contains($js,"fd.append('version_of_file_id'"),'Revisión S3 vinculada sin sobreescritura');
$check(str_contains($upload,'version_of_files3_id')&&str_contains($upload,'revision_number'),'Historial de revisiones en metadata FileS3');
$check(str_contains($upload,'knowledge_status')&&str_contains($upload,'knowledge_issues'),'API distingue guardado e indexado RAG');
$check(str_contains($service,"'file_chunk'")&&str_contains($service,'ready_for_attachment_rag'),'Indexación por fragmentos y embeddings');
$check(str_contains($builder,'explicitFileQuestion')&&str_contains($builder,'fallback_reason'),'Preguntas de archivo pasan por RAG real');
if($fail){fwrite(STDERR,"Archivo y RAG contract FAILED:\n - ".implode("\n - ",$fail)."\n");exit(1);}
echo "Archivo y RAG contract: OK\n";
