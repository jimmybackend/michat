import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';

const bridge = window.TraceGraphBridge;
const cfg = window.TRACE_GRAPH_CONFIG || {};

const el = {
  view2dBtn: document.getElementById('graphView2d'),
  view3dBtn: document.getElementById('graphView3d'),
  view2d: document.getElementById('graph2dView'),
  view3d: document.getElementById('graph3dView'),
  host: document.getElementById('graph3dHost'),
  loading: document.getElementById('graph3dLoading'),
  fallback: document.getElementById('graph3dFallback'),
  tooltip: document.getElementById('graph3dTooltip'),
  fit: document.getElementById('graph3dFit'),
  autoRotate: document.getElementById('graph3dAutoRotate'),
  focus: document.getElementById('graph3dFocus'),
  commonFit: document.getElementById('graphFit'),
  commonZoomIn: document.getElementById('graphZoomIn'),
  commonZoomOut: document.getElementById('graphZoomOut')
};

const COLORS = {
  question: 0x58a6ff,
  answer: 0x7ee787,
  pipeline: 0x8b949e,
  compiler: 0xa371f7,
  router: 0xd2a8ff,
  feature_flags: 0x79c0ff,
  ranking: 0xffa657,
  context: 0x56d364,
  retrieval: 0x2f81f7,
  prompt: 0xe3b341,
  model: 0x39ff88,
  tool: 0xff7b72,
  memory: 0xbc8cff,
  response: 0x7ee787,
  trace: 0x8b949e,
  project_memory: 0xbc8cff,
  procedural_memory: 0xf778ba,
  session_memory: 0x79c0ff,
  question_memory: 0x56d364,
  project_rag: 0x2f81f7,
  attachment: 0xffa657
};

const RESOURCE_X = {
  project_memory: -430,
  procedural_memory: -270,
  session_memory: -105,
  question_memory: 70,
  project_rag: 255,
  attachment: 435
};

const state = {
  activeView: '2d',
  ready: false,
  model: null,
  selectedId: null,
  scene: null,
  camera: null,
  renderer: null,
  controls: null,
  graphGroup: null,
  nodeObjects: new Map(),
  clickable: [],
  raycaster: new THREE.Raycaster(),
  pointer: new THREE.Vector2(),
  hoveredId: null,
  pointerDown: null,
  resizeObserver: null
};

function esc(text) {
  return String(text == null ? '' : text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function short(text, max = 54) {
  const value = String(text || '').replace(/\s+/g, ' ').trim();
  return value.length > max ? value.slice(0, max - 1) + '…' : value;
}

function colorFor(category) {
  return COLORS[category] || 0x8b949e;
}

function setFallback(message) {
  if (el.loading) el.loading.hidden = true;
  if (el.fallback) {
    el.fallback.hidden = false;
    el.fallback.innerHTML = `<i class="fas fa-triangle-exclamation"></i><strong>Vista 3D no disponible</strong><span>${esc(message)}</span><button type="button" id="graph3dFallback2d" class="graph-mini-btn">Volver a 2D</button>`;
    document.getElementById('graph3dFallback2d')?.addEventListener('click', () => setView('2d'));
  }
  el.view3dBtn?.classList.add('is-unavailable');
  el.view3dBtn?.setAttribute('title', 'Vista 3D no disponible en este navegador');
  setView('2d');
}

function setView(view) {
  const next = view === '3d' && state.ready ? '3d' : '2d';
  state.activeView = next;
  if (el.view2d) el.view2d.hidden = next !== '2d';
  if (el.view3d) el.view3d.hidden = next !== '3d';
  if (el.view2dBtn) {
    el.view2dBtn.classList.toggle('active', next === '2d');
    el.view2dBtn.setAttribute('aria-pressed', next === '2d' ? 'true' : 'false');
  }
  if (el.view3dBtn) {
    el.view3dBtn.classList.toggle('active', next === '3d');
    el.view3dBtn.setAttribute('aria-pressed', next === '3d' ? 'true' : 'false');
  }

  if (next === '2d') {
    requestAnimationFrame(() => bridge?.fit2D?.());
  } else {
    requestAnimationFrame(() => {
      resizeRenderer();
      if (state.model) fit3D();
    });
  }
}

function initThree() {
  if (!bridge || !el.host) {
    setFallback('No se encontró el puente del grafo 2D/3D.');
    return false;
  }

  try {
    const scene = new THREE.Scene();
    scene.background = new THREE.Color(0x080c12);
    scene.fog = new THREE.FogExp2(0x080c12, 0.00038);

    const camera = new THREE.PerspectiveCamera(48, 1, 0.1, 7000);
    camera.position.set(820, 500, 1050);

    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false, powerPreference: 'high-performance' });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    renderer.setClearColor(0x080c12, 1);
    renderer.domElement.className = 'trace-graph-3d-canvas';
    renderer.domElement.setAttribute('aria-label', 'Vista tridimensional de la trazabilidad');
    renderer.domElement.setAttribute('tabindex', '0');
    el.host.prepend(renderer.domElement);

    const controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.dampingFactor = 0.075;
    controls.enablePan = true;
    controls.screenSpacePanning = true;
    controls.minDistance = 90;
    controls.maxDistance = 4200;
    controls.target.set(0, 0, 0);

    scene.add(new THREE.HemisphereLight(0xc7ddff, 0x11151c, 1.35));
    const key = new THREE.DirectionalLight(0xffffff, 1.45);
    key.position.set(500, 750, 900);
    scene.add(key);
    const rim = new THREE.PointLight(0x39ff88, 30, 1600, 2);
    rim.position.set(-650, 80, 450);
    scene.add(rim);

    const grid = new THREE.GridHelper(1800, 30, 0x30363d, 0x161b22);
    grid.position.y = -520;
    grid.material.opacity = 0.32;
    grid.material.transparent = true;
    scene.add(grid);

    const depthPlane = new THREE.GridHelper(1500, 20, 0x21262d, 0x121820);
    depthPlane.rotation.z = Math.PI / 2;
    depthPlane.position.x = -620;
    depthPlane.material.opacity = 0.16;
    depthPlane.material.transparent = true;
    scene.add(depthPlane);

    const graphGroup = new THREE.Group();
    graphGroup.name = 'TraceGraph3D';
    scene.add(graphGroup);

    Object.assign(state, { scene, camera, renderer, controls, graphGroup, ready: true });
    resizeRenderer();
    renderer.setAnimationLoop(animate);
    wireInteraction();

    if (el.loading) el.loading.hidden = true;
    if (el.fallback) el.fallback.hidden = true;
    el.view3dBtn?.classList.remove('is-unavailable');
    return true;
  } catch (error) {
    console.error('TRACE_GRAPH_3D_INIT:', error);
    setFallback(error?.message || String(error));
    return false;
  }
}

function clearGraph() {
  if (!state.graphGroup) return;
  while (state.graphGroup.children.length) {
    const child = state.graphGroup.children[0];
    state.graphGroup.remove(child);
    child.traverse?.(obj => {
      if (obj.geometry) obj.geometry.dispose?.();
      if (obj.material) {
        const materials = Array.isArray(obj.material) ? obj.material : [obj.material];
        materials.forEach(material => {
          if (material.map) material.map.dispose?.();
          material.dispose?.();
        });
      }
    });
  }
  state.nodeObjects.clear();
  state.clickable = [];
  state.hoveredId = null;
}

function projectPosition(node, model, positioned) {
  if (node.kind !== 'resource') {
    const x = (Number(node.x || 0) - 1180) * 0.45;
    const y = 410 - ((Number(node.y || 0) - 120) * 0.43);
    let z = 0;
    if (node.category === 'question') z = 65;
    if (node.category === 'answer' || node.category === 'response') z = 48;
    return new THREE.Vector3(x, y, z);
  }

  const incoming = (model.edges || []).find(edge => edge.target === node.id && edge.kind === 'relation');
  const anchor = incoming ? positioned.get(incoming.source) : null;
  const index = Number(node.resourceIndex || 0);
  const categoryX = RESOURCE_X[node.category] ?? 0;
  const row = Math.floor(index / 6);
  const wobble = ((index % 3) - 1) * 58;
  const baseY = anchor ? anchor.y : -30;
  const y = baseY + wobble - row * 72;
  const z = node.selectedResource ? (235 + row * 64) : (-235 - row * 64);
  return new THREE.Vector3(categoryX, y, z);
}

function makeNodeGeometry(node) {
  if (node.kind === 'message') return new THREE.SphereGeometry(node.category === 'question' ? 25 : 28, 28, 18);
  if (node.kind === 'resource') return new THREE.BoxGeometry(92, 42, 30, 1, 1, 1);
  return new THREE.BoxGeometry(66, 34, 24, 1, 1, 1);
}

function makeNodeMaterial(node) {
  const color = colorFor(node.category);
  const discarded = node.kind === 'resource' && !node.selectedResource;
  const missing = !!node.missing;
  const material = new THREE.MeshStandardMaterial({
    color: missing ? 0xf85149 : color,
    roughness: 0.43,
    metalness: 0.18,
    transparent: discarded,
    opacity: discarded ? 0.38 : 0.96,
    wireframe: discarded
  });
  material.emissive = new THREE.Color(missing ? 0x5f1518 : color);
  material.emissiveIntensity = discarded ? 0.05 : 0.12;
  return material;
}

function makeLabelSprite(node) {
  const canvas = document.createElement('canvas');
  canvas.width = 768;
  canvas.height = 196;
  const ctx = canvas.getContext('2d');
  const color = new THREE.Color(node.missing ? 0xf85149 : colorFor(node.category));
  const cssColor = `#${color.getHexString()}`;

  ctx.clearRect(0, 0, canvas.width, canvas.height);
  ctx.fillStyle = 'rgba(8,12,18,.90)';
  ctx.strokeStyle = cssColor;
  ctx.lineWidth = node.kind === 'resource' && !node.selectedResource ? 3 : 5;
  roundRect(ctx, 4, 4, 760, 188, 22);
  ctx.fill();
  ctx.stroke();

  ctx.fillStyle = cssColor;
  ctx.font = '700 27px Inter, Arial, sans-serif';
  ctx.fillText(short(node.fullTitle || node.title, 43), 30, 54);
  ctx.fillStyle = '#f0f6fc';
  ctx.font = '600 22px Inter, Arial, sans-serif';
  ctx.fillText(short(node.subtitle, 58), 30, 98);
  ctx.fillStyle = node.kind === 'resource' && !node.selectedResource ? '#8b949e' : '#b7c0ca';
  ctx.font = '500 18px JetBrains Mono, monospace';
  ctx.fillText(short(node.meta, 69), 30, 140);

  const texture = new THREE.CanvasTexture(canvas);
  texture.colorSpace = THREE.SRGBColorSpace;
  texture.minFilter = THREE.LinearFilter;
  const material = new THREE.SpriteMaterial({ map: texture, transparent: true, depthWrite: false });
  const sprite = new THREE.Sprite(material);
  const width = node.kind === 'resource' ? 190 : 160;
  sprite.scale.set(width, width * (196 / 768), 1);
  sprite.position.set(0, node.kind === 'resource' ? 52 : 45, 0);
  sprite.userData.nodeId = node.id;
  sprite.userData.isLabel = true;
  return sprite;
}

function roundRect(ctx, x, y, width, height, radius) {
  const r = Math.min(radius, width / 2, height / 2);
  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.arcTo(x + width, y, x + width, y + height, r);
  ctx.arcTo(x + width, y + height, x, y + height, r);
  ctx.arcTo(x, y + height, x, y, r);
  ctx.arcTo(x, y, x + width, y, r);
  ctx.closePath();
}

function build3D(model) {
  if (!state.ready || !model) return;
  state.model = model;
  clearGraph();

  const positioned = new Map();
  const timeline = (model.nodes || []).filter(node => node.kind !== 'resource');
  timeline.forEach(node => positioned.set(node.id, projectPosition(node, model, positioned)));
  (model.nodes || []).filter(node => node.kind === 'resource').forEach(node => positioned.set(node.id, projectPosition(node, model, positioned)));

  for (const node of model.nodes || []) {
    const position = positioned.get(node.id) || new THREE.Vector3();
    const mesh = new THREE.Mesh(makeNodeGeometry(node), makeNodeMaterial(node));
    mesh.position.copy(position);
    mesh.userData.nodeId = node.id;
    mesh.userData.baseScale = 1;
    mesh.userData.node = node;
    mesh.renderOrder = 2;

    const label = makeLabelSprite(node);
    mesh.add(label);
    state.graphGroup.add(mesh);
    state.nodeObjects.set(node.id, mesh);
    state.clickable.push(mesh);
  }

  for (const edge of model.edges || []) {
    const source = positioned.get(edge.source);
    const target = positioned.get(edge.target);
    if (!source || !target) continue;
    const relation = edge.kind === 'relation';
    const points = relation ? curvedPoints(source, target) : [source, target];
    const geometry = new THREE.BufferGeometry().setFromPoints(points);
    const material = relation
      ? new THREE.LineDashedMaterial({
          color: edge.selectedResource === false ? 0x6e7681 : 0x56d364,
          transparent: true,
          opacity: edge.selectedResource === false ? 0.34 : 0.64,
          dashSize: 12,
          gapSize: 9
        })
      : new THREE.LineBasicMaterial({ color: edge.error ? 0xf85149 : 0x64748b, transparent: true, opacity: 0.68 });
    const line = new THREE.Line(geometry, material);
    if (relation) line.computeLineDistances();
    line.renderOrder = 1;
    state.graphGroup.add(line);
  }

  addAxisLabels();
  applySelection(state.selectedId);
  if (state.activeView === '3d') requestAnimationFrame(fit3D);
}

function curvedPoints(source, target) {
  const mid = source.clone().lerp(target, 0.5);
  mid.z += target.z > 0 ? 45 : -45;
  const curve = new THREE.QuadraticBezierCurve3(source.clone(), mid, target.clone());
  return curve.getPoints(20);
}

function addAxisLabels() {
  const labels = [
    { text: 'PIPELINE / TIEMPO', pos: new THREE.Vector3(-610, 500, 0), color: 0x8b949e },
    { text: 'CONTEXTO USADO', pos: new THREE.Vector3(-520, 455, 290), color: 0x56d364 },
    { text: 'DESCARTADO', pos: new THREE.Vector3(-520, 455, -290), color: 0x8b949e }
  ];
  labels.forEach(item => {
    const node = { fullTitle: item.text, title: item.text, subtitle: '', meta: '', category: 'trace' };
    const sprite = makeLabelSprite(node);
    sprite.material.color.setHex(item.color);
    sprite.scale.multiplyScalar(0.78);
    sprite.position.copy(item.pos);
    state.graphGroup.add(sprite);
  });
}

function applySelection(nodeId) {
  state.selectedId = nodeId || null;
  state.nodeObjects.forEach((mesh, id) => {
    const selected = id === state.selectedId;
    mesh.scale.setScalar(selected ? 1.38 : 1);
    if (mesh.material?.emissive) mesh.material.emissiveIntensity = selected ? 0.72 : (mesh.userData.node?.selectedResource === false ? 0.05 : 0.12);
  });
}

function focusSelected() {
  if (!state.selectedId) return;
  const mesh = state.nodeObjects.get(state.selectedId);
  if (!mesh) return;
  focusObject(mesh);
}

function focusObject(mesh) {
  if (!state.camera || !state.controls) return;
  const target = new THREE.Vector3();
  mesh.getWorldPosition(target);
  const direction = state.camera.position.clone().sub(state.controls.target).normalize();
  const distance = mesh.userData.node?.kind === 'resource' ? 330 : 280;
  state.controls.target.copy(target);
  state.camera.position.copy(target.clone().add(direction.multiplyScalar(distance)));
  state.controls.update();
}

function fit3D() {
  if (!state.ready || !state.nodeObjects.size) return;
  const box = new THREE.Box3();
  state.nodeObjects.forEach(mesh => box.expandByPoint(mesh.position));
  if (box.isEmpty()) return;
  const center = box.getCenter(new THREE.Vector3());
  const size = box.getSize(new THREE.Vector3());
  const maxDim = Math.max(size.x, size.y, size.z, 300);
  const fov = THREE.MathUtils.degToRad(state.camera.fov);
  let distance = Math.abs(maxDim / (2 * Math.tan(fov / 2))) * 1.34;
  distance = Math.min(Math.max(distance, 520), 3400);
  const direction = new THREE.Vector3(0.72, 0.48, 1).normalize();
  state.controls.target.copy(center);
  state.camera.position.copy(center.clone().add(direction.multiplyScalar(distance)));
  state.camera.near = Math.max(0.1, distance / 2000);
  state.camera.far = Math.max(7000, distance * 6);
  state.camera.updateProjectionMatrix();
  state.controls.update();
}

function resizeRenderer() {
  if (!state.ready || !el.host || !state.renderer || !state.camera) return;
  const rect = el.host.getBoundingClientRect();
  const width = Math.max(320, Math.floor(rect.width));
  const height = Math.max(320, Math.floor(rect.height));
  state.renderer.setSize(width, height, false);
  state.camera.aspect = width / height;
  state.camera.updateProjectionMatrix();
}

function animate() {
  if (!state.ready) return;
  state.controls.update();
  state.renderer.render(state.scene, state.camera);
}

function pointerNdc(event) {
  const rect = state.renderer.domElement.getBoundingClientRect();
  state.pointer.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
  state.pointer.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;
}

function hitTest(event) {
  pointerNdc(event);
  state.raycaster.setFromCamera(state.pointer, state.camera);
  const intersections = state.raycaster.intersectObjects(state.clickable, false);
  return intersections.length ? intersections[0].object : null;
}

function wireInteraction() {
  const canvas = state.renderer.domElement;
  canvas.addEventListener('pointerdown', event => {
    state.pointerDown = { x: event.clientX, y: event.clientY, time: performance.now() };
  });
  canvas.addEventListener('pointerup', event => {
    if (!state.pointerDown) return;
    const moved = Math.hypot(event.clientX - state.pointerDown.x, event.clientY - state.pointerDown.y);
    const elapsed = performance.now() - state.pointerDown.time;
    state.pointerDown = null;
    if (moved > 6 || elapsed > 650) return;
    const mesh = hitTest(event);
    if (!mesh) return;
    bridge.selectNode(mesh.userData.nodeId);
  });
  canvas.addEventListener('dblclick', event => {
    const mesh = hitTest(event);
    if (mesh) focusObject(mesh);
  });
  canvas.addEventListener('pointermove', event => {
    const mesh = hitTest(event);
    const nodeId = mesh?.userData?.nodeId || null;
    canvas.style.cursor = nodeId ? 'pointer' : 'grab';
    if (nodeId === state.hoveredId) {
      if (nodeId) positionTooltip(event);
      return;
    }
    state.hoveredId = nodeId;
    if (!nodeId) {
      if (el.tooltip) el.tooltip.hidden = true;
      return;
    }
    const node = state.model?.nodes?.find(item => item.id === nodeId);
    if (el.tooltip && node) {
      el.tooltip.innerHTML = `<strong>${esc(node.fullTitle || node.title)}</strong><span>${esc(short(node.subtitle, 100))}</span><small>${esc(node.meta || '')}</small>`;
      el.tooltip.hidden = false;
      positionTooltip(event);
    }
  });
  canvas.addEventListener('pointerleave', () => {
    state.hoveredId = null;
    if (el.tooltip) el.tooltip.hidden = true;
  });
}

function positionTooltip(event) {
  if (!el.tooltip || !el.host) return;
  const hostRect = el.host.getBoundingClientRect();
  const x = Math.min(hostRect.width - 250, Math.max(10, event.clientX - hostRect.left + 14));
  const y = Math.min(hostRect.height - 110, Math.max(10, event.clientY - hostRect.top + 14));
  el.tooltip.style.transform = `translate(${x}px, ${y}px)`;
}

function zoomCamera(factor) {
  if (!state.ready) return;
  const offset = state.camera.position.clone().sub(state.controls.target);
  const next = offset.length() * factor;
  if (next < state.controls.minDistance || next > state.controls.maxDistance) return;
  offset.setLength(next);
  state.camera.position.copy(state.controls.target).add(offset);
  state.controls.update();
}

function toggleAutoRotate() {
  if (!state.controls) return;
  state.controls.autoRotate = !state.controls.autoRotate;
  state.controls.autoRotateSpeed = 0.75;
  el.autoRotate?.classList.toggle('active', state.controls.autoRotate);
  el.autoRotate?.setAttribute('aria-pressed', state.controls.autoRotate ? 'true' : 'false');
}

el.view2dBtn?.addEventListener('click', () => setView('2d'));
el.view3dBtn?.addEventListener('click', () => {
  if (state.ready) setView('3d');
});
el.fit?.addEventListener('click', fit3D);
el.focus?.addEventListener('click', focusSelected);
el.autoRotate?.addEventListener('click', toggleAutoRotate);
el.commonFit?.addEventListener('click', () => { if (state.activeView === '3d') fit3D(); });
el.commonZoomIn?.addEventListener('click', () => { if (state.activeView === '3d') zoomCamera(0.82); });
el.commonZoomOut?.addEventListener('click', () => { if (state.activeView === '3d') zoomCamera(1.22); });

if (typeof ResizeObserver !== 'undefined' && el.host) {
  state.resizeObserver = new ResizeObserver(() => {
    if (state.activeView === '3d') resizeRenderer();
  });
  state.resizeObserver.observe(el.host);
} else {
  window.addEventListener('resize', resizeRenderer, { passive: true });
}

if (initThree()) {
  bridge.subscribeModel(model => {
    build3D(model);
    if (state.activeView === '2d' && cfg.initialView !== '2d') setView('3d');
  });
  bridge.subscribeSelection(node => {
    applySelection(node?.id || null);
  });
  // 3D es la vista principal de Fase 7.6, pero 2D queda como fallback inmediato.
  if (bridge.getModel()) {
    build3D(bridge.getModel());
    setView(cfg.initialView === '2d' ? '2d' : '3d');
  }
}
