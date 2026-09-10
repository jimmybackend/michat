<section class="settings-card">
    <div class="settings-card-heading">
        <div>
            <span class="settings-card-kicker">Creatividad audiovisual</span>
            <h6>Generación de videos</h6>
        </div>
        <span class="settings-card-badge"><i class="fas fa-video"></i> video_main</span>
    </div>

    <p class="small text-muted mb-3">
        Genera clips desde texto con Amazon Bedrock y los guarda en S3 para mostrarlos dentro del historial de MiChat.
    </p>

    <div class="form-group mb-0">
        <div class="d-flex align-items-center justify-content-between flex-wrap" style="gap:.5rem;">
            <label for="aiVideoGenerationModel" class="small font-weight-bold mb-1">Modelo para crear videos</label>
            <label class="small mb-1"><input type="checkbox" id="aiVideoGenerationActive"> Activo</label>
        </div>

        <select id="aiVideoGenerationModel" class="form-control form-control-sm">
            <option value="amazon.nova-reel-v1:1">Amazon — Nova Reel 1.1 (recomendado · us-east-1)</option>
            <option value="amazon.nova-reel-v1:0">Amazon — Nova Reel 1.0 (Legacy)</option>
        </select>

        <small class="text-muted">
            agent_key: <code>video_main</code> · salida inicial 6 s · 1280×720 · 24 fps · procesamiento asíncrono en Bedrock.
        </small>
        <div class="alert alert-warning small mt-2 mb-2 py-2">
            AWS marca actualmente Nova Reel como Legacy con fin de vida el 30/09/2026. La integración queda aislada detrás de <code>video_main</code> para sustituir el modelo sin rehacer el chat.
        </div>
        <div id="aiVideoGenerationStatus" class="small text-muted mt-2"></div>
    </div>
</section>

<script>
(function () {
    'use strict';
    const endpoint = 'video_generation_preferences.php';

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? String(meta.content || '') : '';
    }

    function status(message, kind) {
        const el = document.getElementById('aiVideoGenerationStatus');
        if (!el) return;
        el.textContent = message;
        el.className = 'small mt-2 ' + (kind === 'error' ? 'text-danger' : (kind === 'success' ? 'text-success' : 'text-muted'));
    }

    async function load() {
        const model = document.getElementById('aiVideoGenerationModel');
        const active = document.getElementById('aiVideoGenerationActive');
        if (!model || !active) return;
        try {
            const response = await fetch(endpoint + '?action=get', { credentials: 'same-origin', cache: 'no-cache' });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || ('HTTP ' + response.status));
            model.value = data.model_id || 'amazon.nova-reel-v1:1';
            active.checked = Number(data.is_active) === 1;
            status(data.source === 'builtin_fallback'
                ? 'Nova Reel está listo como valor predeterminado para la región ' + (data.region || 'AWS') + '. El primer cambio se guardará en tu configuración.'
                : 'Configuración efectiva cargada (' + data.source + ', región ' + (data.region || 'AWS') + ').', 'info');
        } catch (error) {
            console.error('video generation preferences load:', error);
            status('No se pudo cargar la configuración de videos.', 'error');
        }
    }

    async function save() {
        const model = document.getElementById('aiVideoGenerationModel');
        const active = document.getElementById('aiVideoGenerationActive');
        if (!model || !active) return;
        const body = new FormData();
        body.append('action', 'save');
        body.append('csrf_token', csrfToken());
        body.append('model_id', model.value);
        body.append('is_active', active.checked ? '1' : '0');
        status('Guardando configuración de videos…', 'info');
        try {
            const response = await fetch(endpoint, { method: 'POST', credentials: 'same-origin', body });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || ('HTTP ' + response.status));
            model.value = data.model_id;
            active.checked = Number(data.is_active) === 1;
            status(active.checked ? 'Generación de videos activada.' : 'Generación de videos desactivada.', 'success');
        } catch (error) {
            console.error('video generation preferences save:', error);
            status('No se pudo guardar: ' + error.message, 'error');
            load();
        }
    }

    function init() {
        const model = document.getElementById('aiVideoGenerationModel');
        const active = document.getElementById('aiVideoGenerationActive');
        if (!model || !active) return;
        model.addEventListener('change', save);
        active.addEventListener('change', save);
        load();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
</script>
