<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);

$root = dirname(__DIR__);
$service = (string)file_get_contents($root . '/includes/SessionImageKnowledgeService.php');
$upload = (string)file_get_contents($root . '/session_upload.php');
$index = (string)file_get_contents($root . '/index_session_file.php');
$semantic = (string)file_get_contents($root . '/semantic_session_file.php');
$rag = (string)file_get_contents($root . '/bedrock_chat2.php');

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $ok ? $passed++ : $failed++;
};

$check(
    str_contains($service, "['jpg', 'jpeg', 'png', 'webp', 'gif']"),
    'visión soporta jpg/jpeg/png/webp/gif'
);
$check(
    str_contains($service, "DEFAULT_VISION_MODEL = 'amazon.nova-lite-v1:0'")
    && str_contains($service, "nova-micro")
    && str_contains($service, 'return false'),
    'fallback visual evita Nova Micro y usa Nova Lite'
);
$check(
    str_contains($service, "'image' => [")
    && str_contains($service, "'source' => ['bytes' => \$image['bytes']]")
    && str_contains($service, "'role' => 'user'"),
    'Converse recibe la imagen como content block de usuario'
);
$check(
    str_contains($service, 'BEDROCK_MAX_IMAGE_BYTES = 3932160')
    && str_contains($service, 'BEDROCK_MAX_IMAGE_DIMENSION = 8000')
    && str_contains($service, 'headObject'),
    'límites visuales se validan antes y después de descargar S3'
);
$check(
    str_contains($service, 'TEXTO VISIBLE')
    && str_contains($service, 'RELACIONES O ESTRUCTURA')
    && str_contains($service, 'código, SQL, tablas, mensajes de error'),
    'prompt visual captura OCR semántico, estructura y contenido técnico'
);
$check(
    str_contains($service, "VALUES (?,'file',?,?,1,?,?)")
    && str_contains($service, "'type' => 'visual_summary'")
    && str_contains($service, "'extractor' => 'bedrock_converse_vision'"),
    'descripción visual se persiste como bloque file reutilizable'
);
$check(
    str_contains($service, 'EmbeddingJobs')
    && str_contains($service, "aiAgentActive('embedding_main', false)")
    && str_contains($service, "'search_document'"),
    'descripción visual reutiliza embedding_main y la cola existente'
);
$check(
    str_contains($upload, "SessionImageKnowledgeService::supportsFilename(\$originalName)")
    && str_contains($upload, '? $imageKnowledgeService->process')
    && str_contains($upload, ': $knowledgeService->process'),
    'upload enruta imágenes a visión sin alterar documentos'
);
$check(
    str_contains($index, 'SessionImageKnowledgeService')
    && str_contains($semantic, 'SessionImageKnowledgeService')
    && str_contains($index, '->supports(')
    && str_contains($semantic, '->supports('),
    'reintentos Indexar/Semántica también reconocen imágenes'
);
$check(
    str_contains($rag, "block_type IN ('file', 'file_chunk')")
    && str_contains($rag, 'ARCHIVOS ADJUNTOS RELEVANTES PARA ESTA PREGUNTA'),
    'RAG existente consume visual_summary sin un retriever paralelo'
);
$check(
    str_contains($service, "aiAgentConfig('attachment_vision')")
    && str_contains($service, "aiAgentInstruction(")
    && str_contains($service, "aiAgentUserTemplate('attachment_vision'"),
    'attachment_vision puede sobreescribir modelo e instrucciones desde configuración dinámica'
);

echo "Result: {$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
