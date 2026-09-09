<section class="settings-card">
    <div class="settings-card-heading">
        <div>
            <span class="settings-card-kicker">Multimodal</span>
            <h6>Visión de imágenes adjuntas</h6>
        </div>
        <span class="settings-card-badge"><i class="fas fa-eye"></i> attachment_vision</span>
    </div>

    <p class="small text-muted mb-3">
        Analiza JPG/JPEG, PNG, WEBP y GIF al adjuntarlos, guarda una descripción visual y la reutiliza mediante el RAG existente.
        Nova Micro no aparece aquí porque es solo texto; Nova Canvas genera imágenes y tampoco corresponde a este proceso.
    </p>

    <div class="form-group mb-0">
        <div class="d-flex align-items-center justify-content-between flex-wrap" style="gap:.5rem;">
            <label for="aiAttachmentVisionModel" class="small font-weight-bold mb-1">
                Modelo para comprender imágenes
            </label>
            <label class="small mb-1">
                <input type="checkbox" id="aiAttachmentVisionActive"> Activo
            </label>
        </div>

        <select id="aiAttachmentVisionModel" class="form-control form-control-sm">
            <option value="amazon.nova-lite-v1:0">Amazon — Nova Lite (multimodal, recomendado)</option>
            <option value="amazon.nova-pro-v1:0">Amazon — Nova Pro (multimodal, mayor precisión)</option>
            <option value="amazon.nova-premier-v1:0">Amazon — Nova Premier (multimodal, máxima capacidad)</option>
        </select>

        <small class="text-muted">
            agent_key: <code>attachment_vision</code>. Desactivarlo no impide subir la imagen; solo evita su análisis visual automático.
        </small>
        <div id="aiAttachmentVisionStatus" class="small text-muted mt-2"></div>
    </div>
</section>

<script>
(function () {
    'use strict';

    const endpoint = 'attachment_vision_preferences.php';

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? String(meta.content || '') : '';
    }

    function setStatus(message, kind) {
        const el = document.getElementById('aiAttachmentVisionStatus');
        if (!el) return;
        el.textContent = message;
        el.className = 'small mt-2 ' + (kind === 'error' ? 'text-danger' : (kind === 'success' ? 'text-success' : 'text-muted'));
    }

    async function load() {
        const select = document.getElementById('aiAttachmentVisionModel');
        const active = document.getElementById('aiAttachmentVisionActive');
        if (!select || !active) return;

        try {
            const response = await fetch(endpoint + '?action=get', {
                credentials: 'same-origin',
                cache: 'no-cache'
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || ('HTTP ' + response.status));

            select.value = data.model_id || 'amazon.nova-lite-v1:0';
            active.checked = Number(data.is_active) === 1;
            setStatus(
                data.source === 'builtin_fallback'
                    ? 'Usando Nova Lite como valor predeterminado. El primer cambio se guardará en tu configuración.'
                    : 'Configuración efectiva cargada (' + data.source + ').',
                'info'
            );
        } catch (error) {
            console.error('attachment_vision preferences load:', error);
            setStatus('No se pudo cargar la configuración de visión.', 'error');
        }
    }

    async function save() {
        const select = document.getElementById('aiAttachmentVisionModel');
        const active = document.getElementById('aiAttachmentVisionActive');
        if (!select || !active) return;

        const body = new FormData();
        body.append('action', 'save');
        body.append('csrf_token', csrfToken());
        body.append('model_id', select.value);
        body.append('is_active', active.checked ? '1' : '0');

        setStatus('Guardando configuración visual…', 'info');

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                body
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || ('HTTP ' + response.status));

            select.value = data.model_id;
            active.checked = Number(data.is_active) === 1;
            setStatus(active.checked ? 'Visión de adjuntos activada.' : 'Visión de adjuntos desactivada.', 'success');
        } catch (error) {
            console.error('attachment_vision preferences save:', error);
            setStatus('No se pudo guardar: ' + error.message, 'error');
        }
    }

    function init() {
        const select = document.getElementById('aiAttachmentVisionModel');
        const active = document.getElementById('aiAttachmentVisionActive');
        if (!select || !active) return;
        select.addEventListener('change', save);
        active.addEventListener('change', save);
        load();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
</script>
