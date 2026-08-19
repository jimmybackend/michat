(function () {
  'use strict';
  window.addEventListener('DOMContentLoaded', () => {
    if (!document.getElementById('pane-Chat2')) return;
    const API = {
      send: 'bedrock_chat2.php',
      sessions: 'chat2_sessions.php',
      createSession: 'chat2_session_create.php',
      discardEmptySession: 'chat2_session_discard_empty.php',
      renameSession: 'chat2_session_title.php',
      archiveSession: 'chat2_session_archive.php',
      restoreSession: 'chat2_session_restore.php',
      messages: 'chat2_messages.php',
      genImage: 'chat_gen_image.php',
      genVideoStart: 'chat_gen_video_start.php',
      genVideoStatus: 'chat_gen_video_status.php',
      notifyPoll: 'chat_notify_poll.php',
      markPrimordial: 'chat_mark_primordial.php', 
      getContext: 'get_context.php',
      sessionFiles: 'chat2_session_files.php',
      attachmentMode: 'session_attachment_mode.php',
      activity: 'chat_activity_poll.php',
      activityViewer: 'chat_activity_viewer.php'
    };
    const PROJECT_API = {
      list: 'projects.php',
      create: 'projects.php',
      update: 'projects.php',
      delete: 'projects.php',
      sources: 'project_sources.php',
      tools: 'tools.php'
    };
    const $ = (s) => document.querySelector(s);
    const $$ = (s) => Array.from(document.querySelectorAll(s));
    const el = {
      pane: $('#pane-Chat2'),
      sbChatList: $('#sbChatList'),
      sbChatSearch: $('#sbChatSearch'),
      sbNewChat: $('#sbNewChat'),
      sbProjectList: $('#sbProjectList'),
      sbNewProject: $('#sbNewProject'),
      sbManageProjects: $('#sbManageProjects'),
      sbCurrentProject: $('#sbCurrentProject'),
      sbCurrentSession: $('#sbCurrentSession'),
      sbSourcesCount: $('#sbSourcesCount'),
      sessionsList: $('#sbChatList'),
      search: $('#sbChatSearch'),
      reload: null,
      showArchived: null,
      newBtn: $('#sbNewChat'),
      title: $('#chat2Title'),
      badge: $('#chat2SessionBadge'),
      rename: $('#chat2Rename'),
      archive: $('#chat2Archive'),
      restore: $('#chat2Restore'),
      traceExplorer: $('#chatTraceExplorerBtn'),
      model: $('#chat2Model'),
        temp: $('#chat2Temp'),
        max: $('#chat2Max'),
        topP: $('#chat2TopP'),
        compTemp: $('#chat2CompTemp'),
        compMax: $('#chat2CompMax'),
        compTopP: $('#chat2CompTopP'),
        respMax: $('#chat2RespMax'),
        seed: $('#chat2Seed'),
        auto: $('#chat2Auto'),
            questionMemoryEnabled: $('#chatQuestionMemoryEnabled'),
            questionMemoryScope: $('input[name="chatQuestionMemoryScope"]:checked'),
            questionMemoryMaxCandidates: $('#chatQuestionMemoryMaxCandidates'),
            questionMemoryWindowLines: $('#chatQuestionMemoryWindowLines'),
            activityEnabled: $('#chatActivityEnabled'),
      messages: $('#chat2Messages'),
      status: $('#chat2Status'),
      usage: $('#chat2Usage'),
      input: $('#chat2Input'),
      file: $('#chat2File'),
      attach: $('#chat2Attach'),
      btnGenImg: $('#chat2BtnGenImg'),
      btnGenVid: $('#chat2BtnGenVid'),
      btnSonic: $('#chat2BtnSonic'),
      send: $('#chat2Send'),
      queue: $('#chat2Queue'),
      queueList: $('#chat2QueueList'),
      projectSelect: $('#chat2Project'),
      projectNew: null,
      projectManage: null,
      sourcesPanel: $('#chat2SourcesPanel'),
      sourcesList: $('#chat2SourcesList'),
      sourcesCount: $('#chat2SourcesCount'),
      sourcesAdd: $('#chat2SourcesAdd'),
      sourcesRefresh: $('#chat2SourcesRefresh'),
      attachmentsPanel: $('#chat2AttachmentsPanel'),
      attachmentsList: $('#chat2AttachmentsList'),
      attachmentsCount: $('#chat2AttachmentsCount'),
      attachmentsAdd: $('#chat2AttachmentsAdd'),
      attachmentsRefresh: $('#chat2AttachmentsRefresh'),
      sbSessionFiles: $('#chatSessionFilesList'),
      chatFilesCount: $('#chatFilesCount'),   
    };
    let currentSessionId = null;
    let currentProjectId = null;
    let sessions = [];
    let projects = [];
    let projectSources = [];
    let isSending = false;
    let pendingFiles = [];
    let sessionAttachments = [];
    let currentMemoryScope = null;
    let fileIdSeq = 1;

    if (el.activityEnabled) {
      const storedActivity = localStorage.getItem('chat_activity_enabled');
      if (storedActivity === '0') el.activityEnabled.checked = false;
      if (storedActivity === '1') el.activityEnabled.checked = true;
      el.activityEnabled.addEventListener('change', () => {
        localStorage.setItem('chat_activity_enabled', el.activityEnabled.checked ? '1' : '0');
      });
    }
    function esc(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}
    function fmtDate(dtStr) {
      if (!dtStr) return '';
      try { return new Date(dtStr.replace(' ', 'T')).toLocaleString(); } 
      catch { return dtStr; }
    }
    function setStatus(txt) {
      if (!el.status) return;
      el.status.innerHTML = txt ? `⏳ ${esc(txt)}` : '';
    }
    function setUsage(txt) {
      if (el.usage) el.usage.textContent = txt || '';
    }

    function syncTraceExplorerButton() {
      if (!el.traceExplorer) return;
      const hasSession = Number(currentSessionId || 0) > 0;
      el.traceExplorer.disabled = !hasSession;
      el.traceExplorer.title = hasSession
        ? `Ver trazabilidad completa de la conversación #${currentSessionId}`
        : 'Selecciona una conversación para ver su trazabilidad';
    }

    function openTraceExplorer() {
      const sessionId = Number(currentSessionId || 0);
      if (!sessionId) {
        showActionToast('Selecciona una conversación para ver su trazabilidad.');
        syncTraceExplorerButton();
        return;
      }

      const qs = new URLSearchParams({ session_id: String(sessionId) });
      // No enviamos user_id: trace_explorer.php usa exclusivamente la sesión autenticada.
      window.open(`trace_explorer.php?${qs.toString()}`, '_blank', 'noopener');
    }

    function resizeChatInput() {
      if (!el.input) return;
      el.input.style.height = 'auto';
      const maxHeight = window.matchMedia('(max-width: 600px)').matches ? 132 : 168;
      el.input.style.height = Math.min(Math.max(el.input.scrollHeight, 48), maxHeight) + 'px';
      el.input.style.overflowY = el.input.scrollHeight > maxHeight ? 'auto' : 'hidden';
    }
    if (el.input) {
      el.input.addEventListener('input', resizeChatInput, { passive: true });
      window.addEventListener('resize', resizeChatInput, { passive: true });
      window.setTimeout(resizeChatInput, 0);
    }

    // ============================================================
    // ACTIVIDAD REAL DEL AGENTE (telemetría del pipeline, no CoT)
    // ============================================================
    const activityTraceStates = new Map();

    function isActivityEnabled() {
      return !el.activityEnabled || el.activityEnabled.checked;
    }

    function createActivityTraceId() {
      if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return window.crypto.randomUUID();
      }
      const bytes = new Uint8Array(16);
      if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
        window.crypto.getRandomValues(bytes);
        return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
      }
      return 'trace_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 18);
    }

    function parseTraceIdFromMeta(meta) {
      if (!meta) return '';
      try {
        const obj = (typeof meta === 'string') ? JSON.parse(meta) : meta;
        const trace = obj && typeof obj === 'object' ? String(obj.trace_id || '') : '';
        return /^[A-Za-z0-9_-]{16,36}$/.test(trace) ? trace : '';
      } catch (_) {
        return '';
      }
    }

    function activityDomId(traceId) {
      return 'chat-activity-' + String(traceId).replace(/[^A-Za-z0-9_-]/g, '');
    }

    function activityStatusIcon(status) {
      if (status === 'completed') return '<i class="fas fa-check-circle"></i>';
      if (status === 'error') return '<i class="fas fa-times-circle"></i>';
      if (status === 'waiting') return '<i class="fas fa-clock"></i>';
      if (status === 'started') return '<i class="fas fa-circle-notch fa-spin"></i>';
      return '<i class="fas fa-info-circle"></i>';
    }

    function activityPhaseLabel(phase) {
      const labels = { compile: 'COMPILAR', respond: 'RESPONDER', ui: 'INTERFAZ' };
      return labels[String(phase || '').toLowerCase()] || String(phase || 'EVENTO').toUpperCase();
    }

    function formatActivityDetails(details) {
      if (details == null) return '';
      try {
        return JSON.stringify(details, null, 2);
      } catch (_) {
        return String(details);
      }
    }

    function getActivityState(traceId, sessionId = currentSessionId) {
      let state = activityTraceStates.get(traceId);
      if (!state) {
        state = {
          traceId,
          sessionId: Number(sessionId || currentSessionId || 0),
          lastId: 0,
          events: [],
          timer: null,
          polling: false,
          live: false,
          terminal: false,
          loaded: false,
          error: ''
        };
        activityTraceStates.set(traceId, state);
      }
      if (sessionId) state.sessionId = Number(sessionId);
      return state;
    }

    function ensureActivityCard(traceId, opts = {}) {
      if (!traceId || !isActivityEnabled() || !el.messages) return null;
      const state = getActivityState(traceId, opts.sessionId || currentSessionId);
      if (typeof opts.live === 'boolean') state.live = opts.live;

      const id = activityDomId(traceId);
      let card = document.getElementById(id);
      if (!card) {
        const shortTrace = esc(String(traceId).slice(0, 8));
        const historical = !state.live && !state.loaded;
        el.messages.insertAdjacentHTML('beforeend', `
          <div class="chat-activity-card${state.live ? ' is-live' : ''}" id="${id}" data-trace-id="${esc(traceId)}">
            <button type="button" class="chat-activity-header" aria-expanded="${state.live ? 'true' : 'false'}">
              <span class="chat-activity-title">
                <span class="chat-activity-pulse"></span>
                <i class="fas fa-wave-square"></i>
                Actividad del agente
              </span>
              <span class="chat-activity-header-meta">
                <span class="chat-activity-trace">${shortTrace}</span>
                <i class="fas fa-chevron-down chat-activity-chevron"></i>
              </span>
            </button>
            <div class="chat-activity-body${state.live ? '' : ' d-none'}">
              <div class="chat-activity-events"></div>
              <button type="button" class="chat-activity-load${historical ? '' : ' d-none'}">
                <i class="fas fa-history mr-1"></i>Cargar actividad guardada
              </button>
            </div>
          </div>`);
        card = document.getElementById(id);
        if (card) {
          const header = card.querySelector('.chat-activity-header');
          const body = card.querySelector('.chat-activity-body');
          const loadBtn = card.querySelector('.chat-activity-load');
          if (header && body) {
            header.addEventListener('click', () => {
              const willOpen = body.classList.contains('d-none');
              body.classList.toggle('d-none', !willOpen);
              header.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
              card.classList.toggle('is-open', willOpen);
              if (willOpen && !state.live && !state.loaded && !state.polling) {
                loadHistoricalActivity(traceId);
              }
            });
          }
          if (loadBtn) {
            loadBtn.addEventListener('click', (ev) => {
              ev.stopPropagation();
              loadHistoricalActivity(traceId);
            });
          }
        }
      }
      renderActivityCard(traceId);
      return card;
    }

    function renderActivityCard(traceId) {
      const state = activityTraceStates.get(traceId);
      const card = document.getElementById(activityDomId(traceId));
      if (!state || !card) return;
      card.classList.toggle('is-live', !!state.live && !state.terminal);
      card.classList.toggle('has-error', !!state.error);

      const eventsEl = card.querySelector('.chat-activity-events');
      const loadBtn = card.querySelector('.chat-activity-load');
      if (!eventsEl) return;

      if (!state.events.length) {
        eventsEl.innerHTML = state.error
          ? `<div class="chat-activity-empty text-danger"><i class="fas fa-exclamation-triangle mr-1"></i>${esc(state.error)}</div>`
          : `<div class="chat-activity-empty"><i class="fas fa-circle-notch fa-spin mr-1"></i>${state.live ? 'Esperando eventos reales del servidor…' : 'Actividad todavía no cargada.'}</div>`;
      } else {
        eventsEl.innerHTML = state.events.map((ev) => {
          const detailsText = formatActivityDetails(ev.details);
          const model = ev.model_id ? `<span class="chat-activity-model">${esc(ev.model_id)}</span>` : '';
          const duration = Number.isFinite(Number(ev.duration_ms)) && ev.duration_ms !== null
            ? `<span class="chat-activity-duration">${esc(ev.duration_ms)} ms</span>` : '';
          const summary = ev.summary ? `<div class="chat-activity-summary">${esc(ev.summary)}</div>` : '';
          const details = detailsText ? `
            <details class="chat-activity-details">
              <summary>Ver datos reales</summary>
              <pre>${esc(detailsText)}</pre>
            </details>` : '';
          return `
            <div class="chat-activity-event status-${esc(ev.status || 'info')}">
              <div class="chat-activity-event-icon">${activityStatusIcon(ev.status)}</div>
              <div class="chat-activity-event-content">
                <div class="chat-activity-event-top">
                  <span class="chat-activity-event-title">${esc(ev.title || ev.event_key || 'Evento')}</span>
                  <span class="chat-activity-phase">${esc(activityPhaseLabel(ev.phase))}</span>
                  ${model}${duration}
                </div>
                ${summary}
                ${details}
              </div>
            </div>`;
        }).join('');
      }

      if (loadBtn) loadBtn.classList.toggle('d-none', state.live || state.loaded || state.polling);
      const body = card.querySelector('.chat-activity-body');
      if (state.live && body) body.classList.remove('d-none');
    }

    function appendActivityEvents(traceId, events) {
      const state = getActivityState(traceId);
      const known = new Set(state.events.filter(e => Number(e.id) > 0).map(e => Number(e.id)));
      (events || []).forEach(ev => {
        const id = Number(ev.id || 0);
        if (id > 0 && known.has(id)) return;
        state.events.push(ev);
        if (id > 0) {
          known.add(id);
          state.lastId = Math.max(state.lastId, id);
        }
      });
      renderActivityCard(traceId);
      scrollMessagesToBottom();
    }

    function appendLocalActivityEvent(traceId, eventKey, status, title, summary = '') {
      const state = getActivityState(traceId);
      state.events.push({
        id: -Date.now(), trace_id: traceId, phase: 'ui', event_key: eventKey,
        status, title, summary, details: null, model_id: null, duration_ms: null,
        created_at: new Date().toISOString()
      });
      renderActivityCard(traceId);
    }

    async function fetchActivityEvents(traceId, afterId = 0) {
      const state = getActivityState(traceId);
      if (!state.sessionId) throw new Error('No hay session_id para consultar la actividad.');
      const qs = new URLSearchParams({
        trace_id: traceId,
        session_id: String(state.sessionId),
        after_id: String(afterId),
        _: String(Date.now())
      });
      const r = await fetch(`${API.activity}?${qs.toString()}`, { credentials: 'same-origin', cache: 'no-store' });
      const text = await r.text();
      const j = toJSONorThrow(text, r.status, 'Actividad del agente');
      if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);
      return j;
    }

    async function pollActivityTrace(traceId) {
      const state = activityTraceStates.get(traceId);
      if (!state || !state.live || state.terminal || state.polling) return;
      state.polling = true;
      try {
        const j = await fetchActivityEvents(traceId, state.lastId);
        const newEvents = j.events || [];
        appendActivityEvents(traceId, newEvents);
        state.loaded = true;
        const lastNewEvent = newEvents.length ? newEvents[newEvents.length - 1] : null;
        if (!j.terminal && lastNewEvent && lastNewEvent.event_key === 'approval_waiting') {
          // La fase compile ya terminó. Pausar polling hasta que el usuario apruebe
          // el prompt evita consultas inútiles mientras el modal está abierto.
          state.live = false;
          state.terminal = false;
          state.timer = null;
          renderActivityCard(traceId);
          return;
        }
        if (j.terminal) {
          state.terminal = true;
          state.live = false;
          if (state.timer) clearTimeout(state.timer);
          state.timer = null;
          renderActivityCard(traceId);
          return;
        }
      } catch (e) {
        state.error = e.message || String(e);
        state.live = false;
        state.terminal = true;
        if (state.timer) clearTimeout(state.timer);
        state.timer = null;
        renderActivityCard(traceId);
        return;
      } finally {
        state.polling = false;
      }
      if (state.live && !state.terminal) {
        state.timer = setTimeout(() => pollActivityTrace(traceId), 650);
      }
    }

    function startActivityTrace(traceId, sessionId) {
      if (!traceId || !isActivityEnabled()) return;
      const state = getActivityState(traceId, sessionId);
      state.live = true;
      state.terminal = false;
      state.error = '';
      ensureActivityCard(traceId, { live: true, sessionId });
      pollActivityTrace(traceId);
    }

    function stopActivityTrace(traceId, opts = {}) {
      if (!traceId) return;
      const state = activityTraceStates.get(traceId);
      if (!state) return;
      if (state.timer) clearTimeout(state.timer);
      state.timer = null;
      state.live = false;
      if (opts.terminal !== false) state.terminal = true;
      if (opts.error) state.error = opts.error;
      if (opts.title) {
        appendLocalActivityEvent(
          traceId,
          opts.eventKey || 'ui_trace_stopped',
          opts.status || (opts.error ? 'error' : 'info'),
          opts.title,
          opts.summary || ''
        );
      }
      renderActivityCard(traceId);
    }

    async function loadHistoricalActivity(traceId) {
      if (!traceId || !isActivityEnabled()) return;
      const state = getActivityState(traceId, currentSessionId);
      if (state.polling || state.loaded) return;
      state.polling = true;
      state.error = '';
      ensureActivityCard(traceId, { live: false, sessionId: state.sessionId });
      renderActivityCard(traceId);
      try {
        const j = await fetchActivityEvents(traceId, 0);
        state.events = [];
        state.lastId = 0;
        appendActivityEvents(traceId, j.events || []);
        state.terminal = !!j.terminal;
        state.loaded = true;
      } catch (e) {
        state.error = e.message || String(e);
      } finally {
        state.polling = false;
        renderActivityCard(traceId);
      }
    }

function activityEventHtml(ev) {
  const detailsText = formatActivityDetails(ev.details);
  const model = ev.model_id ? `<span class="chat-activity-model">${esc(ev.model_id)}</span>` : '';
  const duration = Number.isFinite(Number(ev.duration_ms)) && ev.duration_ms !== null
    ? `<span class="chat-activity-duration">${esc(ev.duration_ms)} ms</span>` : '';
  const summary = ev.summary ? `<div class="chat-activity-summary">${esc(ev.summary)}</div>` : '';
  const details = detailsText ? `
    <details class="chat-activity-details">
      <summary>Ver datos reales</summary>
      <pre>${esc(detailsText)}</pre>
    </details>` : '';
  return `
    <div class="chat-activity-event status-${esc(ev.status || 'info')}">
      <div class="chat-activity-event-icon">${activityStatusIcon(ev.status)}</div>
      <div class="chat-activity-event-content">
        <div class="chat-activity-event-top">
          <span class="chat-activity-event-title">${esc(ev.title || ev.event_key || 'Evento')}</span>
          <span class="chat-activity-phase">${esc(activityPhaseLabel(ev.phase))}</span>
          ${model}${duration}
        </div>
        ${summary}
        ${details}
      </div>
    </div>`;
}

function closeActivityDrawer() {
  const drawer = document.getElementById('chatActivityDrawer');
  const backdrop = document.getElementById('chatActivityDrawerBackdrop');
  if (drawer) {
    drawer.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
  }
  if (backdrop) {
    backdrop.classList.remove('is-open');
    backdrop.setAttribute('aria-hidden', 'true');
  }
  document.body.classList.remove('chat-activity-drawer-open');
}

async function openActivityDrawerForMessage(msgDiv) {
  const traceId = String(msgDiv?.dataset?.traceId || '').trim();
  const sessionId = Number(msgDiv?.dataset?.sessionId || currentSessionId || 0);
  const messageId = String(msgDiv?.dataset?.messageId || '').trim();
  if (!traceId || !sessionId) {
    showActionToast('Esta respuesta no tiene actividad registrada.');
    return;
  }

  const drawer = document.getElementById('chatActivityDrawer');
  const backdrop = document.getElementById('chatActivityDrawerBackdrop');
  const body = document.getElementById('chatActivityDrawerBody');
  const title = document.getElementById('chatActivityDrawerTitle');
  const meta = document.getElementById('chatActivityDrawerMeta');
  const openLink = document.getElementById('chatActivityDrawerOpen');
  const txtLink = document.getElementById('chatActivityDrawerTxt');
  const jsonLink = document.getElementById('chatActivityDrawerJson');
  if (!drawer || !body) {
    openActivityViewerTab(msgDiv);
    return;
  }

  drawer.classList.add('is-open');
  drawer.setAttribute('aria-hidden', 'false');
  if (backdrop) {
    backdrop.classList.add('is-open');
    backdrop.setAttribute('aria-hidden', 'false');
  }
  document.body.classList.add('chat-activity-drawer-open');
  if (title) title.textContent = messageId ? `Respuesta #${messageId}` : 'Actividad del agente';
  let previous = msgDiv.previousElementSibling;
  while (previous && !previous.classList.contains('chat-user')) previous = previous.previousElementSibling;
  const questionText = previous ? String((previous.querySelector('.msg-content') || previous).innerText || '').trim() : '';
  if (meta) {
    const questionPreview = questionText ? ` · pregunta: ${questionText.slice(0, 140)}` : '';
    meta.textContent = `trace ${traceId} · sesión #${sessionId}${questionPreview}`;
  }
  if (openLink) openLink.href = activityViewerUrl(traceId, sessionId);
  if (txtLink) txtLink.href = activityViewerUrl(traceId, sessionId, 'txt');
  if (jsonLink) jsonLink.href = activityViewerUrl(traceId, sessionId, 'json');
  body.innerHTML = '<div class="chat-activity-empty"><i class="fas fa-circle-notch fa-spin mr-1"></i>Cargando proceso guardado…</div>';

  try {
    const state = getActivityState(traceId, sessionId);
    const j = await fetchActivityEvents(traceId, 0);
    state.events = Array.isArray(j.events) ? j.events : [];
    state.lastId = Number(j.last_id || 0);
    state.loaded = true;
    state.terminal = !!j.terminal;
    if (!state.events.length) {
      body.innerHTML = '<div class="chat-activity-empty">No hay eventos guardados para esta respuesta.</div>';
      return;
    }
    body.innerHTML = `<div class="chat-activity-events">${state.events.map(activityEventHtml).join('')}</div>`;
  } catch (e) {
    body.innerHTML = `<div class="chat-activity-empty text-danger"><i class="fas fa-exclamation-triangle mr-1"></i>${esc(e.message || String(e))}</div>`;
  }
}

const activityDrawerCloseBtn = document.getElementById('chatActivityDrawerClose');
const activityDrawerBackdrop = document.getElementById('chatActivityDrawerBackdrop');
if (activityDrawerCloseBtn) activityDrawerCloseBtn.addEventListener('click', closeActivityDrawer);
if (activityDrawerBackdrop) activityDrawerBackdrop.addEventListener('click', closeActivityDrawer);
document.addEventListener('keydown', (ev) => {
  if (ev.key === 'Escape') closeActivityDrawer();
});

function toJSONorThrow(text, status, label) {
    // ✅ Detectar respuesta vacía explícitamente
    if (!text || text.trim() === '') {
        throw new Error(
            `${label}: El servidor devolvió respuesta VACÍA (HTTP ${status}). ` +
            `Esto suele ser un timeout del servidor o un error fatal en PHP. ` +
            `Revisa el log de errores de PHP.`
        );
    }
    
    // ✅ Detectar HTML de error de PHP
    if (text.trim().startsWith('<') || text.includes('<b>Fatal error</b>') || text.includes('<b>Warning</b>')) {
        console.error(`${label}: El servidor devolvió HTML en lugar de JSON:`, text.slice(0, 500));
        throw new Error(
            `${label}: El servidor devolvió un error PHP en lugar de JSON. ` +
            `Detalle: ${text.slice(0, 200)}`
        );
    }
    
    try {
        return JSON.parse(text);
    } catch (e) {
        console.error(`${label}: Error parseando JSON. Respuesta cruda:`, text.slice(0, 500));
        throw new Error(
            `${label} no devolvió JSON válido (HTTP ${status}). ` +
            `Respuesta: ${text.slice(0, 280)}`
        );
    }
}
    function buildS3Url(key) {
      return 'descargar.php?archivo=' + encodeURIComponent(key) + '&nombre=' + encodeURIComponent(key.split('/').pop());
    }
    function scrollMessagesToBottom() {
      if (el.messages) el.messages.scrollTop = el.messages.scrollHeight;
    }
    function getUserId() {
      const hid = document.getElementById('chatUserId');
      if (hid && hid.value) {
        const n = parseInt(hid.value, 10);
        if (Number.isFinite(n) && n > 0) return String(n);
      }
      const ds = document.querySelector('[data-user-id]');
      if (ds) {
        const v = ds.getAttribute('data-user-id') || (ds.dataset ? ds.dataset.userId : '');
        const n = parseInt(v, 10);
        if (Number.isFinite(n) && n > 0) return String(n);
      }
      return '';
    }
    function requireModelSelected() {
      const domModel = el.model ? String(el.model.value || '').trim() : '';
      const preferenceModel = window.chatPreferences && typeof window.chatPreferences.getModelId === 'function'
        ? String(window.chatPreferences.getModelId() || '').trim()
        : '';
      const model = domModel || preferenceModel;
      if (!model) {
        setStatus('Selecciona un modelo antes de continuar.');
        if (el.model) el.model.focus();
        return '';
      }
      return model;
    }

    async function waitForChatPreferences(maxWaitMs = 1500) {
      const ready = window.chatPreferencesReady;
      if (!ready || typeof ready.then !== 'function') return;
      await Promise.race([
        ready.catch(() => true),
        new Promise(resolve => setTimeout(resolve, Math.max(100, maxWaitMs)))
      ]);
    }
        function mdSafe(html) {
      return String(html || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }
    function mdInline(text) {
      let s = String(text || '');
      s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
      s = s.replace(/`([^`\n]+)`/g, '<code>$1</code>');
      s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
      s = s.replace(/__([^_]+)__/g, '<strong>$1</strong>');
      s = s.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');
      s = s.replace(/_([^_\n]+)_/g, '<em>$1</em>');
      return s;
    }
    function parseMarkdownTable(block) {
      const lines = String(block || '').split('\n').map(v => v.trim()).filter(Boolean);
      if (lines.length < 2 || !/\|/.test(lines[0]) || !/\|/.test(lines[1])) return null;
      const sep = lines[1].replace(/\s/g, '');
      if (!/^(\|?[:\-]+)+\|?[:\-]*$/.test(sep.replace(/\|/g, '|')) || !/^[:\-\|]+$/.test(sep)) return null;
      const splitRow = (row) => {
        let r = row.trim();
        if (r.startsWith('|')) r = r.slice(1);
        if (r.endsWith('|')) r = r.slice(0, -1);
        return r.split('|').map(cell => mdInline(cell.trim()));
      };
      const head = splitRow(lines[0]);
      const body = lines.slice(2).map(splitRow);
      if (!head.length) return null;
      let html = '<div class="chat-table-wrap"><table><thead><tr>' + head.map(cell => `<th>${cell}</th>`).join('') + '</tr></thead>';
      if (body.length) {
        html += '<tbody>' + body.map(row => `<tr>${head.map((_, i) => `<td>${row[i] || ''}</td>`).join('')}</tr>`).join('') + '</tbody>';
      }
      return html + '</table></div>';
    }
function mdToHtml(md) {
  if (!md) return '';
  let codeBlocks = [];
  let thinkingBlocks = [];
  let tempMd = String(md);
  tempMd = tempMd.replace(/<thinking>([\s\S]*?)<\/thinking>/gi, () => {
    const index = thinkingBlocks.length;
    thinkingBlocks.push(`<div class="thinking-block"><i class="fas fa-shield-alt"></i> <strong>Razonamiento privado omitido</strong></div>`);
    return `\n___THINKING_BLOCK_${index}___\n`;
  });
  tempMd = tempMd.replace(/```(\w*)\n?([\s\S]*?)```/g, (match, lang, code) => {
    const index = codeBlocks.length;
    const cleanCode = mdSafe(code.trim());
    const langLabel = lang || 'text';
    codeBlocks.push(`<div class="chat-code-wrapper"><pre class="chat-code-block" data-lang="${langLabel}"><code>${cleanCode}</code></pre><button class="chat-code-copy-btn" title="Copiar código"><i class="fas fa-copy"></i> <span>Copiar</span></button></div>`);
    return `\n___CODE_BLOCK_${index}___\n`;
  });
  // Procesar bloques de instrucciones o código inline con contenedor especial
  tempMd = tempMd.replace(/\[INSTRUCTION\]([\s\S]*?)\[\/INSTRUCTION\]/gi, (match, content) => {
    const safeContent = mdSafe(content.trim());
    return `<div class="chat-instruction-block" style="background:rgba(255,193,7,0.1);border-left:3px solid #ffc107;padding:8px;margin:8px 0;border-radius:4px;"><strong>Instrucción:</strong><br>${safeContent.replace(/\n/g, '<br>')}</div>`;
  });
  const lines = mdSafe(tempMd).split('\n');
  const out = [];
  let inUl = false, inOl = false, inBlockquote = false;
  const closeLists = () => { if (inUl) { out.push('</ul>'); inUl = false; } if (inOl) { out.push('</ol>'); inOl = false; } };
  const closeBlockquote = () => { if (inBlockquote) { out.push('</blockquote>'); inBlockquote = false; } };
  const closeAllBlocks = () => { closeLists(); closeBlockquote(); };
  for (let i = 0; i < lines.length; i++) {
    const raw = lines[i];
    const line = raw.trim();
    const thinkingMatch = line.match(/^___THINKING_BLOCK_(\d+)___$/);
    if (thinkingMatch) {
      closeAllBlocks();
      const idx = parseInt(thinkingMatch[1], 10);
      out.push(thinkingBlocks[idx]);
      continue;
    }
    const codeMatch = line.match(/^___CODE_BLOCK_(\d+)___$/);
    if (codeMatch) {
      closeAllBlocks();
      const idx = parseInt(codeMatch[1], 10);
      out.push(codeBlocks[idx]);
      continue;
    }
    if (!line) { closeAllBlocks(); continue; }
    if (i + 1 < lines.length && /\|/.test(lines[i]) && /\|/.test(lines[i + 1]) && /^[:\-\|\s]+$/.test(lines[i + 1].trim())) {
      closeAllBlocks();
      const tableBlock = [];
      while (i < lines.length && lines[i].trim() && /\|/.test(lines[i])) { tableBlock.push(lines[i]); i++; }
      const tableHtml = parseMarkdownTable(tableBlock.join('\n'));
      if (tableHtml) { out.push(tableHtml); continue; } else { i -= tableBlock.length; }
    }
    const h = raw.match(/^(#{1,6})\s+(.+)$/);
    if (h) { closeAllBlocks(); out.push(`<h${h[1].length}>${mdInline(h[2].trim())}</h${h[1].length}>`); continue; }
    if (/^([-*_]){3,}$/.test(line.replace(/\s/g, ''))) { closeAllBlocks(); out.push('<hr>'); continue; }
    const bq = raw.match(/^\s*>\s?(.*)$/);
    if (bq) { closeLists(); if (!inBlockquote) { out.push('<blockquote>'); inBlockquote = true; } out.push(`<p>${mdInline(bq[1])}</p>`); continue; } 
    else { closeBlockquote(); }
    const ul = raw.match(/^\s*[-*]\s+(.+)$/);
    if (ul) { closeBlockquote(); if (inOl) { out.push('</ol>'); inOl = false; } if (!inUl) { out.push('<ul>'); inUl = true; } out.push(`<li>${mdInline(ul[1])}</li>`); continue; }
    const ol = raw.match(/^\s*\d+\.\s+(.+)$/);
    if (ol) { closeBlockquote(); if (inUl) { out.push('</ul>'); inUl = false; } if (!inOl) { out.push('<ol>'); inOl = true; } out.push(`<li>${mdInline(ol[1])}</li>`); continue; }
    closeLists(); closeBlockquote();
    out.push(`<p>${mdInline(line)}</p>`);
  }
  closeAllBlocks();
  return out.join('\n').trim();
}
    function addFilesFromInput(fileList) {
      const arr = Array.from(fileList || []);
      if (!arr.length) return;
      arr.forEach(f => pendingFiles.push({ file: f, id: fileIdSeq++ }));
      renderQueue();
      suggestAttachmentsHeader();
      if (el.file) el.file.value = '';
    }
    function removeFileById(id) {
      pendingFiles = pendingFiles.filter(x => x.id !== id);
      renderQueue();
    }
    function renderQueue() {
      const has = pendingFiles.length > 0;
      if (!el.queue || !el.queueList) return;
      el.queue.classList.toggle('d-none', !has);
      if (!has) { el.queueList.innerHTML = ''; return; }
      el.queueList.innerHTML = pendingFiles.map(({file, id}) => {
        const k = 1024, sizes = ['B','KB','MB','GB','TB'];
        const i = file.size === 0 ? 0 : Math.floor(Math.log(file.size)/Math.log(k));
        const size = (file.size/Math.pow(k,i)).toFixed(1) + ' ' + sizes[i];
        return `<span class="file-chip" title="${esc(file.name)} • ${size}">
          <i class="fas fa-file mr-1"></i>${esc(file.name)}
          <button type="button" class="chip-x" data-id="${id}" aria-label="Quitar">&times;</button>
        </span>`;
      }).join('');
      el.queueList.querySelectorAll('.chip-x').forEach(btn => {
        btn.addEventListener('click', () => removeFileById(parseInt(btn.getAttribute('data-id'), 10)));
      });
    }
    function suggestAttachmentsHeader() {
      if (!el.input) return;
      const txt = (el.input.value || '').trim();
      if (txt || !pendingFiles.length) return;
      el.input.value = `Adjunto(s): ${pendingFiles.map(x => x.file.name).join(', ')}\n\n`;
      el.input.focus();
      el.input.setSelectionRange(el.input.value.length, el.input.value.length);
    }


function renderMessageFooterHtml(createdAt, msgId = '', traceId = '', sessionId = '') {
  const timeLabel = createdAt ? fmtDate(createdAt) : '';
  const safeTrace = String(traceId || '').trim();
  const safeSession = String(sessionId || currentSessionId || '');
  const timeHtml = timeLabel
    ? (safeTrace
        ? `<button type="button" class="msg-time msg-time-process" data-action="process" data-trace-id="${esc(safeTrace)}" data-session-id="${esc(safeSession)}" title="Ver el proceso de esta respuesta"><i class="fas fa-clock"></i>${esc(timeLabel)}</button>`
        : `<span class="msg-time"><i class="fas fa-clock"></i>${esc(timeLabel)}</span>`)
    : '';
  const actionsHtml = renderMessageActionsHtml(msgId, safeTrace, safeSession);
  return `<div class="message-footer">${timeHtml}${actionsHtml}</div>`;
}

function pushLocal(role, content, opts = {}) {
  const ct = opts.content_type || 'text';
  const traceId = String(opts.trace_id || '').trim();
  const msgId = opts.message_id || '';
  const sessionId = opts.session_id || currentSessionId || '';

  // La tarjeta grande se conserva únicamente mientras el proceso está EN VIVO.
  // Al terminar, el historial permanece accesible desde la fecha/botón Proceso.
  if (role === 'assistant' && traceId && isActivityEnabled()) {
    const traceState = getActivityState(traceId, sessionId);
    if (traceState.live && !traceState.terminal) {
      ensureActivityCard(traceId, { live: true, sessionId });
    }
  }

  const plainTimeHtml = opts.created_at ? `<div class="msg-time">${esc(fmtDate(opts.created_at))}</div>` : '';
  const footerHtml = role === 'assistant'
    ? renderMessageFooterHtml(opts.created_at || '', msgId, traceId, sessionId)
    : plainTimeHtml;
  let html = '';
  const isPrimordial = opts.is_primordial == 1 || opts.is_primordial === true;
  const dataAttrs = role === 'assistant'
    ? ` data-message-id="${esc(msgId)}" data-trace-id="${esc(traceId)}" data-session-id="${esc(sessionId)}"`
    : '';

  const primordialBtn = (role === 'assistant' && msgId) ? `
    <button class="btn-primordial ${isPrimordial ? 'active' : ''}"
            data-msg-id="${esc(msgId)}"
            title="${isPrimordial ? 'Quitar de primordiales (verdad absoluta)' : 'Marcar como primordial (verdad absoluta)'}"
            style="float:right; background:none; border:1px solid #ffc107; color:${isPrimordial ? '#ffc107' : '#ccc'};
                   padding:2px 8px; font-size:0.7rem; border-radius:4px; cursor:pointer; margin-bottom: 4px; transition: all 0.2s;">
      <i class="fas fa-${isPrimordial ? 'star' : 'star-o'}"></i> ${isPrimordial ? 'Primordial' : 'Marcar'}
    </button>
  ` : '';

  if (ct === 'image' && (opts.s3_key || opts.thumb_s3_key)) {
    const imgUrl = buildS3Url(opts.thumb_s3_key || opts.s3_key);
    const fullUrl = buildS3Url(opts.s3_key || opts.thumb_s3_key);
    const alignClass = role === 'assistant' ? 'align-right' : 'align-left';
    html = `<div class="chat-msg ${role === 'assistant' ? 'assistant chat-assistant' : 'user chat-user'} ${alignClass}"${dataAttrs}>
      <div class="msg-header"><strong>${role === 'assistant' ? 'Asistente' : 'Tú'}</strong></div>
      ${content ? `<div class="msg-content">${esc(content)}</div>` : ''}
      <a href="${fullUrl}" target="_blank" rel="noopener"><img src="${imgUrl}" alt="imagen" style="max-width:320px; border-radius:8px; margin-top:.35rem;"></a>
      ${footerHtml}
    </div>`;
  } else if (ct === 'video' && opts.s3_key) {
    const vidUrl = buildS3Url(opts.s3_key);
    const alignClass = role === 'assistant' ? 'align-right' : 'align-left';
    html = `<div class="chat-msg ${role === 'assistant' ? 'assistant chat-assistant' : 'user chat-user'} ${alignClass}"${dataAttrs}>
      <div class="msg-header"><strong>${role === 'assistant' ? 'Asistente' : 'Tú'}</strong></div>
      ${content ? `<div class="msg-content">${esc(content)}</div>` : ''}
      <video controls style="max-width:420px; margin-top:.35rem;" preload="metadata">
        <source src="${vidUrl}" type="${esc(opts.mime_type || 'video/mp4')}">
        Tu navegador no soporta video embebido. <a href="${vidUrl}" target="_blank" rel="noopener">Descargar</a>
      </video>
      ${footerHtml}
    </div>`;
  } else if (ct === 'audio' && opts.s3_key) {
    const aUrl = buildS3Url(opts.s3_key);
    const alignClass = role === 'assistant' ? 'align-right' : 'align-left';
    html = `<div class="chat-msg ${role === 'assistant' ? 'assistant chat-assistant' : 'user chat-user'} ${alignClass}"${dataAttrs}>
      <div class="msg-header"><strong>${role === 'assistant' ? 'Asistente' : 'Tú'}</strong></div>
      ${content ? `<div class="msg-content">${esc(content)}</div>` : ''}
      <audio controls style="width:320px; margin-top:.35rem;">
        <source src="${aUrl}" type="${esc(opts.mime_type || 'audio/mpeg')}">
        <a href="${aUrl}" target="_blank" rel="noopener">Descargar audio</a>
      </audio>
      ${footerHtml}
    </div>`;
  } else {
    if (role === 'assistant') {
      html = `<div class="chat-msg assistant chat-assistant align-right"${dataAttrs}>
        ${primordialBtn}
        <div class="chat-md">${mdToHtml(content || '')}</div>
        ${footerHtml}
      </div>`;
    }
    else if (role === 'system') {
      html = `<div class="chat-msg system chat-system align-left" style="background: rgba(255, 193, 7, 0.08); border-left: 3px solid #ffc107; padding: 10px; border-radius: 6px; margin: 10px 0; font-size: 0.9em; color: #e0e0e0;">
        <div class="msg-header" style="font-weight: bold; color: #ffc107; margin-bottom: 6px; font-size: 0.85rem;">
          <i class="fas fa-magic"></i> Prompt optimizado por IA:
        </div>
        <div class="msg-content" style="font-style: italic; opacity: 0.9;">${mdToHtml(content || '')}</div>
        ${plainTimeHtml}
      </div>`;
    }
    else {
      html = `<div class="chat-msg user chat-user align-left">
        <div class="msg-header"><strong>Tú</strong></div>
        <div class="msg-content">${esc(content || '').replace(/\n/g, '<br>')}</div>
        ${plainTimeHtml}
      </div>`;
    }
  }
  el.messages.insertAdjacentHTML('beforeend', html);

  if (role === 'assistant') {
    wireMessageActions(el.messages.lastElementChild);
  }

  wireCodeCopyButtons(el.messages);
  scrollMessagesToBottom();
}

function renderMessageActionsHtml(msgId = '', traceId = '', sessionId = '') {
  const branchAttr = msgId ? `data-msg-id="${esc(msgId)}"` : 'disabled aria-disabled="true"';
  const hasTrace = !!String(traceId || '').trim();
  const traceAttrs = hasTrace
    ? `data-trace-id="${esc(traceId)}" data-session-id="${esc(sessionId || currentSessionId || '')}"`
    : '';
  const processButtons = hasTrace ? `
    <button class="action-btn" data-action="process" ${traceAttrs} title="Ver proceso real de esta respuesta">
      <i class="fas fa-wave-square"></i> <span class="action-btn-label">Proceso</span>
    </button>
    <button class="action-btn" data-action="process-tab" ${traceAttrs} title="Abrir proceso en pestaña nueva">
      <i class="fas fa-external-link-alt"></i> <span class="action-btn-label">Abrir</span>
    </button>` : '';
  return `<div class="message-actions">
    <button class="action-btn" data-action="copy" title="Copiar toda la respuesta">
      <i class="fas fa-copy"></i> <span class="action-btn-label">Copiar</span>
    </button>
    <button class="action-btn" data-action="speak" title="Leer la respuesta en voz alta">
      <i class="fas fa-volume-up"></i> <span class="action-btn-label">Escuchar</span>
    </button>
    <button class="action-btn" data-action="share" title="Compartir respuesta">
      <i class="fas fa-share-alt"></i> <span class="action-btn-label">Compartir</span>
    </button>
    <button class="action-btn" data-action="branch" ${branchAttr} title="Crear rama desde esta respuesta">
      <i class="fas fa-code-branch"></i> <span class="action-btn-label">Rama</span>
    </button>
    ${processButtons}
  </div>`;
}
function showActionToast(message) {
  const container = document.getElementById('chatToasts') || document.getElementById('incomingToasts');
  if (!container) return;
  const toast = document.createElement('div');
  toast.className = 'chat-toast';
  toast.innerHTML = `<div class="small">${esc(message)}</div>`;
  container.appendChild(toast);
  setTimeout(() => { if (toast.parentNode) toast.remove(); }, 3000);
}
async function copyMessageText(text, btn) {
  if (!text || !text.trim()) return;
  const restoreHtml = btn.innerHTML;
  const done = () => {
    btn.innerHTML = '<i class="fas fa-check"></i> <span class="action-btn-label">¡Copiado!</span>';
    btn.classList.add('copied');
    setTimeout(() => { btn.innerHTML = restoreHtml; btn.classList.remove('copied'); }, 2000);
  };
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
      done();
      return;
    }
    throw new Error('clipboard no disponible');
  } catch (e) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.className = 'clipboard-fallback';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    const ok = document.execCommand('copy');
    ta.remove();
    if (ok) done();
  }
}
let currentUtterance = null;
function speakMessageText(text, btn) {
  if (!window.speechSynthesis) {
    showActionToast('Tu navegador no soporta lectura en voz alta.');
    return;
  }
  if (window.speechSynthesis.speaking) {
    window.speechSynthesis.cancel();
    btn.classList.remove('listening');
    btn.innerHTML = '<i class="fas fa-volume-up"></i> <span class="action-btn-label">Escuchar</span>';
    if (currentUtterance === btn) currentUtterance = null;
    return;
  }
  const utterance = new SpeechSynthesisUtterance(text);
  utterance.lang = 'es-ES';
  utterance.rate = 1.0;
  utterance.pitch = 1.0;
  const restore = () => {
    btn.classList.remove('listening');
    btn.innerHTML = '<i class="fas fa-volume-up"></i> <span class="action-btn-label">Escuchar</span>';
    currentUtterance = null;
  };
  utterance.onstart = () => {
    currentUtterance = btn;
    btn.classList.add('listening');
    btn.innerHTML = '<i class="fas fa-stop"></i> <span class="action-btn-label">Detener</span>';
  };
  utterance.onend = restore;
  utterance.onerror = restore;
  window.speechSynthesis.speak(utterance);
}
async function shareMessageText(text) {
  if (navigator.share) {
    try {
      await navigator.share({ title: 'Respuesta de IA · Cloud Drive', text });
      return;
    } catch (e) {
    }
  }
  try {
    await navigator.clipboard.writeText(text);
    showActionToast('Copiado para compartir');
  } catch (e) {
    showActionToast('No se pudo compartir ni copiar.');
  }
}
async function branchFromMessage(msgDiv) {
  const msgId = String(msgDiv?.dataset?.messageId || msgDiv?.querySelector('[data-msg-id]')?.dataset?.msgId || '').trim();
  if (!msgId) {
    showActionToast('Esta respuesta todavía no tiene un ID persistido para crear la rama.');
    return;
  }

  const model = requireModelSelected();
  if (!model) return;
  const currentTitle = (el.title && el.title.textContent) || 'Conversación';
  const newTitle = currentTitle + ' (rama)';
  showActionToast('Creando rama con contexto heredado…');

  try {
    const fd = new FormData();
    fd.append('title', newTitle);
    fd.append('parent_message_id', msgId);
    fd.append('model', model);
    // El servidor deriva sesión/proyecto origen desde parent_message_id y valida dueño.
    const r = await fetch(API.createSession, { method: 'POST', credentials: 'same-origin', body: fd });
    const j = toJSONorThrow(await r.text(), r.status, 'Crear rama');
    if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);

    await loadSessions();
    await selectSession(j.id);
    const inherited = j.branch && Number(j.branch.inherited_message_count || 0);
    showActionToast(`✅ Rama creada${inherited ? ` · ${inherited} mensaje(s) heredados en contexto` : ''}`);
  } catch (e) {
    console.error('Error creando rama:', e);
    showActionToast('❌ No se pudo crear la rama: ' + e.message);
  }
}

function getAssistantMessageText(msgDiv) {
  if (!msgDiv) return '';
  const textNode = msgDiv.querySelector('.chat-md') || msgDiv.querySelector('.msg-content');
  return (textNode ? textNode.innerText : '').trim();
}

function activityViewerUrl(traceId, sessionId, format = '') {
  const qs = new URLSearchParams({
    trace_id: String(traceId || ''),
    session_id: String(sessionId || currentSessionId || '')
  });
  if (format) qs.set('format', format);
  return `${API.activityViewer}?${qs.toString()}`;
}

function openActivityViewerTab(msgDiv) {
  const traceId = String(msgDiv?.dataset?.traceId || '').trim();
  const sessionId = Number(msgDiv?.dataset?.sessionId || currentSessionId || 0);
  if (!traceId || !sessionId) {
    showActionToast('Esta respuesta no tiene actividad registrada.');
    return;
  }
  window.open(activityViewerUrl(traceId, sessionId), '_blank', 'noopener');
}

function wireMessageActions(msgDiv) {
  if (!msgDiv) return;
  const controls = Array.from(msgDiv.querySelectorAll('.message-actions .action-btn, .msg-time-process'));
  controls.forEach(btn => {
    if (btn.dataset.wired === 'true') return;
    btn.dataset.wired = 'true';
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      if (btn.disabled) return;
      const action = btn.dataset.action;
      const text = getAssistantMessageText(msgDiv);
      if (action === 'copy') copyMessageText(text, btn);
      else if (action === 'speak') speakMessageText(text, btn);
      else if (action === 'share') shareMessageText(text);
      else if (action === 'branch') branchFromMessage(msgDiv);
      else if (action === 'process') openActivityDrawerForMessage(msgDiv);
      else if (action === 'process-tab') openActivityViewerTab(msgDiv);
    });
  });
}

function wireAllMessageActions(container) {
  if (!container) return;
  container.querySelectorAll('.chat-msg.assistant').forEach(msgDiv => {
    wireMessageActions(msgDiv);
  });
}
function wireCodeCopyButtons(container) {
  if (!container) return;
  container.querySelectorAll('.chat-code-copy-btn').forEach(btn => {
    if (btn.dataset.wired === 'true') return;
    btn.dataset.wired = 'true';
    btn.addEventListener('click', async (e) => {
      e.stopPropagation();
      const pre = btn.closest('.chat-code-wrapper').querySelector('pre.chat-code-block code');
      if (!pre) return;
      const codeText = pre.innerText;
      const originalHtml = btn.innerHTML;
      try {
        if (navigator.clipboard && window.isSecureContext) {
          await navigator.clipboard.writeText(codeText);
        } else {
          throw new Error('clipboard no disponible');
        }
        btn.innerHTML = '<i class="fas fa-check"></i> <span>¡Copiado!</span>';
        btn.classList.add('copied');
      } catch (err) {
        const ta = document.createElement('textarea');
        ta.value = codeText;
        ta.className = 'clipboard-fallback';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        const ok = document.execCommand('copy');
        ta.remove();
        if (ok) {
          btn.innerHTML = '<i class="fas fa-check"></i> <span>¡Copiado!</span>';
          btn.classList.add('copied');
        } else {
          btn.innerHTML = '<i class="fas fa-times"></i> <span>Error</span>';
        }
      }
      setTimeout(() => {
        btn.innerHTML = originalHtml;
        btn.classList.remove('copied');
      }, 2000);
    });
  });
}
async function loadSessions() {
  setStatus('Cargando sesiones…');
  try {
    const qs = new URLSearchParams();
    const q = (el.sbChatSearch && el.sbChatSearch.value.trim()) || (el.search && el.search.value.trim()) || '';
    if (q) qs.set('q', q);
    if (el.showArchived && el.showArchived.checked) qs.set('archived', '1');
    const uid = getUserId();
    if (uid) qs.set('user_id', uid);
    const r = await fetch(`${API.sessions}?${qs.toString()}`, { credentials: 'same-origin' });
    const j = toJSONorThrow(await r.text(), r.status, 'La API de sesiones');
    if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);
    sessions = Array.isArray(j.sessions) ? j.sessions : [];
    renderSessionsList();
  } catch (e) {
    console.error(e);
    const target = el.sbChatList || el.sessionsList;
    if (target) target.innerHTML = `<div class="text-danger small">${esc(e.message)}</div>`;
  } finally {
    setStatus('');
  }
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
function groupFreeSessionsByDate(list) {
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
function renderSessionsList() {
  const targetList = el.sbChatList || el.sessionsList;
  if (!targetList) return;
  const freeSessions = sessions.filter(s => !s.project_id && !s.project_id_);
  const renderItem = (s) => {
    const sid = s.id || s.id_;
    const title = esc(s.title || `Sesión #${sid}`);
    const meta = formatSessionMeta(s.updated_at || s.created_at || '');
    const isArchived = s.archived || s.status === 'archived';
    const badge = isArchived ? `<span class="badge badge-secondary ml-1" style="font-size:0.6rem;">arch</span>` : '';
    const active = (sid === currentSessionId) ? ' active' : '';
    return `<div class="sb-item${active}" data-id="${sid}" title="${esc(title)}">
      <div class="d-flex justify-content-between align-items-center">
        <span class="text-truncate sb-item-title" style="max-width: 70%;">${esc(title)} ${badge}</span>
        <div class="btn-group btn-group-sm" style="flex-shrink: 0;">
          <button class="btn btn-link p-0 js-rename" title="Renombrar"><i class="fas fa-pen" style="font-size:0.55rem;"></i></button>
          ${isArchived
            ? `<button class="btn btn-link p-0 js-restore text-success" title="Restaurar"><i class="fas fa-undo" style="font-size:0.55rem;"></i></button>`
            : `<button class="btn btn-link p-0 js-archive text-danger" title="Archivar"><i class="fas fa-archive" style="font-size:0.55rem;"></i></button>`}
        </div>
      </div>
      <small class="sb-item-meta text-muted d-block">${esc(meta)}</small>
    </div>`;
  };
  const groups = groupFreeSessionsByDate(freeSessions);
  const items = groups.length
    ? groups.map(([label, arr]) => `
        <div class="sb-group-label">${esc(label)}</div>
        ${arr.map(renderItem).join('')}
      `).join('')
    : `<div class="text-muted small">Sin chats libres</div>`;
  targetList.innerHTML = items;
  targetList.querySelectorAll('.sb-item').forEach(item => {
    item.addEventListener('click', (ev) => {
      if (ev.target.closest('.js-rename,.js-archive,.js-restore')) return;
      selectSession(parseInt(item.getAttribute('data-id'), 10));
    });
    const sid = parseInt(item.getAttribute('data-id'), 10);
    const btnRename = item.querySelector('.js-rename');
    const btnArchive = item.querySelector('.js-archive');
    const btnRestore = item.querySelector('.js-restore');
    if (btnRename) btnRename.addEventListener('click', (e) => { e.stopPropagation(); promptRename(sid); });
    if (btnArchive) btnArchive.addEventListener('click', (e) => { e.stopPropagation(); doArchive(sid); });
    if (btnRestore) btnRestore.addEventListener('click', (e) => { e.stopPropagation(); doRestore(sid); });
  });
}



    async function discardEmptySession(sessionId, opts = {}) {
      const sid = Number(sessionId || 0);
      if (!sid) return false;
      try {
        const fd = new FormData();
        fd.append('session_id', String(sid));
        const r = await fetch(API.discardEmptySession, { method:'POST', credentials:'same-origin', body:fd, keepalive: !!opts.keepalive });
        const j = toJSONorThrow(await r.text(), r.status, 'Descartar sesión vacía');
        if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);
        if (j.deleted) {
          sessions = sessions.filter(x => Number(x.id || x.id_) !== sid);
          if (!opts.keepCurrent && Number(currentSessionId) === sid) {
            currentSessionId = null;
            syncTraceExplorerButton();
          }
          renderSessionsList();
          renderProjectList();
          return true;
        }
      } catch (e) {
        if (!opts.silent) console.warn('No se pudo limpiar la sesión vacía:', e);
      }
      return false;
    }

    async function createSession(title) {
      setStatus('Creando sesión…');
      // Dar oportunidad a user_preferences.js de restaurar el modelo guardado.
      // El backend tiene además fallback defensivo por si un cliente antiguo omite model.
      await waitForChatPreferences();
      const fd = new FormData();
      if (title) fd.append('title', title);
      const uid = getUserId();
      if (uid) fd.append('user_id', uid);
      if (currentProjectId) fd.append('project_id', currentProjectId);
      const model = requireModelSelected();
      if (!model) throw new Error('Selecciona un modelo.');
      fd.append('model', model);
      const r = await fetch(API.createSession, { method:'POST', credentials:'same-origin', body: fd });
      const j = toJSONorThrow(await r.text(), r.status, 'Crear sesión');
      if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);
      setStatus('');
      return j;
    }
    async function promptRename(id) {
      const s = sessions.find(x => x.id === id);
      const oldTitle = s ? (s.title || `Sesión #${id}`) : `Sesión #${id}`;
      const title = window.prompt('Nuevo título:', oldTitle);
      if (title == null) return;
      setStatus('Renombrando…');
      const fd = new FormData();
      fd.append('session_id', id);
      fd.append('title', title);
      const r = await fetch(API.renameSession, { method:'POST', credentials:'same-origin', body: fd });
      const j = toJSONorThrow(await r.text(), r.status, 'Renombrar sesión');
      if (!r.ok || j.ok === false) { setStatus(''); alert(j.error || `HTTP ${r.status}`); return; }
      await loadSessions();
      if (id === currentSessionId && el.title) el.title.textContent = title;
      setStatus('');
    }
    async function doArchive(id) {
      setStatus('Archivando…');
      const fd = new FormData();
      fd.append('session_id', id);
      const r = await fetch(API.archiveSession, { method:'POST', credentials:'same-origin', body: fd });
      const j = toJSONorThrow(await r.text(), r.status, 'Archivar sesión');
      if (!r.ok || j.ok === false) { setStatus(''); alert(j.error || `HTTP ${r.status}`); return; }
      await loadSessions();
      if (id === currentSessionId) {
        const s = sessions.find(x => x.id === id);
        if (el.badge) {
          if (s && s.archived) { el.badge.textContent = 'archivada'; el.badge.classList.remove('d-none'); }
          else { el.badge.textContent = ''; el.badge.classList.add('d-none'); }
        }
        if (el.restore) el.restore.classList.toggle('d-none', !(s && s.archived));
        if (el.archive) el.archive.classList.toggle('d-none', !!(s && s.archived));
      }
      setStatus('');
    }
    async function doRestore(id) {
      setStatus('Restaurando…');
      const fd = new FormData();
      fd.append('session_id', id);
      const r = await fetch(API.restoreSession, { method:'POST', credentials:'same-origin', body: fd });
      const j = toJSONorThrow(await r.text(), r.status, 'Restaurar sesión');
      if (!r.ok || j.ok === false) { setStatus(''); alert(j.error || `HTTP ${r.status}`); return; }
      await loadSessions();
      if (id === currentSessionId) {
        const s = sessions.find(x => x.id === id);
        if (el.badge) {
          if (s && s.archived) { el.badge.textContent = 'archivada'; el.badge.classList.remove('d-none'); }
          else { el.badge.textContent = ''; el.badge.classList.add('d-none'); }
        }
        if (el.restore) el.restore.classList.toggle('d-none', !(s && s.archived));
        if (el.archive) el.archive.classList.toggle('d-none', !!(s && s.archived));
      }
      setStatus('');
    }
    async function selectSession(id) {
      if (!id || Number.isNaN(id)) return;
      const previousSessionId = currentSessionId;
      if (previousSessionId && Number(previousSessionId) !== Number(id)) {
        await discardEmptySession(previousSessionId, { silent:true, keepCurrent:true });
      }
      currentSessionId = id;
      syncTraceExplorerButton();
      const s = sessions.find(x => x.id === id);
      if (el.title) el.title.textContent = s ? (s.title || `Sesión #${id}`) : `Sesión #${id}`;
      if (el.sbCurrentSession) el.sbCurrentSession.textContent = s ? (s.title || `Sesión #${id}`) : 'Ninguna';
      if (s && s.project_id) {
        currentProjectId = s.project_id;
        await selectProject(s.project_id);
      } else {
        currentProjectId = null;
        await selectProject(null);
      }
      if (el.badge) {
        if (s && s.archived) { el.badge.textContent = 'archivada'; el.badge.classList.remove('d-none'); }
        else { el.badge.textContent = ''; el.badge.classList.add('d-none'); }
      }
      if (el.restore) el.restore.classList.toggle('d-none', !(s && s.archived));
      if (el.archive) el.archive.classList.toggle('d-none', !!(s && s.archived));
      setStatus('Cargando mensajes…');
      try {
        const qs = new URLSearchParams({ session_id: String(id) });
        const r = await fetch(`${API.messages}?${qs.toString()}`, { credentials: 'same-origin' });
        const j = toJSONorThrow(await r.text(), r.status, 'Mensajes de la sesión');
        if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);
        currentMemoryScope = j.session && j.session.memory_scope ? j.session.memory_scope : null;
        renderMessages(j.messages || []);
        renderSessionsList();
        await loadSessionAttachments(id);
        await loadAttachmentRagMode(id);
        setTimeout(() => wireCodeCopyButtons(el.messages), 0);
      } catch (e) {
        console.error(e);
        el.messages.innerHTML = `<div class="text-danger">Error cargando mensajes: ${e.message}</div>`;
      } finally {
        setStatus('');
      }
    }
    window.selectSessionChat1 = selectSession;
    
    
function renderMessages(msgs) {
  el.messages.innerHTML = '';
  (msgs || []).forEach(m => {
    const traceId = parseTraceIdFromMeta(m.meta || null);
    pushLocal(m.role || 'assistant', m.content || '', {
      message_id: m.id_ || m.id,               
      is_primordial: (m.is_primordial == 1 || m.is_primordial === true),
      content_type: m.content_type || 'text',
      s3_key: m.s3_key || null,
      mime_type: m.mime_type || null,
      thumb_s3_key: m.thumb_s3_key || null,
      created_at: m.created_at || null,
      trace_id: traceId,
      session_id: m.session_id || currentSessionId,
    });
  });
  
  // ✅ CORRECCIÓN: Solo ejecutar wireCodeCopyButtons, NO wireAllMessageActions
  // (porque pushLocal ya ejecuta wireMessageActions para cada mensaje)
  setTimeout(() => {
    wireCodeCopyButtons(el.messages);
  }, 0);
}

    window.renderChatMessages = renderMessages;
    
/* ============================================================
   ARCHIVOS DE LA SESIÓN: endpoints + helpers
   (DEBE ir DENTRO del DOMContentLoaded, junto a const API)
   ============================================================ */
const FILE_ENDPOINTS = {
  viewer:       'ver_archivo.php',          // imágenes, video, audio (valida dueño)
  pdf:          'ver_pdf.php',              // PDF inline
  editor:       'editor.php',               // código/texto (Monaco)
  download:     'descargar.php',            // descarga con nombre visible
  delete:       'eliminar_archivo.php',     // borrado seguro (POST)
  indexFile:    'index_session_file.php',   // indexa en SessionContextBlocks + encola embedding
  semanticFile: 'semantic_session_file.php', // resume con IA dinámica + encola embedding_main
   inspector:    'session_attachment_viewer.php' //stat de adjuntos 
};

const CODE_EXTS = ['php','phtml','inc','js','mjs','cjs','jsx','ts','tsx','css','scss','less',
  'html','htm','json','xml','yaml','yml','ini','conf','cfg','txt','md','markdown','sql',
  'sh','bash','zsh','bat','cmd','ps1','py','rb','java','c','h','cpp','hpp','cs','go','rs',
  'swift','kt','kts','vue','csv','tsv','log','srt','vtt','env','gitignore','htaccess'];
const INDEXABLE_SESSION_EXTS = new Set([...CODE_EXTS, 'pdf','docx','dotx','xlsx','xlsm','xltx','xltm','pptx','ppsx','odt','ods','odp','rtf','epub','doc','xls','ppt']);


function getFileExt(name) {
  const base = String(name || '').toLowerCase().split('/').pop();
  const i = base.lastIndexOf('.');
  return i >= 0 ? base.slice(i + 1) : '';
}

function openFileViewer(s3Key, filename) {
  const ext = getFileExt(filename || s3Key);
  let url;
  if (ext === 'pdf') url = FILE_ENDPOINTS.pdf;
  else if (CODE_EXTS.includes(ext)) url = FILE_ENDPOINTS.editor;
  else url = FILE_ENDPOINTS.viewer;
  window.open(url + '?archivo=' + encodeURIComponent(s3Key), '_blank', 'noopener');
}

function downloadSessionFile(s3Key, filename) {
  const url = FILE_ENDPOINTS.download
    + '?archivo=' + encodeURIComponent(s3Key)
    + '&nombre='  + encodeURIComponent(filename || s3Key.split('/').pop());
  window.open(url, '_blank', 'noopener');
}

async function deleteSessionFile(fileId, s3Key, filename, btn) {
  const label = filename || s3Key || ('#' + fileId);

  if (!confirm('¿Eliminar el archivo "' + label + '"?\nEsta acción borra el archivo de S3 y no se puede deshacer.')) {
    return;
  }

  const fd = new FormData();
  if (fileId && !Number.isNaN(fileId)) fd.append('file_id', String(fileId));
  else if (s3Key) fd.append('archivo', s3Key);
  else { showToast('⚠️ Eliminar', 'Falta la referencia del archivo.', 'warning'); return; }

  const originalHtml = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

  try {
    const r = await fetch(FILE_ENDPOINTS.delete, {
      method: 'POST',
      credentials: 'same-origin',
      body: fd
    });

    let j;
    try { j = await r.json(); }
    catch (err) { throw new Error('Respuesta inválida del servidor (HTTP ' + r.status + ')'); }

    if (!r.ok || j.ok === false || j.estado === 'error') {
      throw new Error(j.error || j.mensaje || ('HTTP ' + r.status));
    }

    showToast('🗑️ Eliminado', 'El archivo "' + label + '" se eliminó correctamente.', 'success');

    if (currentSessionId) {
      await loadSessionAttachments(currentSessionId);
    }
    return j;
  } catch (e) {
    console.error('Error eliminando archivo:', e);
    showToast('⚠️ Error', 'No se pudo eliminar: ' + e.message, 'danger');
    btn.disabled = false;
    btn.innerHTML = originalHtml;
  }
}

/* ============================================================
   PROCESAR ACCIONES DE INDEXACIÓN / SEMÁNTICA (genérica)
   Envía POST con file_id + session_id al endpoint correspondiente.
   ============================================================ */
async function processSessionFileAction(endpoint, fileId, label, btn) {
  if (!currentSessionId) {
    showToast('⚠️ Sesión', 'Selecciona una conversación primero.', 'warning');
    return;
  }
  if (!fileId || Number.isNaN(fileId)) {
    showToast('⚠️ Archivo', 'Referencia inválida.', 'warning');
    return;
  }

  const originalHtml = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

  try {
    const fd = new FormData();
    fd.append('file_id',    String(fileId));
    fd.append('session_id', String(currentSessionId));

    const r = await fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      body: fd
    });

    let j;
    try { j = await r.json(); }
    catch (err) { throw new Error('Respuesta inválida (HTTP ' + r.status + ')'); }

    if (!r.ok || j.ok === false) {
      throw new Error(j.error || j.mensaje || ('HTTP ' + r.status));
    }

    showToast(label, j.mensaje || 'Proceso completado.', 'success');

    // Refrescar la lista de archivos para que el contador quede consistente
    if (currentSessionId) {
      await loadSessionAttachments(currentSessionId);
    }
  } catch (e) {
    console.error(e);
    showToast('⚠️ Error', e.message, 'danger');
  } finally {
    btn.disabled = false;
    btn.innerHTML = originalHtml;
  }
}

// =====================================================================
// INSPECTOR DE ADJUNTOS: Abre página con detalles de indexación
// =====================================================================
function openAttachmentInspector() {
    if (!currentSessionId) {
        showToast('⚠️ Sesión', 'Selecciona una conversación primero.', 'warning');
        return;
    }
    
    const url = FILE_ENDPOINTS.inspector + '?session_id=' + currentSessionId;
    window.open(url, '_blank', 'noopener,noreferrer');
}

function wireFileActions(container) {
  if (!container) return;

  container.querySelectorAll('.chat-file-action').forEach(btn => {
    if (btn.dataset.wired === 'true') return;
    btn.dataset.wired = 'true';

    btn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();

      const action   = btn.getAttribute('data-action');
      const fileId   = parseInt(btn.getAttribute('data-file-id'), 10);
      const s3Key    = btn.getAttribute('data-s3-key') || '';
      const filename = btn.getAttribute('data-filename') || '';

      if ((action === 'view' || action === 'download') && !s3Key) {
        showToast('⚠️ Archivo', 'Este archivo no tiene clave S3 válida.', 'warning');
        return;
      }

      switch (action) {
        case 'view':
          openFileViewer(s3Key, filename);
          break;
        case 'download':
          downloadSessionFile(s3Key, filename);
          break;
        case 'delete':
          deleteSessionFile(fileId, s3Key, filename, btn);
          break;
        case 'index':
          processSessionFileAction(
            FILE_ENDPOINTS.indexFile,
            fileId,
            '🔍 Indexar',
            btn
          );
          break;
        case 'semantic':
          processSessionFileAction(
            FILE_ENDPOINTS.semanticFile,
            fileId,
            '🧠 Semántica',
            btn
          );
          break;
      }
    });
  });
}
    
function ensureMemoryRouterLiveStatus() {
  let node = document.getElementById('chatMemoryRouterLive');
  if (node) return node;

  const host = document.querySelector('.chat-composer-meta') || el.status?.parentElement;
  if (!host) return null;

  node = document.createElement('div');
  node.id = 'chatMemoryRouterLive';
  node.setAttribute('aria-live', 'polite');
  node.style.display = 'none';
  node.style.fontSize = '0.72rem';
  node.style.lineHeight = '1.35';
  node.style.marginTop = '3px';
  node.style.padding = '4px 8px';
  node.style.border = '1px solid var(--border, rgba(127,127,127,.35))';
  node.style.borderRadius = '8px';
  node.style.background = 'rgba(127,127,127,.08)';
  node.style.maxWidth = 'min(100%, 760px)';
  host.appendChild(node);
  return node;
}

function describeMemoryRouter(route, response = null) {
  if (!route || typeof route !== 'object') return '';

  const intentLabels = {
    general: 'general',
    conversation: 'conversación',
    preference: 'preferencia',
    decision: 'decisión',
    fact: 'hecho',
    rule: 'regla',
    todo: 'pendiente/tarea',
    code: 'código/proyecto'
  };

  const intent = intentLabels[String(route.intent || '').toLowerCase()] || String(route.intent || 'desconocido');
  const routeScope = route.memory_scope && typeof route.memory_scope === 'object'
    ? route.memory_scope
    : null;
  const scopeLabel = route.scope_kind === 'project'
    ? (routeScope?.has_lineage ? 'proyecto compartido + linaje de rama' : 'proyecto compartido')
    : (route.scope_kind === 'branch' ? 'rama/linaje aislado' : 'chat libre aislado');
  const sources = [`scope: ${scopeLabel}`];

  if (route.use_project_context) {
    const types = Array.isArray(route.project_context_types) ? route.project_context_types.join(', ') : '';
    sources.push(types ? `ProjectContext(${types})` : 'ProjectContext');
  }
  if (route.use_session_context) sources.push('memoria conversacional');
  if (route.use_question_memory) sources.push('memoria semántica');
  else if (route.question_memory_fallback) sources.push('memoria semántica como respaldo');
  if (route.use_project_rag) sources.push('RAG del proyecto');
  if (route.use_project_tools || route.execution_lane === 'project_tools') sources.push('Tool Use de código');
  if (route.use_answer_procedural_memory) sources.push('memoria procedural específica');

  const projectItems = Array.isArray(response?.project_memory_items)
    ? response.project_memory_items.length
    : 0;
  if (projectItems > 0) sources.push(`${projectItems} memoria(s) estructurada(s) encontrada(s)`);

  if (!sources.length) {
    return `${intent} · no fue necesario consultar memoria semántica ni RAG.`;
  }

  return `${intent} · ${sources.join(' · ')}`;
}

function renderMemoryRouterStatus(response) {
  const route = response && response.memory_router;
  if (!route) return;

  const node = ensureMemoryRouterLiveStatus();
  if (!node) return;

  const description = describeMemoryRouter(route, response);
  const builder = response && response.context_builder && typeof response.context_builder === 'object'
    ? response.context_builder
    : null;

  let builderText = '';
  if (builder && builder.sources && typeof builder.sources === 'object') {
    const labels = {
      procedural_policy: 'procedural',
      procedural_answer: 'procedural dirigida',
      project_context: 'ProjectContext',
      session_memory: 'sesión',
      recent_messages: 'recientes',
      project_rag: 'RAG',
      attachments: 'adjuntos',
      question_memory: 'Q&A'
    };
    const selected = builder.selected_sources && typeof builder.selected_sources === 'object'
      ? builder.selected_sources
      : {};
    const used = Object.entries(builder.sources)
      .filter(([, count]) => Number(count || 0) > 0)
      .map(([key, count]) => {
        const retrieved = Number(count || 0);
        const kept = Object.prototype.hasOwnProperty.call(selected, key)
          ? Number(selected[key] || 0)
          : retrieved;
        return `${labels[key] || key} ${kept}/${retrieved}`;
      });
    builderText = used.length
      ? ` · Context Builder: ${used.join(' · ')}`
      : ' · Context Builder: sin contexto adicional';

    if (builder.ranking && typeof builder.ranking === 'object') {
      const r = builder.ranking;
      const selectedTotal = Number(r.selected || 0);
      const retrievedTotal = Number(r.retrieved || 0);
      const duplicates = Number(r.duplicates_removed || 0);
      builderText += ` · Ranker: ${selectedTotal}/${retrievedTotal}`;
      if (duplicates > 0) builderText += ` · ${duplicates} duplicado(s) fuera`;
    }
  }

  node.style.display = 'block';
  node.textContent = `🧠 Memory Router: ${description}${builderText}`;
  try {
    node.title = JSON.stringify({ memory_router: route, context_builder: builder }, null, 2);
  } catch (_) {
    node.title = description + builderText;
  }
}

function showPromptApprovalModal(compiledPrompt, compilationId, originalPrompt) {
    return new Promise((resolve) => {
        const AUTO_SECONDS = 5;
        const originalLength = compiledPrompt.length;
        const originalText = String(originalPrompt || '').trim();
        const isEnriched = originalLength > 100 && compiledPrompt.trim() !== originalText;

        const modalHtml = `
        <div class="modal fade" id="promptApprovalModal" tabindex="-1" role="dialog" aria-modal="true">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">💡 Prompt mejorado por IA</h5>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info small" role="alert" style="margin-bottom:12px;">
                            <div class="d-flex justify-content-between align-items-center" style="gap:12px;">
                                <div>
                                    <strong>Se usará automáticamente el prompt mejorado.</strong><br>
                                    Si prefieres tu pregunta original, pulsa <strong>Cancelar mejora</strong> antes de que termine la cuenta.
                                </div>
                                <div style="min-width:72px; text-align:center;">
                                    <div id="promptAutoCountdown" style="font-size:2rem; font-weight:800; line-height:1;">${AUTO_SECONDS}</div>
                                    <small>segundos</small>
                                </div>
                            </div>
                            <div class="progress mt-2" style="height:7px;">
                                <div id="promptAutoProgress" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:100%"></div>
                            </div>
                        </div>

                        <div class="form-group mb-0">
                            <label for="compiledPromptText">
                                Prompt mejorado ${isEnriched ? '✅' : ''}
                            </label>
                            <textarea class="form-control" id="compiledPromptText" rows="10"
                                style="font-family:monospace; font-size:.85rem;">${esc(compiledPrompt)}</textarea>
                            <small class="form-text text-muted">
                                Puedes editarlo durante estos 5 segundos. Al llegar a cero se enviará el contenido visible aquí.
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer d-flex justify-content-between">
                        <button type="button" class="btn btn-outline-secondary" id="btnCancelPromptImprovement">
                            <i class="fas fa-undo"></i> Cancelar mejora · usar original
                        </button>
                        <button type="button" class="btn btn-primary" id="btnUsePromptNow">
                            <i class="fas fa-bolt"></i> Usar mejorado ahora
                        </button>
                    </div>
                </div>
            </div>
        </div>`;

        const modalContainer = document.createElement('div');
        modalContainer.innerHTML = modalHtml;
        document.body.appendChild(modalContainer);

        const modal = document.getElementById('promptApprovalModal');
        const textarea = document.getElementById('compiledPromptText');
        const btnUseNow = document.getElementById('btnUsePromptNow');
        const btnCancelImprovement = document.getElementById('btnCancelPromptImprovement');
        const countdownEl = document.getElementById('promptAutoCountdown');
        const progressEl = document.getElementById('promptAutoProgress');

        let settled = false;
        let timer = null;
        const startedAt = Date.now();
        const totalMs = AUTO_SECONDS * 1000;
        const deadline = startedAt + totalMs;

        const cleanupTimer = () => {
            if (timer) clearInterval(timer);
            timer = null;
        };

        const finish = (useImproved, automatic = false) => {
            if (settled) return;
            settled = true;
            cleanupTimer();

            let selectedPrompt = useImproved
                ? String(textarea?.value || compiledPrompt).trim()
                : originalText;

            if (!selectedPrompt) selectedPrompt = useImproved ? compiledPrompt : originalPrompt;

            jQuery(modal).modal('hide');
            resolve({
                prompt: selectedPrompt,
                compilation_id: compilationId,
                use_improved: !!useImproved,
                automatic: !!automatic
            });
        };

        const tick = () => {
            if (settled) return;
            const remainingMs = Math.max(0, deadline - Date.now());
            const remainingSec = Math.max(0, Math.ceil(remainingMs / 1000));
            if (countdownEl) countdownEl.textContent = String(remainingSec);
            if (progressEl) progressEl.style.width = ((remainingMs / totalMs) * 100).toFixed(1) + '%';

            if (remainingMs <= 0) {
                finish(true, true);
            }
        };

        btnUseNow?.addEventListener('click', () => finish(true, false));
        btnCancelImprovement?.addEventListener('click', () => finish(false, false));

        jQuery(modal).modal({
            backdrop: 'static',
            keyboard: false,
            show: true
        });

        timer = setInterval(tick, 100);
        tick();

        jQuery(modal).on('hidden.bs.modal', () => {
            cleanupTimer();
            modalContainer.remove();
        });
    });
}

function renderMemoryWriterStatus(response) {
  if (!response || !response.memory_writer) return;
  const memStatusEl = document.getElementById('chatQuestionMemoryStatus');
  const memTextEl = document.getElementById('chatQuestionMemoryStatusText');
  if (!memStatusEl || !memTextEl) return;

  const writer = response.memory_writer || {};
  const backfill = response.memory_backfill || null;
  const status = String(writer.status || 'skipped');
  const reason = String(writer.reason || '');
  const writes = Number(writer.write_count || 0);
  const candidates = Number(writer.candidate_count || 0);
  let suffix = '';

  if (backfill && Number(backfill.writes || 0) > 0) {
    suffix += ` · Backfill F4.1: ${Number(backfill.writes || 0)} memoria(s) histórica(s) recuperada(s).`;
  }

  if (status === 'completed' && writes > 0) {
    suffix += ` · Writer F4.1: ${writes} memoria(s) consolidada(s) de ${candidates} candidata(s).`;
  } else if (status === 'completed') {
    suffix += ' · Writer F4.1: analizado, sin memoria persistente nueva.';
  } else if (status === 'error') {
    suffix += ' · Writer F4.1: error al consolidar memoria; la respuesta no fue afectada.';
  } else if (reason === 'schema_missing_memory_write_events') {
    suffix += ' · Writer F4.1 inactivo: falta ejecutar sql/fase4_memory_writer.sql.';
  } else if (reason && reason !== 'already_processed') {
    suffix += ' · Writer F4.1: no había información reutilizable que guardar.';
  }

  if (suffix) {
    memStatusEl.style.display = 'block';
    memTextEl.textContent = String(memTextEl.textContent || '') + suffix;
  }
}

function updateMemoryPipelineStatus(response) {
  updateQuestionMemoryStatus(response);
  renderMemoryWriterStatus(response);
}

function updateQuestionMemoryStatus(response) {
  if (!response) return;

  // Siempre mostrar primero la decisión del nuevo Router, incluso cuando
  // precisamente decidió NO consultar memoria.
  renderMemoryRouterStatus(response);

  const memStatusEl = document.getElementById('chatQuestionMemoryStatus');
  const memTextEl = document.getElementById('chatQuestionMemoryStatusText');
  if (!memStatusEl || !memTextEl) return;

  const route = response.memory_router || null;
  const hasMemoryResult = response.memory_used !== undefined;

  memStatusEl.style.display = 'block';

  // La fase compile_only ya trae memory_router, pero todavía no ejecutó la
  // recuperación semántica final. No inventar un "0 candidatos".
  if (!hasMemoryResult) {
    memTextEl.textContent = route
      ? `Memory Router: ${describeMemoryRouter(route, response)}`
      : 'Memory Router evaluado.';
    memTextEl.style.color = '';
    return;
  }

  const candidates = Number(response.memory_candidates || 0);
  const queued = Number(response.memory_reindex_queued || 0);
  const scope = response.memory_scope === 'project' ? 'proyecto' : 'sesión';
  const structuredCount = Array.isArray(response.project_memory_items)
    ? response.project_memory_items.length
    : 0;

  if (response.memory_used) {
    const questions = Array.isArray(response.memory_question_ids)
      ? response.memory_question_ids.length
      : 0;
    const fragments = Number(response.memory_fragments || 0);
    memTextEl.textContent = `Memoria (${scope}): ${candidates} candidata(s) comparada(s), ${questions} pregunta(s) usada(s), ${fragments} fragmento(s) inyectado(s).`;
    memTextEl.style.color = '#00cc66';
    return;
  }

  // El Router puede decidir conscientemente no consultar memoria semántica.
  if (route && !route.use_question_memory && !route.question_memory_fallback) {
    memTextEl.textContent = 'Memory Router: no fue necesario consultar memoria semántica para esta pregunta.';
    memTextEl.style.color = '';
    return;
  }

  // Para decisiones/hechos/reglas el Router consulta primero ProjectContext.
  // Si encontró datos estructurados, el fallback semántico no era necesario.
  if (route && route.question_memory_fallback && structuredCount > 0) {
    memTextEl.textContent = `Memory Router: se resolvió con ${structuredCount} memoria(s) estructurada(s) del proyecto; no fue necesario activar el respaldo semántico.`;
    memTextEl.style.color = '#00cc66';
    return;
  }

  memTextEl.textContent = candidates > 0
    ? `Se compararon ${candidates} pregunta(s), pero ninguna superó el umbral de relevancia.${queued ? ` ${queued} memoria(s) quedaron en cola para revectorizar.` : ''}`
    : (queued
        ? `No había memoria compatible todavía; ${queued} Q&A quedaron en cola para revectorizar con el modelo actual.`
        : 'El Router solicitó memoria semántica, pero todavía no había memoria vectorizada compatible para esta búsqueda.');
  memTextEl.style.color = '';
}

async function sendMessage() {
    if (isSending) return;

    const text = (el.input && el.input.value) ? el.input.value.trim() : '';
    const auto = !!(el.auto && el.auto.checked);
    const model = requireModelSelected();
    if (!model) return;

    const temperature = el.temp ? parseFloat(el.temp.value) : 0.7;
    const max_tokens = el.max ? parseInt(el.max.value, 10) : 800;
    const top_p = el.topP ? parseFloat(el.topP.value) : 0.9;
    const comp_temperature = el.compTemp ? parseFloat(el.compTemp.value) : 0.0;
    const comp_max_tokens  = el.compMax ? parseInt(el.compMax.value, 10) : 200;
    const comp_top_p       = el.compTopP ? parseFloat(el.compTopP.value) : 0.1;
    const resp_max_tokens  = el.respMax ? parseInt(el.respMax.value, 10) : 1000;
    const seed_value       = el.seed ? parseInt(el.seed.value, 10) : 42;
    const useAuto = el.auto ? el.auto.checked : true;
    const useRag = getAttachmentModeCheckbox() ? getAttachmentModeCheckbox().checked : true;

    const useQuestionMemory = el.questionMemoryEnabled ? el.questionMemoryEnabled.checked : true;
    const questionMemoryScope = document.querySelector('input[name="chatQuestionMemoryScope"]:checked')?.value || 'project';
    const questionMemoryMaxCandidates = el.questionMemoryMaxCandidates ? parseInt(el.questionMemoryMaxCandidates.value, 10) : 20;
    const questionMemoryWindowLines = el.questionMemoryWindowLines ? parseInt(el.questionMemoryWindowLines.value, 10) : 5;

    if (pendingFiles.length > 0 && !text) {
        suggestAttachmentsHeader();
        setStatus('Agrega un mensaje para enviar junto con tus archivos.');
        el.input && el.input.focus();
        return;
    }
    if (!text && pendingFiles.length === 0) {
        el.input && el.input.focus();
        return;
    }
    if (!currentSessionId) {
        try {
            const created = await createSession(text.slice(0, 64) || 'Nueva conversación (Auto)');
            currentSessionId = created.id;
            syncTraceExplorerButton();
            await loadSessions();
        } catch (e) {
            pushLocal('assistant', '⚠️ Error creando sesión: ' + e.message);
            return;
        }
    }

    // ============================================================
    // ENRUTAMIENTO CENTRAL ÚNICO
    // ============================================================
    // Ya no existe un atajo frontend hacia code_edit.php. TODA solicitud
    // escrita por el usuario —incluidas crear/editar/leer/borrar código—
    // entra primero a bedrock_chat2.php, donde MemoryContextRouter decide
    // el contexto y chat_main ejecuta Tool Use (code_edit/grep/view/etc.).

    // ============================================================
    // FLUJO PRINCIPAL: FASE 1 (compile) → FASE 2 (respond)
    // ============================================================
    isSending = true;
    setStatus('Compilando prompt…');
    pushLocal('user', text, { created_at: new Date().toISOString() });

    // Fase 6: requestFlowId existe siempre y sirve para reanudar el mismo turno
    // sin duplicar el mensaje si el compilador supera los 5 segundos.
    const requestFlowId = createActivityTraceId();
    const activityTraceId = isActivityEnabled() ? requestFlowId : '';
    if (activityTraceId) startActivityTrace(activityTraceId, currentSessionId);
    const uid = getUserId();
    const PROMPT_COMPILER_TIMEOUT_MS = 5000;

    const requestOriginalPromptFallback = async (reason) => {
        const fdFallback = new FormData();
        fdFallback.append('session_id', String(currentSessionId));
        if (uid) fdFallback.append('user_id', uid);
        fdFallback.append('text', text);
        fdFallback.append('auto', auto ? '1' : '0');
        fdFallback.append('model', model);
        fdFallback.append('temperature', String(temperature));
        fdFallback.append('max_tokens', String(resp_max_tokens));
        fdFallback.append('top_p', String(top_p));
        fdFallback.append('resp_max_tokens', String(resp_max_tokens));
        fdFallback.append('seed', String(seed_value));
        fdFallback.append('use_rag', useRag ? '1' : '0');
        fdFallback.append('use_question_memory', useQuestionMemory ? '1' : '0');
        fdFallback.append('question_memory_scope', questionMemoryScope);
        fdFallback.append('question_memory_max_candidates', String(questionMemoryMaxCandidates));
        fdFallback.append('question_memory_window_lines', String(questionMemoryWindowLines));
        fdFallback.append('compiler_fallback', '1');
        fdFallback.append('compiler_fallback_reason', String(reason || 'fallback'));
        fdFallback.append('request_id', requestFlowId);
        if (activityTraceId) fdFallback.append('trace_id', activityTraceId);

        const fallbackController = new AbortController();
        const fallbackTimeoutId = setTimeout(() => fallbackController.abort(), 600000);
        try {
            return await fetch(API.send, {
                method: 'POST',
                credentials: 'same-origin',
                body: fdFallback,
                signal: fallbackController.signal
            });
        } catch (err) {
            if (err && err.name === 'AbortError') {
                throw new Error('⏱️ La respuesta final superó 10 minutos después del fallback del compilador.');
            }
            throw err;
        } finally {
            clearTimeout(fallbackTimeoutId);
        }
    };

    try {
        // ──────────────────────────────────────────────────────
        // FASE 1: COMPILAR PROMPT (máximo 5 segundos)
        // ──────────────────────────────────────────────────────
        const fdCompile = new FormData();
        fdCompile.append('session_id', String(currentSessionId));
        if (uid) fdCompile.append('user_id', uid);
        fdCompile.append('text', text);
        fdCompile.append('auto', auto ? '1' : '0');
        fdCompile.append('model', model);
        fdCompile.append('temperature', String(temperature));
        fdCompile.append('max_tokens', String(max_tokens));
        fdCompile.append('top_p', String(top_p));
        fdCompile.append('compile_temperature', String(comp_temperature));
        fdCompile.append('compile_max_tokens', String(comp_max_tokens));
        fdCompile.append('compile_top_p', String(comp_top_p));
        fdCompile.append('resp_max_tokens', String(resp_max_tokens));
        fdCompile.append('seed', String(seed_value));
        fdCompile.append('use_rag', useRag ? '1' : '0');
        fdCompile.append('use_question_memory', useQuestionMemory ? '1' : '0');
        fdCompile.append('question_memory_scope', questionMemoryScope);
        fdCompile.append('question_memory_max_candidates', String(questionMemoryMaxCandidates));
        fdCompile.append('question_memory_window_lines', String(questionMemoryWindowLines));
        fdCompile.append('compile_only', '1');
        fdCompile.append('request_id', requestFlowId);
        if (activityTraceId) fdCompile.append('trace_id', activityTraceId);
        if (pendingFiles.length > 0) {
            pendingFiles.forEach(({file}) => fdCompile.append('files[]', file, file.name));
        }

        // Fase 6: corte duro del navegador a los 5 s. Si ocurre, la petición
        // de respuesta se reanuda con el texto original y el mismo request_id.
        const compileController = new AbortController();
        const compileTimeoutId = setTimeout(() => compileController.abort(), PROMPT_COMPILER_TIMEOUT_MS);

        let rCompile;
        let clientFallbackUsed = false;
        try {
            rCompile = await fetch(API.send, {
                method: 'POST',
                credentials: 'same-origin',
                body: fdCompile,
                signal: compileController.signal
            });
        } catch (fetchErr) {
            if (fetchErr && fetchErr.name === 'AbortError') {
                clientFallbackUsed = true;
                setStatus('Prompt Compiler agotó 5 s · usando tu pregunta original…');
                if (activityTraceId) {
                    appendLocalActivityEvent(
                        activityTraceId,
                        'compiler_client_timeout',
                        'info',
                        'Prompt Compiler · TIMEOUT',
                        'El navegador agotó 5 segundos; se continúa con el prompt original.'
                    );
                }
                rCompile = await requestOriginalPromptFallback('client_timeout');
            } else {
                throw fetchErr;
            }
        } finally {
            clearTimeout(compileTimeoutId);
        }

        let compileText = await rCompile.text();
        console.log('📥 Fase 1 (compile) respuesta:', compileText.length > 0 ? compileText.slice(0, 200) + '...' : '(VACÍA)');
        let jCompile = toJSONorThrow(
            compileText,
            rCompile.status,
            clientFallbackUsed ? 'Respuesta con prompt original' : 'Compilar prompt'
        );
        if (!rCompile.ok || jCompile.ok === false) throw new Error(jCompile.error || `HTTP ${rCompile.status}`);

        // El backend detectó timeout/error/vacío/no-mejora antes que el navegador.
        // No se abre modal y no se fabrica otro prompt: se responde con el original.
        if (!clientFallbackUsed && jCompile.phase === 'compile_fallback' && jCompile.fallback_to_original) {
            updateMemoryPipelineStatus(jCompile);
            const reasonLabels = {
                timeout: 'timeout',
                empty: 'respuesta vacía',
                error: 'error',
                no_improvement: 'sin mejora útil'
            };
            const reasonLabel = reasonLabels[String(jCompile.fallback_reason || '')] || 'fallback';
            setStatus(`Prompt Compiler: ${reasonLabel} · usando tu pregunta original…`);
            rCompile = await requestOriginalPromptFallback(jCompile.fallback_reason || 'server_fallback');
            compileText = await rCompile.text();
            jCompile = toJSONorThrow(compileText, rCompile.status, 'Respuesta con prompt original');
            if (!rCompile.ok || jCompile.ok === false) throw new Error(jCompile.error || `HTTP ${rCompile.status}`);
        }

        // Mostrar la decisión final del Router/pipeline. En fallback esta ya es la
        // respuesta generada con el texto original.
        updateMemoryPipelineStatus(jCompile);

        // ──────────────────────────────────────────────────────
        // FASE 1.5: VENTANA DE 5 S PARA CANCELAR LA MEJORA
        // ──────────────────────────────────────────────────────
        if (jCompile.phase === 'compile_only' && jCompile.compiled_prompt) {
            setStatus('Prompt mejorado listo · se usará automáticamente en 5 segundos…');
            const approved = await showPromptApprovalModal(
                jCompile.compiled_prompt,
                jCompile.compilation_id,
                text
            );
            if (!approved) throw new Error('No se pudo resolver la decisión del prompt.');

            setStatus(
                approved.use_improved
                    ? (approved.automatic ? 'Usando prompt mejorado automáticamente…' : 'Usando prompt mejorado…')
                    : 'Mejora cancelada · usando tu pregunta original…'
            );

            const lastUserMsg = el.messages.querySelector('.chat-msg.user:last-child');
            if (lastUserMsg) lastUserMsg.remove();

            // Reanudar la lectura de eventos: durante la aprobación el polling
            // queda pausado en el evento approval_waiting.
            if (activityTraceId) startActivityTrace(activityTraceId, currentSessionId);

            // ──────────────────────────────────────────────────
            // FASE 2: RESPUESTA FINAL (timeout 10 min)
            // ──────────────────────────────────────────────────
            setStatus('Generando respuesta…');

            const fdRespond = new FormData();
            fdRespond.append('session_id', String(currentSessionId));
            if (uid) fdRespond.append('user_id', uid);
            fdRespond.append('text', text);
            fdRespond.append('compiled_prompt', approved.prompt);
            fdRespond.append('compilation_id', String(approved.compilation_id));
            fdRespond.append('model', model);
            fdRespond.append('temperature', String(temperature));
            fdRespond.append('max_tokens', String(resp_max_tokens));
            fdRespond.append('top_p', String(top_p));
            fdRespond.append('seed', String(seed_value));
            fdRespond.append('use_rag', useRag ? '1' : '0');
            fdRespond.append('use_question_memory', useQuestionMemory ? '1' : '0');
            fdRespond.append('question_memory_scope', questionMemoryScope);
            fdRespond.append('question_memory_max_candidates', String(questionMemoryMaxCandidates));
            fdRespond.append('question_memory_window_lines', String(questionMemoryWindowLines));
            fdRespond.append('request_id', requestFlowId);
            if (activityTraceId) fdRespond.append('trace_id', activityTraceId);

            // ✅ FETCH CON TIMEOUT (10 minutos para respond)
            const respondController = new AbortController();
            const respondTimeoutId = setTimeout(() => respondController.abort(), 600000);

            let rRespond;
            try {
                rRespond = await fetch(API.send, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: fdRespond,
                    signal: respondController.signal
                });
            } catch (fetchErr) {
                clearTimeout(respondTimeoutId);
                if (fetchErr.name === 'AbortError') {
                    // ✅ TIMEOUT: El servidor sigue procesando en background.
                    // Esperar y recargar la sesión para ver si se guardó.
                    console.warn('⏱️ Timeout en Fase 2. Esperando 10s y recargando sesión...');
                    setStatus('⏱️ La IA está procesando (tardó más de 10 min). Verificando si se guardó la respuesta...');
                    await new Promise(resolve => setTimeout(resolve, 10000));
                    await selectSession(currentSessionId);
                    setStatus('');
                    isSending = false;
                    if (el.input) el.input.value = '';
                    clearQueue();
                    return;
                }
                throw fetchErr;
            } finally {
                clearTimeout(respondTimeoutId);
            }

            const respondText = await rRespond.text();
            console.log('📥 Fase 2 (respond) respuesta:', respondText.length > 0 ? respondText.slice(0, 300) + '...' : '(VACÍA)');

            // ✅ RESPUESTA VACÍA: El servidor procesó todo pero el proxy cortó la conexión.
            // Los datos SÍ se guardaron en BD. Recargar la sesión.
            if (!respondText || respondText.trim() === '') {
                console.warn('⚠️ Respuesta vacía del servidor. El mensaje probablemente se guardó en BD. Recargando sesión en 5s...');
                setStatus('⚠️ El servidor tardó demasiado y la respuesta se cortó. Verificando si la IA ya respondió...');

                // Esperar 5 segundos para que PHP termine de guardar en BD
                await new Promise(resolve => setTimeout(resolve, 5000));

                // Recargar la sesión para mostrar el mensaje guardado
                await selectSession(currentSessionId);

                setStatus('');
                isSending = false;
                if (el.input) el.input.value = '';
                clearQueue();
                return;
            }

            // ✅ Parsear JSON normalmente
            let jRespond;
            try {
                jRespond = JSON.parse(respondText);
            } catch (parseErr) {
                console.error('❌ Error parseando JSON de Fase 2. Respuesta cruda:', respondText.slice(0, 500));
                // Si no es JSON pero hay contenido, puede ser un error PHP
                if (respondText.includes('<b>Fatal error') || respondText.includes('<b>Warning')) {
                    throw new Error('Error PHP en el servidor: ' + respondText.slice(0, 200));
                }
                throw new Error('La respuesta no es JSON válido: ' + respondText.slice(0, 200));
            }

            if (!rRespond.ok || jRespond.ok === false) throw new Error(jRespond.error || `HTTP ${rRespond.status}`);

            // ✅ Mostrar respuesta
            if (jRespond.reply) {
                pushLocal('assistant', jRespond.reply, {
                    created_at: new Date().toISOString(),
                    trace_id: jRespond.trace_id || activityTraceId,
                    message_id: jRespond.saved && jRespond.saved.assistant_id ? jRespond.saved.assistant_id : '',
                    session_id: currentSessionId
                });
            }

            // Indexación post-edición: solo cuando el backend lo solicita explícitamente.
            // code_edit.php y las tools que ya preparan EmbeddingJobs no deben reindexarse dos veces.
            if (currentProjectId && jRespond.needs_indexing === true) {
                setStatus('🔄 Actualizando índice de conocimientos...');
                const fdIndex = new FormData();
                fdIndex.append('project_id', String(currentProjectId));
                fetch('index_project_sources.php', { method: 'POST', credentials: 'same-origin', body: fdIndex })
                    .then(async (res) => {
                        try {
                            const j = await res.json();
                            if (j.ok) {
                                setStatus(j.prepared_count > 0 ? `🧮 ${j.prepared_count} archivo(s) preparado(s), ${j.queued_jobs || 0} embedding(s) en cola.` : 'ℹ️ No había fuentes pendientes.');
                                setTimeout(() => setStatus(''), 4000);
                                await loadProjectSources(currentProjectId);
                            }
                        } catch (err) { setStatus(''); }
                    })
                    .catch(() => setStatus(''));
            }

            const action = (jRespond.action || '').toLowerCase();
            const improved = jRespond.router && jRespond.router.improved_prompt ? String(jRespond.router.improved_prompt) : (text || '');
            if (action === 'gen_image') {
                await autoGenerateImage(improved);
            } else if (action === 'gen_video') {
                await autoGenerateVideo(improved);
            } else {
                await selectSession(currentSessionId);
            }

            if (jRespond.usage) {
                const u = jRespond.usage;
                setUsage(`Tokens ~ prompt ${u.prompt_tokens||0} + completion ${u.completion_tokens||0} = ${u.total_tokens||0}`);
            }

            // Estado de memoria selectiva
            updateMemoryPipelineStatus(jRespond);

        } else {
            // ──────────────────────────────────────────────────
            // SIN COMPILACIÓN: respuesta directa
            // ──────────────────────────────────────────────────
            if (jCompile.reply) {
                pushLocal('assistant', jCompile.reply, {
                    created_at: new Date().toISOString(),
                    trace_id: jCompile.trace_id || activityTraceId,
                    message_id: jCompile.saved && jCompile.saved.assistant_id ? jCompile.saved.assistant_id : '',
                    session_id: currentSessionId
                });
            }
            updateMemoryPipelineStatus(jCompile);
            await selectSession(currentSessionId);
        }

        if (el.input) { el.input.value = ''; resizeChatInput(); }
        clearQueue();

    } catch (e) {
        console.error('❌ Error en sendMessage:', e);
        if (activityTraceId) {
            stopActivityTrace(activityTraceId, {
                eventKey: 'ui_error',
                status: 'error',
                title: 'Error en la ejecución',
                summary: e.message || String(e),
                error: e.message || String(e)
            });
        }
        pushLocal('assistant', '⚠️ Error: ' + e.message, { trace_id: activityTraceId });
    } finally {
        setStatus('');
        isSending = false;
    }
}

    function clearQueue(){
      pendingFiles = [];
      renderQueue();
    }
    async function autoGenerateImage(prompt) {
      setStatus('Generando imagen…');
      const fd = new FormData();
      fd.append('session_id', String(currentSessionId || 0));
      const uid = getUserId();
      if (uid) fd.append('user_id', uid);
      fd.append('prompt', prompt);
      try {
        const r = await fetch(API.genImage, { method:'POST', credentials:'same-origin', body: fd });
        const j = toJSONorThrow(await r.text(), r.status, 'Generar imagen');
        if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);
        await selectSession(currentSessionId);
        setStatus('Imagen lista.');
        setTimeout(() => setStatus(''), 1500);
      } catch (e) {
        console.error(e);
        pushLocal('assistant', '⚠️ Error generando imagen: ' + e.message);
        setStatus('');
      }
    }
    async function autoGenerateVideo(prompt) {
      setStatus('Iniciando video…');
      const SERVER_WAIT_SECS = 120, MAX_WAIT_MS = 20 * 60 * 1000, COOLDOWN_MS = 1500, FETCH_TIMEOUT_MS = (SERVER_WAIT_SECS + 30) * 1000;
      const wait = (ms) => new Promise(res => setTimeout(res, ms));
      const normalize = (s) => String(s || '').toLowerCase();
      const isDone = (s) => ['completed','complete','succeeded','success','done'].includes(normalize(s));
      const isWorking = (s) => ['queued','processing','running','generating','in_progress','submitted','pending'].includes(normalize(s));
      const fd = new FormData();
      fd.append('session_id', String(currentSessionId || 0));
      const uid = getUserId(); if (uid) fd.append('user_id', uid);
      fd.append('prompt', prompt);
      fd.append('duration', '6');
      try {
        const r = await fetch(API.genVideoStart, { method:'POST', credentials:'same-origin', body: fd });
        const j = toJSONorThrow(await r.text(), r.status, 'Iniciar video');
        if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);
        const messageId = j.message_id || j.msg_id || j.id || null;
        const status0 = normalize(j.status);
        if (!messageId) throw new Error('No se recibió message_id del servidor.');
        if (status0 === 'unsupported') {
          pushLocal('assistant', '⚠️ Video no soportado en esta cuenta/región.');
          await selectSession(currentSessionId);
          setStatus('');
          return;
        }
        setStatus('Generando video…');
        const started = Date.now();
        while (true) {
          const qs = new URLSearchParams();
          qs.set('message_id', String(messageId));
          qs.set('wait_secs', String(SERVER_WAIT_SECS));
          const ctrl = new AbortController();
          const to = setTimeout(() => ctrl.abort(), FETCH_TIMEOUT_MS);
          let js;
          try {
            const rq = await fetch(`${API.genVideoStatus}?${qs.toString()}`, { credentials:'same-origin', signal: ctrl.signal });
            js = toJSONorThrow(await rq.text(), rq.status, 'Estado de video');
            if (!rq.ok || js.ok === false) throw new Error(js.error || `HTTP ${rq.status}`);
          } catch (err) {
            clearTimeout(to);
            if (err.name === 'AbortError') {
              if (Date.now() - started > MAX_WAIT_MS) throw new Error('Timeout de red consultando estado de video.');
              await wait(COOLDOWN_MS);
              continue;
            }
            throw err;
          }
          clearTimeout(to);
          const st = normalize(js.status);
          if (isDone(st)) {
            await selectSession(currentSessionId);
            setStatus('Video listo.');
            setTimeout(() => setStatus(''), 1500);
            break;
          }
          if (!isWorking(st)) {
            pushLocal('assistant', `⚠️ Estado de video: ${st || 'desconocido'}.`);
            await selectSession(currentSessionId);
            setStatus('');
            break;
          }
          if (Date.now() - started > MAX_WAIT_MS) {
            pushLocal('assistant', `⚠️ Timeout de generación de video (${st || 'sin estatus'})`);
            await selectSession(currentSessionId);
            setStatus('');
            break;
          }
          await wait(COOLDOWN_MS);
        }
      } catch (e) {
        console.error(e);
        pushLocal('assistant', '⚠️ Error generando video: ' + e.message);
        setStatus('');
      }
    }
    const NOTIFY_POLL_MS = 20000;
    let notifyTimer = null;
    let lastNotifyId = parseInt(localStorage.getItem('chat2_last_notify_id') || '0', 10);
    let notifiedSet = new Set(JSON.parse(localStorage.getItem('chat2_notified_ids') || '[]'));
    function ensureToastContainer(){
      if (!document.getElementById('chatToasts')) {
        const c = document.createElement('div');
        c.id = 'chatToasts';
        c.className = 'chat-toasts';
        document.body.appendChild(c);
      }
      return document.getElementById('chatToasts');
    }
    function showToastVideo(item){
      if (notifiedSet.has(item.id)) return;
      notifiedSet.add(item.id);
      localStorage.setItem('chat2_notified_ids', JSON.stringify(Array.from(notifiedSet)));
      const $c = ensureToastContainer();
      const elx = document.createElement('div');
      elx.className = 'chat-toast';
      elx.innerHTML = `
        <div class="ct-title">🎬 Video listo</div>
        <div class="ct-body">En <strong>${(item.title || 'esta conversación')}</strong></div>
        <div class="ct-actions">
          <button class="ct-view">Ver</button>
          <button class="ct-close">Cerrar</button>
        </div>`;
      $c.appendChild(elx);
      elx.querySelector('.ct-close').addEventListener('click', () => elx.remove());
      elx.querySelector('.ct-view').addEventListener('click', async () => {
        elx.remove();
        if (item.session_id) {
          await selectSession(item.session_id);
          const box = document.getElementById('chat2Messages');
          if (box) box.scrollTop = box.scrollHeight;
        }
      });
      setTimeout(() => { try{ elx.remove(); }catch{} }, 12000);
    }
    async function pollNotifications(){
      try{
        const uid = getUserId();
        const qs = new URLSearchParams();
        if (lastNotifyId > 0) qs.set('last_id', String(lastNotifyId));
        if (uid) qs.set('user_id', uid);
        const r = await fetch(`${API.notifyPoll}?${qs.toString()}`, { credentials:'same-origin' });
        const j = toJSONorThrow(await r.text(), r.status, 'Notificaciones');
        if (!j || j.ok === false) return;
        const items = Array.isArray(j.items) ? j.items : [];
        if (items.length) {
          items.forEach(item => {
            showToastVideo(item);
            if (item.id && item.id > lastNotifyId) lastNotifyId = item.id;
          });
          localStorage.setItem('chat2_last_notify_id', String(lastNotifyId));
        }
      }catch(e){ /* silencio */ }
    }
    function startNotifyPoller(){
      pollNotifications();
      if (notifyTimer) clearInterval(notifyTimer);
      notifyTimer = setInterval(pollNotifications, NOTIFY_POLL_MS);
    }
    async function loadProjects() {
      try {
        const uid = getUserId();
        const qs = new URLSearchParams();
        if (uid) qs.set('user_id', uid);
        console.log("🔄 Cargando proyectos desde:", `${PROJECT_API.list}?${qs.toString()}`);
        const r = await fetch(`${PROJECT_API.list}?${qs.toString()}`, { credentials: 'same-origin' });
        if (!r.ok) {
          console.error("❌ Error HTTP al cargar proyectos:", r.status, r.statusText);
          throw new Error(`HTTP ${r.status}`);
        }
        const j = toJSONorThrow(await r.text(), r.status, 'Listar proyectos');
        if (!j.ok) throw new Error(j.error || 'Error desconocido');
        projects = Array.isArray(j.projects) ? j.projects : [];
        console.log("✅ Proyectos cargados:", projects.length);
        renderProjectSelector();
        renderProjectList();
      } catch (e) {
        console.error('❌ Error cargando proyectos:', e);
        setStatus('Error cargando proyectos (revisa la consola F12)');
        if (el.sbProjectList) el.sbProjectList.innerHTML = '<div class="text-danger small">Error al cargar</div>';
      }
    }
    function renderProjectSelector() {
      const select = el.projectSelect;
      if (!select) return;
      const currentValue = select.value;
      select.innerHTML = '<option value="">— Sin proyecto (chat libre) —</option>';
      projects.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = p.name;
        if (p.id == currentValue) opt.selected = true;
        select.appendChild(opt);
      });
    }
function renderProjectList() {
  const targetList = el.sbProjectList;
  if (!targetList) return;
  if (projects.length === 0) {
    targetList.innerHTML = '<div class="text-muted small">Sin proyectos</div>';
    return;
  }
  targetList.innerHTML = projects.map(p => {
    const pid = p.id || p.id_;
    const isActive = (pid === currentProjectId) ? ' active' : '';
    const projSessions = sessions.filter(s => (s.project_id === pid) || (s.project_id_ === pid));
    let sessionsHtml = '';
    if (projSessions.length > 0) {
      sessionsHtml = projSessions.map(s => {
        const sid = s.id || s.id_;
        const stitle = esc(s.title || `Sesión #${sid}`);
        const sactive = (sid === currentSessionId) ? ' active' : '';
        const isArchived = s.archived || s.status === 'archived';
        const badge = isArchived ? `<span class="badge badge-secondary ml-1" style="font-size:0.6rem;">arch</span>` : '';
        return `<div class="sb-item project-session${sactive}" data-id="${sid}" title="${esc(stitle)}" 
                style="padding-left: 1.2rem; font-size: 0.75rem; border-left: 2px solid var(--accent, #00ff88); margin-top: 2px;">
          <div class="d-flex justify-content-between align-items-center">
            <span class="text-truncate sb-item-title" style="max-width: 70%; font-size: 0.7rem;">
              <i class="fas fa-comment-dots mr-1" style="font-size:0.6rem;"></i>${stitle} ${badge}
            </span>
            <div class="btn-group btn-group-sm" style="flex-shrink: 0;">
              <button class="btn btn-link p-0 js-rename text-muted" title="Renombrar"><i class="fas fa-pen" style="font-size:0.5rem;"></i></button>
            </div>
          </div>
        </div>`;
      }).join('');
    } else {
      sessionsHtml = `<div class="text-muted small" style="padding-left: 1.2rem; font-size: 0.7rem; margin-top: 2px;">
        <i class="fas fa-info-circle mr-1"></i>Sin chats aún
      </div>`;
    }
    return `
      <div class="project-group mb-2">
        <div class="sb-item project-header${isActive}" data-id="${pid}" title="${esc(p.name)}" style="font-weight: 600;">
          <div class="text-truncate"><i class="fas fa-briefcase mr-1" style="font-size:0.7rem;"></i>${esc(p.name)}</div>
        </div>
        <div class="project-sessions-list">
          ${sessionsHtml}
        </div>
      </div>
    `;
  }).join('');
  targetList.querySelectorAll('.project-header').forEach(item => {
    item.addEventListener('click', () => {
      const pid = parseInt(item.getAttribute('data-id'), 10);
      selectProject(pid);
    });
  });
  targetList.querySelectorAll('.project-session').forEach(item => {
    item.addEventListener('click', (ev) => {
      if (ev.target.closest('.js-rename')) return; 
      const sid = parseInt(item.getAttribute('data-id'), 10);
      selectSession(sid);
    });
    const sid = parseInt(item.getAttribute('data-id'), 10);
    const btnRename = item.querySelector('.js-rename');
    if (btnRename) {
      btnRename.addEventListener('click', (e) => { 
        e.stopPropagation(); 
        promptRename(sid); 
      });
    }
  });
}
    async function selectProject(projectId) {
      currentProjectId = projectId ? parseInt(projectId) : null;
      if (el.sbCurrentProject) {
        const p = projects.find(x => x.id === currentProjectId);
        el.sbCurrentProject.textContent = p ? p.name : 'Ninguno';
      }
      if (el.projectSelect) el.projectSelect.value = currentProjectId || '';
      const sourcesPanel = el.sourcesPanel;
      if (!currentProjectId) {
        if (sourcesPanel) sourcesPanel.classList.add('d-none');
        projectSources = [];
        if (el.sbSourcesCount) el.sbSourcesCount.textContent = '0';
        renderProjectList();
        return;
      }
      if (sourcesPanel) sourcesPanel.classList.remove('d-none');
      await loadProjectSources(currentProjectId);
      renderProjectList();
    }
    async function loadProjectSources(projectId) {
      try {
        const qs = new URLSearchParams({ project_id: projectId });
        const r = await fetch(`${PROJECT_API.sources}?${qs.toString()}`, { credentials: 'same-origin' });
        const j = toJSONorThrow(await r.text(), r.status, 'Listar fuentes');
        if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);
        projectSources = Array.isArray(j.sources) ? j.sources : [];
        renderProjectSources();
      } catch (e) {
        console.error('Error cargando fuentes:', e);
        const list = el.sourcesList;
        if (list) list.innerHTML = '<div class="text-danger small">Error cargando fuentes</div>';
      }
    }
function renderProjectSources() {
  const list = el.sourcesList;
  const countMain = el.sourcesCount;
  if (!list) return;
  if (projectSources.length === 0) {
    list.innerHTML = '<div class="text-muted small">Sin fuentes agregadas</div>';
    if (countMain) countMain.textContent = '0';
    if (el.sbSourcesCount) el.sbSourcesCount.textContent = '0';
    return;
  }
  list.innerHTML = projectSources.map(s => {
    const statusClass = s.status || 'pending';
    const statusText = { 'pending': 'Pendiente', 'indexed': 'Indexado', 'stale': 'Desactualizado', 'error': 'Error' }[statusClass] || statusClass;
    const badgeClass = statusClass === 'indexed' ? 'success' : statusClass === 'error' ? 'danger' : 'warning';
    let actionsHtml = '';
    if (s.edit_url) {
      actionsHtml += `<a href="${esc(s.edit_url)}" target="_blank" class="btn btn-sm btn-primary" style="padding: 0 .3rem; font-size: 0.6rem;" title="Editar"><i class="fas fa-edit"></i></a>`;
    }
    if (s.view_url) {
      actionsHtml += `<a href="${esc(s.view_url)}" target="_blank" class="btn btn-sm btn-info" style="padding: 0 .3rem; font-size: 0.6rem;" title="Ver"><i class="fas fa-eye"></i></a>`;
    }
    return `<div class="list-group-item source-item d-flex justify-content-between align-items-center py-1 px-2" data-id="${s.id}" style="font-size:0.7rem;">
      <span class="text-truncate" style="max-width:45%;" title="${esc(s.filename)}">${esc(s.filename)}</span>
      <span class="d-flex align-items-center" style="gap: 4px;">
        ${actionsHtml}
        <span class="badge badge-${badgeClass}" style="font-size:0.6rem;">${statusText}</span>
        <button class="btn btn-sm btn-outline-danger btn-delete-source" data-id="${s.id}" title="Eliminar" style="padding: 0 .3rem; font-size: 0.6rem;">
          <i class="fas fa-trash"></i>
        </button>
      </span>
    </div>`;
  }).join('');
  if (countMain) countMain.textContent = String(projectSources.length);
  if (el.sbSourcesCount) el.sbSourcesCount.textContent = String(projectSources.length);
  list.querySelectorAll('.btn-delete-source').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const id = parseInt(btn.getAttribute('data-id'), 10);
      deleteProjectSource(id);
    });
  });
}
    function openProjectManager() {
      const modal = document.getElementById('modalProjectManager');
      if (!modal) return;
      loadProjectsInModal();
      jQuery(modal).modal('show'); 
    }
    async function loadProjectsInModal() {
      const list = document.getElementById('projectList');
      if (!list) return;
      try {
        await loadProjects();
        if (projects.length === 0) {
          list.innerHTML = '<div class="list-group-item text-muted">No hay proyectos creados</div>';
          return;
        }
        list.innerHTML = projects.map(p => `
          <div class="list-group-item d-flex align-items-center" data-id="${p.id}">
            <div class="flex-grow-1">
              <strong>${esc(p.name)}</strong>
              <small class="text-muted d-block">${esc(p.description || 'Sin descripción')}</small>
              <small class="text-muted"><i class="fas fa-folder"></i> ${esc(p.root_prefix)}</small>
            </div>
            <button class="btn btn-sm btn-outline-primary btn-edit-project ml-2" data-id="${p.id}"><i class="fas fa-edit"></i></button>
            <button class="btn btn-sm btn-outline-danger btn-delete-project ml-1" data-id="${p.id}"><i class="fas fa-trash"></i></button>
          </div>
        `).join('');
        list.querySelectorAll('.btn-edit-project').forEach(btn => {
          btn.addEventListener('click', (e) => { e.stopPropagation(); editProject(parseInt(btn.dataset.id)); });
        });
        list.querySelectorAll('.btn-delete-project').forEach(btn => {
          btn.addEventListener('click', (e) => { e.stopPropagation(); deleteProject(parseInt(btn.dataset.id)); });
        });
      } catch (e) {
        console.error('Error cargando proyectos en modal:', e);
        list.innerHTML = '<div class="list-group-item text-danger">Error cargando proyectos</div>';
      }
    }
    function editProject(projectId) {
      const project = projects.find(p => p.id === projectId);
      if (!project) return;
      document.getElementById('projectId').value = project.id;
      document.getElementById('projectName').value = project.name || '';
      document.getElementById('projectSlug').value = project.slug || '';
      document.getElementById('projectDescription').value = project.description || '';
      document.getElementById('projectLanguage').value = project.language || '';
      document.getElementById('projectFramework').value = project.framework || '';
      document.getElementById('projectRootPrefix').value = project.root_prefix || '';
      const instructions = (project.meta && project.meta.instructions) ? project.meta.instructions : '';
      document.getElementById('projectInstructions').value = instructions;
    }
    async function deleteProject(projectId) {
      if (!confirm('¿Estás seguro de eliminar este proyecto? Esta acción no se puede deshacer.')) return;
      try {
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('project_id', projectId);
        const r = await fetch(PROJECT_API.delete, { method: 'POST', credentials: 'same-origin', body: fd });
        const j = toJSONorThrow(await r.text(), r.status, 'Eliminar proyecto');
        if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);
        await loadProjectsInModal();
        await loadProjects();
        setStatus('Proyecto eliminado');
      } catch (e) {
        console.error('Error eliminando proyecto:', e);
        alert('Error eliminando proyecto: ' + e.message);
      }
    }

const slugInput = document.getElementById('projectSlug');
const prefixInput = document.getElementById('projectRootPrefix');

if (slugInput && prefixInput) {
    slugInput.addEventListener('input', () => {
        const slug = slugInput.value.trim().replace(/[^a-z0-9-]/gi, '').toLowerCase();
        if (slug) {
            const uid = getUserId() || '0';
            
            // Verificar si estamos editando un proyecto existente 
            const projectIdField = document.getElementById('projectId');
            const projectId = projectIdField ? projectIdField.value : '';
            
            if (projectId && projectId !== '') {
                // ✅ EDICIÓN: Mostrar la ruta real con el ID del proyecto
                prefixInput.value = `Data/Chat/Uploads/${uid}/${projectId}/`;
            } else {
                // ✅ CREACIÓN: Mostrar placeholder porque el ID se asignará al guardar
                prefixInput.value = `Data/Chat/Uploads/${uid}/{project_id}/`;
            }
        } else {
            prefixInput.value = '';
        }
    });
}

async function saveProject(e) {
  e.preventDefault();
  const projectId = document.getElementById('projectId').value;
  const action = projectId ? 'update' : 'create';
  const fd = new FormData();
  fd.append('action', action);
  if (projectId) fd.append('project_id', projectId);
  fd.append('name', document.getElementById('projectName').value);
  fd.append('slug', document.getElementById('projectSlug').value);
  fd.append('description', document.getElementById('projectDescription').value);
  fd.append('language', document.getElementById('projectLanguage').value);
  fd.append('framework', document.getElementById('projectFramework').value);
  fd.append('root_prefix', document.getElementById('projectRootPrefix').value);
  const instructions = document.getElementById('projectInstructions').value;
  if (instructions) {
    fd.append('meta', JSON.stringify({ instructions: instructions }));
  }
  const uid = getUserId();
  if (uid) fd.append('user_id', uid);
  try {
    const r = await fetch(PROJECT_API.create, { method: 'POST', credentials: 'same-origin', body: fd });
    const j = toJSONorThrow(await r.text(), r.status, 'Guardar proyecto');
    if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);
    document.getElementById('projectForm').reset();
    document.getElementById('projectId').value = '';
    await loadProjectsInModal();
    await loadProjects();
    setStatus('Proyecto guardado');
    jQuery('#modalProjectManager').modal('hide'); 
  } catch (e) {
    console.error('Error guardando proyecto:', e);
    alert('Error guardando proyecto: ' + e.message);
  }
}
    async function loadAvailableFiles() {
      const select = document.getElementById('sourceFileSelector');
      if (!select) return;
      select.innerHTML = '<option value="">Cargando archivos...</option>';
      try {
        select.innerHTML = '<option value="">Funcionalidad pendiente - requiere endpoint backend</option>';
      } catch (e) {
        console.error('Error cargando archivos:', e);
        select.innerHTML = '<option value="">Error cargando archivos</option>';
      }
    }
    async function addSourcesToProject() {
      const select = document.getElementById('sourceFileSelector');
      if (!select) return;
      const selected = Array.from(select.selectedOptions).map(o => o.value);
      if (selected.length === 0) { alert('Selecciona al menos un archivo'); return; }
      try {
        const fd = new FormData();
        fd.append('action', 'add');
        fd.append('project_id', currentProjectId);
        selected.forEach(s3Key => fd.append('s3_keys[]', s3Key));
        const r = await fetch(PROJECT_API.sources, { method: 'POST', credentials: 'same-origin', body: fd });
        const j = toJSONorThrow(await r.text(), r.status, 'Agregar fuentes');
        if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);
        await loadProjectSources(currentProjectId);
        const modal = document.getElementById('modalProjectSources');
        if (window.bootstrap) bootstrap.Modal.getInstance(modal)?.hide();
        else $(modal).modal('hide');
        setStatus('Fuentes agregadas');
      } catch (e) {
        console.error('Error agregando fuentes:', e);
        alert('Error agregando fuentes: ' + e.message); 
      }
    }
    async function executeTool(toolName) {
      if (!currentProjectId) { 
        alert('⚠️ Primero selecciona o crea un proyecto en el panel lateral izquierdo.'); 
        return; 
      }
      if (!currentSessionId) { 
        alert('⚠️ Primero crea o selecciona una sesión de chat.'); 
        return; 
      }
      pushLocal('user', `[Herramienta: ${toolName}] Solicitando ejecución en el proyecto activo...`);
      setStatus(`Ejecutando ${toolName}...`);
      setTimeout(() => {
        pushLocal('assistant', `⚙️ La herramienta **${toolName}** está configurada y lista.\n\nEl backend está preparado para procesar solicitudes para el proyecto #${currentProjectId}.\n\n*(Próximo paso: Implementar el modal de parámetros específicos para "${toolName}")*`);
        setStatus('');
      }, 600);
    }
//para el modal de subir archivos
function openProjectSourcesModal() {
    if (!currentProjectId) {
        alert('⚠️ Selecciona un proyecto primero en el panel lateral.');
        return;
    }
    const modal = document.getElementById('modalProjectSources');
    if (!modal) return;
    
    const project = projects.find(p => p.id === currentProjectId);
    if (project) {
        // ✅ CORREGIDO: Usar la misma ruta que el backend
        const userId = document.getElementById('chatUserId') ? document.getElementById('chatUserId').value : 1;
        const path = `Data/Chat/Uploads/${userId}/${currentProjectId}/`;
        const pathEl = document.getElementById('projectUploadPath');
        if (pathEl) pathEl.textContent = path;
    }
    
    const modalList = document.getElementById('modalSourcesList');
    if (modalList) {
        if (projectSources.length === 0) {
            modalList.innerHTML = '<div class="list-group-item text-muted small">No hay fuentes agregadas aún.</div>';
        } else {
            modalList.innerHTML = projectSources.map(s => {
                const statusClass = s.status || 'pending';
                const statusText = {
                    'pending': 'Pendiente',
                    'indexed': 'Indexado',
                    'stale': 'Desactualizado',
                    'error': 'Error'
                }[statusClass] || statusClass;
                const badgeClass = statusClass === 'indexed' ? 'success' : statusClass === 'error' ? 'danger' : 'warning';
                return `<div class="list-group-item d-flex justify-content-between align-items-center py-2" data-id="${s.id}">
                    <div class="text-truncate" style="max-width: 70%;" title="${esc(s.filename)}">
                        <i class="fas fa-file-code mr-1 text-muted"></i> ${esc(s.filename)}
                    </div>
                    <div class="d-flex align-items-center" style="gap: 8px;">
                        <span class="badge badge-${badgeClass}" style="font-size: 0.7rem;">${statusText}</span>
                        <button class="btn btn-sm btn-outline-danger btn-delete-modal-source" data-id="${s.id}" title="Eliminar fuente" style="padding: 0 .4rem;">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>`;
            }).join('');
            
            modalList.querySelectorAll('.btn-delete-modal-source').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const id = parseInt(btn.getAttribute('data-id'), 10);
                    deleteProjectSource(id);
                });
            });
        }
    }
    
    const progress = document.getElementById('projectUploadProgress');
    const result = document.getElementById('projectUploadResult');
    if (progress) progress.classList.add('d-none');
    if (result) result.classList.add('d-none');
    
    const fileInput = document.getElementById('projectFilesInput');
    if (fileInput) fileInput.value = '';
    
    jQuery(modal).modal('show');
}

// funcin para subir archivos al proyecto
async function uploadProjectFiles() {
    if (!currentProjectId) {
        alert('⚠️ No hay proyecto seleccionado');
        return;
    }
    
    const fileInput = document.getElementById('projectFilesInput');
    if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
        alert('⚠️ Selecciona al menos un archivo');
        return;
    }
    
    const files = fileInput.files;
    const fd = new FormData();
    
    // ✅ AGREGADO: Enviar user_id explícitamente
    const uid = getUserId();
    if (uid) fd.append('user_id', uid);
    
    fd.append('project_id', currentProjectId);
    
    for (let i = 0; i < files.length; i++) {
        fd.append('files[]', files[i], files[i].name);
    }
    
    const progress = document.getElementById('projectUploadProgress');
    const progressBar = document.getElementById('projectUploadProgressBar');
    const statusEl = document.getElementById('projectUploadStatus');
    const result = document.getElementById('projectUploadResult');
    const successMsg = document.getElementById('projectUploadSuccessMsg');
    
    if (progress) progress.classList.remove('d-none');
    if (result) result.classList.add('d-none');
    if (progressBar) progressBar.style.width = '30%';
    if (statusEl) statusEl.textContent = `Subiendo ${files.length} archivo(s)...`;
    
    try {
        const r = await fetch('project_upload.php', {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        });
        
        if (progressBar) progressBar.style.width = '80%';
        
        const j = toJSONorThrow(await r.text(), r.status, 'Subir archivos');
        
        if (!r.ok || j.ok === false) {
            throw new Error(j.error || `HTTP ${r.status}`);
        }
        
        if (progressBar) {
            progressBar.style.width = '100%';
            progressBar.classList.remove('progress-bar-animated');
        }
        
        if (statusEl) statusEl.textContent = '¡Completado!';
        
        if (successMsg) {
            let msg = `${j.uploaded.length} archivo(s) subido(s) correctamente.`;
            if (j.errors && j.errors.length > 0) {
                msg += `<br><small class="text-warning">${j.errors.length} error(es): ${j.errors.join(', ')}</small>`;
            }
            successMsg.innerHTML = msg;
        }
        
        if (result) result.classList.remove('d-none');
        
        await loadProjectSources(currentProjectId);
        
        setTimeout(() => {
            jQuery('#modalProjectSources').modal('hide');
        }, 2000);
        
    } catch (e) {
        console.error('Error subiendo archivos:', e);
        if (statusEl) statusEl.textContent = 'Error: ' + e.message;
        if (progressBar) {
            progressBar.classList.remove('progress-bar-animated');
            progressBar.classList.add('bg-danger');
        }
        alert('Error subiendo archivos: ' + e.message);
    }
}


async function deleteProjectSource(sourceId) {
  if (!confirm('¿Eliminar esta fuente del proyecto? Se borrará el archivo de S3 y todos sus chunks indexados.')) {
    return;
  }
  try {
    const fd = new FormData();
    fd.append('source_id', sourceId);
    const r = await fetch('project_source_delete.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: fd
    });
    const j = toJSONorThrow(await r.text(), r.status, 'Eliminar fuente');
    if (!r.ok || j.ok === false) {
      throw new Error(j.error || `HTTP ${r.status}`);
    }
    setStatus('Fuente eliminada');
    await loadProjectSources(currentProjectId);
  } catch (e) {
    console.error('Error eliminando fuente:', e);
    alert('Error eliminando fuente: ' + e.message);
  }
}
async function indexPendingFromPanel() {
  if (!currentProjectId) {
    alert('⚠️ No hay proyecto seleccionado');
    return;
  }
  const btn = document.getElementById('chat2IndexPending');
  if (!btn) return;
  const originalHtml = btn.innerHTML;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
  btn.disabled = true;
  try {
    const fd = new FormData();
    fd.append('project_id', currentProjectId);
    const r = await fetch('index_project_sources.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: fd
    });
    const j = toJSONorThrow(await r.text(), r.status, 'Indexar fuentes');
    if (!r.ok || j.ok === false) {
      throw new Error(j.error || `HTTP ${r.status}`);
    }
    if (j.prepared_count > 0) {
      setStatus(`🧮 ${j.prepared_count} archivo(s) preparado(s) · ${j.queued_jobs || 0} embedding(s) en cola`);
      setTimeout(() => setStatus(''), 5000);
    } else {
      setStatus('ℹ️ No hay archivos pendientes por preparar');
      setTimeout(() => setStatus(''), 3000);
    }
    await loadProjectSources(currentProjectId);
  } catch (e) {
    console.error('Error indexando fuentes:', e);
    alert('Error al indexar: ' + e.message);
  } finally {
    btn.innerHTML = originalHtml;
    btn.disabled = false;
  }
}
async function indexPendingSources() {
  if (!currentProjectId) {
    alert('⚠️ No hay proyecto seleccionado');
    return;
  }
  const btn = document.getElementById('btnIndexPending');
  if (!btn) return;
  const originalHtml = btn.innerHTML;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Indexando...';
  btn.disabled = true;
  try {
    const fd = new FormData();
    fd.append('project_id', currentProjectId);
    const r = await fetch('index_project_sources.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: fd
    });
    const j = toJSONorThrow(await r.text(), r.status, 'Indexar fuentes');
    if (!r.ok || j.ok === false) {
      throw new Error(j.error || `HTTP ${r.status}`);
    }
    if (j.prepared_count > 0) {
      alert(`🧮 Se prepararon ${j.prepared_count} archivo(s) y quedaron ${j.queued_jobs || 0} embedding(s) en cola.\n\nLa fuente aparecerá como Indexada cuando process_embedding_queue.php termine todos sus chunks.`);
    } else {
      alert('ℹ️ No se encontraron archivos pendientes por preparar.');
    }
    await loadProjectSources(currentProjectId);
    const modalList = document.getElementById('modalSourcesList');
    if (modalList && document.getElementById('modalProjectSources').classList.contains('show')) {
        if (projectSources.length === 0) {
            modalList.innerHTML = '<div class="list-group-item text-muted small">No hay fuentes agregadas aún.</div>';
        } else {
            modalList.innerHTML = projectSources.map(s => {
                const statusClass = s.status || 'pending';
                const statusText = { 'pending': 'Pendiente', 'indexed': 'Indexado', 'stale': 'Desactualizado', 'error': 'Error' }[statusClass] || statusClass;
                const badgeClass = statusClass === 'indexed' ? 'success' : statusClass === 'error' ? 'danger' : 'warning';
                return `<div class="list-group-item d-flex justify-content-between align-items-center py-2" data-id="${s.id}">
                  <div class="text-truncate" style="max-width: 70%;" title="${esc(s.filename)}">
                    <i class="fas fa-file-code mr-1 text-muted"></i> ${esc(s.filename)}
                  </div>
                  <div class="d-flex align-items-center" style="gap: 8px;">
                    <span class="badge badge-${badgeClass}" style="font-size: 0.7rem;">${statusText}</span>
                    <button class="btn btn-sm btn-outline-danger btn-delete-modal-source" data-id="${s.id}" title="Eliminar fuente" style="padding: 0 .4rem;">
                      <i class="fas fa-trash"></i>
                    </button>
                  </div>
                </div>`;
            }).join('');
            modalList.querySelectorAll('.btn-delete-modal-source').forEach(b => {
                b.addEventListener('click', (e) => {
                    e.stopPropagation();
                    deleteProjectSource(parseInt(b.getAttribute('data-id'), 10));
                });
            });
        }
    }
    if (j.errors && j.errors.length > 0) {
      console.warn('Errores de indexación:', j.errors);
    }
  } catch (e) {
    console.error('Error indexando fuentes:', e);
    alert('Error al indexar: ' + e.message);
  } finally {
    btn.innerHTML = originalHtml;
    btn.disabled = false;
  }
}
const btnIndexPending = document.getElementById('btnIndexPending');
if (btnIndexPending) {
  btnIndexPending.addEventListener('click', indexPendingSources);
}
    if (el.attach) {
      el.attach.addEventListener('click', (e) => {
        e.preventDefault();
        openSessionAttachmentsModal();
      });
    }
    if (el.file) el.file.addEventListener('change', (ev) => addFilesFromInput(el.file.files));
    if (el.send) el.send.addEventListener('click', sendMessage);
    if (el.input) {
      el.input.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); sendMessage(); }
      });
    }
    if (el.sbNewChat) {
      el.sbNewChat.addEventListener('click', async () => {
        try {
          const created = await createSession('Nueva conversación');
          await loadSessions();
          await selectSession(created.id);
          el.input && el.input.focus();
        } catch (e) {
          pushLocal('assistant', '⚠️ No se pudo crear la sesión: ' + e.message);
        }
      });
    }
    if (el.sbChatSearch) el.sbChatSearch.addEventListener('input', () => loadSessions());
    if (el.sbNewProject) el.sbNewProject.addEventListener('click', openProjectManager);
    if (el.sbManageProjects) el.sbManageProjects.addEventListener('click', openProjectManager);
    if (el.btnGenImg) {
      el.btnGenImg.addEventListener('click', async () => {
        if (!currentSessionId) {
          const created = await createSession('Imágenes');
          currentSessionId = created.id;
          syncTraceExplorerButton();
          await loadSessions();
        }
        const prompt = (el.input && el.input.value.trim()) || window.prompt('Prompt de imagen:') || '';
        if (!prompt) return;
        await autoGenerateImage(prompt);
      });
    }
    if (el.btnGenVid) {
      el.btnGenVid.addEventListener('click', async () => {
        if (!currentSessionId) {
          const created = await createSession('Videos');
          currentSessionId = created.id;
          syncTraceExplorerButton();
          await loadSessions();
        }
        const prompt = (el.input && el.input.value.trim()) || window.prompt('Prompt de video:') || '';
        if (!prompt) return;
        await autoGenerateVideo(prompt);
      });
    }
    if (el.reload) el.reload.addEventListener('click', () => loadSessions());
    if (el.search) el.search.addEventListener('input', () => loadSessions());
    if (el.showArchived) el.showArchived.addEventListener('change', () => loadSessions());
    if (el.rename) el.rename.addEventListener('click', () => currentSessionId && promptRename(currentSessionId));
    if (el.archive) el.archive.addEventListener('click', () => currentSessionId && doArchive(currentSessionId));
    if (el.restore) el.restore.addEventListener('click', () => currentSessionId && doRestore(currentSessionId));
    if (el.traceExplorer) el.traceExplorer.addEventListener('click', openTraceExplorer);
    syncTraceExplorerButton();
    if (el.projectSelect) el.projectSelect.addEventListener('change', (e) => selectProject(e.target.value));
    if (el.projectNew) el.projectNew.addEventListener('click', openProjectManager);
    if (el.projectManage) el.projectManage.addEventListener('click', openProjectManager);
    if (el.sourcesAdd) el.sourcesAdd.addEventListener('click', openProjectSourcesModal);
    if (el.sourcesRefresh) el.sourcesRefresh.addEventListener('click', () => { if (currentProjectId) loadProjectSources(currentProjectId); });
const chat2IndexPending = document.getElementById('chat2IndexPending');
if (chat2IndexPending) {
  chat2IndexPending.addEventListener('click', indexPendingFromPanel);
}
    document.querySelectorAll('[data-tool]').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const tool = btn.dataset.tool;
        executeTool(tool);
      });
    });
    const projectForm = document.getElementById('projectForm');
    if (projectForm) projectForm.addEventListener('submit', saveProject);
    const btnCancelProject = document.getElementById('btnCancelProject');
    if (btnCancelProject) {
      btnCancelProject.addEventListener('click', () => {
        document.getElementById('projectForm').reset();
        document.getElementById('projectId').value = '';
      });
    }
const btnUploadProjectFiles = document.getElementById('btnUploadProjectFiles');
if (btnUploadProjectFiles) btnUploadProjectFiles.addEventListener('click', uploadProjectFiles);
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('.btn-primordial');
  if (!btn) return;
  const msgId = parseInt(btn.getAttribute('data-msg-id'), 10);
  if (!msgId) return;
  const isCurrentlyPrimordial = btn.classList.contains('active');
  const action = isCurrentlyPrimordial ? 'unmark' : 'mark';
  try {
    setStatus('Actualizando memoria...');
    const fd = new FormData();
    fd.append('message_id', String(msgId));
    fd.append('action', action);
    const r = await fetch(API.markPrimordial, {
      method: 'POST',
      credentials: 'same-origin',
      body: fd
    });
    const j = toJSONorThrow(await r.text(), r.status, 'Marcar primordial');
    if (j.ok) {
      btn.classList.toggle('active');
      const icon = isCurrentlyPrimordial ? 'star-o' : 'star';
      const text = isCurrentlyPrimordial ? 'Marcar' : 'Primordial';
      const color = isCurrentlyPrimordial ? '#ccc' : '#ffc107';
      btn.innerHTML = `<i class="fas fa-${icon}"></i> ${text}`;
      btn.style.color = color;
      btn.title = isCurrentlyPrimordial
        ? 'Marcar como primordial (verdad absoluta)'
        : 'Quitar de primordiales (verdad absoluta)';
      setStatus('✅ Memoria actualizada');
      setTimeout(() => setStatus(''), 2000);
    } else {
      alert('⚠️ ' + (j.error || 'Error al actualizar'));
    }
  } catch (err) {
    console.error(err);
    setStatus('');
    alert('Error de red: ' + err.message);
  }
});

  window.addEventListener('error', (ev) => {
      const msg = ev && ev.message ? ev.message : 'Error JS no especificado';
      setStatus('Error en script');
      pushLocal('assistant', '⚠️ Error de script: ' + msg);
    });
    window.addEventListener('unhandledrejection', (ev) => {
      const msg = (ev && ev.reason && (ev.reason.message || ev.reason)) || 'Promise rechazada sin detalle';
      setStatus('Error en promesa');
      pushLocal('assistant', '⚠️ Error: ' + msg);
    });
async function loadContextTab() {
  const emptyState = document.getElementById('contextEmptyState');
  const contentState = document.getElementById('contextContent');
  const projectList = document.getElementById('ctxProjectList');
  const sessionList = document.getElementById('ctxSessionList');
  const projectNameEl = document.getElementById('ctxProjectName');
  const sessionNameEl = document.getElementById('ctxSessionName');
  if (!currentProjectId && !currentSessionId) {
    if (emptyState) emptyState.classList.remove('d-none');
    if (contentState) contentState.classList.add('d-none');
    return;
  }
  if (emptyState) emptyState.classList.add('d-none');
  if (contentState) contentState.classList.remove('d-none');
  if (projectList) projectList.innerHTML = '<div class="text-muted small text-center py-4"><i class="fas fa-spinner fa-spin"></i> Cargando...</div>';
  if (sessionList) sessionList.innerHTML = '<div class="text-muted small text-center py-4"><i class="fas fa-spinner fa-spin"></i> Cargando...</div>';
  const proj = projects.find(p => p.id === currentProjectId);
  if (projectNameEl) projectNameEl.textContent = proj ? proj.name : 'Ninguno';
  const sess = sessions.find(s => (s.id || s.id_) === currentSessionId);
  if (sessionNameEl) sessionNameEl.textContent = sess ? (sess.title || `Sesión #${currentSessionId}`) : 'Ninguna';
  try {
    const qs = new URLSearchParams();
    if (currentProjectId) qs.set('project_id', currentProjectId);
    if (currentSessionId) qs.set('session_id', currentSessionId);
    const r = await fetch(`${API.getContext}?${qs.toString()}`, { credentials: 'same-origin' });
    const j = await r.json();
    if (!r.ok || !j.ok) throw new Error(j.error || 'Error al cargar contexto');
    const sessionSummaryContainer = document.getElementById('ctxSessionSummary');
    if (sessionSummaryContainer) {
        if (j.session_summary && j.session_summary.context_summary) {
            const levelLabels = { '0': 'Crudo', '1': 'Resumen x5', '2': 'Macro x20', '3': 'Épico x80' };
            const level = j.session_summary.context_level || '0';
            const lastComp = j.session_summary.last_compressed_at ? new Date(j.session_summary.last_compressed_at).toLocaleString() : 'Nunca';
            sessionSummaryContainer.innerHTML = `
                <div class="card bg-info border-0 mb-3 shadow-sm" style="font-size: 0.85rem;">
                    <div class="card-body py-2 px-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0 text-white"><i class="fas fa-brain mr-2"></i>Memoria Consolidada de la Sesión</h6>
                            <span class="badge badge-light">Nivel ${level}: ${levelLabels[level] || 'Crudo'}</span>
                        </div>
                        <div class="text-white" style="white-space: pre-wrap; max-height: 250px; overflow-y: auto; font-family: monospace; font-size: 0.8rem; background: rgba(0,0,0,0.15); padding: 10px; border-radius: 4px;">${esc(j.session_summary.context_summary)}</div>
                        <small class="text-white-50 d-block mt-2"><i class="fas fa-clock mr-1"></i> Última compresión: ${lastComp}</small>
                    </div>
                </div>
            `;
        } else {
            sessionSummaryContainer.innerHTML = `
                <div class="alert alert-secondary border-0 mb-3 small" style="background: rgba(255,255,255,0.05); color: #ccc;">
                    <i class="fas fa-info-circle mr-1"></i> Aún no se ha generado un resumen consolidado para esta sesión. 
                    <br>El resumen automático se crea cuando la sesión supera los 5 mensajes y el cron de compresión se ejecuta.
                </div>
            `;
        }
    }
    if (j.project_context && j.project_context.length > 0 && projectList) {
      const typeColors = { 'rule': 'danger', 'decision': 'primary', 'fact': 'success', 'style': 'info', 'todo': 'warning', 'note': 'secondary' };
      const typeLabels = { 'rule': 'Regla', 'decision': 'Decisión', 'fact': 'Hecho', 'style': 'Estilo', 'todo': 'Pendiente', 'note': 'Nota' };
      projectList.innerHTML = j.project_context.map(ctx => {
        const badge = typeColors[ctx.type] || 'secondary';
        const label = typeLabels[ctx.type] || ctx.type;
        return `
          <div class="card bg-secondary border-0 mb-2" style="font-size: 0.85rem;">
            <div class="card-body py-2 px-3">
              <div class="d-flex justify-content-between align-items-start mb-1">
                <span class="badge badge-${badge}">${label}</span>
                <small class="text-muted">${new Date(ctx.created_at).toLocaleDateString()}</small>
              </div>
              ${ctx.title ? `<h6 class="mb-1 text-white">${esc(ctx.title)}</h6>` : ''}
              <div class="text-light" style="white-space: pre-wrap;">${esc(ctx.content)}</div>
            </div>
          </div>`;
      }).join('');
    } else if (projectList) {
      projectList.innerHTML = '<div class="text-muted small text-center py-4">Sin contexto registrado para este proyecto.</div>';
    }
    if (j.session_context && j.session_context.length > 0 && sessionList) {
      const typeColors = { 'primordial': 'warning', 'level_0': 'secondary', 'level_1': 'info', 'level_2': 'primary', 'level_3': 'danger' };
      const typeLabels = { 'primordial': '👑 Primordial', 'level_0': 'Nivel 0 (Crudo)', 'level_1': 'Nivel 1 (Resumen)', 'level_2': 'Nivel 2 (Macro)', 'level_3': 'Nivel 3 (Épico)' };
      sessionList.innerHTML = j.session_context.map(ctx => {
        const badge = typeColors[ctx.block_type] || 'secondary';
        const label = typeLabels[ctx.block_type] || ctx.block_type;
        return `
          <div class="card bg-secondary border-0 mb-2" style="font-size: 0.85rem;">
            <div class="card-body py-2 px-3">
              <div class="d-flex justify-content-between align-items-start mb-1">
                <span class="badge badge-${badge}">${label}</span>
                <small class="text-muted">${ctx.token_count || 0} tokens</small>
              </div>
              <div class="text-light" style="white-space: pre-wrap; font-family: monospace; font-size: 0.8rem;">${esc(ctx.content_preview || 'Sin vista previa')}</div>
              ${ctx.s3_path ? `<small class="text-info d-block mt-1"><i class="fas fa-file-alt"></i> ${esc(ctx.s3_path)}</small>` : ''}
            </div>
          </div>`;
      }).join('');
    } else if (sessionList) {
      sessionList.innerHTML = '<div class="text-muted small text-center py-4">Sin bloques de contexto para esta sesión.</div>';
    }
  } catch (e) {
    console.error('Error cargando contexto:', e);
    const errMsg = `<div class="text-danger small text-center py-4">Error: ${esc(e.message)}</div>`;
    if (projectList) projectList.innerHTML = errMsg;
    if (sessionList) sessionList.innerHTML = errMsg;
  }
}
const tabContexto = document.getElementById('tab-Contexto');
if (tabContexto) {
  tabContexto.addEventListener('shown.bs.tab', function (e) {
    loadContextTab();
  });
}
const btnRefreshContext = document.getElementById('btnRefreshContext');
if (btnRefreshContext) {
  btnRefreshContext.addEventListener('click', loadContextTab);
}
window.addEventListener('pagehide', () => {
  const sid = Number(currentSessionId || 0);
  if (!sid || !navigator.sendBeacon) return;
  try {
    const fd = new FormData();
    fd.append('session_id', String(sid));
    navigator.sendBeacon(API.discardEmptySession, fd);
  } catch (_) {}
});

(async function boot() {
  setStatus('Cargando...');
  await Promise.all([loadSessions(), loadProjects()]);
  setStatus('');
  startNotifyPoller();
}());

// =====================================================================
// ✅ MODO DE ADJUNTOS POR SESIÓN (RAG / ALWAYS)
// =====================================================================
function getAttachmentModeCheckbox() {
    return document.getElementById('chatAttachmentsRagMode');
}

async function loadAttachmentRagMode(sessionId) {
    const checkbox = getAttachmentModeCheckbox();
    if (!checkbox) return;

    if (!sessionId || Number.isNaN(Number(sessionId))) {
        checkbox.checked = true;
        checkbox.disabled = true;
        return;
    }

    try {
        checkbox.disabled = true;

        const qs = new URLSearchParams({
            session_id: String(sessionId)
        });

        const r = await fetch(`${API.attachmentMode}?${qs.toString()}`, {
            credentials: 'same-origin',
            cache: 'no-cache'
        });

        const j = toJSONorThrow(await r.text(), r.status, 'Modo de adjuntos');

        if (!r.ok || j.ok === false) {
            throw new Error(j.error || `HTTP ${r.status}`);
        }

        // Por defecto usamos RAG.
        checkbox.checked = (j.mode !== 'always');
    } catch (e) {
        console.error('Error cargando modo de adjuntos:', e);

        // Si falla, dejamos RAG activo por seguridad/costo.
        checkbox.checked = true;
    } finally {
        checkbox.disabled = false;
    }
}

async function saveAttachmentRagMode(sessionId, mode) {
    const checkbox = getAttachmentModeCheckbox();

    if (!sessionId || Number.isNaN(Number(sessionId))) {
        return;
    }

    if (!['rag', 'always'].includes(mode)) {
        mode = 'rag';
    }

    try {
        if (checkbox) checkbox.disabled = true;

        const fd = new FormData();
        fd.append('session_id', String(sessionId));
        fd.append('mode', mode);

        const r = await fetch(API.attachmentMode, {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        });

        const j = toJSONorThrow(await r.text(), r.status, 'Guardar modo de adjuntos');

        if (!r.ok || j.ok === false) {
            throw new Error(j.error || `HTTP ${r.status}`);
        }

        if (typeof showToast === 'function') {
            if (mode === 'always') {
                showToast(
                    '📎 Adjuntos',
                    'Se incluirán todos los adjuntos de la sesión.',
                    'success'
                );
            } else {
                showToast(
                    '📎 Adjuntos',
                    'Solo se incluirán adjuntos relevantes (RAG).',
                    'success'
                );
            }
        }
    } catch (e) {
        console.error('Error guardando modo de adjuntos:', e);

        if (typeof showToast === 'function') {
            showToast(
                '⚠️ Error',
                'No se pudo guardar el modo de adjuntos: ' + e.message,
                'danger'
            );
        }

        // Intentar restaurar el estado real desde el servidor.
        try {
            await loadAttachmentRagMode(sessionId);
        } catch (_) {}
    } finally {
        if (checkbox) checkbox.disabled = false;
    }
}

function wireAttachmentRagModeCheckbox() {
    const checkbox = getAttachmentModeCheckbox();
    if (!checkbox) return;

    if (checkbox.dataset.wired === 'true') return;
    checkbox.dataset.wired = 'true';

    checkbox.addEventListener('change', async () => {
        if (!currentSessionId) {
            if (typeof showToast === 'function') {
                showToast(
                    '⚠️ Sesión',
                    'Selecciona una conversación antes de cambiar este modo.',
                    'warning'
                );
            }

            checkbox.checked = true;
            return;
        }

        const mode = checkbox.checked ? 'rag' : 'always';
        await saveAttachmentRagMode(currentSessionId, mode);
    });
}

wireAttachmentRagModeCheckbox();

async function loadSessionAttachments(sessionId) {
  const target = el.sbSessionFiles;
  const countEl = el.chatFilesCount;

  if (!target) return;

  if (!sessionId || Number.isNaN(Number(sessionId))) {
    sessionAttachments = [];
    target.innerHTML = `
      <div class="empty-state-sidebar chat-files-empty">
        <i class="fas fa-file"></i>
        <span>Selecciona una conversación para ver sus archivos</span>
      </div>
    `;
    if (countEl) countEl.textContent = '0';
    return;
  }

  target.innerHTML = `<div class="text-muted small">Cargando archivos…</div>`;

  try {
    const qs = new URLSearchParams({ session_id: String(sessionId) });
    const r = await fetch(`${API.sessionFiles}?${qs.toString()}`, {
      credentials: 'same-origin'
    });

    let j;
    try {
      j = await r.json();
    } catch (err) {
      throw new Error('Respuesta inválida del servidor');
    }

    if (!r.ok || j.ok === false) {
      throw new Error(j.error || `HTTP ${r.status}`);
    }

    const files = j.files || [];
    sessionAttachments = files;

    if (countEl) {
      countEl.textContent = String(files.length);
    }

    if (!files.length) {
      sessionAttachments = [];
      target.innerHTML = `
        <div class="empty-state-sidebar chat-files-empty">
          <i class="fas fa-file"></i>
          <span>No hay archivos adjuntos</span>
        </div>
      `;
      return;
    }

    // Generar HTML para cada archivo
    target.innerHTML = files.map(file => {
      const fileId = file.id || 0;
      const filename = esc(file.filename || file.s3_key || `Archivo #${fileId}`);
      
      // Formatear tamaño
      let sizeLabel = '0 B';
      const size = Number(file.size_bytes || 0);
      if (size >= 1048576) {
        sizeLabel = (size / 1048576).toFixed(2) + ' MB';
      } else if (size >= 1024) {
        sizeLabel = (size / 1024).toFixed(2) + ' KB';
      } else if (size > 0) {
        sizeLabel = size + ' B';
      }

      // Formatear fecha
      let dateLabel = '';
      if (file.created_at) {
        const timestamp = new Date(file.created_at).getTime();
        if (!isNaN(timestamp)) {
          const d = new Date(file.created_at);
          dateLabel = d.toLocaleDateString('es-ES', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
          });
        }
      }

      // Icono según extensión
      const extension = (file.filename || '').split('.').pop().toLowerCase();
      const canIndexText = INDEXABLE_SESSION_EXTS.has(extension);
      let fileIcon = 'fa-file';
      if (extension === 'pdf') fileIcon = 'fa-file-pdf';
      else if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(extension)) fileIcon = 'fa-file-image';
      else if (['php', 'js', 'css', 'html', 'json', 'xml'].includes(extension)) fileIcon = 'fa-file-code';
      else if (['doc', 'docx'].includes(extension)) fileIcon = 'fa-file-word';
      else if (['xls', 'xlsx', 'csv'].includes(extension)) fileIcon = 'fa-file-excel';

      return `
        <div class="chat-session-file" data-file-id="${fileId}">
          <div class="chat-session-file-main">
            <div class="chat-session-file-icon">
              <i class="fas ${fileIcon}"></i>
            </div>

            <div class="chat-session-file-info">
              <div class="chat-session-file-name" title="${filename}">
                ${filename}
              </div>

              <div class="chat-session-file-meta">
                ${dateLabel}
                ·
                ${sizeLabel}
              </div>
            </div>
          </div>

          <div class="chat-session-file-index-state" style="font-size:.62rem; margin:.2rem 0; display:flex; gap:.25rem; flex-wrap:wrap;">
            ${file.index_status === 'indexed' ? '<span class="badge badge-success">RAG listo</span>' : file.index_status === 'embedding_disabled' ? '<span class="badge badge-secondary">Embeddings desactivados</span>' : file.index_status === 'processing' ? '<span class="badge badge-warning">Embedding pendiente</span>' : file.index_status === 'stale_embedding' ? '<span class="badge badge-danger">Reindexar · modelo cambió</span>' : file.chunks_total > 0 ? '<span class="badge badge-warning">Chunks sin vector</span>' : '<span class="badge badge-secondary">Sin indexar</span>'}
            ${file.semantic_status === 'ready' ? '<span class="badge badge-info">Semántica lista</span>' : file.semantic_status === 'embedding_disabled' ? '<span class="badge badge-secondary">Semántica sin vector</span>' : file.semantic_status === 'pending_embedding' ? '<span class="badge badge-warning">Semántica pendiente</span>' : file.semantic_status === 'stale_embedding' ? '<span class="badge badge-danger">Semántica requiere nuevo embedding</span>' : ''}
          </div>

          <div class="chat-session-file-actions">
            <button type="button" class="chat-file-action" title="Ver archivo"
              data-action="view" data-file-id="${fileId}"
              data-s3-key="${esc(file.s3_key || '')}" data-filename="${filename}">
              <i class="fas fa-eye"></i>
            </button>

            <button type="button" class="chat-file-action" title="Descargar archivo"
              data-action="download" data-file-id="${fileId}"
              data-s3-key="${esc(file.s3_key || '')}" data-filename="${filename}">
              <i class="fas fa-download"></i>
            </button>

            ${canIndexText ? `<button type="button" class="chat-file-action" title="Indexar archivo"
              data-action="index" data-file-id="${fileId}">
              <i class="fas fa-search"></i>
            </button>

            <button type="button" class="chat-file-action" title="Crear semántica"
              data-action="semantic" data-file-id="${fileId}">
              <i class="fas fa-brain"></i>
            </button>` : ''}

            <button type="button" class="chat-file-action chat-file-action-danger" title="Eliminar archivo"
              data-action="delete" data-file-id="${fileId}">
              <i class="fas fa-trash"></i>
            </button>
          </div>
          
          
        </div>
      `;
    }).join('');

    // Wire up action buttons
    wireFileActions(target);

  } catch (e) {
    sessionAttachments = [];
    console.error(e);
    target.innerHTML = `<div class="text-danger small">${esc(e.message)}</div>`;
    if (countEl) countEl.textContent = '0';
  }
}


async function deleteSessionAttachment(attachmentId) {
  const file = sessionAttachments.find(a => Number(a.files3_id || a.id) === Number(attachmentId));
  const label = file?.filename || ('#' + attachmentId);
  if (!confirm('¿Eliminar el adjunto \"' + label + '\"?\nTambién se eliminará su índice y semántica de la conversación.')) return;
  try {
    const fd = new FormData();
    fd.append('file_id', String(attachmentId));
    const r = await fetch(FILE_ENDPOINTS.delete, { method:'POST', credentials:'same-origin', body:fd });
    const j = toJSONorThrow(await r.text(), r.status, 'Eliminar adjunto');
    if (!r.ok || j.ok === false) throw new Error(j.error || j.mensaje || `HTTP ${r.status}`);
    await loadSessionAttachments(currentSessionId);
  } catch (e) {
    console.error('Error eliminando adjunto:', e);
    alert('Error eliminando adjunto: ' + e.message);
  }
}
async function uploadSessionFiles() {
  if (!currentSessionId) {
    alert('⚠️ No hay sesión seleccionada');
    return;
  }
  const fileInput = document.getElementById('sessionFilesInput');
  if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
    alert('⚠️ Selecciona al menos un archivo');
    return;
  }
  const files = fileInput.files;
  const fd = new FormData();
  fd.append('session_id', currentSessionId);
  for (let i = 0; i < files.length; i++) {
    fd.append('files[]', files[i], files[i].name);
  }
  const progress = document.getElementById('sessionUploadProgress');
  const progressBar = document.getElementById('sessionUploadProgressBar');
  const statusEl = document.getElementById('sessionUploadStatus');
  const result = document.getElementById('sessionUploadResult');
  const successMsg = document.getElementById('sessionUploadSuccessMsg');
  if (progress) progress.classList.remove('d-none');
  if (result) result.classList.add('d-none');
  if (progressBar) progressBar.style.width = '30%';
  if (statusEl) statusEl.textContent = `Subiendo ${files.length} archivo(s)...`;
  try {
    const r = await fetch('session_upload.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: fd
    });
    if (progressBar) progressBar.style.width = '80%';
    const j = toJSONorThrow(await r.text(), r.status, 'Subir archivos');
    if (!r.ok || j.ok === false) {
      throw new Error(j.error || `HTTP ${r.status}`);
    }
    if (progressBar) {
      progressBar.style.width = '100%';
      progressBar.classList.remove('progress-bar-animated');
    }
    if (statusEl) statusEl.textContent = '¡Completado!';
    if (successMsg) {
      let msg = `${j.uploaded.length} archivo(s) subido(s) correctamente.`;
      if (j.errors && j.errors.length > 0) {
        msg += `<br><small class="text-warning">${j.errors.length} error(es): ${j.errors.join(', ')}</small>`;
      }
      successMsg.innerHTML = msg;
    }
    if (result) result.classList.remove('d-none');
    await loadSessionAttachments(currentSessionId);
    setTimeout(() => {
      jQuery('#modalSessionAttachments').modal('hide');
    }, 2000);
  } catch (e) {
    console.error('Error subiendo archivos:', e);
    if (statusEl) statusEl.textContent = 'Error: ' + e.message;
    if (progressBar) {
      progressBar.classList.remove('progress-bar-animated');
      progressBar.classList.add('bg-danger');
    }
    alert('Error subiendo archivos: ' + e.message);
  }
}
function openSessionAttachmentsModal() {
  if (!currentSessionId) { 
    alert('⚠️ Selecciona o crea una sesión primero.'); 
    return; 
  }
  const modal = document.getElementById('modalSessionAttachments');
  if (!modal) return;
  // La ruta ahora incluye día y session_id
  const userId = document.getElementById('chatUserId') ? document.getElementById('chatUserId').value : 1;
  const now = new Date();
  const year = now.getFullYear();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');
  const path = `Data/Chat/Uploads/${userId}/${year}/${month}/${day}/${currentSessionId}/`;
  const pathEl = document.getElementById('sessionUploadPath');
  if (pathEl) pathEl.textContent = path;
  const modalList = document.getElementById('modalSessionAttachmentsList');
  if (modalList) {
    if (sessionAttachments.length === 0) {
      modalList.innerHTML = '<div class="list-group-item text-muted small">No hay adjuntos aún.</div>';
    } else {
      modalList.innerHTML = sessionAttachments.map(a => {
        const ready = a.index_status === 'indexed';
        const processing = a.index_status === 'processing';
        const statusText = ready ? 'RAG listo' : (a.index_status === 'embedding_disabled' ? 'Embedding off' : (processing ? 'Procesando' : (a.index_status === 'stale_embedding' ? 'Reindexar' : (a.chunks_total > 0 ? 'Sin embedding' : 'Sin indexar'))));
        const badgeClass = ready ? 'success' : (processing ? 'warning' : 'secondary');
        return `<div class="list-group-item d-flex justify-content-between align-items-center py-2" data-id="${a.files3_id}">
          <div class="text-truncate" style="max-width: 70%;" title="${esc(a.filename)}">
            <i class="fas fa-file mr-1 text-muted"></i> ${esc(a.filename)}
          </div>
          <div class="d-flex align-items-center" style="gap: 8px;">
            <span class="badge badge-${badgeClass}" style="font-size: 0.7rem;">${statusText}</span>
            <button class="btn btn-sm btn-outline-danger btn-delete-modal-attachment" data-id="${a.files3_id}" title="Eliminar adjunto" style="padding: 0 .4rem;">
              <i class="fas fa-trash"></i>
            </button>
          </div>
        </div>`;
      }).join('');
      modalList.querySelectorAll('.btn-delete-modal-attachment').forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.stopPropagation();
          const id = parseInt(btn.getAttribute('data-id'), 10);
          deleteSessionAttachment(id);
        });
      });
    }
  }
  const progress = document.getElementById('sessionUploadProgress');
  const result = document.getElementById('sessionUploadResult');
  if (progress) progress.classList.add('d-none');
  if (result) result.classList.add('d-none');
  const fileInput = document.getElementById('sessionFilesInput');
  if (fileInput) fileInput.value = '';
  jQuery(modal).modal('show');
}
  if (el.attachmentsAdd) {
    el.attachmentsAdd.addEventListener('click', openSessionAttachmentsModal);
  }
  if (el.attachmentsRefresh) {
    el.attachmentsRefresh.addEventListener('click', () => {
      if (currentSessionId) loadSessionAttachments(currentSessionId);
    });
  }
  // ✅ INSPECTOR DE ADJUNTOS
    const btnInspector = document.getElementById('btnAttachmentInspector');
    if (btnInspector) {
        btnInspector.addEventListener('click', openAttachmentInspector);
    }
  const btnUploadSessionFiles = document.getElementById('btnUploadSessionFiles');
  if (btnUploadSessionFiles) {
    btnUploadSessionFiles.addEventListener('click', uploadSessionFiles);
  }
  const btnIndexSessionAttachments = document.getElementById('btnIndexSessionAttachments');
  if (btnIndexSessionAttachments) {
    btnIndexSessionAttachments.addEventListener('click', async () => {
      if (!currentSessionId) return alert('Selecciona una sesión primero.');
      if (!sessionAttachments.length) return alert('No hay adjuntos para indexar.');
      const original = btnIndexSessionAttachments.innerHTML;
      btnIndexSessionAttachments.disabled = true;
      let ok = 0, failed = 0;
      try {
        for (let i = 0; i < sessionAttachments.length; i++) {
          const a = sessionAttachments[i];
          const ext = getFileExt(a.filename || '');
          if (!INDEXABLE_SESSION_EXTS.has(ext)) continue;
          btnIndexSessionAttachments.innerHTML = `<i class="fas fa-spinner fa-spin"></i> ${i+1}/${sessionAttachments.length}`;
          const fd = new FormData();
          fd.append('file_id', String(a.files3_id || a.id));
          fd.append('session_id', String(currentSessionId));
          try {
            const r = await fetch(FILE_ENDPOINTS.indexFile,{method:'POST',credentials:'same-origin',body:fd});
            const j = await r.json();
            if(!r.ok || !j.ok) throw new Error(j.error || 'HTTP '+r.status);
            ok++;
          } catch(e) {
            console.error('Indexación adjunto:', a.filename, e);
            failed++;
          }
        }
        await loadSessionAttachments(currentSessionId);
        showToast('🔍 Indexación', `${ok} archivo(s) procesado(s)` + (failed ? ` · ${failed} con error` : ''), failed ? 'warning' : 'success');
      } finally {
        btnIndexSessionAttachments.disabled=false;
        btnIndexSessionAttachments.innerHTML=original;
      }
    });
  }
  
// =====================================================================
// 🧠 MEMORIA PROCEDURAL: Cargar, crear, editar, eliminar 
// =====================================================================
const PM_ENDPOINT = 'procedural_memory.php';

const PM_TYPE_LABELS = {
    rule: '📏 Regla',
    preference: '🎨 Preferencia',
    correction: '✏️ Corrección',
    workflow: '🔄 Flujo',
    pattern: '🔁 Patrón'
};

const PM_TYPE_COLORS = {
    rule: 'var(--accent)',
    preference: 'var(--ok)',
    correction: 'var(--warn)',
    workflow: 'var(--accent-2)',
    pattern: 'var(--text-soft)'
};

async function loadProceduralMemories() {
    const list = document.getElementById('pmList');
    if (!list) return;

    list.innerHTML = '<div style="text-align:center; color:var(--text-soft); padding:30px;"><i class="fas fa-spinner fa-spin"></i> Cargando...</div>';

    try {
        const r = await fetch(PM_ENDPOINT + '?action=list', { credentials: 'same-origin' });
        const j = await r.json();
        if (!r.ok || !j.ok) throw new Error(j.error || 'HTTP ' + r.status);

        const memories = j.memories || [];

        if (memories.length === 0) {
            list.innerHTML = `
                <div style="text-align:center; color:var(--text-soft); padding:40px 20px;">
                    <i class="fas fa-brain" style="font-size:2rem; opacity:.3; display:block; margin-bottom:12px;"></i>
                    <div style="font-size:0.9rem; font-weight:600; color:var(--text-strong);">Sin memorias procedurales</div>
                    <div style="font-size:0.8rem; margin-top:6px;">
                        Agrega reglas manualmente arriba, o la IA las detectará automáticamente
                        conforme converses con ella.
                    </div>
                </div>
            `;
            return;
        }

        list.innerHTML = memories.map(m => {
            const typeLabel = PM_TYPE_LABELS[m.memory_type] || m.memory_type;
            const typeColor = PM_TYPE_COLORS[m.memory_type] || 'var(--text-soft)';
            const activeClass = m.is_active == 1 ? '' : 'opacity:.45;';
            const activeLabel = m.is_active == 1 ? 'Activa' : 'Inactiva';
            const activeIcon = m.is_active == 1 ? 'fa-toggle-on' : 'fa-toggle-off';
            const dateStr = m.created_at ? new Date(m.created_at.replace(' ','T')).toLocaleDateString('es-ES',{day:'2-digit',month:'short',year:'numeric'}) : '';

            return `
            <div class="pm-card" data-id="${m.id_}" style="background:var(--bg3); border:1px solid var(--border); border-radius:var(--radius,12px); padding:14px 16px; ${activeClass} transition:border-color .15s;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:999px; font-size:0.7rem; font-weight:700; background:rgba(var(--accent-rgb),.12); color:${typeColor}; border:1px solid ${typeColor};">
                            ${typeLabel}
                        </span>
                        <span style="font-size:0.65rem; color:var(--text-soft);">
                            <i class="fas fa-fire" style="color:var(--warn);"></i> Confianza: ${m.confidence}/10
                        </span>
                        <span style="font-size:0.65rem; color:var(--text-soft);">${dateStr}</span>
                    </div>
                    <div style="display:flex; gap:4px;">
                        <button class="btn btn-sm pm-btn-toggle" data-id="${m.id_}" title="${m.is_active == 1 ? 'Desactivar' : 'Activar'}"
                            style="border:none; background:none; color:${m.is_active == 1 ? 'var(--ok)' : 'var(--danger)'}; padding:2px 6px; cursor:pointer; font-size:0.85rem;">
                            <i class="fas ${activeIcon}"></i>
                        </button>
                        <button class="btn btn-sm pm-btn-edit" data-id="${m.id_}" title="Editar"
                            style="border:none; background:none; color:var(--accent); padding:2px 6px; cursor:pointer; font-size:0.85rem;">
                            <i class="fas fa-pen"></i>
                        </button>
                        <button class="btn btn-sm pm-btn-delete" data-id="${m.id_}" title="Eliminar"
                            style="border:none; background:none; color:var(--danger); padding:2px 6px; cursor:pointer; font-size:0.85rem;">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                <div class="pm-content" style="font-size:0.85rem; color:var(--text); line-height:1.5; white-space:pre-wrap;">${escapeHtml(m.content)}</div>
                <div class="pm-edit-form" style="display:none; margin-top:10px;">
                    <select class="form-control form-control-sm pm-edit-type" style="max-width:160px; margin-bottom:6px; background:var(--bg); border-color:var(--border); color:var(--text);">
                        <option value="rule" ${m.memory_type==='rule'?'selected':''}>📏 Regla</option>
                        <option value="preference" ${m.memory_type==='preference'?'selected':''}>🎨 Preferencia</option>
                        <option value="correction" ${m.memory_type==='correction'?'selected':''}>✏️ Corrección</option>
                        <option value="workflow" ${m.memory_type==='workflow'?'selected':''}>🔄 Flujo de trabajo</option>
                        <option value="pattern" ${m.memory_type==='pattern'?'selected':''}>🔁 Patrón</option>
                    </select>
                    <textarea class="form-control form-control-sm pm-edit-content" rows="3"
                        style="background:var(--bg); border-color:var(--border); color:var(--text); font-size:0.85rem;">${escapeHtml(m.content)}</textarea>
                    <div style="text-align:right; margin-top:6px;">
                        <button class="btn btn-sm pm-btn-cancel-edit" style="background:transparent; border:1px solid var(--border); color:var(--text-soft); margin-right:4px; font-size:0.75rem;">Cancelar</button>
                        <button class="btn btn-sm pm-btn-save" data-id="${m.id_}" style="background:var(--accent); border-color:var(--accent); color:#fff; font-weight:600; font-size:0.75rem;">
                            <i class="fas fa-check mr-1"></i>Guardar
                        </button>
                    </div>
                </div>
            </div>
            `;
        }).join('');

        // Wire events
        list.querySelectorAll('.pm-btn-edit').forEach(btn => {
            btn.addEventListener('click', () => {
                const card = btn.closest('.pm-card');
                const form = card.querySelector('.pm-edit-form');
                const content = card.querySelector('.pm-content');
                form.style.display = form.style.display === 'none' ? 'block' : 'none';
                content.style.display = form.style.display === 'block' ? 'none' : 'block';
            });
        });

        list.querySelectorAll('.pm-btn-cancel-edit').forEach(btn => {
            btn.addEventListener('click', () => {
                const card = btn.closest('.pm-card');
                card.querySelector('.pm-edit-form').style.display = 'none';
                card.querySelector('.pm-content').style.display = 'block';
            });
        });

        list.querySelectorAll('.pm-btn-save').forEach(btn => {
            btn.addEventListener('click', async () => {
                const card = btn.closest('.pm-card');
                const id = btn.dataset.id;
                const content = card.querySelector('.pm-edit-content').value.trim();
                const type = card.querySelector('.pm-edit-type').value;

                if (content.length < 10) {
                    showToast('⚠️ Error', 'El contenido debe tener al menos 10 caracteres.', 'warning');
                    return;
                }

                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

                try {
                    const fd = new FormData();
                    fd.append('action', 'update');
                    fd.append('id', id);
                    fd.append('content', content);
                    fd.append('memory_type', type);
                    fd.append('is_active', '1');

                    const r = await fetch(PM_ENDPOINT, { method:'POST', credentials:'same-origin', body:fd });
                    const j = await r.json();
                    if (!r.ok || !j.ok) throw new Error(j.error || 'HTTP ' + r.status);

                    showToast('✅ Guardado', 'Memoria actualizada correctamente.', 'success');
                    await loadProceduralMemories();
                } catch (e) {
                    showToast('⚠️ Error', e.message, 'danger');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check mr-1"></i>Guardar';
                }
            });
        });

        list.querySelectorAll('.pm-btn-toggle').forEach(btn => {
            btn.addEventListener('click', async () => {
                const card = btn.closest('.pm-card');
                const id = btn.dataset.id;
                const content = card.querySelector('.pm-content').textContent.trim();
                const isActive = !card.style.opacity || card.style.opacity !== '0.45';

                try {
                    const fd = new FormData();
                    fd.append('action', 'update');
                    fd.append('id', id);
                    fd.append('content', content);
                    fd.append('memory_type', 'rule');
                    fd.append('is_active', isActive ? '0' : '1');

                    const r = await fetch(PM_ENDPOINT, { method:'POST', credentials:'same-origin', body:fd });
                    const j = await r.json();
                    if (!r.ok || !j.ok) throw new Error(j.error || 'HTTP ' + r.status);

                    showToast('✅ Actualizado', isActive ? 'Memoria desactivada.' : 'Memoria activada.', 'success');
                    await loadProceduralMemories();
                } catch (e) {
                    showToast('⚠️ Error', e.message, 'danger');
                }
            });
        });

        list.querySelectorAll('.pm-btn-delete').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id = btn.dataset.id;
                if (!confirm('¿Eliminar esta memoria procedural? Esta acción no se puede deshacer.')) return;

                btn.disabled = true;
                try {
                    const fd = new FormData();
                    fd.append('action', 'delete');
                    fd.append('id', id);

                    const r = await fetch(PM_ENDPOINT, { method:'POST', credentials:'same-origin', body:fd });
                    const j = await r.json();
                    if (!r.ok || !j.ok) throw new Error(j.error || 'HTTP ' + r.status);

                    showToast('🗑️ Eliminado', 'Memoria eliminada.', 'success');
                    await loadProceduralMemories();
                } catch (e) {
                    showToast('⚠️ Error', e.message, 'danger');
                    btn.disabled = false;
                }
            });
        });

    } catch (e) {
        console.error('Error cargando memorias:', e);
        list.innerHTML = `<div style="text-align:center; color:var(--danger); padding:30px;">Error: ${escapeHtml(e.message)}</div>`;
    }
}

// Abrir modal de memoria procedural
const btnOpenPM = document.getElementById('btnOpenProceduralMemory');
if (btnOpenPM) {
    btnOpenPM.addEventListener('click', () => {
        jQuery('#settings-modal').modal('hide');
        jQuery('#modalProceduralMemory').modal('show');
        loadProceduralMemories();
    });
}

// Agregar nueva memoria
const pmBtnAdd = document.getElementById('pmBtnAdd');
if (pmBtnAdd) {
    pmBtnAdd.addEventListener('click', async () => {
        const contentEl = document.getElementById('pmNewContent');
        const typeEl = document.getElementById('pmNewType');
        const content = contentEl ? contentEl.value.trim() : '';
        const type = typeEl ? typeEl.value : 'rule';

        if (content.length < 10) {
            showToast('⚠️ Error', 'Escribe al menos 10 caracteres.', 'warning');
            if (contentEl) contentEl.focus();
            return;
        }

        pmBtnAdd.disabled = true;
        pmBtnAdd.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

        try {
            const fd = new FormData();
            fd.append('action', 'create');
            fd.append('content', content);
            fd.append('memory_type', type);

            const r = await fetch(PM_ENDPOINT, { method:'POST', credentials:'same-origin', body:fd });
            const j = await r.json();
            if (!r.ok || !j.ok) throw new Error(j.error || 'HTTP ' + r.status);

            showToast('✅ Creada', 'Memoria procedural agregada.', 'success');
            if (contentEl) contentEl.value = '';
            await loadProceduralMemories();
        } catch (e) {
            showToast('⚠️ Error', e.message, 'danger');
        } finally {
            pmBtnAdd.disabled = false;
            pmBtnAdd.innerHTML = '<i class="fas fa-save mr-1"></i> Agregar';
        }
    });
}

// =====================================================================
// 🔄 FORZAR RE-ANÁLISIS DE MEMORIA PROCEDURAL
// =====================================================================
const btnForceProcedural = document.getElementById('btnForceProceduralExtraction');
if (btnForceProcedural) {
    btnForceProcedural.addEventListener('click', async () => {
        const statusEl = document.getElementById('proceduralExtractionStatus');
        btnForceProcedural.disabled = true;
        btnForceProcedural.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Analizando...';
        if (statusEl) statusEl.textContent = 'Analizando todas tus sesiones para detectar patrones...';

        try {
            const fd = new FormData();
            const r = await fetch('force_procedural_extraction.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            });
            const j = await r.json();
            if (!r.ok || !j.ok) throw new Error(j.error || 'HTTP ' + r.status);

            showToast('✅ Análisis completado', j.mensaje, 'success');
            if (statusEl) statusEl.textContent = j.mensaje;

            // Recargar la lista si el modal está abierto
            const pmList = document.getElementById('pmList');
            if (pmList && pmList.offsetParent !== null) {
                await loadProceduralMemories();
            }
        } catch (e) {
            showToast('⚠️ Error', e.message, 'danger');
            if (statusEl) statusEl.textContent = 'Error: ' + e.message;
        } finally {
            btnForceProcedural.disabled = false;
            btnForceProcedural.innerHTML = '<i class="fas fa-sync-alt mr-1"></i> Re-analizar todas las sesiones';
        }
    });
}

// =====================================================================
// UTILIDADES EXPORTADAS PARA OTROS MÓDULOS
// =====================================================================
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showToast(title, message, type = 'info') {
    const container = document.getElementById('chatToasts') || document.getElementById('incomingToasts');
    if (!container) {
        alert(`${title}: ${message}`);
        return;
    }

    const toast = document.createElement('div');
    toast.className = 'chat-toast';
    toast.innerHTML = `
        <div class="ct-title">${title}</div>
        <div class="small">${message}</div>
        <div class="ct-actions">
            <button class="ct-close" onclick="this.closest('.chat-toast').remove()">✕</button>
        </div>
    `;

    if (type === 'success') toast.style.borderLeftColor = '#00ff66';
    if (type === 'warning') toast.style.borderLeftColor = '#ffd861';
    if (type === 'danger') toast.style.borderLeftColor = '#ff5a5a';
    if (type === 'info') toast.style.borderLeftColor = '#17a2b8';

    container.appendChild(toast);
    setTimeout(() => { if (toast.parentNode) toast.remove(); }, 8000);
}

function getCurrentProjectId() {
    const projectSelect = document.getElementById('chat2Project');
    if (projectSelect && projectSelect.value) return parseInt(projectSelect.value);
    if (typeof window.currentProjectId !== 'undefined') return parseInt(window.currentProjectId);
    return 0;
}

function getCurrentSessionId() {
    // 1) Variable local del IIFE (capturada por closure - esta SÍ tiene el valor real)
    if (typeof currentSessionId !== 'undefined' && currentSessionId) {
        return parseInt(currentSessionId);
    }
    // 2) Sesión libre activa en el sidebar principal
    const freeActive = document.querySelector('#sbChatList .sb-item.active');
    if (freeActive && freeActive.getAttribute('data-id')) {
        return parseInt(freeActive.getAttribute('data-id'), 10);
    }
    // 3) ✅ NUEVO: Sesión activa DENTRO de un proyecto
    const projActive = document.querySelector('#sbProjectList .project-session.active');
    if (projActive && projActive.getAttribute('data-id')) {
        return parseInt(projActive.getAttribute('data-id'), 10);
    }
    // 4) Badge
    const badge = document.getElementById('chat2SessionBadge');
    if (badge && badge.dataset && badge.dataset.sessionId) {
        return parseInt(badge.dataset.sessionId, 10);
    }
    // 5) Fallback global
    if (typeof window.currentSessionId !== 'undefined' && window.currentSessionId) {
        return parseInt(window.currentSessionId);
    }
    return 0;
}

// Exportar funciones utilitarias para otros módulos
window.chatUtils = {
    escapeHtml: escapeHtml,
    showToast: showToast,
    getCurrentProjectId: getCurrentProjectId,
    getCurrentSessionId: getCurrentSessionId,
    getUserId: getUserId,
    fmtDate: fmtDate,
    buildS3Url: buildS3Url,
    mdToHtml: mdToHtml
};
  // === FIN DEL ARCHIVO chat.js CORREGIDO ===
  }); // cierra DOMContentLoaded
})(); // cierra IIFE
