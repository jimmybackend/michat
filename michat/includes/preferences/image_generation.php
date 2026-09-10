<section class="settings-card">
    <div class="settings-card-heading">
        <div>
            <span class="settings-card-kicker">Creatividad visual</span>
            <h6>Generación de imágenes</h6>
        </div>
        <span class="settings-card-badge"><i class="fas fa-image"></i> image_main</span>
    </div>

    <p class="small text-muted mb-3">
        Genera imágenes desde texto y las muestra dentro del historial de MiChat. Sirve para ilustraciones, portadas, esquemas visuales, diseños y material escolar.
    </p>

    <div class="form-group mb-0">
        <div class="d-flex align-items-center justify-content-between flex-wrap" style="gap:.5rem;">
            <label for="aiImageGenerationModel" class="small font-weight-bold mb-1">Modelo para crear imágenes</label>
            <label class="small mb-1"><input type="checkbox" id="aiImageGenerationActive"> Activo</label>
        </div>

        <select id="aiImageGenerationModel" class="form-control form-control-sm">
            <option value="amazon.titan-image-generator-v2:0">Amazon — Titan Image Generator v2 (recomendado)</option>
            <option value="amazon.nova-canvas-v1:0">Amazon — Nova Canvas (Legacy · EOL 30/09/2026)</option>
        </select>

        <small class="text-muted">
            agent_key: <code>image_main</code> · salida inicial 1024×1024 · la imagen se guarda privada en S3 y se muestra en el chat.
        </small>
        <div id="aiImageGenerationStatus" class="small text-muted mt-2"></div>
    </div>
</section>

<script>
(function () {
    'use strict';
    const endpoint = 'image_generation_preferences.php';

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? String(meta.content || '') : '';
    }

    function status(message, kind) {
        const el = document.getElementById('aiImageGenerationStatus');
        if (!el) return;
        el.textContent = message;
        el.className = 'small mt-2 ' + (kind === 'error' ? 'text-danger' : (kind === 'success' ? 'text-success' : 'text-muted'));
    }

    async function load() {
        const model = document.getElementById('aiImageGenerationModel');
        const active = document.getElementById('aiImageGenerationActive');
        if (!model || !active) return;
        try {
            const response = await fetch(endpoint + '?action=get', { credentials: 'same-origin', cache: 'no-cache' });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || ('HTTP ' + response.status));
            model.value = data.model_id || 'amazon.titan-image-generator-v2:0';
            active.checked = Number(data.is_active) === 1;
            status(data.source === 'builtin_fallback'
                ? 'Titan Image Generator v2 está listo como valor predeterminado. El primer cambio se guardará en tu configuración.'
                : 'Configuración efectiva cargada (' + data.source + ').', 'info');
        } catch (error) {
            console.error('image generation preferences load:', error);
            status('No se pudo cargar la configuración de imágenes.', 'error');
        }
    }

    async function save() {
        const model = document.getElementById('aiImageGenerationModel');
        const active = document.getElementById('aiImageGenerationActive');
        if (!model || !active) return;
        const body = new FormData();
        body.append('action', 'save');
        body.append('csrf_token', csrfToken());
        body.append('model_id', model.value);
        body.append('is_active', active.checked ? '1' : '0');
        status('Guardando configuración de imágenes…', 'info');
        try {
            const response = await fetch(endpoint, { method: 'POST', credentials: 'same-origin', body });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || ('HTTP ' + response.status));
            model.value = data.model_id;
            active.checked = Number(data.is_active) === 1;
            status(active.checked ? 'Generación de imágenes activada.' : 'Generación de imágenes desactivada.', 'success');
        } catch (error) {
            console.error('image generation preferences save:', error);
            status('No se pudo guardar: ' + error.message, 'error');
        }
    }

    function init() {
        const model = document.getElementById('aiImageGenerationModel');
        const active = document.getElementById('aiImageGenerationActive');
        if (!model || !active) return;
        model.addEventListener('change', save);
        active.addEventListener('change', save);
        load();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
</script>
