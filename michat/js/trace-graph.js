(function () {
  'use strict';

  const cfg = window.TRACE_GRAPH_CONFIG || {};
  const API = cfg.api || 'chat_trace_api.php';
  const EDIT_API = cfg.editApi || 'trace_node_edit_api.php';
  const METRICS_API = cfg.metricsApi || 'trace_metrics_api.php';

  const el = {
    svg: document.getElementById('traceGraphSvg'),
    viewport: document.getElementById('graphViewport'),
    wrap: document.getElementById('graphCanvasWrap'),
    loading: document.getElementById('graphLoading'),
    error: document.getElementById('graphError'),
    stats: document.getElementById('graphStats'),
    subtitle: document.getElementById('graphSubtitle'),
    responseLabel: document.getElementById('graphResponseLabel'),
    fit: document.getElementById('graphFit'),
    zoomIn: document.getElementById('graphZoomIn'),
    zoomOut: document.getElementById('graphZoomOut'),
    modeToggle: document.getElementById('graphModeToggle'),
    processLink: document.getElementById('graphProcessLink'),
    jsonLink: document.getElementById('graphJsonLink'),
    backExplorer: document.getElementById('graphBackExplorer'),
    detailEmpty: document.getElementById('graphDetailEmpty'),
    detail: document.getElementById('graphDetail'),
    detailCategory: document.getElementById('graphDetailCategory'),
    detailStatus: document.getElementById('graphDetailStatus'),
    detailTitle: document.getElementById('graphDetailTitle'),
    detailSummary: document.getElementById('graphDetailSummary'),
    detailGrid: document.getElementById('graphDetailGrid'),
    detailActions: document.getElementById('graphDetailActions'),
    detailJson: document.getElementById('graphDetailJson'),
    editBackdrop: document.getElementById('graphEditBackdrop'),
    editModal: document.getElementById('graphEditModal'),
    editClose: document.getElementById('graphEditClose'),
    editCancel: document.getElementById('graphEditCancel'),
    editForm: document.getElementById('graphEditForm'),
    editTitle: document.getElementById('graphEditTitle'),
    editSubtitle: document.getElementById('graphEditSubtitle'),
    editWarning: document.getElementById('graphEditWarning'),
    editFields: document.getElementById('graphEditFields'),
    editStatus: document.getElementById('graphEditStatus'),
    editSave: document.getElementById('graphEditSave'),
    metricsToggle: document.getElementById('graphMetricsToggle'),
    metricsDrawer: document.getElementById('graphMetricsDrawer'),
    metricsClose: document.getElementById('graphMetricsClose'),
    metricsRefresh: document.getElementById('graphMetricsRefresh'),
    metricsMonth: document.getElementById('graphMetricsMonth'),
    metricsBody: document.getElementById('graphMetricsBody'),
    metricsNote: document.getElementById('graphMetricsNote')
  };

  const state = {
    data: null,
    mode: 'essential',
    model: null,
    selectedNodeId: null,
    transform: { x: 0, y: 0, scale: 1 },
    panning: false,
    panStart: null,
    editingNode: null,
    metricsScope: null,
    metricsLoading: false,
    metricsMonth: ''
  };

  const bridgeSubscribers = {
    model: new Set(),
    selection: new Set()
  };

  function emitBridge(kind, payload) {
    const listeners = bridgeSubscribers[kind];
    if (!listeners) return;
    listeners.forEach(listener => {
      try { listener(payload); } catch (error) { console.error('TraceGraphBridge listener:', error); }
    });
  }

  const NODE_W = 238;
  const NODE_H = 88;
  const RESOURCE_NODE_W = 480;
  const RESOURCE_NODE_H = 108;
  const RESOURCE_COLUMNS = 4;
  const RESOURCE_START_X = 110;
  const RESOURCE_GAP_X = 590;
  const RESOURCE_GAP_Y = 132;
  const TOP_Y = 120;
  const STEP_Y = 132;
  const LANE_X = {
    question: 1020,
    pipeline: 120,
    compiler: 300,
    router: 480,
    feature_flags: 650,
    ranking: 820,
    context: 990,
    retrieval: 1160,
    prompt: 1330,
    model: 1500,
    tool: 1670,
    memory: 1840,
    response: 2010,
    trace: 2180,
    answer: 2010
  };

  const LANE_LABELS = {
    pipeline: 'Pipeline',
    compiler: 'Compiler',
    router: 'Router',
    feature_flags: 'Flags',
    ranking: 'Ranking',
    context: 'Contexto',
    retrieval: 'RAG / Embed',
    prompt: 'Prompt',
    model: 'Modelo',
    tool: 'Tools',
    memory: 'Memoria',
    response: 'Respuesta',
    trace: 'Trace'
  };

  function esc(text) {
    return String(text == null ? '' : text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function short(text, max = 42) {
    const s = String(text || '').replace(/\s+/g, ' ').trim();
    return s.length > max ? s.slice(0, max - 1) + '…' : s;
  }

  function formatDate(value) {
    if (!value) return '—';
    const d = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return String(value);
    return d.toLocaleString('es-MX', {
      year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit'
    });
  }

  function formatMs(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return '—';
    if (n >= 1000) return (n / 1000).toFixed(n >= 10000 ? 1 : 2) + ' s';
    return Math.round(n) + ' ms';
  }

  function formatUsd(value, digits = 6) {
    const n = Number(value);
    if (!Number.isFinite(n)) return '—';
    return '$' + n.toFixed(digits);
  }

  function traceMonth(data = state.data) {
    const raw = String(data?.turn?.answer?.created_at || data?.trace?.started_at || '');
    const m = raw.match(/^(\d{4})-(\d{2})/);
    return m ? `${m[1]}-${m[2]}` : new Date().toISOString().slice(0, 7);
  }

  function metricsApiUrl(month = '') {
    const qs = new URLSearchParams({ session_id: String(Number(cfg.sessionId || state.data?.scope?.session?.id || 0)) });
    const userId = Number(cfg.userId || state.data?.scope?.target_user_id || 0);
    const projectId = Number(cfg.projectId || state.data?.scope?.project?.id || 0);
    if (userId > 0) qs.set('user_id', String(userId));
    if (projectId > 0) qs.set('project_id', String(projectId));
    if (month) qs.set('month', month);
    return `${METRICS_API}?${qs.toString()}`;
  }

  async function fetchScopeMetrics(month = '') {
    const response = await fetch(metricsApiUrl(month), { credentials: 'same-origin', cache: 'no-store' });
    const text = await response.text();
    let json;
    try { json = JSON.parse(text); }
    catch (_) { throw new Error(`Métricas devolvió contenido no JSON (HTTP ${response.status}): ${text.slice(0, 220)}`); }
    if (!response.ok || json.ok === false) throw new Error(json.error || `HTTP ${response.status}`);
    return json.data || {};
  }

  function traceApiUrl() {
    const qs = new URLSearchParams({ action: 'trace', session_id: String(Number(cfg.sessionId || 0)) });
    if (Number(cfg.userId || 0) > 0) qs.set('user_id', String(Number(cfg.userId)));
    if (String(cfg.traceId || '').trim()) qs.set('trace_id', String(cfg.traceId).trim());
    if (Number(cfg.answerMessageId || 0) > 0) qs.set('answer_message_id', String(Number(cfg.answerMessageId)));
    else if (Number(cfg.questionMessageId || 0) > 0) qs.set('question_message_id', String(Number(cfg.questionMessageId)));
    return `${API}?${qs.toString()}`;
  }

  async function fetchTrace() {
    const response = await fetch(traceApiUrl(), { credentials: 'same-origin', cache: 'no-store' });
    const text = await response.text();
    let json;
    try { json = JSON.parse(text); }
    catch (_) { throw new Error(`La API devolvió contenido no JSON (HTTP ${response.status}): ${text.slice(0, 240)}`); }
    if (!response.ok || json.ok === false) throw new Error(json.error || `HTTP ${response.status}`);
    return json.data || {};
  }



  const RESOURCE_LABELS = {
    project_memory: 'Memoria proyecto',
    procedural_memory: 'Memoria procedural',
    session_memory: 'Memoria sesión',
    question_memory: 'Q&A',
    project_rag: 'RAG proyecto',
    attachment: 'Adjunto'
  };

  const LIVE_RESOURCE_KEYS = {
    ProjectContext: 'project_context_live',
    UserProceduralMemory: 'procedural_memory_live',
    SessionContextBlocks: 'session_context_blocks_live',
    SourceChunks: 'source_chunks_live',
    ChatMessages: 'chat_messages_live',
    ChatSessions: 'chat_sessions_live'
  };


  const EDITABLE_LIVE_SOURCES = new Set([
    'ProjectContext',
    'UserProceduralMemory',
    'SessionContextBlocks',
    'SourceChunks',
    'ChatSessions'
  ]);

  const EDIT_SOURCE_LABELS = {
    ProjectContext: 'memoria estructurada del proyecto',
    UserProceduralMemory: 'memoria procedural',
    SessionContextBlocks: 'bloque de memoria de sesión',
    SourceChunks: 'chunk RAG indexado',
    ChatSessions: 'resumen maestro de sesión'
  };

  function resourceCategory(item) {
    const source = String(item?.source || '');
    const type = String(item?.type || '').toLowerCase();
    const scope = String(item?.scope || '').toLowerCase();
    const metadata = item && typeof item.metadata === 'object' && item.metadata ? item.metadata : {};

    if (source === 'ProjectContext') return 'project_memory';
    if (source === 'UserProceduralMemory') return 'procedural_memory';
    if (source === 'SourceChunks') return 'project_rag';
    if (source === 'ChatSessions' || source === 'ChatMessages') return 'session_memory';
    if (source === 'SessionContextBlocks') {
      if (type === 'qa_memory' || type === 'level_0' || metadata.question_msg_id || metadata.question_message_id) return 'question_memory';
      if (scope.includes('attachment') || type === 'file' || type === 'file_chunk' || metadata.filename) return 'attachment';
      return 'session_memory';
    }
    return 'session_memory';
  }

  function liveResourceFor(data, item) {
    const source = String(item?.source || '');
    const sourceId = Number(item?.source_id || 0);
    if (!sourceId) return null;
    const key = LIVE_RESOURCE_KEYS[source];
    if (!key) return null;
    const list = Array.isArray(data?.resources?.[key]) ? data.resources[key] : [];
    return list.find(row => Number(row?.id || 0) === sourceId) || null;
  }

  function missingLiveResource(data, item) {
    const source = String(item?.source || '');
    const sourceId = Number(item?.source_id || 0);
    const list = Array.isArray(data?.resources?.live_missing_records) ? data.resources.live_missing_records : [];
    return list.find(row => String(row?.source || '') === source && Number(row?.source_id || 0) === sourceId) || null;
  }

  function resourceTitle(item, category) {
    const id = Number(item?.source_id || 0);
    const type = String(item?.type || '').replace(/_/g, ' ');
    const meta = item && typeof item.metadata === 'object' && item.metadata ? item.metadata : {};
    if (category === 'project_memory') return `${type || 'Memoria'}${id ? ' #' + id : ''}`;
    if (category === 'procedural_memory') return `${type || 'Procedural'}${id ? ' #' + id : ''}`;
    if (category === 'question_memory') return `Q&A${id ? ' #' + id : ''}`;
    if (category === 'project_rag') return `Chunk${id ? ' #' + id : ''}${meta.filename ? ' · ' + short(meta.filename, 22) : ''}`;
    if (category === 'attachment') return `${type === 'file' ? 'Archivo' : 'Adjunto'}${id ? ' #' + id : ''}${meta.filename ? ' · ' + short(meta.filename, 22) : ''}`;
    return `${type || 'Contexto'}${id ? ' #' + id : ''}`;
  }

  function normalizeResourceNode(data, item, selected, index) {
    const category = resourceCategory(item);
    const live = liveResourceFor(data, item);
    const missing = !live ? missingLiveResource(data, item) : null;
    const score = item?.ranking_score != null ? Number(item.ranking_score) : (item?.score != null ? Number(item.score) : null);
    const rank = item?.rank != null ? Number(item.rank) : null;
    const changed = live?.changed_since_trace === true;
    const content = String(item?.content || item?.preview || item?.metadata?.raw_content || '').trim();
    const badges = [];
    if (rank != null && Number.isFinite(rank)) badges.push(`#${rank}`);
    if (score != null && Number.isFinite(score)) badges.push(score.toFixed(3));
    if (!selected && item?.exclusion_reason) badges.push(short(String(item.exclusion_reason).replace(/_/g, ' '), 24));
    if (changed) badges.push('cambió');
    if (missing) badges.push('ya no existe');
    if (live && EDITABLE_LIVE_SOURCES.has(String(item?.source || ''))) badges.push('editable');

    return {
      id: `resource:${selected ? 'used' : 'discarded'}:${String(item?.source || 'unknown')}:${String(item?.source_id ?? 'na')}:${String(item?.bucket || 'bucket')}:${index}`,
      kind: 'resource',
      category,
      status: missing ? 'error' : (selected ? 'completed' : 'info'),
      title: short(resourceTitle(item, category), 48),
      fullTitle: resourceTitle(item, category),
      subtitle: short(content || RESOURCE_LABELS[category] || String(item?.source || 'Recurso'), 76),
      meta: `${selected ? 'USADO' : 'DESCARTADO'}${badges.length ? ' · ' + badges.join(' · ') : ''}`,
      historical: item,
      live,
      missing,
      selectedResource: !!selected,
      changedSinceTrace: changed,
      resourceIndex: index,
      width: RESOURCE_NODE_W,
      height: RESOURCE_NODE_H
    };
  }

  function isEssentialEvent(event) {
    const key = String(event.event_key || '');
    const category = String(event.category || 'pipeline');
    const status = String(event.status || 'info');

    if (status === 'started' && key !== 'request_started') return false;
    if (['model_round_started', 'tool_started'].includes(key)) return false;
    if (key === 'approval_waiting') return true;
    if (key === 'input_prepared' || key === 'request_started' || key === 'qa_memory_prepared') return true;

    return [
      'compiler', 'router', 'feature_flags', 'ranking', 'context', 'retrieval',
      'prompt', 'model', 'tool', 'memory', 'response', 'trace'
    ].includes(category);
  }

  function normalizeNodeFromEvent(event, idx, order) {
    const category = String(event.category || 'pipeline');
    const title = event.title || event.event_key || `Evento ${idx + 1}`;
    const subtitleParts = [];
    if (event.model_id) subtitleParts.push(short(event.model_id, 36));
    if (event.duration_ms != null) subtitleParts.push(formatMs(event.duration_ms));
    if (!subtitleParts.length && event.summary) subtitleParts.push(short(event.summary, 44));

    return {
      id: `event:${event.id || idx}`,
      kind: 'event',
      category,
      status: String(event.status || 'info'),
      title: short(title, 36),
      fullTitle: String(title),
      subtitle: subtitleParts.join(' · ') || String(event.event_key || ''),
      meta: String(event.event_key || ''),
      event,
      order
    };
  }

  function buildGraphModel(data) {
    const events = Array.isArray(data.trace?.events) ? data.trace.events : [];
    const visibleEvents = state.mode === 'essential' ? events.filter(isEssentialEvent) : events.slice();
    const nodes = [];
    const edges = [];
    const sections = [];
    const q = data.turn?.question || null;
    const a = data.turn?.answer || null;

    if (q) {
      nodes.push({
        id: `question:${q.id}`,
        kind: 'message', category: 'question', status: 'completed',
        title: `Pregunta #${q.id}`,
        fullTitle: `Pregunta #${q.id}`,
        subtitle: short(q.content, 48),
        meta: formatDate(q.created_at),
        message: q,
        order: -1,
        width: NODE_W,
        height: NODE_H
      });
    }

    visibleEvents.forEach((event, index) => {
      const node = normalizeNodeFromEvent(event, index, index);
      node.width = NODE_W;
      node.height = NODE_H;
      nodes.push(node);
    });

    if (a) {
      nodes.push({
        id: `answer:${a.id}`,
        kind: 'message', category: 'answer', status: 'completed',
        title: `Respuesta #${a.id}`,
        fullTitle: `Respuesta #${a.id}`,
        subtitle: short(a.content, 48),
        meta: a.model_id ? short(a.model_id, 34) : formatDate(a.created_at),
        message: a,
        order: visibleEvents.length + 1,
        width: NODE_W,
        height: NODE_H
      });
    }

    // La línea principal sólo representa orden temporal real.
    const temporalNodes = nodes.slice();
    for (let i = 0; i < temporalNodes.length - 1; i++) {
      edges.push({
        id: `edge:timeline:${i}`,
        source: temporalNodes[i].id,
        target: temporalNodes[i + 1].id,
        kind: 'timeline',
        error: temporalNodes[i + 1].status === 'error'
      });
    }

    const minX = 70;
    temporalNodes.forEach((node, index) => {
      const cat = node.category;
      node.x = LANE_X[cat] != null ? LANE_X[cat] : LANE_X.pipeline;
      node.y = TOP_Y + (index * STEP_Y);
      node.x = Math.max(minX, node.x);
    });

    const resources = data.resources || {};
    const used = Array.isArray(resources.context_items_historical) ? resources.context_items_historical : [];
    const discardedAll = Array.isArray(resources.context_items_discarded_historical) ? resources.context_items_discarded_historical : [];
    const discarded = state.mode === 'full' ? discardedAll : [];
    const baseResourceY = TOP_Y + temporalNodes.length * STEP_Y + 155;

    const contextAnchor = temporalNodes.find(n => n.kind === 'event' && n.event?.event_key === 'context_builder_completed')
      || temporalNodes.find(n => n.category === 'context');
    const rankingAnchor = temporalNodes.find(n => n.kind === 'event' && n.event?.event_key === 'context_ranking_completed')
      || temporalNodes.find(n => n.category === 'ranking')
      || contextAnchor;

    if (used.length) {
      sections.push({ y: baseResourceY - 54, label: `CONTEXTO REAL UTILIZADO · ${used.length}`, kind: 'used' });
    }

    const usedNodes = used.map((item, index) => normalizeResourceNode(data, item, true, index));
    usedNodes.forEach((node, index) => {
      node.x = RESOURCE_START_X + ((index % RESOURCE_COLUMNS) * RESOURCE_GAP_X);
      node.y = baseResourceY + (Math.floor(index / RESOURCE_COLUMNS) * RESOURCE_GAP_Y);
      nodes.push(node);
      if (contextAnchor) {
        edges.push({
          id: `edge:resource:used:${index}`,
          source: contextAnchor.id,
          target: node.id,
          kind: 'relation',
          relation: 'selected_context',
          selectedResource: true
        });
      }
    });

    const usedRows = Math.ceil(Math.max(usedNodes.length, 1) / RESOURCE_COLUMNS);
    const discardedStartY = baseResourceY + (used.length ? usedRows * RESOURCE_GAP_Y + 118 : 0);
    if (discarded.length) {
      sections.push({ y: discardedStartY - 54, label: `RECUPERADO PERO DESCARTADO · ${discarded.length}`, kind: 'discarded' });
    }

    const discardedNodes = discarded.map((item, index) => normalizeResourceNode(data, item, false, index));
    discardedNodes.forEach((node, index) => {
      node.x = RESOURCE_START_X + ((index % RESOURCE_COLUMNS) * RESOURCE_GAP_X);
      node.y = discardedStartY + (Math.floor(index / RESOURCE_COLUMNS) * RESOURCE_GAP_Y);
      nodes.push(node);
      if (rankingAnchor) {
        edges.push({
          id: `edge:resource:discarded:${index}`,
          source: rankingAnchor.id,
          target: node.id,
          kind: 'relation',
          relation: 'discarded_context',
          selectedResource: false
        });
      }
    });

    const discardedRows = Math.ceil(discardedNodes.length / RESOURCE_COLUMNS);
    const resourceBottom = discardedNodes.length
      ? discardedStartY + discardedRows * RESOURCE_GAP_Y + 80
      : (usedNodes.length ? baseResourceY + usedRows * RESOURCE_GAP_Y + 80 : 0);
    const timelineBottom = TOP_Y + temporalNodes.length * STEP_Y + 180;
    const height = Math.max(720, timelineBottom, resourceBottom);
    const width = 2460;

    return {
      nodes, edges, sections, width, height,
      sourceEventCount: events.length,
      visibleEventCount: visibleEvents.length,
      usedResourceCount: used.length,
      discardedResourceCount: discardedAll.length,
      visibleDiscardedResourceCount: discarded.length
    };
  }

  function svg(tag, attrs = {}) {
    const node = document.createElementNS('http://www.w3.org/2000/svg', tag);
    Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, String(value)));
    return node;
  }

  function renderGraph() {
    if (!state.data) return;
    const model = buildGraphModel(state.data);
    state.model = model;
    state.selectedNodeId = null;
    emitBridge('selection', null);
    el.detail.hidden = true;
    el.detailEmpty.hidden = false;
    el.viewport.innerHTML = '';

    const laneGroup = svg('g', { class: 'graph-lanes' });
    Object.entries(LANE_LABELS).forEach(([category, label]) => {
      const x = LANE_X[category] + NODE_W / 2;
      const line = svg('line', { class: 'graph-lane-line', x1: x, y1: 70, x2: x, y2: model.height - 80 });
      const text = svg('text', { class: 'graph-lane-label', x, y: 42, 'text-anchor': 'middle' });
      text.textContent = label.toUpperCase();
      laneGroup.append(line, text);
    });
    el.viewport.appendChild(laneGroup);

    if (Array.isArray(model.sections) && model.sections.length) {
      const sectionGroup = svg('g', { class: 'graph-resource-sections' });
      model.sections.forEach(section => {
        const line = svg('line', {
          class: `graph-resource-section-line ${section.kind || ''}`,
          x1: 80, y1: section.y, x2: model.width - 80, y2: section.y
        });
        const text = svg('text', {
          class: `graph-resource-section-label ${section.kind || ''}`,
          x: 92, y: section.y - 10
        });
        text.textContent = section.label;
        sectionGroup.append(line, text);
      });
      el.viewport.appendChild(sectionGroup);
    }

    const byId = new Map(model.nodes.map(n => [n.id, n]));
    const edgeGroup = svg('g', { class: 'graph-edges' });
    model.edges.forEach(edge => {
      const a = byId.get(edge.source);
      const b = byId.get(edge.target);
      if (!a || !b) return;
      const x1 = a.x + a.width / 2;
      const y1 = a.y + a.height;
      const x2 = b.x + b.width / 2;
      const y2 = b.y;
      const midY = y1 + Math.max(20, (y2 - y1) * 0.48);
      const edgeClass = [
        'graph-edge',
        edge.kind === 'relation' ? 'is-relation' : 'is-timeline',
        edge.relation ? `relation-${edge.relation}` : '',
        edge.error ? 'is-error' : ''
      ].filter(Boolean).join(' ');
      const path = svg('path', {
        class: edgeClass,
        d: `M ${x1} ${y1} C ${x1} ${midY}, ${x2} ${midY}, ${x2} ${y2}`
      });
      edgeGroup.appendChild(path);
    });
    el.viewport.appendChild(edgeGroup);

    const nodeGroup = svg('g', { class: 'graph-nodes' });
    model.nodes.forEach(node => nodeGroup.appendChild(renderNode(node)));
    el.viewport.appendChild(nodeGroup);

    applyTransform();
    requestAnimationFrame(fitGraph);
    updateModeButton();
    emitBridge('model', model);
  }

  function renderNode(node) {
    const extraClasses = node.kind === 'resource'
      ? `${node.selectedResource ? ' resource-used' : ' resource-discarded'}${node.changedSinceTrace ? ' resource-changed' : ''}${node.missing ? ' resource-missing' : ''}`
      : '';
    const group = svg('g', {
      class: `graph-node category-${node.category} status-${node.status}${extraClasses}`,
      transform: `translate(${node.x},${node.y})`,
      tabindex: '0',
      role: 'button',
      'aria-label': `${node.fullTitle}. ${node.subtitle || ''}`,
      'data-node-id': node.id
    });
    const rect = svg('rect', { width: node.width, height: node.height });
    const dot = svg('circle', { class: 'node-status-dot', cx: 17, cy: 17, r: 5 });
    const title = svg('text', { class: 'node-title', x: 29, y: 21 });
    title.textContent = node.title;
    const subtitle = svg('text', { class: 'node-subtitle', x: 15, y: 48 });
    subtitle.textContent = short(node.subtitle, 45);
    const meta = svg('text', { class: 'node-meta', x: 15, y: 70 });
    meta.textContent = short(node.meta, 46);
    group.append(rect, dot, title, subtitle, meta);
    group.addEventListener('click', (e) => { e.stopPropagation(); selectNode(node.id); });
    group.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectNode(node.id); }
    });
    return group;
  }

  function selectNode(nodeId) {
    const node = state.model?.nodes.find(n => n.id === nodeId);
    if (!node) return;
    state.selectedNodeId = nodeId;
    el.viewport.querySelectorAll('.graph-node').forEach(n => n.classList.toggle('is-selected', n.dataset.nodeId === nodeId));
    showNodeDetail(node);
    emitBridge('selection', node);
  }

  function detailField(label, value) {
    return `<div class="graph-detail-field"><span>${esc(label)}</span><strong>${esc(value == null || value === '' ? '—' : value)}</strong></div>`;
  }

  function showNodeDetail(node) {
    el.detailEmpty.hidden = true;
    el.detail.hidden = false;
    el.detailCategory.textContent = node.category.replace(/_/g, ' ');
    el.detailStatus.textContent = node.status;
    el.detailStatus.className = `graph-detail-status ${node.status}`;
    el.detailTitle.textContent = node.fullTitle;

    if (node.kind === 'resource') {
      const historical = node.historical || {};
      const live = node.live || null;
      const score = historical.ranking_score != null ? Number(historical.ranking_score) : (historical.score != null ? Number(historical.score) : null);
      const rank = historical.rank != null ? Number(historical.rank) : null;
      const sourceId = historical.source_id != null ? historical.source_id : '—';
      const content = String(historical.content || historical.preview || historical.metadata?.raw_content || '');
      const statusText = node.selectedResource ? 'Utilizado en la respuesta' : 'Recuperado pero descartado';
      const currentText = node.missing ? 'Registro actual no disponible' : (live ? 'Registro actual localizado' : 'Sin comparación actual');

      el.detailSummary.textContent = content || statusText;
      el.detailGrid.innerHTML = [
        detailField('participación', statusText),
        detailField('fuente', historical.source),
        detailField('source_id', sourceId === '—' ? '—' : `#${sourceId}`),
        detailField('tipo', historical.type),
        detailField('scope', historical.scope),
        detailField('bucket', historical.bucket),
        detailField('rank', rank == null || !Number.isFinite(rank) ? '—' : `#${rank}`),
        detailField('score', score == null || !Number.isFinite(score) ? '—' : score.toFixed(6)),
        detailField('estado actual', currentText),
        detailField('comparación', live?.historical_comparison_mode === 'exact'
          ? (live?.changed_since_trace === true ? 'Cambió' : (live?.changed_since_trace === false ? 'Sin cambios' : 'Exacta · sin datos'))
          : (live?.historical_comparison_mode === 'prefix_only'
              ? (live?.historical_prefix_matches_current === true ? 'Prefijo histórico coincide' : (live?.historical_prefix_matches_current === false ? 'Prefijo distinto' : 'Parcial'))
              : '—'))
      ].join('');
      el.detailJson.textContent = JSON.stringify({
        historical_snapshot: historical,
        live_current: live,
        missing_live_record: node.missing || null
      }, null, 2);
      renderDetailActions(node);
      return;
    }

    if (node.kind === 'event') {
      const ev = node.event || {};
      el.detailSummary.textContent = ev.summary || 'Evento operacional registrado durante la respuesta.';
      el.detailGrid.innerHTML = [
        detailField('event_key', ev.event_key),
        detailField('fase', ev.phase),
        detailField('modelo', ev.model_id),
        detailField('duración', ev.duration_ms == null ? '—' : formatMs(ev.duration_ms)),
        detailField('fecha', formatDate(ev.created_at)),
        detailField('evento DB', ev.id ? `#${ev.id}` : '—')
      ].join('');
      el.detailJson.textContent = JSON.stringify(ev.details == null ? {} : ev.details, null, 2);
      renderDetailActions(node);
      return;
    }

    const msg = node.message || {};
    el.detailSummary.textContent = msg.content || '';
    el.detailGrid.innerHTML = [
      detailField('message_id', msg.id ? `#${msg.id}` : '—'),
      detailField('rol', msg.role),
      detailField('modelo', msg.model_id),
      detailField('latencia', msg.latency_ms == null ? '—' : formatMs(msg.latency_ms)),
      detailField('tokens input', msg.prompt_tokens),
      detailField('tokens output', msg.completion_tokens),
      detailField('fecha', formatDate(msg.created_at)),
      detailField('fase', msg.phase)
    ].join('');
    el.detailJson.textContent = JSON.stringify(msg.meta == null ? {} : msg.meta, null, 2);
    renderDetailActions(node);
  }

  function renderDetailActions(node) {
    if (!el.detailActions) return;
    el.detailActions.innerHTML = '';

    if (!node || node.kind !== 'resource') return;

    const source = String(node.historical?.source || '');
    if (node.missing) {
      el.detailActions.innerHTML = '<div class="graph-detail-action-note is-danger"><i class="fas fa-triangle-exclamation"></i> El registro vivo ya no existe; el snapshot histórico permanece disponible.</div>';
      return;
    }
    if (!node.live) {
      el.detailActions.innerHTML = '<div class="graph-detail-action-note"><i class="fas fa-circle-info"></i> Este recurso no tiene un registro vivo editable asociado.</div>';
      return;
    }
    if (!EDITABLE_LIVE_SOURCES.has(source)) {
      const reason = source === 'ChatMessages'
        ? 'Los mensajes originales permanecen de solo lectura para preservar el transcript.'
        : 'Este tipo de recurso es de solo lectura en Fase 7.5.';
      el.detailActions.innerHTML = `<div class="graph-detail-action-note"><i class="fas fa-lock"></i> ${esc(reason)}</div>`;
      return;
    }

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'graph-detail-edit-btn';
    btn.innerHTML = '<i class="fas fa-pen-to-square"></i> Editar registro actual';
    btn.addEventListener('click', () => openNodeEditor(node));
    el.detailActions.appendChild(btn);

    const note = document.createElement('div');
    note.className = 'graph-detail-action-note';
    note.innerHTML = '<i class="fas fa-shield-alt"></i> El trace histórico de esta respuesta no se modifica.';
    el.detailActions.appendChild(note);
  }

  function editInput(label, field, value, opts = {}) {
    const type = opts.type || 'text';
    const help = opts.help ? `<small>${esc(opts.help)}</small>` : '';
    const readonly = opts.readonly ? ' readonly' : '';
    return `<label class="graph-edit-field"><span>${esc(label)}</span><input data-edit-field="${esc(field)}" type="${esc(type)}" value="${esc(value == null ? '' : value)}"${readonly}>${help}</label>`;
  }

  function editTextarea(label, field, value, opts = {}) {
    const help = opts.help ? `<small>${esc(opts.help)}</small>` : '';
    return `<label class="graph-edit-field is-wide"><span>${esc(label)}</span><textarea data-edit-field="${esc(field)}" rows="${Number(opts.rows || 8)}">${esc(value == null ? '' : value)}</textarea>${help}</label>`;
  }

  function editSelect(label, field, value, options, help = '') {
    const html = options.map(([v, text]) => `<option value="${esc(v)}"${String(v) === String(value) ? ' selected' : ''}>${esc(text)}</option>`).join('');
    return `<label class="graph-edit-field"><span>${esc(label)}</span><select data-edit-field="${esc(field)}">${html}</select>${help ? `<small>${esc(help)}</small>` : ''}</label>`;
  }

  function editCheckbox(label, field, checked, help = '') {
    return `<label class="graph-edit-check"><input data-edit-field="${esc(field)}" type="checkbox"${checked ? ' checked' : ''}><span><strong>${esc(label)}</strong>${help ? `<small>${esc(help)}</small>` : ''}</span></label>`;
  }

  function editorHtml(node) {
    const source = String(node.historical?.source || '');
    const live = node.live || {};

    if (source === 'ProjectContext') {
      return [
        editSelect('Tipo', 'type', live.type, [
          ['rule','Regla'],['decision','Decisión'],['fact','Hecho'],['style','Estilo'],['todo','Pendiente'],['note','Nota']
        ]),
        editInput('Título', 'title', live.title || ''),
        editTextarea('Contenido', 'content', live.content || '', { rows: 10, help: 'Al guardar se invalida el embedding anterior y se solicita uno nuevo cuando embedding_main está disponible.' }),
        editInput('source_chunk_id', 'source_chunk_id', live.source_chunk_id || '', { type: 'number', help: 'Opcional. Debe pertenecer al mismo proyecto.' })
      ].join('');
    }

    if (source === 'UserProceduralMemory') {
      return [
        editSelect('Tipo de memoria', 'memory_type', live.memory_type, [
          ['preference','Preferencia'],['rule','Regla'],['pattern','Patrón'],['correction','Corrección'],['workflow','Flujo de trabajo']
        ]),
        editTextarea('Contenido', 'content', live.content || '', { rows: 10 }),
        editInput('Confianza / observaciones', 'confidence', live.confidence ?? 1, { type: 'number', help: 'Valor entre 1 y 255.' }),
        editCheckbox('Memoria activa', 'is_active', live.is_active === true, 'OFF conserva el registro pero deja de aplicarlo.')
      ].join('');
    }

    if (source === 'SessionContextBlocks') {
      return [
        editSelect('Tipo de bloque', 'block_type', live.block_type, [
          ['primordial','Primordial'],['level_0','Nivel 0 · crudo'],['level_1','Nivel 1 · resumen'],['level_2','Nivel 2 · macro'],['level_3','Nivel 3 · épico'],['file','Archivo'],['file_chunk','Fragmento de archivo']
        ]),
        editTextarea('Contenido del bloque', 'content_preview', live.content_preview || '', { rows: 11, help: 'Se invalida el vector anterior y se reencola embedding cuando está disponible.' }),
        editInput('Tokens', 'token_count', live.token_count ?? 0, { type: 'number' }),
        editCheckbox('Bloque protegido', 'is_locked', live.is_locked === true, 'Los bloques bloqueados no deben ser comprimidos automáticamente.')
      ].join('');
    }

    if (source === 'SourceChunks') {
      const meta = live.meta == null ? '' : (typeof live.meta === 'string' ? live.meta : JSON.stringify(live.meta, null, 2));
      return [
        `<div class="graph-edit-readonly"><span>Archivo / chunk</span><strong>${esc(live.filename || '—')} · ${esc(live.name || ('#' + live.id))}</strong></div>`,
        editTextarea('Contenido indexado', 'content', live.content || '', { rows: 12, help: 'Edita el índice RAG, no el archivo fuente. El embedding del chunk será invalidado y reencolado.' }),
        editInput('Tokens', 'token_count', live.token_count ?? 0, { type: 'number' }),
        editTextarea('Meta JSON', 'meta', meta, { rows: 7 })
      ].join('');
    }

    if (source === 'ChatSessions') {
      return [
        `<div class="graph-edit-readonly"><span>Sesión</span><strong>${esc(live.title || ('#' + live.id))}</strong></div>`,
        editTextarea('Resumen maestro', 'context_summary', live.context_summary || '', { rows: 12, help: 'El transcript original no cambia. Sólo se modifica el resumen vivo usado como memoria de sesión.' }),
        editSelect('Nivel de contexto', 'context_level', live.context_level ?? 0, [
          ['0','0 · Crudo'],['1','1 · Resumen'],['2','2 · Macro'],['3','3 · Épico']
        ])
      ].join('');
    }

    return '<div class="graph-detail-action-note">Este nodo no tiene editor.</div>';
  }

  function openNodeEditor(node) {
    if (!node || node.kind !== 'resource' || !node.live) return;
    const source = String(node.historical?.source || '');
    if (!EDITABLE_LIVE_SOURCES.has(source)) return;

    state.editingNode = node;
    el.editTitle.textContent = `Editar ${EDIT_SOURCE_LABELS[source] || source} #${node.live.id || node.historical?.source_id || ''}`;
    el.editSubtitle.textContent = `Fuente viva: ${source}. La versión histórica usada por esta respuesta seguirá intacta.`;
    el.editFields.innerHTML = editorHtml(node);
    el.editStatus.hidden = true;
    el.editStatus.className = 'graph-edit-status';
    el.editStatus.textContent = '';
    el.editSave.disabled = false;
    el.editModal.hidden = false;
    el.editBackdrop.hidden = false;
    document.body.classList.add('graph-edit-open');
    const first = el.editFields.querySelector('input:not([readonly]), textarea, select');
    setTimeout(() => first?.focus(), 0);
  }

  function closeNodeEditor() {
    state.editingNode = null;
    el.editModal.hidden = true;
    el.editBackdrop.hidden = true;
    document.body.classList.remove('graph-edit-open');
  }

  function collectEditFields() {
    const fields = {};
    el.editFields.querySelectorAll('[data-edit-field]').forEach(input => {
      const key = input.getAttribute('data-edit-field');
      if (!key) return;
      if (input.type === 'checkbox') fields[key] = !!input.checked;
      else if (input.type === 'number') fields[key] = input.value === '' ? '' : Number(input.value);
      else fields[key] = input.value;
    });
    return fields;
  }

  async function saveNodeEdit(event) {
    event?.preventDefault?.();
    const node = state.editingNode;
    if (!node || !node.live) return;
    const source = String(node.historical?.source || '');
    const id = Number(node.live.id || node.historical?.source_id || 0);
    if (!source || !id) return;

    el.editSave.disabled = true;
    el.editStatus.hidden = false;
    el.editStatus.className = 'graph-edit-status is-working';
    el.editStatus.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Guardando registro vivo…';

    try {
      const response = await fetch(EDIT_API, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          csrf_token: String(cfg.csrfToken || ''),
          user_id: Number(cfg.userId || state.data?.scope?.user?.id || 0),
          source,
          id,
          fields: collectEditFields()
        })
      });
      const text = await response.text();
      let json;
      try { json = JSON.parse(text); }
      catch (_) { throw new Error(`El editor devolvió contenido no JSON (HTTP ${response.status}): ${text.slice(0, 220)}`); }
      if (!response.ok || json.ok === false) throw new Error(json.error || `HTTP ${response.status}`);

      const warnings = Array.isArray(json.warnings) ? json.warnings.filter(Boolean) : [];
      const embed = json.embedding || {};
      const embeddingText = embed.queued
        ? ` Embedding ${embed.model_id || ''} reencolado.`
        : (embed.reason && embed.reason !== 'not_applicable' ? ` Embedding no reencolado: ${embed.reason}.` : '');
      el.editStatus.className = 'graph-edit-status is-success';
      el.editStatus.innerHTML = `<i class="fas fa-check-circle"></i> Registro actualizado.${esc(embeddingText)}${warnings.length ? '<br><small>' + warnings.map(esc).join('<br>') + '</small>' : ''}`;

      const sourceId = id;
      state.data = await fetchTrace();
      state.metricsMonth = traceMonth(state.data);
      if (el.metricsMonth) el.metricsMonth.value = state.metricsMonth;
      updateHeaderAndStats(state.data);
      renderGraph();
      const refreshed = state.model?.nodes.find(n => n.kind === 'resource' && String(n.historical?.source || '') === source && Number(n.historical?.source_id || 0) === sourceId);
      if (refreshed) selectNode(refreshed.id);
      setTimeout(closeNodeEditor, 650);
    } catch (error) {
      console.error(error);
      el.editStatus.className = 'graph-edit-status is-error';
      el.editStatus.innerHTML = `<i class="fas fa-triangle-exclamation"></i> ${esc(error.message || String(error))}`;
      el.editSave.disabled = false;
    }
  }

  function applyTransform() {
    el.viewport.setAttribute('transform', `translate(${state.transform.x} ${state.transform.y}) scale(${state.transform.scale})`);
  }

  function fitGraph() {
    if (!state.model || !el.wrap) return;
    const rect = el.wrap.getBoundingClientRect();
    const padding = 42;
    const sx = (rect.width - padding * 2) / state.model.width;
    const sy = (rect.height - padding * 2) / state.model.height;
    const scale = Math.max(0.12, Math.min(1.1, sx, sy));
    state.transform.scale = scale;
    state.transform.x = (rect.width - state.model.width * scale) / 2;
    state.transform.y = Math.max(28, (rect.height - state.model.height * scale) / 2);
    applyTransform();
  }

  function zoomAt(factor, cx, cy) {
    const old = state.transform.scale;
    const next = Math.max(0.08, Math.min(2.8, old * factor));
    if (next === old) return;
    const gx = (cx - state.transform.x) / old;
    const gy = (cy - state.transform.y) / old;
    state.transform.scale = next;
    state.transform.x = cx - gx * next;
    state.transform.y = cy - gy * next;
    applyTransform();
  }

  function wirePanZoom() {
    el.svg.addEventListener('wheel', (e) => {
      e.preventDefault();
      const r = el.svg.getBoundingClientRect();
      zoomAt(e.deltaY < 0 ? 1.12 : 0.89, e.clientX - r.left, e.clientY - r.top);
    }, { passive: false });

    el.svg.addEventListener('pointerdown', (e) => {
      if (e.button !== 0 || e.target.closest?.('.graph-node')) return;
      state.panning = true;
      state.panStart = { px: e.clientX, py: e.clientY, x: state.transform.x, y: state.transform.y };
      el.svg.classList.add('is-panning');
      el.svg.setPointerCapture?.(e.pointerId);
    });
    el.svg.addEventListener('pointermove', (e) => {
      if (!state.panning || !state.panStart) return;
      state.transform.x = state.panStart.x + (e.clientX - state.panStart.px);
      state.transform.y = state.panStart.y + (e.clientY - state.panStart.py);
      applyTransform();
    });
    const stop = () => { state.panning = false; state.panStart = null; el.svg.classList.remove('is-panning'); };
    el.svg.addEventListener('pointerup', stop);
    el.svg.addEventListener('pointercancel', stop);
  }

  function updateModeButton() {
    const essential = state.mode === 'essential';
    el.modeToggle.innerHTML = essential
      ? '<i class="fas fa-filter"></i> Esencial · usados'
      : '<i class="fas fa-list"></i> Completo · + descartados';
    const eventCount = state.model ? `${state.model.visibleEventCount}/${state.model.sourceEventCount}` : '';
    const discarded = state.model ? Number(state.model.discardedResourceCount || 0) : 0;
    el.modeToggle.title = essential
      ? `Eventos esenciales ${eventCount}; muestra sólo contexto realmente usado. Clic para añadir todos los eventos y ${discarded} recurso(s) descartado(s).`
      : `Todos los eventos y candidatos descartados visibles. Clic para volver al camino efectivo de la respuesta.`;
  }

  function updateHeaderAndStats(data) {
    const q = data.turn?.question || {};
    const a = data.turn?.answer || {};
    const trace = data.trace || {};
    const totals = data.totals || {};
    const tokens = totals.token_accounting || {};

    el.responseLabel.textContent = a.id ? `· #${a.id}` : '';
    el.subtitle.textContent = q.content
      ? `Pregunta #${q.id || '?'}: ${short(q.content, 105)} · trace ${String(trace.trace_id || 'sin trace')}`
      : `trace ${String(trace.trace_id || 'sin trace')}`;

    const usedContext = Array.isArray(data.resources?.context_items_historical)
      ? data.resources.context_items_historical.length : 0;
    const discardedContext = Array.isArray(data.resources?.context_items_discarded_historical)
      ? data.resources.context_items_discarded_historical.length : 0;
    const values = [
      [trace.status || '—', `status-${trace.status || 'unknown'}`],
      [String(trace.event_count || 0), ''],
      [trace.duration_ms == null ? '—' : formatMs(trace.duration_ms), ''],
      [Number(tokens.total_tokens || 0).toLocaleString(), ''],
      [formatUsd(tokens.recalculated_cost_usd || tokens.estimated_cost_usd || 0), ''],
      [String(usedContext), ''],
      [String(discardedContext), ''],
      [String(totals.tool_call_count || 0), ''],
      [String(totals.memory_write_count || 0), '']
    ];
    const cards = Array.from(el.stats.querySelectorAll('.graph-stat strong'));
    cards.forEach((strong, i) => { strong.textContent = values[i]?.[0] ?? '—'; });

    const processQs = new URLSearchParams({ session_id: String(Number(data.scope?.session?.id || cfg.sessionId || 0)) });
    if (trace.trace_id) processQs.set('trace_id', String(trace.trace_id));
    el.processLink.href = `${cfg.processViewer || 'chat_activity_viewer.php'}?${processQs.toString()}`;

    const jsonQs = new URLSearchParams({ action: 'trace', session_id: String(Number(data.scope?.session?.id || cfg.sessionId || 0)), pretty: '1' });
    if (Number(cfg.userId || 0) > 0) jsonQs.set('user_id', String(Number(cfg.userId)));
    if (a.id) jsonQs.set('answer_message_id', String(a.id));
    else if (trace.trace_id) jsonQs.set('trace_id', String(trace.trace_id));
    el.jsonLink.href = `${API}?${jsonQs.toString()}`;

    const explorerQs = new URLSearchParams();
    if (Number(cfg.userId || 0) > 0) explorerQs.set('user_id', String(Number(cfg.userId)));
    if (Number(data.scope?.project?.id || cfg.projectId || 0) > 0) explorerQs.set('project_id', String(Number(data.scope?.project?.id || cfg.projectId)));
    if (Number(data.scope?.session?.id || cfg.sessionId || 0) > 0) explorerQs.set('session_id', String(Number(data.scope?.session?.id || cfg.sessionId)));
    el.backExplorer.href = `${cfg.explorer || 'trace_explorer.php'}${explorerQs.toString() ? '?' + explorerQs.toString() : ''}`;
  }

  function metricCard(label, value, note = '', cls = '') {
    return `<div class="metrics-card ${cls}"><span>${esc(label)}</span><strong title="${esc(String(value))}">${esc(String(value))}</strong>${note ? `<small>${esc(note)}</small>` : ''}</div>`;
  }

  function scopeMetricCard(title, aggregate, budget = null) {
    if (!aggregate) return '';
    const t = aggregate.tokens || {};
    const r = aggregate.responses || {};
    const tools = aggregate.tools || {};
    const mem = aggregate.memory || {};
    const eff = aggregate.efficiency || {};
    let budgetHtml = '';
    if (budget) {
      const pct = budget.used_percent == null ? null : Math.max(0, Number(budget.used_percent || 0));
      budgetHtml = `<div class="metrics-budget"><dl><dt>Presupuesto mensual</dt><dd>${formatUsd(budget.budget_usd_monthly, 4)}</dd><dt>Restante</dt><dd>${budget.remaining_usd == null ? '—' : formatUsd(budget.remaining_usd, 4)}</dd></dl>${pct == null ? '' : `<div class="metrics-budget-bar" title="${pct.toFixed(2)}% usado"><i style="width:${Math.min(100, pct)}%"></i></div>`}</div>`;
    }
    return `<article class="metrics-scope-card"><h3>${esc(title)}</h3><dl>
      <dt>Respuestas</dt><dd>${Number(r.assistant_responses || 0).toLocaleString()}</dd>
      <dt>Tokens</dt><dd>${Number(t.total_tokens || 0).toLocaleString()}</dd>
      <dt>Costo recalc.</dt><dd>${formatUsd(t.recalculated_cost_usd || 0)}</dd>
      <dt>Costo guardado</dt><dd>${formatUsd(t.stored_cost_usd || 0)}</dd>
      <dt>Latencia promedio</dt><dd>${r.avg_latency_ms == null ? '—' : formatMs(r.avg_latency_ms)}</dd>
      <dt>Tools</dt><dd>${Number(tools.calls || 0).toLocaleString()}</dd>
      <dt>Escrituras memoria</dt><dd>${Number(mem.writes || 0).toLocaleString()}</dd>
      <dt>Tokens/respuesta</dt><dd>${eff.tokens_per_response == null ? '—' : Number(eff.tokens_per_response).toLocaleString(undefined,{maximumFractionDigits:2})}</dd>
    </dl>${budgetHtml}</article>`;
  }

  function renderMetrics() {
    if (!el.metricsBody || !state.data) return;
    const totals = state.data.totals || {};
    const turn = totals.token_accounting || {};
    const rounds = totals.model_rounds_trace || {};
    const durations = totals.duration_breakdown || {};
    const tools = totals.tools || {};
    const writer = totals.memory_writer || {};
    const trace = state.data.trace || {};
    const scope = state.metricsScope || {};
    const phases = Array.isArray(turn.by_phase) ? turn.by_phase : [];
    const models = Array.isArray(turn.by_model) ? turn.by_model : [];
    const maxPhaseTokens = Math.max(1, ...phases.map(p => Number(p.total_tokens || 0)));

    const phaseRows = phases.length ? phases.map(p => `<tr><td>${esc(p.phase || '—')}<div class="metrics-phase-bar"><i style="width:${Math.max(2, Number(p.total_tokens || 0) / maxPhaseTokens * 100).toFixed(1)}%"></i></div></td><td class="num">${Number(p.calls || 0)}</td><td class="num">${Number(p.input_tokens || 0).toLocaleString()}</td><td class="num">${Number(p.output_tokens || 0).toLocaleString()}</td><td class="num">${formatUsd(p.recalculated_cost_usd || 0)}</td><td class="num">${formatMs(p.duration_ms || 0)}</td></tr>`).join('') : '<tr><td colspan="6">Sin TokenUsage asociado a este turno.</td></tr>';
    const modelRows = models.length ? models.map(m => `<tr><td><div class="metrics-model-id">${esc(m.model_id || '(sin modelo)')}</div>${m.pricing?.fallback ? '<small style="color:#d29922">precio fallback</small>' : ''}</td><td class="num">${Number(m.calls || 0)}</td><td class="num">${Number(m.total_tokens || 0).toLocaleString()}</td><td class="num">${formatUsd(m.recalculated_cost_usd || 0)}</td><td class="num">${formatUsd(m.stored_cost_usd || 0)}</td><td class="num">${formatMs(m.duration_ms || 0)}</td></tr>`).join('') : '<tr><td colspan="6">Sin modelos contabilizados.</td></tr>';

    const fallbackModels = Array.isArray(turn.fallback_pricing_models) ? turn.fallback_pricing_models : [];
    el.metricsBody.innerHTML = `
      <section class="metrics-section">
        <div class="metrics-section-title">Esta pregunta / respuesta <span>trace ${esc(String(trace.trace_id || '—'))}</span></div>
        <div class="metrics-card-grid">
          ${metricCard('Input', Number(turn.input_tokens || 0).toLocaleString(), 'tokens')}
          ${metricCard('Output', Number(turn.output_tokens || 0).toLocaleString(), 'tokens')}
          ${metricCard('Total', Number(turn.total_tokens || 0).toLocaleString(), `${Number(turn.calls || 0)} registro(s) TokenUsage`)}
          ${metricCard('Costo recalculado', formatUsd(turn.recalculated_cost_usd || 0), 'tabla actual del dashboard', 'is-cost')}
          ${metricCard('Costo guardado', formatUsd(turn.stored_cost_usd || 0), 'TokenUsage histórico')}
          ${metricCard('Trace total', trace.duration_ms == null ? '—' : formatMs(trace.duration_ms), 'pared real del pipeline')}
          ${metricCard('Modelo', formatMs(rounds.duration_ms_sum || 0), `${Number(rounds.rounds || 0)} ronda(s)`)}
          ${metricCard('Tools', formatMs(tools.duration_ms_sum || 0), `${Number(tools.calls || 0)} llamada(s)`)}
          ${metricCard('Writer tokens', Number(writer.total_tokens || 0).toLocaleString(), `${Number(writer.writes || 0)} escritura(s)`)}
          ${metricCard('Writer costo', formatUsd(writer.estimated_cost_usd || 0), 'estimado')}
        </div>
      </section>
      <section class="metrics-section">
        <div class="metrics-section-title">Desglose del turno <span>TokenUsage enlazado por message_id</span></div>
        <div class="metrics-tables">
          <div class="metrics-table-wrap"><table class="metrics-table"><thead><tr><th>Fase</th><th>Calls</th><th>Input</th><th>Output</th><th>Costo</th><th>Tiempo</th></tr></thead><tbody>${phaseRows}</tbody></table></div>
          <div class="metrics-table-wrap"><table class="metrics-table"><thead><tr><th>Modelo</th><th>Calls</th><th>Tokens</th><th>Recalc.</th><th>Guardado</th><th>Tiempo</th></tr></thead><tbody>${modelRows}</tbody></table></div>
        </div>
      </section>
      <section class="metrics-section">
        <div class="metrics-section-title">Scope <span>${esc(scope.scope?.month || state.metricsMonth || '')}</span></div>
        <div class="metrics-scope-grid">
          ${scopeMetricCard('Sesión · mes', scope.session?.month)}
          ${scopeMetricCard('Sesión · histórico', scope.session?.all_time)}
          ${scope.project ? scopeMetricCard('Proyecto · mes', scope.project.month, scope.project.budget) : ''}
          ${scope.project ? scopeMetricCard('Proyecto · histórico', scope.project.all_time) : ''}
          ${scopeMetricCard('Usuario · mes', scope.user?.month)}
        </div>
      </section>
      <div class="metrics-note"><i class="fas fa-info-circle"></i>${esc(scope.cost_note || turn.pricing_basis || 'Costos estimados.')}${fallbackModels.length ? ` Modelos con precio fallback en este turno: ${esc(fallbackModels.join(', '))}.` : ''}</div>`;
  }

  async function loadMetrics(month = '') {
    if (!el.metricsBody || state.metricsLoading) return;
    state.metricsLoading = true;
    const selectedMonth = month || state.metricsMonth || traceMonth();
    state.metricsMonth = selectedMonth;
    if (el.metricsMonth) el.metricsMonth.value = selectedMonth;
    el.metricsBody.innerHTML = '<div class="trace-metrics-loading"><i class="fas fa-circle-notch fa-spin"></i> Calculando métricas por turno, sesión y proyecto…</div>';
    try {
      state.metricsScope = await fetchScopeMetrics(selectedMonth);
      if (el.metricsNote && state.metricsScope?.cost_note) el.metricsNote.textContent = state.metricsScope.cost_note;
      renderMetrics();
    } catch (error) {
      console.error('Trace metrics:', error);
      el.metricsBody.innerHTML = `<div class="trace-metrics-error"><i class="fas fa-triangle-exclamation"></i>${esc(error.message || String(error))}</div>`;
    } finally {
      state.metricsLoading = false;
    }
  }

  function openMetrics() {
    if (!el.metricsDrawer) return;
    el.metricsDrawer.hidden = false;
    el.metricsToggle?.setAttribute('aria-expanded', 'true');
    if (!state.metricsScope) loadMetrics(traceMonth());
    else renderMetrics();
  }

  function closeMetrics() {
    if (!el.metricsDrawer) return;
    el.metricsDrawer.hidden = true;
    el.metricsToggle?.setAttribute('aria-expanded', 'false');
  }

  function showError(error) {
    el.loading.style.display = 'none';
    el.error.hidden = false;
    el.error.textContent = error.message || String(error);
  }

  async function load() {
    if (!Number(cfg.sessionId || 0)) {
      showError(new Error('Falta session_id para cargar el grafo.'));
      return;
    }
    try {
      state.data = await fetchTrace();
      state.metricsMonth = traceMonth(state.data);
      if (el.metricsMonth) el.metricsMonth.value = state.metricsMonth;
      updateHeaderAndStats(state.data);
      renderGraph();
      el.loading.style.display = 'none';
    } catch (error) {
      console.error(error);
      showError(error);
    }
  }

  function setGraphMode(mode) {
    const normalized = mode === 'full' ? 'full' : 'essential';
    if (state.mode === normalized && state.model) return;
    state.mode = normalized;
    renderGraph();
  }

  window.TraceGraphBridge = {
    getData: () => state.data,
    getModel: () => state.model,
    getMode: () => state.mode,
    getSelectedNode: () => state.model?.nodes.find(node => node.id === state.selectedNodeId) || null,
    selectNode,
    setMode: setGraphMode,
    subscribeModel(listener) {
      if (typeof listener !== 'function') return () => {};
      bridgeSubscribers.model.add(listener);
      if (state.model) { try { listener(state.model); } catch (error) { console.error(error); } }
      return () => bridgeSubscribers.model.delete(listener);
    },
    subscribeSelection(listener) {
      if (typeof listener !== 'function') return () => {};
      bridgeSubscribers.selection.add(listener);
      const current = state.model?.nodes.find(node => node.id === state.selectedNodeId) || null;
      if (current) { try { listener(current); } catch (error) { console.error(error); } }
      return () => bridgeSubscribers.selection.delete(listener);
    },
    fit2D: fitGraph,
    version: '7.7'
  };

  el.fit?.addEventListener('click', fitGraph);
  el.zoomIn?.addEventListener('click', () => {
    const r = el.wrap.getBoundingClientRect();
    zoomAt(1.18, r.width / 2, r.height / 2);
  });
  el.zoomOut?.addEventListener('click', () => {
    const r = el.wrap.getBoundingClientRect();
    zoomAt(0.84, r.width / 2, r.height / 2);
  });
  el.modeToggle?.addEventListener('click', () => {
    setGraphMode(state.mode === 'essential' ? 'full' : 'essential');
  });
  el.metricsToggle?.addEventListener('click', () => el.metricsDrawer?.hidden ? openMetrics() : closeMetrics());
  el.metricsClose?.addEventListener('click', closeMetrics);
  el.metricsRefresh?.addEventListener('click', () => loadMetrics(el.metricsMonth?.value || state.metricsMonth || traceMonth()));
  el.metricsMonth?.addEventListener('change', () => loadMetrics(el.metricsMonth.value));
  window.addEventListener('resize', () => { if (state.model) fitGraph(); }, { passive: true });

  el.editForm?.addEventListener('submit', saveNodeEdit);
  el.editClose?.addEventListener('click', closeNodeEditor);
  el.editCancel?.addEventListener('click', closeNodeEditor);
  el.editBackdrop?.addEventListener('click', closeNodeEditor);
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && el.editModal && !el.editModal.hidden) closeNodeEditor();
    else if (event.key === 'Escape' && el.metricsDrawer && !el.metricsDrawer.hidden) closeMetrics();
  });

  wirePanZoom();
  load();
})();
