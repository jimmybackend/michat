(function () {
  'use strict';

  const MODEL = 'amazon.nova-2-sonic-v1:0';
  const INPUT_RATE = 16000;
  const DEFAULT_MAX_SECONDS = 30;
  let recording = false;
  let processing = false;
  let stream = null;
  let context = null;
  let source = null;
  let processor = null;
  let chunks = [];
  let startedAt = 0;
  let stopTimer = null;

  const $ = (s) => document.querySelector(s);
  const button = $('#chat2BtnSonic');
  if (!button || button.dataset.voiceWired === '1') return;
  button.dataset.voiceWired = '1';

  function csrf() {
    return String($('meta[name="csrf-token"]')?.content || '');
  }
  function status(text, busy) {
    const node = $('#chat2Status');
    if (node) node.textContent = text || '';
    button.disabled = !!busy;
  }
  function currentSessionId() {
    const active = $('#chat-sidebar [data-id].active') || $('.chat-msg.assistant[data-session-id]');
    return Number(active?.dataset?.id || active?.dataset?.sessionId || 0);
  }
  async function ensureSessionId() {
    let id = currentSessionId();
    if (id) return id;
    const newButton = $('#sbNewChat');
    if (!newButton) throw new Error('Crea o selecciona una conversación antes de usar voz.');
    newButton.click();
    const deadline = Date.now() + 4000;
    while (Date.now() < deadline) {
      await new Promise(resolve => setTimeout(resolve, 100));
      id = currentSessionId();
      if (id) return id;
    }
    throw new Error('No se pudo crear una conversación para el turno de voz.');
  }
  async function config() {
    const response = await fetch('voice_preferences.php?action=get', {
      credentials: 'same-origin',
      cache: 'no-cache'
    });
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.error || ('HTTP ' + response.status));
    if (Number(data.is_active) !== 1) throw new Error('Activa Voz / Audio en Preferencias → Modelos IA.');
    if (data.model_id !== MODEL) throw new Error('El modelo de voz configurado no es compatible.');
    return data;
  }
  function cleanupRecording() {
    if (stopTimer) clearTimeout(stopTimer);
    stopTimer = null;
    try { processor?.disconnect(); } catch (_) {}
    try { source?.disconnect(); } catch (_) {}
    if (stream) stream.getTracks().forEach(track => track.stop());
    try { context?.close(); } catch (_) {}
    processor = null;
    source = null;
    stream = null;
    context = null;
    recording = false;
    button.classList.remove('btn-danger');
    button.classList.add('btn-outline-secondary');
    button.innerHTML = '<i class="fas fa-microphone"></i>';
    button.title = 'Voz';
    button.setAttribute('aria-label', 'Voz');
  }
  function flatten(input) {
    const length = input.reduce((sum, part) => sum + part.length, 0);
    const out = new Float32Array(length);
    let offset = 0;
    for (const part of input) { out.set(part, offset); offset += part.length; }
    return out;
  }
  function downsample(samples, sourceRate) {
    if (sourceRate === INPUT_RATE) return samples;
    const ratio = sourceRate / INPUT_RATE;
    const outLength = Math.max(1, Math.floor(samples.length / ratio));
    const out = new Float32Array(outLength);
    for (let i = 0; i < outLength; i++) {
      const start = Math.floor(i * ratio);
      const end = Math.min(samples.length, Math.max(start + 1, Math.floor((i + 1) * ratio)));
      let sum = 0;
      for (let j = start; j < end; j++) sum += samples[j];
      out[i] = sum / (end - start);
    }
    return out;
  }
  function pcm16(samples) {
    const buffer = new ArrayBuffer(samples.length * 2);
    const view = new DataView(buffer);
    for (let i = 0; i < samples.length; i++) {
      const s = Math.max(-1, Math.min(1, samples[i]));
      view.setInt16(i * 2, s < 0 ? s * 0x8000 : s * 0x7fff, true);
    }
    return buffer;
  }
  function base64Bytes(base64) {
    const binary = atob(base64 || '');
    const out = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) out[i] = binary.charCodeAt(i);
    return out;
  }
  function wavBlob(pcmBytes, sampleRate) {
    const dataSize = pcmBytes.byteLength;
    const buffer = new ArrayBuffer(44 + dataSize);
    const view = new DataView(buffer);
    const write = (offset, text) => { for (let i = 0; i < text.length; i++) view.setUint8(offset + i, text.charCodeAt(i)); };
    write(0, 'RIFF'); view.setUint32(4, 36 + dataSize, true); write(8, 'WAVE');
    write(12, 'fmt '); view.setUint32(16, 16, true); view.setUint16(20, 1, true);
    view.setUint16(22, 1, true); view.setUint32(24, sampleRate, true);
    view.setUint32(28, sampleRate * 2, true); view.setUint16(32, 2, true); view.setUint16(34, 16, true);
    write(36, 'data'); view.setUint32(40, dataSize, true);
    new Uint8Array(buffer, 44).set(pcmBytes);
    return new Blob([buffer], { type: 'audio/wav' });
  }
  async function playResponse(base64, sampleRate) {
    if (!base64) return;
    const blob = wavBlob(base64Bytes(base64), Number(sampleRate || 24000));
    const url = URL.createObjectURL(blob);
    const audio = new Audio(url);
    audio.addEventListener('ended', () => URL.revokeObjectURL(url), { once: true });
    audio.addEventListener('error', () => URL.revokeObjectURL(url), { once: true });
    try { await audio.play(); } catch (_) { URL.revokeObjectURL(url); }
  }
  function refreshChat() {
    const active = $('#chat-sidebar [data-id].active');
    if (active) window.setTimeout(() => active.click(), 50);
  }
  async function start() {
    if (processing || recording) return;
    await config();
    await ensureSessionId();
    if (!navigator.mediaDevices?.getUserMedia) throw new Error('Este navegador no permite capturar micrófono.');
    if (!window.isSecureContext) throw new Error('El micrófono requiere HTTPS.');

    stream = await navigator.mediaDevices.getUserMedia({
      audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true }
    });
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    if (!AudioCtx) throw new Error('Web Audio no está disponible en este navegador.');
    context = new AudioCtx();
    source = context.createMediaStreamSource(stream);
    processor = context.createScriptProcessor(2048, 1, 1);
    chunks = [];
    processor.onaudioprocess = (event) => {
      if (!recording) return;
      chunks.push(new Float32Array(event.inputBuffer.getChannelData(0)));
    };
    source.connect(processor);
    processor.connect(context.destination);
    recording = true;
    startedAt = Date.now();
    button.classList.remove('btn-outline-secondary');
    button.classList.add('btn-danger');
    button.innerHTML = '<i class="fas fa-stop"></i>';
    button.title = 'Detener y enviar voz';
    button.setAttribute('aria-label', 'Detener y enviar voz');
    status('🎙️ Escuchando… pulsa otra vez para enviar.', false);
    stopTimer = setTimeout(() => { if (recording) stopAndSend().catch(showError); }, DEFAULT_MAX_SECONDS * 1000);
  }
  async function stopAndSend() {
    if (!recording || processing) return;
    const sessionId = currentSessionId();
    const sampleRate = context?.sampleRate || 48000;
    const duration = Date.now() - startedAt;
    const captured = chunks;
    cleanupRecording();
    if (!sessionId) throw new Error('La conversación dejó de estar disponible.');
    if (duration < 250 || !captured.length) throw new Error('La grabación fue demasiado corta.');

    const samples = downsample(flatten(captured), sampleRate);
    const raw = pcm16(samples);
    processing = true;
    status('Procesando voz con Nova 2 Sonic…', true);
    try {
      const body = new FormData();
      body.append('csrf_token', csrf());
      body.append('session_id', String(sessionId));
      body.append('audio', new Blob([raw], { type: 'audio/lpcm' }), 'voice.pcm');
      const response = await fetch('voice_turn.php', { method: 'POST', credentials: 'same-origin', body });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || ('HTTP ' + response.status));
      const usage = data.usage || {};
      const usageNode = $('#chat2Usage');
      if (usageNode) usageNode.textContent = `Voz: ${Number(usage.input_tokens || 0)} in / ${Number(usage.output_tokens || 0)} out · ${Number(usage.total_tokens || 0)} tokens`;
      refreshChat();
      status('🔊 Respuesta de voz lista.', false);
      await playResponse(data.audio_pcm_base64 || '', data.output_sample_rate || 24000);
    } finally {
      processing = false;
      button.disabled = false;
    }
  }
  function showError(error) {
    cleanupRecording();
    processing = false;
    button.disabled = false;
    const message = error?.message || String(error);
    status('⚠️ ' + message, false);
    console.error('MiChat voice:', error);
  }

  button.addEventListener('click', () => {
    if (recording) stopAndSend().catch(showError);
    else start().catch(showError);
  });
  window.addEventListener('beforeunload', cleanupRecording, { once: true });
})();
