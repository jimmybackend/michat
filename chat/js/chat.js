(function () {
  'use strict';
  window.addEventListener('DOMContentLoaded', () => {
    if (!document.getElementById('pane-Chat2')) return;
    const API = {
      send: 'bedrock_chat2.php',
      sessions: 'chat2_sessions.php',
      createSession: 'chat2_session_create.php',
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
      attachmentMode: 'session_attachment_mode.php'
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
      model: $('#chat2Model'),
      auto: $('#chat2Auto'),
      temp: $('#chat2Temp'),
      max: $('#chat2Max'),
      topP: $('#chat2TopP'),
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
    let fileIdSeq = 1;
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
    function toJSONorThrow(text, status, label) {
      try { return JSON.parse(text); }
      catch {
        throw new Error(`${label} no devolvió JSON (HTTP ${status}). Respuesta: ${text.slice(0, 280)}`);
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
      const model = el.model ? String(el.model.value || '').trim() : '';
      if (!model) {
        setStatus('Selecciona un modelo antes de continuar.');
        if (el.model) el.model.focus();
        return '';
      }
      return model;
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
  tempMd = tempMd.replace(/<thinking>([\s\S]*?)<\/thinking>/gi, (match, content) => {
    const index = thinkingBlocks.length;
    const safeContent = mdSafe(content.trim()).replace(/\n/g, '<br>');
    thinkingBlocks.push(`<div class="thinking-block"><i class="fas fa-brain"></i> <strong>Pensamiento:</strong><br>${safeContent}</div>`);
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


function pushLocal(role, content, opts = {}) {
  const ct = opts.content_type || 'text';
  const timeHtml = opts.created_at ? `<div class="msg-time">${esc(fmtDate(opts.created_at))}</div>` : '';
  let html = '';
  const msgId = opts.message_id || '';
  const isPrimordial = opts.is_primordial == 1 || opts.is_primordial === true;
  
  // Botón primordial solo para asistente
  const primordialBtn = (role === 'assistant' && msgId) ? `
    <button class="btn-primordial ${isPrimordial ? 'active' : ''}" 
            data-msg-id="${msgId}" 
            title="${isPrimordial ? 'Quitar de primordiales (verdad absoluta)' : 'Marcar como primordial (verdad absoluta)'}"
            style="float:right; background:none; border:1px solid #ffc107; color:${isPrimordial ? '#ffc107' : '#ccc'}; 
                   padding:2px 8px; font-size:0.7rem; border-radius:4px; cursor:pointer; margin-bottom: 4px; transition: all 0.2s;">
      <i class="fas fa-${isPrimordial ? 'star' : 'star-o'}"></i> ${isPrimordial ? 'Primordial' : 'Marcar'}
    </button>
  ` : '';
  
  // Botones de acción solo para asistente
  const actionsHtml = (role === 'assistant') ? renderMessageActionsHtml(msgId) : '';
  
  if (ct === 'image' && (opts.s3_key || opts.thumb_s3_key)) {
    const imgUrl = buildS3Url(opts.thumb_s3_key || opts.s3_key);
    const fullUrl = buildS3Url(opts.s3_key || opts.thumb_s3_key);
    const alignClass = role === 'assistant' ? 'align-right' : 'align-left';
    html = `<div class="chat-msg ${role === 'assistant' ? 'assistant chat-assistant' : 'user chat-user'} ${alignClass}">
      <div class="msg-header"><strong>${role === 'assistant' ? 'Asistente' : 'Tú'}</strong></div>
      ${content ? `<div class="msg-content">${esc(content)}</div>` : ''}
      <a href="${fullUrl}" target="_blank" rel="noopener"><img src="${imgUrl}" alt="imagen" style="max-width:320px; border-radius:8px; margin-top:.35rem;"></a>
      ${timeHtml}
      ${actionsHtml}
    </div>`;
  } else if (ct === 'video' && opts.s3_key) {
    const vidUrl = buildS3Url(opts.s3_key);
    const alignClass = role === 'assistant' ? 'align-right' : 'align-left';
    html = `<div class="chat-msg ${role === 'assistant' ? 'assistant chat-assistant' : 'user chat-user'} ${alignClass}">
      <div class="msg-header"><strong>${role === 'assistant' ? 'Asistente' : 'Tú'}</strong></div>
      ${content ? `<div class="msg-content">${esc(content)}</div>` : ''}
      <video controls style="max-width:420px; margin-top:.35rem;" preload="metadata">
        <source src="${vidUrl}" type="${esc(opts.mime_type || 'video/mp4')}">
        Tu navegador no soporta video embebido. <a href="${vidUrl}" target="_blank" rel="noopener">Descargar</a>
      </video>
      ${timeHtml}
      ${actionsHtml}
    </div>`;
  } else if (ct === 'audio' && opts.s3_key) {
    const aUrl = buildS3Url(opts.s3_key);
    const alignClass = role === 'assistant' ? 'align-right' : 'align-left';
    html = `<div class="chat-msg ${role === 'assistant' ? 'assistant chat-assistant' : 'user chat-user'} ${alignClass}">
      <div class="msg-header"><strong>${role === 'assistant' ? 'Asistente' : 'Tú'}</strong></div>
      ${content ? `<div class="msg-content">${esc(content)}</div>` : ''}
      <audio controls style="width:320px; margin-top:.35rem;">
        <source src="${aUrl}" type="${esc(opts.mime_type || 'audio/mpeg')}">
        <a href="${aUrl}" target="_blank" rel="noopener">Descargar audio</a>
      </audio>
      ${timeHtml}
      ${actionsHtml}
    </div>`;
  } else {
    if (role === 'assistant') {
      html = `<div class="chat-msg assistant chat-assistant align-right">
        ${primordialBtn}
        <div class="chat-md">${mdToHtml(content || '')}</div>${timeHtml}
        ${actionsHtml}
      </div>`;
    }
    else if (role === 'system') {
      html = `<div class="chat-msg system chat-system align-left" style="background: rgba(255, 193, 7, 0.08); border-left: 3px solid #ffc107; padding: 10px; border-radius: 6px; margin: 10px 0; font-size: 0.9em; color: #e0e0e0;">
        <div class="msg-header" style="font-weight: bold; color: #ffc107; margin-bottom: 6px; font-size: 0.85rem;">
          <i class="fas fa-magic"></i> Prompt optimizado por IA:
        </div>
        <div class="msg-content" style="font-style: italic; opacity: 0.9;">${mdToHtml(content || '')}</div>
        ${timeHtml}
      </div>`;
    } 
    else {
      html = `<div class="chat-msg user chat-user align-left">
        <div class="msg-header"><strong>Tú</strong></div>
        <div class="msg-content">${esc(content || '').replace(/\n/g, '<br>')}</div>
        ${timeHtml}
      </div>`;
    }
  }
  el.messages.insertAdjacentHTML('beforeend', html);
  
  // ✅ CORRECCIÓN: Activar botones para TODOS los mensajes del asistente
  if (role === 'assistant') {
    wireMessageActions(el.messages.lastElementChild);
  }
  
  wireCodeCopyButtons(el.messages);
  scrollMessagesToBottom();
}
function renderMessageActionsHtml(msgId = '') {
  const branchAttr = msgId ? `data-msg-id="${esc(msgId)}"` : '';
  return `<div class="message-actions">
    <button class="action-btn" data-action="copy" title="Copiar">
      <i class="fas fa-copy"></i> <span class="action-btn-label">Copiar</span>
    </button>
    <button class="action-btn" data-action="speak" title="Escuchar">
      <i class="fas fa-volume-up"></i> <span class="action-btn-label">Escuchar</span>
    </button>
    <button class="action-btn" data-action="share" title="Compartir">
      <i class="fas fa-share-alt"></i> <span class="action-btn-label">Compartir</span>
    </button>
    <button class="action-btn" data-action="branch" ${branchAttr} title="Crear rama desde aquí">
      <i class="fas fa-code-branch"></i> <span class="action-btn-label">Rama</span>
    </button>
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
  const msgId = msgDiv.querySelector('[data-msg-id]')?.dataset?.msgId || '';
  const allMessages = Array.from(el.messages.children);
  const cutIndex = allMessages.indexOf(msgDiv);
  const upTo = cutIndex === -1 ? allMessages : allMessages.slice(0, cutIndex + 1);
  const history = upTo.map(m => {
    const isUser = m.classList.contains('chat-user');
    const isAssistant = m.classList.contains('chat-assistant');
    if (!isUser && !isAssistant) return null;
    const textNode = isAssistant ? m.querySelector('.chat-md') : (m.querySelector('.msg-content') || m.firstElementChild);
    const text = (textNode ? textNode.innerText : '').trim();
    if (!text) return null;
    return { role: isUser ? 'user' : 'assistant', content: text };
  }).filter(Boolean);
  const model = requireModelSelected();
  if (!model) return;
  const currentTitle = (el.title && el.title.textContent) || 'Conversación';
  const newTitle = currentTitle + ' (rama)';
  showActionToast('Creando rama…');
  try {
    const fd = new FormData();
    fd.append('title', newTitle);
    fd.append('parent_message_id', msgId);
    const uid = getUserId();
    if (uid) fd.append('user_id', uid);
    fd.append('model', model);
    if (currentProjectId) fd.append('project_id', String(currentProjectId));
    const r = await fetch(API.createSession, { method: 'POST', credentials: 'same-origin', body: fd });
    const j = toJSONorThrow(await r.text(), r.status, 'Crear rama');
    if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);
    currentSessionId = j.id;
    await loadSessions();
    if (el.title) el.title.textContent = newTitle;
    if (el.badge) { el.badge.textContent = ''; el.badge.classList.add('d-none'); }
    if (el.restore) el.restore.classList.add('d-none');
    if (el.archive) el.archive.classList.remove('d-none');
    el.messages.innerHTML = '';
    history.forEach(h => pushLocal(h.role, h.content, { created_at: new Date().toISOString() }));
    showActionToast('✅ Rama creada — sigue la conversación desde aquí');
  } catch (e) {
    console.error('Error creando rama:', e);
    showActionToast('❌ No se pudo crear la rama: ' + e.message);
  }
}
function wireMessageActions(msgDiv) {
  if (!msgDiv) return;
  const bar = msgDiv.querySelector('.message-actions');
  if (!bar) return;
  bar.querySelectorAll('.action-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const action = btn.dataset.action;
      const textNode = msgDiv.querySelector('.chat-md');
      const text = textNode ? textNode.innerText.trim() : '';
      if (action === 'copy') copyMessageText(text, btn);
      else if (action === 'speak') speakMessageText(text, btn);
      else if (action === 'share') shareMessageText(text);
      else if (action === 'branch') branchFromMessage(msgDiv);
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



    async function createSession(title) {
      setStatus('Creando sesión…');
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
      currentSessionId = id;
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
    pushLocal(m.role || 'assistant', m.content || '', {
      message_id: m.id_ || m.id,               
      is_primordial: (m.is_primordial == 1 || m.is_primordial === true),
      content_type: m.content_type || 'text',
      s3_key: m.s3_key || null,
      mime_type: m.mime_type || null,
      thumb_s3_key: m.thumb_s3_key || null,
      created_at: m.created_at || null,
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
  semanticFile: 'semantic_session_file.php', // resume con IA + guarda embedding inmediato
   inspector:    'session_attachment_viewer.php' //stat de adjuntos 
};

const CODE_EXTS = ['php','phtml','inc','js','mjs','cjs','jsx','ts','tsx','css','scss','less',
  'html','htm','json','xml','yaml','yml','ini','conf','cfg','txt','md','markdown','sql',
  'sh','bash','zsh','bat','cmd','ps1','py','rb','java','c','h','cpp','hpp','cs','go','rs',
  'swift','kt','kts','vue','csv','tsv','log','srt','vtt','env','gitignore','htaccess'];

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
    
function showPromptApprovalModal(compiledPrompt, compilationId) {
    return new Promise((resolve) => {
        const originalLength = compiledPrompt.length;
        const isEnriched = originalLength > 100 && !compiledPrompt.startsWith("Por favor, responde de manera detallada");
        const warningHtml = !isEnriched ? `
            <div class="alert alert-warning small" role="alert">
                <i class="fas fa-exclamation-triangle"></i> 
                <strong>Advertencia:</strong> El prompt parece no haber sido enriquecido por la IA. 
                Te recomiendo editarlo manualmente para agregar más contexto y detalles antes de aprobarlo.
            </div>
        ` : '';
        const modalHtml = `
        <div class="modal fade" id="promptApprovalModal" tabindex="-1" role="dialog">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">💡 Prompt Optimizado por IA</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small">
                            He optimizado tu pregunta incorporando el contexto de la sesión, las instrucciones del proyecto 
                            y los fragmentos de código relevantes. <strong>Puedes editarlo antes de enviarlo</strong> o aprobarlo tal cual.
                        </p>
                        ${warningHtml}
                        <div class="form-group">
                            <label for="compiledPromptText">
                                Prompt compilado ${isEnriched ? '(✅ enriquecido)' : '(⚠️ sin enriquecer - edita antes de aprobar)'}:
                            </label>
                            <textarea class="form-control" id="compiledPromptText" rows="12" 
                                style="font-family: monospace; font-size: 0.85rem;">${esc(compiledPrompt)}</textarea>
                            <small class="form-text text-muted">
                                Longitud: ${originalLength} caracteres
                            </small>
                        </div>
                        <div class="alert alert-info small" role="alert">
                            <i class="fas fa-info-circle"></i> 
                            Este prompt se enviará al modelo de IA para generar la respuesta. 
                            Editarlo puede mejorar o empeorar los resultados.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" id="btnCancelPrompt" data-dismiss="modal">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                        <button type="button" class="btn btn-primary" id="btnApprovePrompt">
                            <i class="fas fa-check"></i> Aprobar y Enviar
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
        const btnApprove = document.getElementById('btnApprovePrompt');
        const btnCancel = document.getElementById('btnCancelPrompt');
        jQuery(modal).modal('show');
        btnApprove.addEventListener('click', () => {
            const finalPrompt = textarea.value.trim();
            if (!finalPrompt) {
                alert('El prompt no puede estar vacío');
                return;
            }
            jQuery(modal).modal('hide');
            resolve({
                prompt: finalPrompt,
                compilation_id: compilationId
            });
        });
        btnCancel.addEventListener('click', () => {
            jQuery(modal).modal('hide');
            resolve(null);
        });
        jQuery(modal).on('hidden.bs.modal', () => {
            modalContainer.remove();
        });
    });
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
          await loadSessions();
        } catch (e) {
          pushLocal('assistant', '⚠️ Error creando sesión: ' + e.message);
          return;
        }
      }
const editMatch = text.match(/(?:edita|modifica|cambia|actualiza)\s+(?:el\s+archivo\s+)?([a-zA-Z0-9_.-]+\.[a-zA-Z0-9]+)\s+(?:para\s+|y\s+)?(.+)/i);
const createMatch = text.match(/(?:crea|crear|genera|generar|haz|has)\s+(?:un\s+)?(?:archivo|clase|módulo|script)\s+(?:llamado|denominado|con\s+nombre|nombrado)?\s*([a-zA-Z0-9_.-]+\.[a-zA-Z0-9]+)\s+(?:en\s+la\s+(?:raíz|carpeta)\s+del\s+proyecto\s+)?(?:con|que\s+(?:tenga|contenga|tenga)|para)\s+(.+)/i);
if ((editMatch || createMatch) && currentProjectId) {
  const match = editMatch || createMatch;
  const targetFilename = match[1];
  const instruction = match[2];
  const isCreation = !!createMatch;
  isSending = true;
  setStatus(`🔪 ${isCreation ? 'Creando' : 'Editando'} ${targetFilename}…`);
  pushLocal('user', text, { created_at: new Date().toISOString() });
  try {
    const fd = new FormData();
    fd.append('session_id', String(currentSessionId));
    fd.append('project_id', String(currentProjectId));
    fd.append('target_filename', targetFilename);
    fd.append('instruction', instruction);
    const r = await fetch('code_edit.php', { 
      method:'POST', 
      credentials:'same-origin', 
      body: fd 
    });
    const j = toJSONorThrow(await r.text(), r.status, isCreation ? 'Crear código' : 'Editar código');
    if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);
    const action = isCreation ? 'creado' : 'actualizado';
    const replyText = `✅ **${j.filename}** ${action} exitosamente (versión **v${j.new_version}**).\n\n📝 *Instrucción:* ${esc(j.diff_summary)}\n\n🤖 *Modelo usado:* \`${j.model_used || 'desconocido'}\`\n\n📂 *Ruta S3:* \`${j.download_url || 'N/A'}\``;
    
    // ✅ GUARDAR PREGUNTA Y RESPUESTA EN BD (ChatMessages)
try {
    const fdSave = new FormData();
    fdSave.append('session_id', String(currentSessionId));
    fdSave.append('user_text', text);
    fdSave.append('reply_text', replyText);
    fdSave.append('model_used', j.model_used || 'code_edit_direct');

    const rSave = await fetch('chat_save_edit.php', {
        method: 'POST',
        credentials: 'same-origin',
        body: fdSave
    });

    if (rSave.ok) {
        const jSave = await rSave.json();
        if (jSave.ok && jSave.saved) {
            console.log('✅ Edición guardada en BD:', jSave.saved);
        }
    } else {
        console.warn('⚠️ No se pudo guardar la edición en BD. HTTP:', rSave.status);
    }
} catch (saveErr) {
    console.warn('⚠️ Error guardando edición en BD:', saveErr);
    // No bloquear por esto
}
    
    pushLocal('assistant', replyText, { created_at: new Date().toISOString() });
    await loadProjectSources(currentProjectId);
    setStatus('🔄 Actualizando índice de conocimientos...');
    const fdIndex = new FormData();
    fdIndex.append('project_id', String(currentProjectId));
    fetch('index_project_sources.php', { method: 'POST', credentials: 'same-origin', body: fdIndex })
      .then(async (res) => {
        try {
          const jIdx = await res.json();
          if (jIdx.ok) {
            setStatus(`✅ Índice actualizado (${jIdx.indexed_count || 1} archivo procesado).`);
            setTimeout(() => setStatus(''), 4000);
            await loadProjectSources(currentProjectId);
          }
        } catch (err) {
          console.error('Error parseando indexación:', err);
          setStatus('');
        }
      })
      .catch(err => {
        console.error('Error indexando:', err);
        setStatus('');
      });
  } catch (e) {
    console.error(e);
    pushLocal('assistant', '⚠️ Error al procesar el archivo: ' + e.message);
  } finally {
    setStatus('');
    isSending = false;
    if (el.input) el.input.value = '';
    clearQueue();
  }
  return; 
}
      isSending = true;
      setStatus('Compilando prompt…');
      pushLocal('user', text, { created_at: new Date().toISOString() });
      try {
        const fdCompile = new FormData();
        fdCompile.append('session_id', String(currentSessionId));
        const uid = getUserId();
        if (uid) fdCompile.append('user_id', uid);
        fdCompile.append('text', text);
        fdCompile.append('auto', auto ? '1' : '0');
        fdCompile.append('model', model);
        fdCompile.append('temperature', String(temperature));
        fdCompile.append('max_tokens', String(max_tokens));
        fdCompile.append('top_p', String(top_p));
        fdCompile.append('compile_only', '1');
        if (pendingFiles.length > 0) {
          pendingFiles.forEach(({file}) => fdCompile.append('files[]', file, file.name));
        }
        const rCompile = await fetch(API.send, { method:'POST', credentials:'same-origin', body: fdCompile });
        const jCompile = toJSONorThrow(await rCompile.text(), rCompile.status, 'Compilar prompt');
        if (!rCompile.ok || jCompile.ok === false) throw new Error(jCompile.error || `HTTP ${rCompile.status}`);
        if (jCompile.phase === 'compile_only' && jCompile.compiled_prompt) {
          const approved = await showPromptApprovalModal(jCompile.compiled_prompt, jCompile.compilation_id);
          if (!approved) {
            setStatus('');
            isSending = false;
            const lastMsg = el.messages.lastElementChild;
            if (lastMsg && lastMsg.classList.contains('chat-user')) {
              lastMsg.remove();
            }
            return; 
          }
         /* 
          const lastUserMsg = el.messages.querySelector('.chat-msg.user:last-child');
          if (lastUserMsg) {
            const contentDiv = lastUserMsg.querySelector('div > div') || lastUserMsg.querySelector('div');
            if (contentDiv) {
              contentDiv.innerHTML = mdToHtml(approved.prompt);
            }
          }*/
const lastUserMsg = el.messages.querySelector('.chat-msg.user:last-child');
if (lastUserMsg) {
  lastUserMsg.remove();
}
          setStatus('Generando respuesta…');
          const fdRespond = new FormData();
          fdRespond.append('session_id', String(currentSessionId));
          if (uid) fdRespond.append('user_id', uid);
          fdRespond.append('text', text); 
          fdRespond.append('compiled_prompt', approved.prompt); 
          fdRespond.append('compilation_id', String(approved.compilation_id));
          fdRespond.append('model', model);
          fdRespond.append('temperature', String(temperature));
          fdRespond.append('max_tokens', String(max_tokens));
          fdRespond.append('top_p', String(top_p));
          const rRespond = await fetch(API.send, { method:'POST', credentials:'same-origin', body: fdRespond });
          const jRespond = toJSONorThrow(await rRespond.text(), rRespond.status, 'Enviar mensaje');
          if (!rRespond.ok || jRespond.ok === false) throw new Error(jRespond.error || `HTTP ${rRespond.status}`);
          if (jRespond.reply) {
            pushLocal('assistant', jRespond.reply, { created_at: new Date().toISOString() });
          }
          if (currentProjectId && jRespond.reply && (jRespond.reply.includes('actualizado') || jRespond.reply.includes('modificado') || jRespond.needs_indexing)) {
            setStatus('🔄 Actualizando índice de conocimientos...');
            const fdIndex = new FormData();
            fdIndex.append('project_id', String(currentProjectId));
            fetch('index_project_sources.php', { method: 'POST', credentials: 'same-origin', body: fdIndex })
              .then(async (res) => {
                try {
                  const j = await res.json();
                  if (j.ok) {
                    setStatus(`✅ Índice actualizado (${j.indexed_count || 1} archivo procesado). La IA ya puede leer los cambios.`);
                    setTimeout(() => setStatus(''), 4000);
                    await loadProjectSources(currentProjectId);
                  }
                } catch (err) {
                  console.error('Error parseando respuesta de indexación:', err);
                  setStatus('');
                }
              })
              .catch(err => {
                console.error('Error indexando en segundo plano:', err);
                setStatus('');
              });
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
        } else {
          if (jCompile.reply) {
            pushLocal('assistant', jCompile.reply, { created_at: new Date().toISOString() });
          }
          await selectSession(currentSessionId);
        }
        if (el.input) el.input.value = '';
        clearQueue();
      } catch (e) {
        console.error(e);
        pushLocal('assistant', '⚠️ Error: ' + e.message);
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
    if (j.indexed_count > 0) {
      setStatus(`✅ ${j.indexed_count} archivo(s) indexado(s)`);
      setTimeout(() => setStatus(''), 3000);
    } else {
      setStatus('ℹ️ No hay archivos pendientes por indexar');
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
    if (j.indexed_count > 0) {
      alert(`✅ ¡Éxito! Se indexaron ${j.indexed_count} archivo(s).\n\nAhora la IA puede buscar en su contenido.`);
    } else {
      alert('ℹ️ No se encontraron archivos pendientes por indexar.');
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
          currentSessionId = created.id;
          await loadSessions();
          await selectSession(currentSessionId);
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
          await loadSessions();
        }
        const prompt = (el.input && el.input.value.trim()) || window.prompt('Prompt de video:') || '';
        if (!prompt) return;
        await autoGenerateVideo(prompt);
      });
    }
    if (el.newBtn) {
      el.newBtn.addEventListener('click', async () => {
        try {
          const created = await createSession('Nueva conversación (Auto)');
          currentSessionId = created.id;
          await loadSessions();
          await selectSession(currentSessionId);
          el.input && el.input.focus();
        } catch (e) {
          pushLocal('assistant', '⚠️ No se pudo crear la sesión: ' + e.message);
        }
      });
    }
    if (el.reload) el.reload.addEventListener('click', () => loadSessions());
    if (el.search) el.search.addEventListener('input', () => loadSessions());
    if (el.showArchived) el.showArchived.addEventListener('change', () => loadSessions());
    if (el.rename) el.rename.addEventListener('click', () => currentSessionId && promptRename(currentSessionId));
    if (el.archive) el.archive.addEventListener('click', () => currentSessionId && doArchive(currentSessionId));
    if (el.restore) el.restore.addEventListener('click', () => currentSessionId && doRestore(currentSessionId));
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

    if (countEl) {
      countEl.textContent = String(files.length);
    }

    if (!files.length) {
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

            <button type="button" class="chat-file-action" title="Indexar archivo"
              data-action="index" data-file-id="${fileId}">
              <i class="fas fa-search"></i>
            </button>

            <button type="button" class="chat-file-action" title="Crear semántica"
              data-action="semantic" data-file-id="${fileId}">
              <i class="fas fa-brain"></i>
            </button>

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
    console.error(e);
    target.innerHTML = `<div class="text-danger small">${esc(e.message)}</div>`;
    if (countEl) countEl.textContent = '0';
  }
}


async function deleteSessionAttachment(attachmentId) {
  if (!confirm('¿Eliminar este adjunto de la sesión?')) {
    return;
  }
  try {
    const fd = new FormData();
    fd.append('attachment_id', attachmentId);
    const r = await fetch('session_attachments.php?action=remove', {
      method: 'POST',
      credentials: 'same-origin',
      body: fd
    });
    const j = toJSONorThrow(await r.text(), r.status, 'Eliminar adjunto');
    if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);
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
        // El status ahora es siempre 'indexed' ya que FileS3 existe
        const statusClass = 'indexed';
        const statusText = 'Indexado';
        const badgeClass = 'success';
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
    btnIndexSessionAttachments.addEventListener('click', () => {
      alert('Funcionalidad de indexación pendiente de implementar');
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
