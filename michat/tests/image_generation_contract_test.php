<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$policyPath = $root . '/michat/includes/Images/ImageGenerationPolicy.php';
$prefsPath = $root . '/michat/image_generation_preferences.php';
$uiPath = $root . '/michat/includes/preferences/image_generation.php';
$modalPath = $root . '/michat/includes/preferences/modal.php';
$endpointPath = $root . '/michat/chat_gen_image.php';
$chatJsPath = $root . '/michat/js/chat.js';
$runtimePath = $root . '/michat/includes/ai_agent_runtime.php';

$passed = 0;
$failed = 0;
$check = function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $ok ? $passed++ : $failed++;
};

foreach ([$policyPath,$prefsPath,$uiPath,$modalPath,$endpointPath,$chatJsPath,$runtimePath] as $file) {
    $check(is_file($file), 'existe ' . basename($file));
}

require_once $policyPath;
$check(ImageGenerationPolicy::DEFAULT_MODEL === 'amazon.titan-image-generator-v2:0', 'Titan Image v2 es fallback durable por defecto');
$check(ImageGenerationPolicy::isAllowed('amazon.titan-image-generator-v2:0'), 'Titan Image v2 permitido');
$check(ImageGenerationPolicy::isAllowed('amazon.nova-canvas-v1:0'), 'Nova Canvas permitido mientras siga disponible');
$check(!ImageGenerationPolicy::isAllowed('amazon.nova-lite-v1:0'), 'modelo de comprensión no se acepta como generador');
$check(ImageGenerationPolicy::maxPromptChars('amazon.nova-canvas-v1:0') === 1024, 'límite de prompt Nova Canvas protegido');
$check(ImageGenerationPolicy::maxPromptChars('amazon.titan-image-generator-v2:0') === 512, 'límite de prompt Titan protegido');

$prefs = (string)file_get_contents($prefsPath);
$ui = (string)file_get_contents($uiPath);
$modal = (string)file_get_contents($modalPath);
$endpoint = (string)file_get_contents($endpointPath);
$chatJs = (string)file_get_contents($chatJsPath);
$runtime = (string)file_get_contents($runtimePath);

$check(str_contains($modal, "require __DIR__ . '/image_generation.php'"), 'Preferencias carga el bloque image_main');
$check(str_contains($ui, 'aiImageGenerationModel') && str_contains($ui, 'aiImageGenerationActive'), 'UI permite elegir modelo y activar/desactivar');
$check(str_contains($ui, 'Titan Image Generator v2') && str_contains($ui, 'Nova Canvas'), 'UI muestra los dos generadores soportados');
$check(str_contains($prefs, "upsertUserOverride(\$userId, 'image_main'") && str_contains($prefs, 'csrf_token'), 'preferencia se persiste por usuario con CSRF');
$check(str_contains($prefs, 'ensureImageGenerationGlobal') && str_contains($prefs, 'defaultGlobalConfig'), 'primer cambio crea configuración global idempotente');

$check(str_contains($endpoint, "aiAgentConfig('image_main')") && str_contains($endpoint, "aiAgentActive('image_main'"), 'runtime respeta image_main y su interruptor');
$check(str_contains($endpoint, "aiAgentModel('image_main'") && str_contains($endpoint, 'ImageGenerationPolicy::isAllowed'), 'modelo se resuelve del servidor y se valida');
$check(!str_contains($endpoint, "\$_POST['model']") && !str_contains($endpoint, '\$owner_id'), 'cliente no elige modelo arbitrario y no queda owner_id indefinido');
$check(str_contains($endpoint, 'resolveOwnedSession($userId, $sessionId)'), 'generación valida ownership de sesión antes de Bedrock');
$check(str_contains($endpoint, 'Config::getBedrockRuntime()') && str_contains($endpoint, "'taskType' => 'TEXT_IMAGE'"), 'generación usa Bedrock configurado y contrato TEXT_IMAGE');
$check(str_contains($endpoint, "'ACL' => 'private'") && str_contains($endpoint, "Chat/GenerationsImages/"), 'imagen se guarda privada en S3 bajo namespace de generaciones');
$check(str_contains($endpoint, 'INSERT INTO ChatMessages') && str_contains($endpoint, "'assistant','image'"), 'resultado se persiste como mensaje de imagen');
$check(!str_contains($endpoint, 'MAX(id_)') && str_contains($endpoint, '$db_connection->insert_id'), 'mensaje usa AUTO_INCREMENT seguro y evita carrera MAX(id)+1');
$check(str_contains($endpoint, 'INSERT INTO TokenUsage') && str_contains($endpoint, "'billing_unit' => 'image'"), 'telemetría registra llamada sin inventar tokens');
$check(!str_contains($endpoint, "\$_POST['width']") && !str_contains($endpoint, "\$_POST['height']"), 'dimensiones costosas no se controlan desde el cliente');

$check(str_contains($chatJs, "genImage: 'chat_gen_image.php'") && str_contains($chatJs, 'autoGenerateImage(prompt)'), 'chat ya conecta botón/router con endpoint de imagen');
$check(str_contains($chatJs, "ct === 'image'") && str_contains($chatJs, '<img src='), 'historial ya renderiza imágenes generadas');
$check(str_contains($runtime, "'image_main'") && str_contains($runtime, "'attachment_vision'") && str_contains($runtime, "'voice_main'"), 'snapshot runtime expone imagen, visión y voz');

echo "Result: {$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
