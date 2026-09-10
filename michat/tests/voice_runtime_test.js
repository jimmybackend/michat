'use strict';

const fs = require('fs');
const path = require('path');
const child = require('child_process');
const root = path.resolve(__dirname, '..');
const browser = fs.readFileSync(path.join(root, 'js/chat-voice.js'), 'utf8');
const sonic = fs.readFileSync(path.join(root, 'voice/sonic_turn.mjs'), 'utf8');
const pkg = JSON.parse(fs.readFileSync(path.join(root, 'voice/package.json'), 'utf8'));

let passed = 0;
let failed = 0;
function check(ok, label) {
  if (ok) { passed++; console.log('PASS ' + label); }
  else { failed++; console.log('FAIL ' + label); }
}

check(browser.includes("#chat2BtnSonic"), 'cliente enlaza el botón de micrófono existente');
check(browser.includes('navigator.mediaDevices.getUserMedia'), 'cliente solicita micrófono mediante API segura');
check(browser.includes('downsample') && browser.includes('setInt16'), 'cliente convierte audio a PCM 16 kHz/16-bit');
check(browser.includes("voice_turn.php"), 'cliente envía turno al endpoint autenticado');
check(browser.includes('audio_pcm_base64') && browser.includes('audio/wav'), 'cliente reproduce PCM de respuesta como WAV');
check(browser.includes('input_tokens') && browser.includes('output_tokens'), 'cliente muestra tokens del turno de voz');
check(sonic.includes('InvokeModelWithBidirectionalStreamCommand'), 'bridge usa API bidireccional de Bedrock');
check(sonic.includes("amazon.nova-2-sonic-v1:0"), 'bridge limita el modelo a Nova 2 Sonic');
check(sonic.includes("stage !== 'SPECULATIVE'"), 'bridge no persiste transcripciones especulativas');
check(sonic.includes('usageEvent') && sonic.includes('totalInputTokens') && sonic.includes('totalOutputTokens'), 'bridge recoge telemetría oficial de tokens');
check(sonic.includes("sampleRateHertz: INPUT_RATE") && sonic.includes("sampleRateHertz: OUTPUT_RATE"), 'bridge usa 16 kHz entrada y 24 kHz salida');
check(pkg.dependencies['@aws-sdk/client-bedrock-runtime'], 'bridge declara SDK Bedrock Runtime');
check(pkg.dependencies['@smithy/node-http-handler'], 'bridge declara transporte HTTP/2 requerido');

try {
  child.execFileSync(process.execPath, ['--check', path.join(root, 'voice/sonic_turn.mjs')], { stdio: 'pipe' });
  child.execFileSync(process.execPath, ['--check', path.join(root, 'js/chat-voice.js')], { stdio: 'pipe' });
  check(true, 'JavaScript de voz tiene sintaxis válida');
} catch (error) {
  check(false, 'JavaScript de voz tiene sintaxis válida');
}

console.log(`Result: ${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
