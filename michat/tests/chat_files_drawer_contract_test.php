<?php
declare(strict_types=1);

// Contrato del acceso directo a archivos tras quitar el panel duplicado.
$root = dirname(__DIR__);
$html = file_get_contents($root.'/chat.php');
$js = file_get_contents($root.'/js/chat.js');
$upload = file_get_contents($root.'/session_upload.php');
$service = file_get_contents($root.'/includes/Chat/SessionLifecycleService.php');
$problems = [];
$check = static function(bool $ok,string $reason) use (&$problems):void {
    if (!$ok) $problems[]=$reason;
};

$check(substr_count($html,'id="chat2Attach"')===1,'Hay un clip inferior único');
$check(substr_count($html,'id="chat2File"')===1,'Existe un input de selección de archivos');
$check(strpos($html,'id="chat2Attach"') < strpos($html,'id="chat2BtnGenImg"'),
    'El clip precede al botón de imagen');
$check(!str_contains($html,'id="chatFilesOpen"') && !str_contains($html,'id="chatFilesDrawer"'),
    'No se muestra el panel superior duplicado');
$check(!str_contains($html,'id="modalSessionAttachments"'),
    'No existe el modal duplicado de adjuntos');
$check(str_contains($js,'async function uploadComposerFiles(')
    && str_contains($js,"el.file?.click()"),'El clip permite seleccionar y subir');
$check(str_contains($js,"fetch('session_upload.php'"),'La subida usa el endpoint existente');
$check(str_contains($js,"createSession('Nueva conversación (archivos)', {allowModelFallback: true})"),
    'Crea conversación si no hay una seleccionada');
$check(str_contains($js,'currentSessionStatus !== \'open\''),
    'Se comprueba que la conversación esté activa');
$check(str_contains($upload,'SessionAttachmentKnowledgeService')
    && str_contains($upload,'SessionImageKnowledgeService')
    && str_contains($upload,'$knowledgeService->process(')
    && str_contains($upload,'$imageKnowledgeService->process('),
    'La subida procesa automáticamente texto e imágenes para RAG');
$check(str_contains($upload,"(\$session['status'] ?? '') !== 'open'"),
    'El backend rechaza subidas a conversaciones archivadas/eliminadas');
$check(str_contains($service,"FileS3 WHERE user_id_=?"),
    'Una conversación con archivos no se descarta como vacía');
$check(str_contains($js,'btn-project-sources') && str_contains($js,'openProjectSourcesModal'),
    'La indexación manual de proyecto sigue disponible desde Gestionar proyectos');

if ($problems) {
    fwrite(STDERR, "Direct file upload checks FAILED:\n- ".implode("\n- ",$problems)."\n");
    exit(1);
}
echo "Direct file upload and RAG contract: OK\n";
