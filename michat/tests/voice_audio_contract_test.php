<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$modal = (string)file_get_contents($root . '/includes/preferences/modal.php');
$voiceUi = (string)file_get_contents($root . '/includes/preferences/voice.php');
$preferences = (string)file_get_contents($root . '/voice_preferences.php');
$turn = (string)file_get_contents($root . '/voice_turn.php');
$runtimePath = $root . '/includes/ai_agent_runtime.php';

$passed = 0;
$failed = 0;
$check = function (bool $ok, string $message) use (&$passed, &$failed): void {
    if ($ok) { $passed++; echo "PASS {$message}\n"; }
    else { $failed++; echo "FAIL {$message}\n"; }
};

$check(str_contains($modal, "require __DIR__ . '/voice.php'"), 'Preferencias incluye Voz / Audio');
$check(str_contains($voiceUi, 'aiVoiceActive') && str_contains($voiceUi, 'aiVoiceSpeaker'), 'UI permite activar voz y elegir locutor');
$check(str_contains($voiceUi, 'amazon.nova-2-sonic-v1:0'), 'UI usa Nova 2 Sonic');
$check(str_contains($voiceUi, 'value="lupe"') && str_contains($voiceUi, 'value="carlos"'), 'UI ofrece voces españolas Lupe y Carlos');
$check(str_contains($preferences, "'agent_key' => 'voice_main'"), 'Preferencias persiste agent_key voice_main');
$check(str_contains($preferences, "'is_active' => 0"), 'Voz queda desactivada por defecto');
$check(str_contains($preferences, "'token_usage_phase' => 'respond'"), 'Voz usa fase TokenUsage compatible con el esquema histórico');
$check(str_contains($preferences, 'hash_equals') && str_contains($preferences, 'upsertUserOverride'), 'Guardado de voz conserva CSRF y override por usuario');
$check(str_contains($turn, "ChatSessions WHERE id_=? AND user_id_=?"), 'Turno de voz valida ownership de sesión');
$check(str_contains($turn, "aiAgentConfig('voice_main')") && str_contains($turn, "['is_active']"), 'Runtime de voz respeta activación del agente');
$check(str_contains($turn, 'proc_open([') && !str_contains($turn, 'shell_exec(') && !str_contains($turn, 'exec('), 'Bridge Node se invoca sin shell arbitrario');
$check(str_contains($turn, 'INSERT INTO ChatMessages') && str_contains($turn, 'INSERT INTO TokenUsage'), 'Transcripciones y tokens se persisten');
$check(str_contains($turn, 'audio_pcm_base64') && str_contains($turn, 'input_speech_tokens'), 'Endpoint devuelve audio y telemetría de voz');

require_once $runtimePath;
$GLOBALS['AI_AGENT_CONFIGS'] = [
    'attachment_vision' => ['token_usage_phase' => 'vision'],
    'voice_main' => ['token_usage_phase' => 'voice'],
];
$check(aiAgentValue('attachment_vision', 'token_usage_phase', '') === 'rag', 'visión mapea a fase RAG válida para TokenUsage');
$check(aiAgentValue('voice_main', 'token_usage_phase', '') === 'respond', 'voz mapea a fase respond válida para TokenUsage');

printf("Result: %d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
