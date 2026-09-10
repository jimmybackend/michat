import fs from 'node:fs';
import crypto from 'node:crypto';
import {
  BedrockRuntimeClient,
  InvokeModelWithBidirectionalStreamCommand,
} from '@aws-sdk/client-bedrock-runtime';
import { NodeHttp2Handler } from '@smithy/node-http-handler';

const MAX_AUDIO_BYTES = 16_000 * 2 * 30;
const INPUT_RATE = 16000;
const OUTPUT_RATE = 24000;
const ALLOWED_MODEL = 'amazon.nova-2-sonic-v1:0';
const ALLOWED_VOICES = new Set(['lupe', 'carlos']);

function fail(message, code = 1) {
  process.stderr.write(String(message) + '\n');
  process.exit(code);
}

function argValue(name) {
  const i = process.argv.indexOf(name);
  return i >= 0 && i + 1 < process.argv.length ? process.argv[i + 1] : '';
}

const configPath = argValue('--config');
const audioPath = argValue('--audio');
if (!configPath || !audioPath) fail('Usage: sonic_turn.mjs --config <json> --audio <pcm>');

let config;
try {
  config = JSON.parse(fs.readFileSync(configPath, 'utf8'));
} catch (error) {
  fail('Invalid voice configuration: ' + error.message);
}

let audio;
try {
  audio = fs.readFileSync(audioPath);
} catch (error) {
  fail('Unable to read input audio: ' + error.message);
}
if (!audio.length) fail('Input audio is empty.');
if (audio.length > MAX_AUDIO_BYTES) fail('Input audio exceeds the 30 second PCM limit.');
if (audio.length % 2 !== 0) fail('Input PCM must be 16-bit little-endian.');

const modelId = String(config.model_id || ALLOWED_MODEL);
const voiceId = String(config.voice_id || 'lupe').toLowerCase();
if (modelId !== ALLOWED_MODEL) fail('Unsupported Sonic model.');
if (!ALLOWED_VOICES.has(voiceId)) fail('Unsupported Spanish voice.');

const region = String(config.region || process.env.AWS_REGION || process.env.AWS_DEFAULT_REGION || 'us-east-1');
const systemPrompt = String(config.system_prompt || 'Responde en español de forma clara, natural y breve.');
const history = Array.isArray(config.history) ? config.history.slice(-12) : [];
const maxTokens = Math.max(128, Math.min(2048, Number(config.max_tokens || 1024)));
const temperature = Math.max(0, Math.min(1, Number(config.temperature ?? 0.5)));
const topP = Math.max(0.01, Math.min(1, Number(config.top_p ?? 0.9)));

class AsyncEventQueue {
  constructor() {
    this.items = [];
    this.waiters = [];
    this.closed = false;
  }
  push(value) {
    if (this.closed) return;
    const waiter = this.waiters.shift();
    if (waiter) waiter({ value, done: false });
    else this.items.push(value);
  }
  close() {
    if (this.closed) return;
    this.closed = true;
    while (this.waiters.length) this.waiters.shift()({ value: undefined, done: true });
  }
  [Symbol.asyncIterator]() { return this; }
  next() {
    if (this.items.length) return Promise.resolve({ value: this.items.shift(), done: false });
    if (this.closed) return Promise.resolve({ value: undefined, done: true });
    return new Promise(resolve => this.waiters.push(resolve));
  }
}

function chunkEvent(event) {
  return { chunk: { bytes: new TextEncoder().encode(JSON.stringify({ event })) } };
}
function uuid() { return crypto.randomUUID(); }
function textContentEvents(promptName, role, text) {
  const contentName = uuid();
  return [
    chunkEvent({ contentStart: {
      promptName,
      contentName,
      type: 'TEXT',
      interactive: false,
      role,
      textInputConfiguration: { mediaType: 'text/plain' },
    }}),
    chunkEvent({ textInput: { promptName, contentName, content: text } }),
    chunkEvent({ contentEnd: { promptName, contentName } }),
  ];
}

const queue = new AsyncEventQueue();
const promptName = uuid();
const audioContentName = uuid();

queue.push(chunkEvent({ sessionStart: {
  inferenceConfiguration: { maxTokens, topP, temperature },
}}));
queue.push(chunkEvent({ promptStart: {
  promptName,
  textOutputConfiguration: { mediaType: 'text/plain' },
  audioOutputConfiguration: {
    mediaType: 'audio/lpcm',
    sampleRateHertz: OUTPUT_RATE,
    sampleSizeBits: 16,
    channelCount: 1,
    voiceId,
    encoding: 'base64',
    audioType: 'SPEECH',
  },
}}));
for (const ev of textContentEvents(promptName, 'SYSTEM', systemPrompt)) queue.push(ev);
for (const item of history) {
  const role = String(item?.role || '').toUpperCase();
  const text = String(item?.content || '').trim();
  if (!text || !['USER', 'ASSISTANT'].includes(role)) continue;
  for (const ev of textContentEvents(promptName, role, text.slice(0, 6000))) queue.push(ev);
}
queue.push(chunkEvent({ contentStart: {
  promptName,
  contentName: audioContentName,
  type: 'AUDIO',
  interactive: true,
  role: 'USER',
  audioInputConfiguration: {
    mediaType: 'audio/lpcm',
    sampleRateHertz: INPUT_RATE,
    sampleSizeBits: 16,
    channelCount: 1,
    encoding: 'base64',
    audioType: 'SPEECH',
  },
}}));
for (let offset = 0; offset < audio.length; offset += 2048) {
  const data = audio.subarray(offset, Math.min(audio.length, offset + 2048));
  queue.push(chunkEvent({ audioInput: {
    promptName,
    contentName: audioContentName,
    content: data.toString('base64'),
  }}));
}
queue.push(chunkEvent({ contentEnd: { promptName, contentName: audioContentName } }));

const client = new BedrockRuntimeClient({
  region,
  requestHandler: new NodeHttp2Handler({
    requestTimeout: 120000,
    sessionTimeout: 120000,
    disableConcurrentStreams: false,
    maxConcurrentStreams: 4,
  }),
});

let userTranscript = '';
let assistantTranscript = '';
const audioParts = [];
let inputTokens = 0;
let outputTokens = 0;
let inputSpeechTokens = 0;
let outputSpeechTokens = 0;
let inputTextTokens = 0;
let outputTextTokens = 0;
let completed = false;
let endSent = false;
const contentMeta = new Map();
let currentContentMeta = {};

function generationStage(start) {
  try {
    const extra = start?.additionalModelFields ? JSON.parse(start.additionalModelFields) : {};
    return String(extra.generationStage || 'FINAL').toUpperCase();
  } catch {
    return 'FINAL';
  }
}
function appendText(existing, text) {
  const next = String(text || '');
  if (!next) return existing;
  return (existing + next).trimStart();
}
function finishInputStream() {
  if (endSent) return;
  endSent = true;
  queue.push(chunkEvent({ promptEnd: { promptName } }));
  queue.push(chunkEvent({ sessionEnd: {} }));
  queue.close();
}

const hardTimeout = setTimeout(() => {
  finishInputStream();
}, 90_000);

try {
  const response = await client.send(new InvokeModelWithBidirectionalStreamCommand({
    modelId,
    body: queue,
  }));

  for await (const event of response.body) {
    if (!event?.chunk?.bytes) {
      if (event?.modelStreamErrorException) throw new Error('Bedrock model stream error');
      if (event?.internalServerException) throw new Error('Bedrock internal stream error');
      continue;
    }
    const decoded = new TextDecoder().decode(event.chunk.bytes);
    let payload;
    try { payload = JSON.parse(decoded); } catch { continue; }
    const ev = payload?.event || {};

    if (ev.contentStart) {
      const start = ev.contentStart;
      currentContentMeta = {
        role: String(start.role || '').toUpperCase(),
        stage: generationStage(start),
        type: String(start.type || '').toUpperCase(),
      };
      contentMeta.set(String(start.contentName || ''), currentContentMeta);
      continue;
    }
    if (ev.textOutput) {
      const out = ev.textOutput;
      const meta = contentMeta.get(String(out.contentName || '')) || currentContentMeta || {};
      const role = String(out.role || meta.role || '').toUpperCase();
      const stage = String(meta.stage || 'FINAL').toUpperCase();
      if (stage !== 'SPECULATIVE') {
        if (role === 'USER') userTranscript = appendText(userTranscript, out.content);
        if (role === 'ASSISTANT') assistantTranscript = appendText(assistantTranscript, out.content);
      }
      continue;
    }
    if (ev.audioOutput?.content) {
      audioParts.push(Buffer.from(String(ev.audioOutput.content), 'base64'));
      continue;
    }
    if (ev.usageEvent) {
      const u = ev.usageEvent;
      inputTokens = Math.max(inputTokens, Number(u.totalInputTokens || 0));
      outputTokens = Math.max(outputTokens, Number(u.totalOutputTokens || 0));
      const d = u.details || {};
      inputSpeechTokens = Math.max(inputSpeechTokens, Number(d.inputSpeechTokens || 0));
      outputSpeechTokens = Math.max(outputSpeechTokens, Number(d.outputSpeechTokens || 0));
      inputTextTokens = Math.max(inputTextTokens, Number(d.inputTextTokens || 0));
      outputTextTokens = Math.max(outputTextTokens, Number(d.outputTextTokens || 0));
      continue;
    }
    if (ev.completionEnd) {
      completed = true;
      finishInputStream();
    }
  }
} catch (error) {
  clearTimeout(hardTimeout);
  finishInputStream();
  client.destroy();
  fail(error?.message || String(error));
}

clearTimeout(hardTimeout);
finishInputStream();
client.destroy();

userTranscript = userTranscript.trim();
assistantTranscript = assistantTranscript.trim();
if (!completed && !assistantTranscript) fail('Nova 2 Sonic did not complete the turn.');
if (!userTranscript) fail('No final user transcription was returned.');
if (!assistantTranscript) fail('No final assistant transcription was returned.');

process.stdout.write(JSON.stringify({
  ok: true,
  model_id: modelId,
  voice_id: voiceId,
  input_sample_rate: INPUT_RATE,
  output_sample_rate: OUTPUT_RATE,
  user_transcript: userTranscript,
  assistant_transcript: assistantTranscript,
  audio_pcm_base64: Buffer.concat(audioParts).toString('base64'),
  usage: {
    input_tokens: inputTokens,
    output_tokens: outputTokens,
    input_speech_tokens: inputSpeechTokens,
    output_speech_tokens: outputSpeechTokens,
    input_text_tokens: inputTextTokens,
    output_text_tokens: outputTextTokens,
  },
}));
