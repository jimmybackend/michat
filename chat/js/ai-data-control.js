// =====================================================================
// CONTROL IA
// Abre ai_data_control.php con la sesión y proyecto actuales
// =====================================================================
(function () {
    'use strict';

    if (window.__aiDataControlInitialized) return;
    window.__aiDataControlInitialized = true;

    function parseIntSafe(value) {
        const n = parseInt(value, 10);
        return Number.isFinite(n) && n > 0 ? n : 0;
    }

    function getActiveSessionId() {
        // 1) Variables globales comunes
        if (typeof window.currentSessionId !== 'undefined' && window.currentSessionId) {
            return parseIntSafe(window.currentSessionId);
        }

        if (typeof window.currentSession !== 'undefined' && window.currentSession && window.currentSession.id_) {
            return parseIntSafe(window.currentSession.id_);
        }

        // 2) Badge de sesión si lo usas con data attribute
        const badge = document.getElementById('chat2SessionBadge');
        if (badge && badge.dataset && badge.dataset.sessionId) {
            return parseIntSafe(badge.dataset.sessionId);
        }

        // 3) Item activo del sidebar
        const activeSessionItem = document.querySelector('#sbChatList .sb-item.active');
        if (activeSessionItem && activeSessionItem.getAttribute('data-id')) {
            return parseIntSafe(activeSessionItem.getAttribute('data-id'));
        }

        return 0;
    }

    function getActiveProjectId() {
        // 1) Select de proyecto
        const projectSelect = document.getElementById('chat2Project');
        if (projectSelect && projectSelect.value) {
            return parseIntSafe(projectSelect.value);
        }

        // 2) Variables globales comunes
        if (typeof window.currentProjectId !== 'undefined' && window.currentProjectId) {
            return parseIntSafe(window.currentProjectId);
        }

        // 3) Proyecto activo en sidebar
        const activeProjectItem = document.querySelector('#sbProjectList .project-header.active');
        if (activeProjectItem && activeProjectItem.getAttribute('data-id')) {
            return parseIntSafe(activeProjectItem.getAttribute('data-id'));
        }

        // 4) Fallback genérico
        const activeGenericProject = document.querySelector('#sbProjectList .sb-item.active');
        if (activeGenericProject && activeGenericProject.getAttribute('data-id')) {
            return parseIntSafe(activeGenericProject.getAttribute('data-id'));
        }

        return 0;
    }

    function openAiDataControl() {
        const sessionId = getActiveSessionId();
        const projectId = getActiveProjectId();

        const params = new URLSearchParams();

        if (sessionId > 0) {
            params.set('session_id', sessionId);
        }

        if (projectId > 0) {
            params.set('project_id', projectId);
        }

        const url = 'ai_data_control.php' + (params.toString() ? ('?' + params.toString()) : '');

        window.open(url, '_blank');
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