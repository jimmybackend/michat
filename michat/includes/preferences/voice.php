<section class="settings-card">
    <div class="settings-card-heading">
        <div>
            <span class="settings-card-kicker">Audio bidireccional</span>
            <h6>Voz / Audio</h6>
        </div>
        <span class="settings-card-badge"><i class="fas fa-microphone"></i> voice_main</span>
    </div>

    <p class="small text-muted mb-3">
        Permite hablarle a MiChat y escuchar la respuesta con Amazon Nova 2 Sonic. El primer modo es pulsar para grabar y pulsar de nuevo para enviar; la transcripción queda en el historial del chat.
    </p>

    <div class="form-row">
        <div class="form-group col-md-6">
            <label for="aiVoiceModel" class="small font-weight-bold mb-1">Modelo de voz</label>
            <select id="aiVoiceModel" class="form-control form-control-sm">
                <option value="amazon.nova-2-sonic-v1:0">Amazon — Nova 2 Sonic</option>
            </select>
        </div>
        <div class="form-group col-md-3">
            <label for="aiVoiceSpeaker" class="small font-weight-bold mb-1">Voz en español</label>
            <select id="aiVoiceSpeaker" class="form-control form-control-sm">
                <option value="lupe">Lupe</option>
                <option value="carlos">Carlos</option>
            </select>
        </div>
        <div class="form-group col-md-3 d-flex align-items-end">
            <label class="small mb-2">
                <input type="checkbox" id="aiVoiceActive"> Activo
            </label>
        </div>
    </div>

    <small class="text-muted">
        agent_key: <code>voice_main</code> · idioma <code>es-US</code> · entrada PCM 16 kHz · salida PCM 24 kHz.
    </small>
    <div id="aiVoiceStatus" class="small text-muted mt-2"></div>
</section>

<script>
(function () {
    'use strict';
    const endpoint = 'voice_preferences.php';

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? String(meta.content || '') : '';
    }
    function status(message, kind) {
        const el = document.getElementById('aiVoiceStatus');
        if (!el) return;
        el.textContent = message;
        el.className = 'small mt-2 ' + (kind === 'error' ? 'text-danger' : (kind === 'success' ? 'text-success' : 'text-muted'));
    }
    async function load() {
        const model = document.getElementById('aiVoiceModel');
        const voice = document.getElementById('aiVoiceSpeaker');
        const active = document.getElementById('aiVoiceActive');
        if (!model || !voice || !active) return;
        try {
            const response = await fetch(endpoint + '?action=get', { credentials: 'same-origin', cache: 'no-cache' });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || ('HTTP ' + response.status));
            model.value = data.model_id || 'amazon.nova-2-sonic-v1:0';
            voice.value = data.voice_id || 'lupe';
            active.checked = Number(data.is_active) === 1;
            status(data.source === 'builtin_fallback' ? 'Voz desactivada por defecto. Actívala cuando quieras usar el micrófono.' : 'Configuración efectiva cargada (' + data.source + ').', 'info');
        } catch (error) {
            console.error('voice preferences load:', error);
            status('No se pudo cargar la configuración de voz.', 'error');
        }
    }
    async function save() {
        const model = document.getElementById('aiVoiceModel');
        const voice = document.getElementById('aiVoiceSpeaker');
        const active = document.getElementById('aiVoiceActive');
        if (!model || !voice || !active) return;
        const body = new FormData();
        body.append('action', 'save');
        body.append('csrf_token', csrfToken());
        body.append('model_id', model.value);
        body.append('voice_id', voice.value);
        body.append('is_active', active.checked ? '1' : '0');
        status('Guardando configuración de voz…', 'info');
        try {
            const response = await fetch(endpoint, { method: 'POST', credentials: 'same-origin', body });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || ('HTTP ' + response.status));
            model.value = data.model_id;
            voice.value = data.voice_id;
            active.checked = Number(data.is_active) === 1;
            status(active.checked ? 'Voz activada.' : 'Voz desactivada.', 'success');
        } catch (error) {
            console.error('voice preferences save:', error);
            status('No se pudo guardar: ' + error.message, 'error');
        }
    }
    function init() {
        const model = document.getElementById('aiVoiceModel');
        const voice = document.getElementById('aiVoiceSpeaker');
        const active = document.getElementById('aiVoiceActive');
        if (!model || !voice || !active) return;
        model.addEventListener('change', save);
        voice.addEventListener('change', save);
        active.addEventListener('change', save);
        load();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
</script>
<script src="js/chat-voice.js" defer></script>
