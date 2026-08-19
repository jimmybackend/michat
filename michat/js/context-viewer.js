// =====================================================================
// 📊 INSPECTOR DE CONTEXTO ACTIVO
// Abre session_context_viewer.php con la sesión y proyecto actuales
// =====================================================================
(function () {
    'use strict';

    if (window.__contextViewerInitialized) return;
    window.__contextViewerInitialized = true;

    function parseIntSafe(value) {
        const n = parseInt(value, 10);
        return Number.isFinite(n) && n > 0 ? n : 0;
    }

    function getActiveSessionId() {
        if (window.chatUtils && typeof window.chatUtils.getCurrentSessionId === 'function') {
            const id = parseIntSafe(window.chatUtils.getCurrentSessionId());
            if (id > 0) return id;
        }
        if (typeof window.currentSessionId !== 'undefined' && window.currentSessionId) {
            return parseIntSafe(window.currentSessionId);
        }
        if (typeof window.currentSession !== 'undefined' && window.currentSession && window.currentSession.id_) {
            return parseIntSafe(window.currentSession.id_);
        }
        const badge = document.getElementById('chat2SessionBadge');
        if (badge && badge.dataset && badge.dataset.sessionId) {
            return parseIntSafe(badge.dataset.sessionId);
        }
        const activeSessionItem = document.querySelector('#sbChatList .sb-item.active, #sbProjectList .project-session.active');
        if (activeSessionItem && activeSessionItem.getAttribute('data-id')) {
            return parseIntSafe(activeSessionItem.getAttribute('data-id'));
        }
        return 0;
    }

    function getActiveProjectId() {
        if (window.chatUtils && typeof window.chatUtils.getCurrentProjectId === 'function') {
            const id = parseIntSafe(window.chatUtils.getCurrentProjectId());
            if (id > 0) return id;
        }
        const projectSelect = document.getElementById('chat2Project');
        if (projectSelect && projectSelect.value) {
            return parseIntSafe(projectSelect.value);
        }
        if (typeof window.currentProjectId !== 'undefined' && window.currentProjectId) {
            return parseIntSafe(window.currentProjectId);
        }
        const activeProjectItem = document.querySelector('#sbProjectList .sb-item.active');
        if (activeProjectItem && activeProjectItem.getAttribute('data-id')) {
            return parseIntSafe(activeProjectItem.getAttribute('data-id'));
        }
        return 0;
    }

    function openContextViewer() {
        const sessionId = getActiveSessionId();
        const projectId = getActiveProjectId();
        const params = new URLSearchParams();
        if (sessionId > 0) params.set('session_id', sessionId);
        if (projectId > 0) params.set('project_id', projectId);
        const url = 'session_context_viewer.php' + (params.toString() ? ('?' + params.toString()) : '');
        window.open(url, '_blank');
    }

    function bindButton() {
        const btn = document.getElementById('btnContextViewer');
        if (!btn) return;
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            openContextViewer();
        });
    }

    if (document.readyState === 'loading') { 
        document.addEventListener('DOMContentLoaded', bindButton);
    } else {
        bindButton();
    }

    window.openContextViewer = openContextViewer;
})();
