(function () {
    'use strict';

    const ENDPOINT = 'user_preferences.php';

    const DEFAULTS = {
        model_id: 'amazon.nova-micro-v1:0',
        seed: 42,

        compile_temperature: 0.0,
        compile_max_tokens: 200,
        response_max_tokens: 1000,
        compile_top_p: 0.1,

        question_memory_enabled: 1,
        question_memory_scope: 'project',
        question_memory_max_candidates: 20,
        question_memory_window_lines: 5,

        theme_mode: 'theme-light'
    };

    const DEFAULT_PIPELINE_FEATURES = {
        prompt_compiler: true,
        memory_router: true,
        procedural_memory_read: true,
        project_memory_read: true,
        session_memory_read: true,
        question_memory_read: true,
        project_rag: true,
        attachment_rag: true,
        context_ranking: true,
        memory_backfill: true,
        project_tools: true,
        memory_writer: true
    };

    let pipelineFeatures = Object.assign({}, DEFAULT_PIPELINE_FEATURES);

    let els = {};
    let saveTimer = null;
    let readyResolved = false;
    let resolveReady;

    window.chatPreferencesReady = new Promise((resolve) => {
        resolveReady = resolve;
    });

    function markReady() {
        if (readyResolved) return;
        readyResolved = true;
        if (typeof resolveReady === 'function') resolveReady(true);
    }

    window.chatPreferences = {
        getModelId: function () {
            const current = els.model ? String(els.model.value || '').trim() : '';
            return current || DEFAULTS.model_id;
        },
        getPipelineFeatures: function () {
            return Object.assign({}, pipelineFeatures);
        },
        isFeatureEnabled: function (featureKey) {
            return pipelineFeatures[featureKey] !== false;
        },
        defaults: Object.assign({}, DEFAULTS),
        pipelineDefaults: Object.assign({}, DEFAULT_PIPELINE_FEATURES)
    };

    function init() {
        if (!document.getElementById('settings-modal')) {
            markReady();
            return;
        }

        cacheElements();
        loadPreferences()
            .then(() => {
                wireEvents();
            })
            .catch((err) => {
                console.error('Error cargando preferencias del usuario:', err);
            })
            .finally(() => {
                markReady();
            });
    }

    function cacheElements() {
        els.model = document.getElementById('chat2Model');
        els.seed = document.getElementById('chat2Seed');

        els.compTemp = document.getElementById('chat2CompTemp');
        els.compMax = document.getElementById('chat2CompMax');
        els.respMax = document.getElementById('chat2RespMax');
        els.compTopP = document.getElementById('chat2CompTopP');

        els.questionMemoryEnabled = document.getElementById('chatQuestionMemoryEnabled');
        els.questionMemoryMaxCandidates = document.getElementById('chatQuestionMemoryMaxCandidates');
        els.questionMemoryWindowLines = document.getElementById('chatQuestionMemoryWindowLines');

        els.pipelineSwitches = Array.from(document.querySelectorAll('.js-pipeline-feature'));
        els.pipelineStatus = document.getElementById('pipelineFeaturesStatus');
        els.pipelineReset = document.getElementById('pipelineFeaturesReset');

        els.themeButtons = Array.from(document.querySelectorAll('.js-set-mode'));
    }

    async function loadPreferences() {
        const r = await fetch(ENDPOINT + '?action=get', {
            credentials: 'same-origin',
            cache: 'no-cache'
        });

        const j = await r.json().catch(() => {
            return {
                ok: false,
                error: 'Respuesta inválida del servidor'
            };
        });

        if (!r.ok || !j.ok) {
            throw new Error(j.error || ('HTTP ' + r.status));
        }

        applyPreferences(j.preferences || {}, j.pipeline_features || {});
        if (els.pipelineStatus) {
            if (j.pipeline_storage_available === false) {
                els.pipelineStatus.textContent = 'Ejecuta fase5_pipeline_features.sql para guardar los switches. Mientras tanto se usan todos en ON.';
                els.pipelineStatus.className = 'small text-warning mt-2';
            } else {
                updatePipelineStatus();
            }
        }
    }

    function applyPreferences(prefs, features) {
        const p = Object.assign({}, DEFAULTS, prefs || {});
        pipelineFeatures = Object.assign({}, DEFAULT_PIPELINE_FEATURES, features || {});

        if (els.model) {
            setSelectValue(els.model, p.model_id);
        }

        if (els.seed) {
            els.seed.value = String(p.seed);
        }

        if (els.compTemp) {
            els.compTemp.value = String(p.compile_temperature);
        }

        if (els.compMax) {
            els.compMax.value = String(p.compile_max_tokens);
        }

        if (els.respMax) {
            els.respMax.value = String(p.response_max_tokens);
        }

        if (els.compTopP) {
            els.compTopP.value = String(p.compile_top_p);
        }

        if (els.questionMemoryEnabled) {
            pipelineFeatures.question_memory_read = Number(p.question_memory_enabled) === 1
                && pipelineFeatures.question_memory_read !== false;
            els.questionMemoryEnabled.checked = pipelineFeatures.question_memory_read;
        }

        applyPipelineSwitches();

        const scope = p.question_memory_scope === 'session' ? 'session' : 'project';
        const scopeRadio = document.querySelector(
            'input[name="chatQuestionMemoryScope"][value="' + scope + '"]'
        );

        if (scopeRadio) {
            scopeRadio.checked = true;
        }

        if (els.questionMemoryMaxCandidates) {
            els.questionMemoryMaxCandidates.value = String(p.question_memory_max_candidates);
        }

        if (els.questionMemoryWindowLines) {
            els.questionMemoryWindowLines.value = String(p.question_memory_window_lines);
        }

        applyTheme(p.theme_mode);
    }

    function applyPipelineSwitches() {
        (els.pipelineSwitches || []).forEach((input) => {
            const key = String(input.dataset.feature || '');
            if (!key || !(key in DEFAULT_PIPELINE_FEATURES)) return;
            input.checked = pipelineFeatures[key] !== false;
        });

        const advancedQuestion = document.querySelector('.js-pipeline-feature[data-feature="question_memory_read"]');
        if (advancedQuestion && els.questionMemoryEnabled) {
            advancedQuestion.checked = els.questionMemoryEnabled.checked;
        }
        updatePipelineStatus();
    }

    function readPipelineSwitches() {
        const next = Object.assign({}, pipelineFeatures);
        (els.pipelineSwitches || []).forEach((input) => {
            const key = String(input.dataset.feature || '');
            if (!key || !(key in DEFAULT_PIPELINE_FEATURES)) return;
            next[key] = !!input.checked;
        });

        if (els.questionMemoryEnabled) {
            next.question_memory_read = !!els.questionMemoryEnabled.checked;
        }

        pipelineFeatures = Object.assign({}, DEFAULT_PIPELINE_FEATURES, next);
        return Object.assign({}, pipelineFeatures);
    }

    function updatePipelineStatus() {
        if (!els.pipelineStatus) return;
        const values = Object.values(pipelineFeatures);
        const enabled = values.filter(Boolean).length;
        els.pipelineStatus.textContent = enabled === values.length
            ? 'Configuración recomendada: todas las funciones del pipeline están activas.'
            : `Configuración personalizada: ${enabled} de ${values.length} funciones activas.`;
        els.pipelineStatus.className = 'small text-muted mt-2';
    }

    function setSelectValue(select, value) {
        if (!select) {
            return;
        }

        const exists = Array.from(select.options).some((opt) => opt.value === value);

        if (!exists) {
            const opt = document.createElement('option');
            opt.value = value;
            opt.textContent = value;
            select.appendChild(opt);
        }

        select.value = value;
    }

    function applyTheme(themeMode) {
        const mode = String(themeMode).toLowerCase() === 'theme-dark'
            ? 'theme-dark'
            : 'theme-light';

        document.body.classList.remove('theme-dark', 'theme-light');
        document.body.classList.add(mode);

        els.themeButtons.forEach((btn) => {
            const isActive = (btn.getAttribute('data-mode') || '') === mode;
            btn.classList.toggle('active', isActive);
            btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    }

    function getCurrentTheme() {
        return document.body.classList.contains('theme-dark')
            ? 'theme-dark'
            : 'theme-light';
    }

    function clampInt(value, min, max, defaultValue) {
        const n = parseInt(value, 10);

        if (Number.isNaN(n)) {
            return defaultValue;
        }

        return Math.min(max, Math.max(min, n));
    }

    function clampFloat(value, min, max, defaultValue) {
        const n = parseFloat(value);

        if (Number.isNaN(n)) {
            return defaultValue;
        }

        return Math.min(max, Math.max(min, n));
    }

    function currentState() {
        const scopeRadio = document.querySelector(
            'input[name="chatQuestionMemoryScope"]:checked'
        );

        return {
            model_id: els.model ? els.model.value : DEFAULTS.model_id,

            seed: clampInt(
                els.seed ? els.seed.value : DEFAULTS.seed,
                0,
                999999999,
                DEFAULTS.seed
            ),

            compile_temperature: clampFloat(
                els.compTemp ? els.compTemp.value : DEFAULTS.compile_temperature,
                0,
                2,
                DEFAULTS.compile_temperature
            ),

            compile_max_tokens: clampInt(
                els.compMax ? els.compMax.value : DEFAULTS.compile_max_tokens,
                100,
                4096,
                DEFAULTS.compile_max_tokens
            ),

            response_max_tokens: clampInt(
                els.respMax ? els.respMax.value : DEFAULTS.response_max_tokens,
                100,
                4096,
                DEFAULTS.response_max_tokens
            ),

            compile_top_p: clampFloat(
                els.compTopP ? els.compTopP.value : DEFAULTS.compile_top_p,
                0.05,
                1,
                DEFAULTS.compile_top_p
            ),

            question_memory_enabled: els.questionMemoryEnabled && els.questionMemoryEnabled.checked
                ? 1
                : 0,

            question_memory_scope: scopeRadio && scopeRadio.value === 'session'
                ? 'session'
                : 'project',

            question_memory_max_candidates: clampInt(
                els.questionMemoryMaxCandidates
                    ? els.questionMemoryMaxCandidates.value
                    : DEFAULTS.question_memory_max_candidates,
                5,
                50,
                DEFAULTS.question_memory_max_candidates
            ),

            question_memory_window_lines: clampInt(
                els.questionMemoryWindowLines
                    ? els.questionMemoryWindowLines.value
                    : DEFAULTS.question_memory_window_lines,
                2,
                15,
                DEFAULTS.question_memory_window_lines
            ),

            pipeline_features_json: JSON.stringify(readPipelineSwitches()),

            theme_mode: getCurrentTheme()
        };
    }

    function scheduleSave() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(savePreferences, 700);
    }

    async function savePreferences() {
        const data = currentState();

        const fd = new FormData();
        fd.append('action', 'save');

        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        const csrfToken = csrfMeta ? csrfMeta.content : '';

        if (csrfToken) {
            fd.append('csrf_token', csrfToken);
        }

        Object.entries(data).forEach(([key, value]) => {
            fd.append(key, String(value));
        });

        try {
            const r = await fetch(ENDPOINT, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            });

            const j = await r.json().catch(() => {
                return {
                    ok: false,
                    error: 'Respuesta inválida del servidor'
                };
            });

            if (!r.ok || !j.ok) {
                throw new Error(j.error || ('HTTP ' + r.status));
            }

            if (j.pipeline_features) {
                pipelineFeatures = Object.assign({}, DEFAULT_PIPELINE_FEATURES, j.pipeline_features);
                applyPipelineSwitches();
            }

            notify('✅ Preferencias', 'Configuración guardada.', 'success');
        } catch (err) {
            console.error('Error guardando preferencias:', err);
            notify('⚠️ Preferencias', 'No se pudo guardar: ' + err.message, 'danger');
        }
    }

    function wireEvents() {
        if (els.model) {
            els.model.addEventListener('change', scheduleSave);
        }

        const numericInputs = [
            els.seed,
            els.compTemp,
            els.compMax,
            els.respMax,
            els.compTopP,
            els.questionMemoryMaxCandidates,
            els.questionMemoryWindowLines
        ];

        numericInputs.forEach((input) => {
            if (!input) {
                return;
            }

            input.addEventListener('change', scheduleSave);
            input.addEventListener('blur', scheduleSave);
        });

        if (els.questionMemoryEnabled) {
            els.questionMemoryEnabled.addEventListener('change', () => {
                pipelineFeatures.question_memory_read = !!els.questionMemoryEnabled.checked;
                const peer = document.querySelector('.js-pipeline-feature[data-feature="question_memory_read"]');
                if (peer) peer.checked = els.questionMemoryEnabled.checked;
                updatePipelineStatus();
                scheduleSave();
            });
        }

        (els.pipelineSwitches || []).forEach((input) => {
            input.addEventListener('change', () => {
                const key = String(input.dataset.feature || '');
                if (!key || !(key in DEFAULT_PIPELINE_FEATURES)) return;
                pipelineFeatures[key] = !!input.checked;

                if (key === 'question_memory_read' && els.questionMemoryEnabled) {
                    els.questionMemoryEnabled.checked = input.checked;
                }

                updatePipelineStatus();
                scheduleSave();
            });
        });

        if (els.pipelineReset) {
            els.pipelineReset.addEventListener('click', () => {
                pipelineFeatures = Object.assign({}, DEFAULT_PIPELINE_FEATURES);
                if (els.questionMemoryEnabled) els.questionMemoryEnabled.checked = true;
                applyPipelineSwitches();
                savePreferences();
            });
        }

        document
            .querySelectorAll('input[name="chatQuestionMemoryScope"]')
            .forEach((radio) => {
                radio.addEventListener('change', scheduleSave);
            });

        els.themeButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                const mode = btn.getAttribute('data-mode') || '';
                applyTheme(mode);
                savePreferences();
            });
        });
    }

    function notify(title, message, type) {
        if (window.chatUtils && typeof window.chatUtils.showToast === 'function') {
            window.chatUtils.showToast(title, message, type);
            return;
        }

        const container = document.getElementById('chatToasts')
            || document.getElementById('incomingToasts');

        if (!container) {
            console.log(title, message);
            return;
        }

        const toast = document.createElement('div');
        toast.className = 'chat-toast';
        toast.innerHTML =
            '<div class="ct-title">' + escapeHtml(title) + '</div>' +
            '<div class="small">' + escapeHtml(message) + '</div>';

        if (type === 'success') {
            toast.style.borderLeftColor = '#00ff66';
        }

        if (type === 'danger') {
            toast.style.borderLeftColor = '#ff5a5a';
        }

        if (type === 'warning') {
            toast.style.borderLeftColor = '#ffd861';
        }

        container.appendChild(toast);

        setTimeout(() => {
            if (toast.parentNode) {
                toast.remove();
            }
        }, 5000);
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();