// =====================================================================
// CONTROL IA
// Abre ai_data_control.php con la sesión y proyecto actuales
// =====================================================================
(function () {
    'use strict';

    if (window.__aiDataControlInitialized) return;
    window.__aiDataControlInitialized = true;

    function openAiDataControl() {
        // ✅ Usar chatUtils que YA está en chat.js y conoce la sesión real
        const utils = window.chatUtils || {};
        const sessionId = (typeof utils.getCurrentSessionId === 'function')
            ? parseInt(utils.getCurrentSessionId() || 0, 10)
            : 0;
        const projectId = (typeof utils.getCurrentProjectId === 'function')
            ? parseInt(utils.getCurrentProjectId() || 0, 10)
            : 0;

        const params = new URLSearchParams();
        if (sessionId > 0) params.set('session_id', sessionId);
        if (projectId > 0) params.set('project_id', projectId);

        const url = 'ai_data_control.php' + (params.toString() ? ('?' + params.toString()) : '');
        console.log('[AI-Data-Control] Abriendo:', url, { sessionId, projectId });
        window.open(url, '_blank', 'noopener');
    }

    function bindButton() {
        const btn = document.getElementById('btnAiDataControl');
        if (!btn) return;
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            openAiDataControl();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindButton);
    } else {
        bindButton();
    }

    window.openAiDataControl = openAiDataControl;
})();