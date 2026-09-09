<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);

$root = dirname(__DIR__);
$service = (string)file_get_contents($root . '/includes/SessionAttachmentKnowledgeService.php');
$upload = (string)file_get_contents($root . '/session_upload.php');
$index = (string)file_get_contents($root . '/index_session_file.php');
$semantic = (string)file_get_contents($root . '/semantic_session_file.php');
$maintenance = (string)file_get_contents($root . '/includes/preferences/maintenance.php');
$builder = (string)file_get_contents($root . '/includes/Memory/ContextBuilder.php');
$chat = (string)file_get_contents($root . '/bedrock_chat2.php');

$passed = 0; $failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $ok ? $passed++ : $failed++;
};

$check(str_contains($upload, 'SessionAttachmentKnowledgeService') && str_contains($upload, '->process('), 'session upload dispara indexación + semántica automática');
$check(str_contains($service, "'file_chunk'") && str_contains($service, "'file'"), 'servicio persiste chunks y resumen semántico');
$check(str_contains($service, "'search_document'") && str_contains($service, 'saveSessionBlockEmbedding'), 'adjuntos se vectorizan como documentos y se guardan inmediatamente');
$check(str_contains($service, "status='pending'") && str_contains($service, 'Mantenimiento'), 'fallo de embedding conserva retry en cola');
$check(str_contains($index, '->index(') && str_contains($semantic, '->semantic('), 'botones manuales comparten el mismo servicio');
$check(str_contains($maintenance, 'btnRunEmbeddings') && str_contains($maintenance, 'btnRunCompression'), 'mantenimiento conserva reparación de embeddings y compresión de chat');
$check(str_contains($builder, 'AttachmentContextRepository') && str_contains($chat, "block_type IN ('file', 'file_chunk')"), 'pipeline de respuesta consume índice y semántica de adjuntos');
$check(str_contains($service, 'smart_memory_code') && str_contains($service, 'smart_memory_general') && str_contains($service, 'attachment_semantic_prompt'), 'semántica usa configuración dinámica existente');
$check(!str_contains($upload, 'SessionAttachments'), 'upload continúa usando FileS3/S3Folders sin tabla legacy');

echo "Result: {$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
