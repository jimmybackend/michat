<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$policyPath = $root . '/michat/includes/Videos/VideoGenerationPolicy.php';
$prefsPath = $root . '/michat/video_generation_preferences.php';
$uiPath = $root . '/michat/includes/preferences/video_generation.php';
$modalPath = $root . '/michat/includes/preferences/modal.php';
$startPath = $root . '/michat/chat_gen_video_start.php';
$statusPath = $root . '/michat/chat_gen_video_status.php';
$chatJsPath = $root . '/michat/js/chat.js';
$runtimePath = $root . '/michat/includes/ai_agent_runtime.php';

$passed = 0;
$failed = 0;
$check = function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $ok ? $passed++ : $failed++;
};

foreach ([$policyPath,$prefsPath,$uiPath,$modalPath,$startPath,$statusPath,$chatJsPath,$runtimePath] as $file) {
    $check(is_file($file), 'existe ' . basename($file));
}

require_once $policyPath;
$check(VideoGenerationPolicy::DEFAULT_MODEL === 'amazon.nova-reel-v1:1', 'Nova Reel 1.1 es default actual');
$check(VideoGenerationPolicy::isAllowed('amazon.nova-reel-v1:1'), 'Nova Reel 1.1 permitido');
$check(VideoGenerationPolicy::isAllowed('amazon.nova-reel-v1:0'), 'Nova Reel 1.0 permitido como fallback regional');
$check(!VideoGenerationPolicy::isAllowed('amazon.nova-lite-v1:0'), 'modelo de comprensión no se acepta como generador de video');
$check(VideoGenerationPolicy::supportsRegion('amazon.nova-reel-v1:1', 'us-east-1'), 'Reel 1.1 permitido en us-east-1');
$check(!VideoGenerationPolicy::supportsRegion('amazon.nova-reel-v1:1', 'eu-west-1'), 'Reel 1.1 bloqueado fuera de us-east-1');
$check(VideoGenerationPolicy::supportsRegion('amazon.nova-reel-v1:0', 'eu-west-1'), 'Reel 1.0 conserva fallback regional');
$check(VideoGenerationPolicy::normalizeStatus('InProgress') === 'in_progress', 'estado AWS InProgress se normaliza para el polling web');
$defaults = VideoGenerationPolicy::defaults();
$check($defaults['duration_seconds'] === 6 && $defaults['fps'] === 24 && $defaults['dimension'] === '1280x720', 'clip simple usa contrato Nova Reel 6s 720p 24fps');

$prefs = (string)file_get_contents($prefsPath);
$ui = (string)file_get_contents($uiPath);
$modal = (string)file_get_contents($modalPath);
$start = (string)file_get_contents($startPath);
$status = (string)file_get_contents($statusPath);
$chatJs = (string)file_get_contents($chatJsPath);
$runtime = (string)file_get_contents($runtimePath);

$check(str_contains($modal, "require __DIR__ . '/video_generation.php'"), 'Preferencias carga bloque video_main');
$check(str_contains($ui, 'aiVideoGenerationModel') && str_contains($ui, 'aiVideoGenerationActive'), 'UI permite modelo y activación de video');
$check(str_contains($ui, 'Nova Reel 1.1') && str_contains($ui, 'Nova Reel 1.0'), 'UI sólo ofrece generadores Amazon Nova Reel');
$check(str_contains($prefs, "upsertUserOverride(\$userId, 'video_main'") && str_contains($prefs, 'csrf_token'), 'preferencia se persiste por usuario con CSRF');
$check(str_contains($prefs, 'ensureVideoGenerationGlobal') && str_contains($prefs, 'defaultGlobalConfig'), 'primer cambio crea configuración global idempotente');
$check(str_contains($prefs, 'supportsRegion'), 'preferencias validan compatibilidad regional');

$check(str_contains($start, "aiAgentConfig('video_main')") && str_contains($start, "aiAgentActive('video_main'"), 'inicio respeta video_main y su interruptor');
$check(str_contains($start, "aiAgentModel('video_main'") && str_contains($start, 'VideoGenerationPolicy::isAllowed'), 'modelo de video se resuelve server-side');
$check(!str_contains($start, "\$_POST['model']") && !str_contains($start, '$owner_id'), 'cliente no selecciona modelo arbitrario y no queda owner_id indefinido');
$check(str_contains($start, 'resolveOwnedSession($userId, $sessionId)'), 'ownership de sesión se valida antes de generar');
$guard = strpos($start, 'resolveOwnedSession(');
$check($guard !== false && $guard < strpos($start, 'INSERT INTO ChatMessages') && $guard < strpos($start, 'Config::getS3()') && $guard < strpos($start, 'startAsyncInvoke('), 'guard de ownership precede DB, S3 y Bedrock');
$check(str_contains($start, '$db_connection->insert_id') && !str_contains($start, 'MAX(id_)'), 'placeholder usa AUTO_INCREMENT seguro');
$check(str_contains($start, 'Config::getBedrockRuntime()') && str_contains($start, "'taskType' => 'TEXT_VIDEO'") && str_contains($start, 'startAsyncInvoke('), 'inicio usa Bedrock Async Invoke y TEXT_VIDEO');
$check(str_contains($start, "'durationSeconds' => \$durationSeconds") && str_contains($start, "'dimension' => \$dimension"), 'parámetros de video salen de política server-side');
$check(str_contains($start, 'Chat/GenerationsVideos/') && str_contains($start, "'s3OutputDataConfig'"), 'Bedrock escribe video bajo namespace privado del usuario/sesión');

$statusGuard = strpos($status, 'resolveOwnedMessage(');
$check($statusGuard !== false && $statusGuard < strpos($status, 'Config::getS3()') && $statusGuard < strpos($status, 'UPDATE ChatMessages'), 'status revalida ownership antes de S3 y DB');
$check(str_contains($status, 'getAsyncInvoke(') && str_contains($status, "'/output.mp4'"), 'status consulta Bedrock y detecta output.mp4');
$check(str_contains($status, "'status' => 'completed'") && str_contains($status, 's3_key=?'), 'video completado se persiste en ChatMessages');
$check(str_contains($status, 'INSERT INTO TokenUsage') && str_contains($status, "'billing_unit' => 'video_second'"), 'telemetría registra ejecución sin inventar tokens de texto');
$check(str_contains($chatJs, "genVideoStart: 'chat_gen_video_start.php'") && str_contains($chatJs, "genVideoStatus: 'chat_gen_video_status.php'") && str_contains($chatJs, 'autoGenerateVideo(prompt)'), 'chat reutiliza router/botón y polling de video existentes');
$check(str_contains($chatJs, "ct === 'video'") && str_contains($chatJs, '<video'), 'historial renderiza video generado');
$check(str_contains($runtime, "'video_main'"), 'snapshot runtime expone video_main');

echo "Result: {$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
