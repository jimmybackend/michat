/**
 * Carga de Sesiones y Proyectos para el Sidebar
 */
(function() {
    'use strict';

    const $ = (s) => document.querySelector(s);
    const esc = (s) => (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\"/g,'&quot;').replace(/'/g,'&#39;');

    const sbChatList = $('#sbChatList');
    const sbChatSearch = $('#sbChatSearch');
    const sbNewChat = $('#sbNewChat');
    const sbProjectList = $('#sbProjectList');
    const sbNewProject = $('#sbNewProject');
    const sbManageProjects = $('#sbManageProjects');
    const sbCurrentProject = $('#sbCurrentProject');
    const sbCurrentSession = $('#sbCurrentSession');

    let currentSessionId = null;
    let currentProjectId = null;
    let sessions = [];
    let projects = [];

    function getUserId() {
        const hid = document.getElementById('chatUserId');
        if (hid && hid.value) {
            const n = parseInt(hid.value, 10);
            if (Number.isFinite(n) && n > 0) return String(n);
        }
        return '';
    }

    function formatSessionMeta(dtStr) {
        if (!dtStr) return '';
        const d = new Date(String(dtStr).replace(' ', 'T'));
        if (isNaN(d.getTime())) return esc(dtStr);
        const now = new Date();
        const startOfDay = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate());
        const today = startOfDay(now);
        const diffDays = Math.round((today - startOfDay(d)) / 86400000);
        if (diffDays === 0) return d.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
        if (diffDays === 1) return 'Ayer';
        if (diffDays > 1 && diffDays < 7) return d.toLocaleDateString('es-ES', { weekday: 'short' });
        return d.toLocaleDateString('es-ES', { day: 'numeric', month: 'short' });
    }

    function groupSessionsByDate(list) {
        const now = new Date();
        const startOfDay = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate());
        const today = startOfDay(now);
        const groups = { 'Hoy': [], 'Ayer': [], 'Últimos 7 días': [], 'Anteriores': [] };
        list.forEach(s => {
            const raw = s.updated_at || s.created_at;
            const d = raw ? new Date(String(raw).replace(' ', 'T')) : null;
            if (!d || isNaN(d.getTime())) { groups['Anteriores'].push(s); return; }
            const diffDays = Math.round((today - startOfDay(d)) / 86400000);
            if (diffDays <= 0) groups['Hoy'].push(s);
            else if (diffDays === 1) groups['Ayer'].push(s);
            else if (diffDays < 7) groups['Últimos 7 días'].push(s);
            else groups['Anteriores'].push(s);
        });
        return Object.entries(groups).filter(([, arr]) => arr.length > 0);
    }

    async function loadSessions() {
        if (!sbChatList) return;
        sbChatList.innerHTML = '<div class="text-muted small">Cargando...</div>';
        try {
            const qs = new URLSearchParams();
            const q = (sbChatSearch && sbChatSearch.value.trim()) || '';
            if (q) qs.set('q', q);
            const uid = getUserId();
            if (uid) qs.set('user_id', uid);

            const r = await fetch(`chat2_sessions.php?${qs.toString()}`, { credentials: 'same-origin' });
            const j = await r.json();
            if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);
            sessions = Array.isArray(j.sessions) ? j.sessions : [];
            renderSessionsList();
        } catch (e) {
            console.error('Error cargando sesiones:', e);
            sbChatList.innerHTML = `<div class="text-danger small">${esc(e.message)}</div>`;
        }
    }

    function renderSessionsList() {
        if (!sbChatList) return;

        const freeSessions = sessions.filter(s => !s.project_id && !s.project_id_);
        const projectSessions = sessions.filter(s => s.project_id || s.project_id_);

        const renderItem = (s) => {
            const sid = s.id || s.id_;
            const title = esc(s.title || `Sesión #${sid}`);
            const meta = formatSessionMeta(s.updated_at || s.created_at || '');
            const isArchived = s.archived || s.status === 'archived';
            const badge = isArchived ? `<span class="badge badge-secondary ml-1" style="font-size:0.6rem;">arch</span>` : '';
            const active = (sid === currentSessionId) ? ' active' : '';

            return `<div class="sb-item${active}" data-id="${sid}" data-type="session" title="${title}"
                    style="cursor: pointer; padding: 6px 8px; border-radius: 4px; margin-bottom: 2px;">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-truncate" style="max-width: 85%; font-size: 0.8rem;">
                        <i class="fas fa-comment-dots mr-1" style="font-size:0.65rem;"></i>${title} ${badge}
                    </span>
                </div>
                <small class="text-muted d-block" style="font-size: 0.65rem;">${esc(meta)}</small>
            </div>`;
        };

        const groups = groupSessionsByDate(freeSessions);
        let html = '';

        if (groups.length > 0) {
            html += '<div class="mb-2"><small class="text-muted" style="font-size: 0.7rem;"><i class="fas fa-comments mr-1"></i>Chats Libres</small></div>';
            groups.forEach(([label, arr]) => {
                html += `<div class="mb-2"><small class="text-muted" style="font-size: 0.7rem; padding-left: 8px;">${esc(label)}</small></div>`;
                arr.forEach(s => { html += renderItem(s); });
            });
        }

        if (projectSessions.length > 0) {
            html += '<div class="mb-2 mt-2"><small class="text-muted" style="font-size: 0.7rem;"><i class="fas fa-briefcase mr-1"></i>Chats de Proyectos</small></div>';
            projectSessions.forEach(s => { html += renderItem(s); });
        }

        if (freeSessions.length === 0 && projectSessions.length === 0) {
            html = '<div class="text-muted small" style="font-size: 0.75rem;"><i class="fas fa-info-circle mr-1"></i>Sin chats</div>';
        }

        sbChatList.innerHTML = html;

        sbChatList.querySelectorAll('.sb-item[data-type="session"]').forEach(item => {
            item.addEventListener('click', () => {
                const sid = parseInt(item.getAttribute('data-id'), 10);
                selectSession(sid);
            });
        });
    }

    async function loadProjects() {
        if (!sbProjectList) return;
        sbProjectList.innerHTML = '<div class="text-muted small">Cargando...</div>';
        try {
            const uid = getUserId();
            const qs = new URLSearchParams();
            if (uid) qs.set('user_id', uid);

            const r = await fetch(`projects.php?${qs.toString()}`, { credentials: 'same-origin' });
            const j = await r.json();
            if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);
            projects = Array.isArray(j.projects) ? j.projects : [];
            renderProjectList();
        } catch (e) {
            console.error('Error cargando proyectos:', e);
            sbProjectList.innerHTML = `<div class="text-danger small">${esc(e.message)}</div>`;
        }
    }

    function renderProjectList() {
        if (!sbProjectList) return;

        if (projects.length === 0) {
            sbProjectList.innerHTML = '<div class="text-muted small" style="font-size: 0.75rem;"><i class="fas fa-info-circle mr-1"></i>Sin proyectos</div>';
            return;
        }

        let html = '';
        projects.forEach(p => {
            const pid = p.id || p.id_;
            const isActive = (pid === currentProjectId) ? ' active' : '';
            const pname = esc(p.name || `Proyecto #${pid}`);

            const projSessions = sessions.filter(s => (s.project_id == pid) || (s.project_id_ == pid));
            const sessCount = projSessions.length;

            html += `<div class="sb-item project-item${isActive}" data-id="${pid}" data-type="project"
                     style="cursor: pointer; padding: 6px 8px; border-radius: 4px; margin-bottom: 4px; border-left: 3px solid var(--accent, #00ff88); background: rgba(0,255,136,0.05);">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-truncate" style="max-width: 70%; font-weight: 600; font-size: 0.8rem;">
                        <i class="fas fa-briefcase mr-1" style="font-size:0.7rem;"></i>${pname}
                    </span>
                    <span class="badge badge-secondary" style="font-size: 0.65rem;">${sessCount} chats</span>
                </div>
            </div>`;
        });

        sbProjectList.innerHTML = html;

        sbProjectList.querySelectorAll('.sb-item[data-type="project"]').forEach(item => {
            item.addEventListener('click', () => {
                const pid = parseInt(item.getAttribute('data-id'), 10);
                selectProject(pid);
            });
        });
    }

    function selectSession(sessionId) {
        if (typeof window.selectSessionChat1 === 'function') {
            window.selectSessionChat1(sessionId);
            return;
        }
        
        currentSessionId = sessionId;
        currentProjectId = null;

        if (sbChatList) {
            sbChatList.querySelectorAll('.sb-item').forEach(el => el.classList.remove('active'));
            const item = sbChatList.querySelector(`.sb-item[data-id="${sessionId}"]`);
            if (item) item.classList.add('active');
        }
        if (sbProjectList) {
            sbProjectList.querySelectorAll('.sb-item').forEach(el => el.classList.remove('active'));
        }

        if (sbCurrentSession) sbCurrentSession.textContent = `ID: ${sessionId}`;
        if (sbCurrentProject) sbCurrentProject.textContent = 'Ninguno';

        loadMessagesForSession(sessionId);
        
        if (typeof loadContextForSession === 'function') {
            loadContextForSession(sessionId);
        }
    }

    async function loadMessagesForSession(sessionId) {
        const chat2Messages = document.getElementById('chat2Messages');
        const chat2Title = document.getElementById('chat2Title');
        
        if (!chat2Messages) return;
        
        try {
            chat2Messages.innerHTML = '<div class="text-muted text-center mt-5"><i class="fas fa-spinner fa-spin"></i> Cargando mensajes...</div>';
            
            const qs = new URLSearchParams({ session_id: String(sessionId) });
            const r = await fetch(`chat2_messages.php?${qs.toString()}`, { credentials: 'same-origin' });
            const j = await r.json();
            
            if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);
            
            const messages = Array.isArray(j.messages) ? j.messages : [];
            
            if (typeof window.renderChatMessages === 'function') {
                window.renderChatMessages(messages);
            } else {
                chat2Messages.innerHTML = '';
                if (messages.length === 0) {
                    chat2Messages.innerHTML = '<div class="text-muted text-center mt-5"><i class="fas fa-comments"></i><br>No hay mensajes aún. ¡Comienza la conversación!</div>';
                } else {
                    messages.forEach(m => {
                        const role = m.role || 'assistant';
                        const content = m.content || '';
                        const time = m.created_at ? new Date(m.created_at).toLocaleString() : '';
                        const isUser = role === 'user';
                        
                        const msgDiv = document.createElement('div');
                        msgDiv.className = `chat-msg ${isUser ? 'user' : 'assistant'} mb-3 p-3 rounded`;
                        msgDiv.style.cssText = `background: ${isUser ? 'rgba(0,123,255,0.1)' : 'rgba(255,255,255,0.05)'}; border-left: 3px solid ${isUser ? '#007bff' : '#28a745'};`;
                        
                        let html = `<div class="d-flex justify-content-between">
                            <strong>${isUser ? 'Tú' : 'Asistente'}</strong>
                            <small class="text-muted">${time}</small>
                        </div>`;
                        
                        if (m.content_type === 'image' && m.s3_key) {
                            const imgUrl = `descargar.php?archivo=${encodeURIComponent(m.s3_key)}`;
                            html += `<img src="${imgUrl}" alt="imagen" style="max-width:320px; border-radius:8px; margin-top:.5rem;">`;
                        } else if (m.content_type === 'video' && m.s3_key) {
                            const vidUrl = `descargar.php?archivo=${encodeURIComponent(m.s3_key)}`;
                            html += `<video controls style="max-width:420px; margin-top:.5rem;"><source src="${vidUrl}"></video>`;
                        } else if (content) {
                            html += `<div class="mt-2">${convertMarkdownToHtml(content)}</div>`;
                        }
                        
                        msgDiv.innerHTML = html;
                        chat2Messages.appendChild(msgDiv);
                    });
                    
                    chat2Messages.scrollTop = chat2Messages.scrollHeight;
                }
            }
            
            const session = sessions.find(s => s.id === sessionId);
            if (chat2Title && session) {
                chat2Title.textContent = session.title || `Sesión #${sessionId}`;
            }
            
        } catch (e) {
            console.error('Error cargando mensajes:', e);
            chat2Messages.innerHTML = `<div class="text-danger text-center mt-5">Error cargando mensajes: ${e.message}</div>`;
        }
    }
    
    function convertMarkdownToHtml(text) {
        if (!text) return '';
        let html = text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.+?)\*/g, '<em>$1</em>')
            .replace(/`(.+?)`/g, '<code>$1</code>')
            .replace(/\n/g, '<br>');
        return html;
    }

    function selectProject(projectId) {
        currentProjectId = projectId;
        currentSessionId = null;

        if (sbProjectList) {
            sbProjectList.querySelectorAll('.sb-item').forEach(el => el.classList.remove('active'));
            const item = sbProjectList.querySelector(`.sb-item[data-id="${projectId}"]`);
            if (item) item.classList.add('active');
        }
        if (sbChatList) {
            sbChatList.querySelectorAll('.sb-item').forEach(el => el.classList.remove('active'));
        }

        const proj = projects.find(p => (p.id == projectId) || (p.id_ == projectId));
        if (sbCurrentProject) sbCurrentProject.textContent = proj ? proj.name : `ID: ${projectId}`;
        if (sbCurrentSession) sbCurrentSession.textContent = 'Ninguna';

        if (typeof loadContextForProject === 'function') {
            loadContextForProject(projectId);
        }
    }

    if (sbNewChat) {
        sbNewChat.addEventListener('click', async () => {
            try {
                const fd = new FormData();
                fd.append('title', 'Nueva conversación');
                const uid = getUserId();
                if (uid) fd.append('user_id', uid);
                
                const r = await fetch('chat2_session_create.php', { method: 'POST', credentials: 'same-origin', body: fd });
                const j = await r.json();
                if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);
                
                currentSessionId = j.id;
                await loadSessions();
                selectSession(currentSessionId);
                
                window.location.reload();
            } catch (e) {
                console.error('Error creando sesión:', e);
                alert('No se pudo crear la sesión: ' + e.message);
            }
        });
    }

    if (sbChatSearch) {
        sbChatSearch.addEventListener('input', () => loadSessions());
    }

    if (sbNewProject) {
        sbNewProject.addEventListener('click', () => {
            if (typeof openProjectManager === 'function') {
                openProjectManager();
            } else {
                const name = prompt('Nombre del proyecto:');
                if (name) {
                    createProject(name);
                }
            }
        });
    }

    if (sbManageProjects) {
        sbManageProjects.addEventListener('click', () => {
            if (typeof openProjectManager === 'function') {
                openProjectManager();
            }
        });
    }

    async function createProject(name) {
        try {
            const fd = new FormData();
            fd.append('name', name);
            const uid = getUserId();
            if (uid) fd.append('user_id', uid);
            
            const r = await fetch('projects.php', { method: 'POST', credentials: 'same-origin', body: fd });
            const j = await r.json();
            if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);
            
            await loadProjects();
        } catch (e) {
            console.error('Error creando proyecto:', e);
            alert('No se pudo crear el proyecto: ' + e.message);
        }
    }

    console.log('🚀 Inicializando sidebar de chats...');
    loadSessions();
    loadProjects();

})();
