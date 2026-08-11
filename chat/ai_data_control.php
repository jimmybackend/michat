<?php
/**
 * ai_data_control.php
 *
 * Panel visual avanzado para controlar/editar datos internos de IA:
 * - ChatSessions.meta / context_summary
 * - ProjectContext completo
 * - SourceChunks + ChunkEmbeddings
 * - PromptCompilations
 * - PhaseCache
 * - ToolCalls
 */

session_start();
require_once __DIR__ . '/app_bootstrap.php';

if (!isset($_SESSION['usuario']) || empty($_SESSION['usuario'])) {
    header("Location: ../index.php");
    exit;
}

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    die('DB no disponible');
}

$sessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

$userId = 0;
foreach (['user_id_', 'user_id', 'id_usuario', 'id_user', 'id'] as $k) {
    if (isset($_SESSION[$k]) && ctype_digit((string)$_SESSION[$k])) {
        $userId = (int)$_SESSION[$k];
        break;
    }
}

if ($userId <= 0) {
    die('Usuario inválido');
}

$sessionTitle = null;

if ($sessionId > 0) {
    $stmt = $db_connection->prepare("SELECT title, project_id_ FROM ChatSessions WHERE id_ = ? AND user_id_ = ? LIMIT 1");
    if (!$stmt) {
        die('Error DB');
    }

    $stmt->bind_param('ii', $sessionId, $userId);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$session) {
        die('Sesión no encontrada o sin permisos');
    }

    $sessionTitle = $session['title'];

    if ($projectId <= 0 && !empty($session['project_id_'])) {
        $projectId = (int)$session['project_id_'];
    }
}

$projectName = null;

if ($projectId > 0) {
    $found = false;
    $userCols = ['user_id_', 'user_id', 'id_usuario', 'id_user'];

    foreach ($userCols as $userCol) {
        try {
            $stmt = $db_connection->prepare("SELECT name FROM Projects WHERE id_ = ? AND {$userCol} = ? LIMIT 1");
            if (!$stmt) {
                continue;
            }

            $stmt->bind_param('ii', $projectId, $userId);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($row = $res->fetch_assoc()) {
                $projectName = $row['name'] ?? ('Proyecto #' . $projectId);
                $found = true;
                $stmt->close();
                break;
            }

            $stmt->close();
        } catch (Throwable $e) {
            continue;
        }
    }

    if (!$found) {
        $projectId = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Control de Datos IA</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.3.1/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link rel="icon" href="asistente-de-inteligencia-artificial.gif" type="image/x-icon">
<link rel="stylesheet" href="css/chat2.css">
<link rel="stylesheet" href="css/design-system.css">

<style>
    body {
        background: var(--bg) !important;
        color: var(--text) !important;
        font-family: 'Inter', var(--font-sans) !important;
        min-height: 100vh;
        margin: 0;
    }

    .control-header {
        background: var(--bg2) !important;
        border-bottom: 1px solid var(--border) !important;
        padding: 20px 30px;
        position: sticky;
        top: 0;
        z-index: 100;
        backdrop-filter: blur(8px);
    }

    .control-header h1 {
        color: var(--text-strong) !important;
        font-size: 1.5rem;
        font-weight: 700;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .control-header h1 i {
        color: var(--accent);
    }

    .control-container {
        max-width: 1500px;
        margin: 0 auto;
        padding: 30px;
    }

    .tab-bar {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 20px;
    }

    .tab-btn {
        background: var(--bg3) !important;
        color: var(--text) !important;
        border: 1px solid var(--border) !important;
        padding: 8px 14px;
        border-radius: var(--radius) !important;
        cursor: pointer;
        font-weight: 600;
        font-size: 0.85rem;
        transition: all 0.15s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .tab-btn:hover,
    .tab-btn.active {
        border-color: var(--accent) !important;
        color: var(--accent) !important;
        background: rgba(var(--accent-rgb), 0.08) !important;
    }

    .panel-card {
        background: var(--bg2) !important;
        border: 1px solid var(--border) !important;
        border-radius: var(--radius) !important;
        margin-bottom: 18px;
        overflow: hidden;
    }

    .panel-card-body {
        padding: 18px;
    }

    .section-title {
        font-size: 0.82rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--accent) !important;
        margin: 0 0 16px 0;
        padding-bottom: 8px;
        border-bottom: 2px solid var(--border-soft) !important;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }

    .section-title-left {
        display: flex;
        align-items: center;
        gap: 8px;
        min-width: 0;
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 12px;
        margin-bottom: 16px;
    }

    .info-item {
        background: var(--bg3) !important;
        border: 1px solid var(--border-soft) !important;
        border-radius: 6px;
        padding: 10px 12px;
    }

    .info-label {
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--text-soft) !important;
        margin-bottom: 4px;
    }

    .info-value {
        font-size: 0.9rem;
        color: var(--text-strong) !important;
        word-break: break-word;
    }

    .form-label {
        display: block;
        font-size: 0.78rem;
        font-weight: 700;
        color: var(--text-strong) !important;
        margin: 12px 0 6px 0;
    }

    .form-control-dark,
    .form-select-dark {
        width: 100%;
        background: var(--bg) !important;
        color: var(--text) !important;
        border: 1px solid var(--border) !important;
        border-radius: 6px;
        padding: 10px 12px;
        font-size: 0.9rem;
    }

    textarea.form-control-dark {
        min-height: 140px;
        font-family: 'JetBrains Mono', monospace;
        font-size: 0.8rem;
        line-height: 1.5;
        resize: vertical;
    }

    .form-control-dark:focus,
    .form-select-dark:focus {
        outline: none;
        border-color: var(--accent) !important;
        box-shadow: 0 0 0 3px rgba(var(--accent-rgb), .15);
    }

    .btn-control {
        background: var(--bg3) !important;
        color: var(--text) !important;
        border: 1px solid var(--border) !important;
        padding: 8px 14px;
        border-radius: var(--radius) !important;
        cursor: pointer;
        font-weight: 600;
        font-size: 0.85rem;
        transition: all 0.15s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .btn-control:hover {
        border-color: var(--accent) !important;
        color: var(--accent) !important;
        background: rgba(var(--accent-rgb), 0.06) !important;
    }

    .btn-control.danger:hover {
        border-color: var(--danger) !important;
        color: var(--danger) !important;
        background: rgba(248,81,73,.08) !important;
    }

    .badge-mini {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 9px;
        border-radius: 999px;
        font-size: 0.68rem;
        font-weight: 700;
        border: 1px solid var(--border);
        background: var(--bg3);
        color: var(--text);
    }

    .badge-accent { border-color: var(--accent); color: var(--accent); background: rgba(var(--accent-rgb), .1); }
    .badge-success { border-color: var(--ok); color: var(--ok); background: rgba(var(--ok-rgb, 63,185,80), .1); }
    .badge-warning { border-color: var(--warn); color: var(--warn); background: rgba(210,153,34,.1); }
    .badge-danger { border-color: var(--danger); color: var(--danger); background: rgba(248,81,73,.1); }

    .mono-preview {
        font-family: 'JetBrains Mono', monospace;
        font-size: 0.75rem;
        line-height: 1.5;
        white-space: pre-wrap;
        word-break: break-word;
        background: var(--bg) !important;
        border: 1px solid var(--border-soft) !important;
        border-radius: 6px;
        padding: 12px;
        max-height: 280px;
        overflow: auto;
        color: var(--text) !important;
    }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: var(--text-soft) !important;
    }

    .empty-state i {
        font-size: 3rem;
        margin-bottom: 16px;
        opacity: 0.3;
        color: var(--accent) !important;
    }

    .empty-state h3 {
        color: var(--text-strong) !important;
        margin-bottom: 8px;
        font-size: 1.1rem;
    }

    .loading {
        text-align: center;
        padding: 80px 20px;
        color: var(--text-soft) !important;
    }

    .loading i {
        color: var(--accent);
    }

    .ctx-modal-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,.55);
        display: none;
        z-index: 1000;
    }

    .ctx-modal-backdrop.open {
        display: block;
    }

    .ctx-modal {
        display: none;
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: min(900px, calc(100vw - 40px));
        max-height: 88vh;
        background: var(--bg2);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        z-index: 1001;
        flex-direction: column;
        box-shadow: var(--shadow);
    }

    .ctx-modal.open {
        display: flex;
    }

    .ctx-modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 16px 20px;
        border-bottom: 1px solid var(--border);
    }

    .ctx-modal-title {
        margin: 0;
        font-size: 1rem;
        font-weight: 800;
        color: var(--text-strong);
    }

    .ctx-modal-close {
        background: transparent;
        border: 0;
        color: var(--text-soft);
        font-size: 1.4rem;
        line-height: 1;
        cursor: pointer;
    }

    .ctx-modal-close:hover {
        color: var(--danger);
    }

    .ctx-modal-body {
        padding: 20px;
        overflow: auto;
    }

    .ctx-modal-footer {
        padding: 14px 20px;
        border-top: 1px solid var(--border);
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        flex-wrap: wrap;
    }

    .notify-toast {
        position: fixed;
        bottom: 20px;
        right: 20px;
        z-index: 2000;
        background: var(--bg2);
        color: var(--text);
        border: 1px solid var(--ok);
        border-radius: 8px;
        padding: 12px 16px;
        box-shadow: var(--shadow);
        max-width: 360px;
    }

    .notify-toast.error {
        border-color: var(--danger);
    }

    .mt-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-top: 12px;
    }

    .subcard {
        background: var(--bg3) !important;
        border: 1px solid var(--border-soft) !important;
        border-radius: 6px;
        padding: 12px;
        margin-top: 10px;
    }

    @media (max-width: 768px) {
        .control-container { padding: 16px; }
        .control-header { padding: 16px; }
        .control-header h1 { font-size: 1.2rem; }
    }
</style>
</head>
<body class="ui-theme theme-neon-green">

<div class="control-header">
    <div style="display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <a href="index.php" style="color: var(--text-soft); text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fas fa-arrow-left"></i> Volver
            </a>

            <div>
                <h1>
                    <i class="fas fa-database"></i>
                    Control de Datos IA
                </h1>
                <div style="color: var(--text-soft); font-size: 0.85rem; margin-top: 5px;">
                    <i class="fas fa-comment-dots"></i>
                    <?= htmlspecialchars($sessionTitle ?: 'Sin sesión') ?>
                    <?= $sessionId > 0 ? '(#' . $sessionId . ')' : '' ?>
                    &nbsp;·&nbsp;
                    <i class="fas fa-briefcase"></i>
                    <?= htmlspecialchars($projectName ?: 'Sin proyecto') ?>
                    <?= $projectId > 0 ? '(#' . $projectId . ')' : '' ?>
                </div>
            </div>
        </div>

        <button class="btn-control" onclick="loadData()">
            <i class="fas fa-sync"></i> Actualizar
        </button>
    </div>
</div>

<div class="control-container">
    <div class="tab-bar">
        <button class="tab-btn active" data-tab="session"><i class="fas fa-comment-dots"></i> Sesión</button>
        <button class="tab-btn" data-tab="project"><i class="fas fa-briefcase"></i> Proyecto</button>
        <button class="tab-btn" data-tab="chunks"><i class="fas fa-puzzle-piece"></i> Chunks/Embeddings</button>
        <button class="tab-btn" data-tab="prompt"><i class="fas fa-magic"></i> Prompt Compilado</button>
        <button class="tab-btn" data-tab="cache"><i class="fas fa-layer-group"></i> Cache/Herramientas</button>
    </div>

    <div id="content">
        <div class="loading">
            <i class="fas fa-spinner fa-spin fa-3x"></i>
            <p style="margin-top: 20px; font-size: 0.95rem;">Cargando datos internos...</p>
        </div>
    </div>
</div>

<div id="modalBackdrop" class="ctx-modal-backdrop" onclick="closeModal()"></div>
<div id="modal" class="ctx-modal" role="dialog" aria-modal="true">
    <div class="ctx-modal-header">
        <h3 id="modalTitle" class="ctx-modal-title"></h3>
        <button type="button" class="ctx-modal-close" onclick="closeModal()" aria-label="Cerrar">&times;</button>
    </div>
    <div id="modalBody" class="ctx-modal-body"></div>
    <div id="modalFooter" class="ctx-modal-footer"></div>
</div>

<script>
const sessionId = <?= (int)$sessionId ?>;
const projectId = <?= (int)$projectId ?>;

let DATA = null;
let activeTab = 'session';

const PROJECT_TYPES = {
    rule: 'Regla',
    decision: 'Decisión',
    fact: 'Hecho',
    style: 'Estilo',
    todo: 'Pendiente',
    note: 'Nota'
};

const CHUNK_TYPES = {
    file: 'Archivo',
    namespace: 'Namespace',
    class: 'Clase',
    trait: 'Trait',
    interface: 'Interfaz',
    function: 'Función',
    method: 'Método',
    block: 'Bloque',
    comment: 'Comentario',
    docstring: 'Docstring',
    import: 'Import',
    other: 'Otro'
};

const PROMPT_STATUS = {
    pending: 'Pendiente',
    approved: 'Aprobado',
    rejected: 'Rechazado'
};

document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach(x => x.classList.remove('active'));
        btn.classList.add('active');
        activeTab = btn.getAttribute('data-tab');
        renderActiveTab();
    });
});

async function loadData() {
    const container = document.getElementById('content');

    try {
        const qs = new URLSearchParams();
        if (sessionId > 0) qs.set('session_id', sessionId);
        if (projectId > 0) qs.set('project_id', projectId);

        const response = await fetch(`ai_data_api.php?${qs.toString()}`, {
            credentials: 'same-origin',
            cache: 'no-cache'
        });

        const text = await response.text();

        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            throw new Error('El servidor devolvió HTML en lugar de JSON. Respuesta: ' + text.slice(0, 300));
        }

        if (!data.ok) {
            throw new Error(data.error || 'Error desconocido');
        }

        DATA = data;
        renderActiveTab();
    } catch (error) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-exclamation-triangle"></i>
                <h3>Error al cargar datos</h3>
                <p>${escapeHtml(error.message)}</p>
                <button class="btn-control" onclick="loadData()" style="margin-top: 20px;">
                    <i class="fas fa-sync"></i> Reintentar
                </button>
            </div>
        `;
    }
}

function renderActiveTab() {
    const container = document.getElementById('content');

    if (!DATA) {
        container.innerHTML = '<div class="loading">Sin datos.</div>';
        return;
    }

    if (activeTab === 'session') {
        container.innerHTML = renderSessionTab();
    } else if (activeTab === 'project') {
        container.innerHTML = renderProjectTab();
    } else if (activeTab === 'chunks') {
        container.innerHTML = renderChunksTab();
    } else if (activeTab === 'prompt') {
        container.innerHTML = renderPromptTab();
    } else if (activeTab === 'cache') {
        container.innerHTML = renderCacheTab();
    }
}

function renderSessionTab() {
    const s = DATA.session;

    if (!s) {
        return emptyState('fa-comment-dots', 'Sin sesión', 'Selecciona una sesión para ver sus campos internos.');
    }

    const level = parseInt(s.context_level || 0, 10);

    return `
        <div class="panel-card">
            <div class="panel-card-body">
                <div class="section-title">
                    <div class="section-title-left">
                        <i class="fas fa-comment-dots"></i>
                        ChatSessions
                    </div>
                </div>

                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Título</div>
                        <div class="info-value">${escapeHtml(s.title || '')}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Modelo</div>
                        <div class="info-value">${escapeHtml(s.model_id || '')}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Estado</div>
                        <div class="info-value">${escapeHtml(s.status || '')}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Última compresión</div>
                        <div class="info-value">${formatDate(s.last_compressed_at)}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Embedding de contexto</div>
                        <div class="info-value">${Number(s.context_embedding_length || 0).toLocaleString()} caracteres</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Actualizado</div>
                        <div class="info-value">${formatDate(s.updated_at)}</div>
                    </div>
                </div>

                <label class="form-label">context_summary</label>
                <textarea id="sessContextSummary" class="form-control-dark">${escapeHtml(s.context_summary || '')}</textarea>

                <label class="form-label">meta (JSON)</label>
                <textarea id="sessMeta" class="form-control-dark">${escapeHtml(s.meta_pretty || '')}</textarea>

                <div class="info-grid" style="margin-top: 16px; margin-bottom: 0;">
                    <div class="info-item">
                        <div class="info-label">context_level</div>
                        <select id="sessContextLevel" class="form-select-dark">
                            <option value="0" ${level === 0 ? 'selected' : ''}>0 · Crudo</option>
                            <option value="1" ${level === 1 ? 'selected' : ''}>1 · Resumen x5</option>
                            <option value="2" ${level === 2 ? 'selected' : ''}>2 · Macro x20</option>
                            <option value="3" ${level === 3 ? 'selected' : ''}>3 · Épico x80</option>
                        </select>
                    </div>

                    <div class="info-item">
                        <div class="info-label">pending_summary</div>
                        <label style="display:flex; align-items:center; gap:8px; margin-top:8px; cursor:pointer;">
                            <input type="checkbox" id="sessPendingSummary" ${Number(s.pending_summary || 0) === 1 ? 'checked' : ''}>
                            Pendiente de resumir
                        </label>
                    </div>
                </div>

                <div class="mt-actions">
                    <button class="btn-control" onclick="saveSessionData()">
                        <i class="fas fa-save"></i> Guardar sesión
                    </button>
                </div>
            </div>
        </div>
    `;
}

function renderProjectTab() {
    const items = DATA.project_context || [];

    let html = `
        <div class="panel-card">
            <div class="panel-card-body">
                <div class="section-title">
                    <div class="section-title-left">
                        <i class="fas fa-briefcase"></i>
                        ProjectContext completo
                    </div>
                    <button class="btn-control" onclick="openProjectContextForm(0)">
                        <i class="fas fa-plus"></i> Agregar
                    </button>
                </div>
    `;

    if (projectId <= 0) {
        html += emptyState('fa-briefcase', 'Sin proyecto', 'Selecciona una sesión con proyecto o abre esta página con project_id.');
    } else if (items.length === 0) {
        html += emptyState('fa-briefcase', 'Sin contexto de proyecto', 'No hay registros en ProjectContext para este proyecto.');
    } else {
        items.forEach(item => {
            html += `
                <div class="subcard">
                    <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center;">
                        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                            <span class="badge-mini badge-accent">${escapeHtml(PROJECT_TYPES[item.type] || item.type)}</span>
                            <strong>${escapeHtml(item.title || 'Sin título')}</strong>
                        </div>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <button class="btn-control" onclick="openProjectContextForm(${Number(item.id_)})">
                                <i class="fas fa-pen"></i> Editar
                            </button>
                            <button class="btn-control danger" onclick="deleteProjectContext(${Number(item.id_)})">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>

                    <div class="info-grid" style="margin-top:12px; margin-bottom:12px;">
                        <div class="info-item">
                            <div class="info-label">ID</div>
                            <div class="info-value">${Number(item.id_)}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">source_chunk_id</div>
                            <div class="info-value">${item.source_chunk_id ? Number(item.source_chunk_id) : '—'}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Embedding</div>
                            <div class="info-value">${Number(item.embedding_length || 0).toLocaleString()} caracteres</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Creado</div>
                            <div class="info-value">${formatDate(item.created_at)}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Actualizado</div>
                            <div class="info-value">${formatDate(item.updated_at)}</div>
                        </div>
                    </div>

                    <div class="mono-preview">${escapeHtml(item.content || '')}</div>
                </div>
            `;
        });
    }

    html += `
            </div>
        </div>
    `;

    return html;
}

function renderChunksTab() {
    const chunks = DATA.source_chunks || [];

    let html = `
        <div class="panel-card">
            <div class="panel-card-body">
                <div class="section-title">
                    <div class="section-title-left">
                        <i class="fas fa-puzzle-piece"></i>
                        SourceChunks + ChunkEmbeddings
                    </div>
                </div>
    `;

    if (projectId <= 0) {
        html += emptyState('fa-puzzle-piece', 'Sin proyecto', 'Selecciona un proyecto para ver chunks y embeddings.');
    } else if (chunks.length === 0) {
        html += emptyState('fa-puzzle-piece', 'Sin chunks', 'No hay SourceChunks indexados para este proyecto.');
    } else {
        chunks.forEach(chunk => {
            const embeddings = chunk.embeddings || [];

            html += `
                <div class="subcard">
                    <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center;">
                        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                            <span class="badge-mini badge-warning">${escapeHtml(CHUNK_TYPES[chunk.chunk_type] || chunk.chunk_type)}</span>
                            <strong>${escapeHtml(chunk.name || 'Chunk #' + chunk.id_)}</strong>
                            ${chunk.source_filename ? `<span class="badge-mini">${escapeHtml(chunk.source_filename)}</span>` : ''}
                        </div>

                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <button class="btn-control" onclick="openChunkForm(${Number(chunk.id_)})">
                                <i class="fas fa-pen"></i> Editar chunk
                            </button>
                            <button class="btn-control" onclick="toggleEmbeddings(${Number(chunk.id_)})">
                                <i class="fas fa-vector-square"></i> Embeddings (${embeddings.length})
                            </button>
                        </div>
                    </div>

                    <div class="info-grid" style="margin-top:12px; margin-bottom:12px;">
                        <div class="info-item">
                            <div class="info-label">Líneas</div>
                            <div class="info-value">${Number(chunk.start_line || 0)} - ${Number(chunk.end_line || 0)}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Tokens</div>
                            <div class="info-value">${Number(chunk.token_count || 0).toLocaleString()}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Contenido</div>
                            <div class="info-value">${Number(chunk.content_length || 0).toLocaleString()} caracteres</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Checksum</div>
                            <div class="info-value" style="font-size:0.7rem; font-family:monospace;">${escapeHtml(chunk.checksum || '—')}</div>
                        </div>
                    </div>

                    <div class="mono-preview">${escapeHtml(chunk.content_preview || '')}</div>

                    <div id="chunk-embeddings-${Number(chunk.id_)}" style="display:none;">
                        ${renderEmbeddings(chunk)}
                    </div>
                </div>
            `;
        });
    }

    html += `
            </div>
        </div>
    `;

    return html;
}

function renderEmbeddings(chunk) {
    const embeddings = chunk.embeddings || [];

    if (embeddings.length === 0) {
        return `<div class="subcard">Sin embeddings para este chunk.</div>`;
    }

    return embeddings.map(e => `
        <div class="subcard">
            <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center;">
                <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                    <span class="badge-mini badge-accent">Embedding #${Number(e.id_)}</span>
                    <span class="badge-mini">${escapeHtml(e.model_id || '')}</span>
                    <span class="badge-mini">${Number(e.dimensions || 0)} dims</span>
                    <span class="badge-mini">${Number(e.embedding_bytes || 0).toLocaleString()} bytes</span>
                </div>

                <button class="btn-control" onclick="openEmbeddingForm(${Number(e.id_)})">
                    <i class="fas fa-pen"></i> Editar JSON
                </button>
            </div>

            <div style="margin-top:10px; color:var(--text-soft); font-size:0.75rem;">
                JSON: ${Number(e.embedding_json_length || 0).toLocaleString()} caracteres · Creado: ${formatDate(e.created_at)}
            </div>

            <div class="mono-preview" style="margin-top:8px;">${escapeHtml(e.embedding_json_preview || '')}</div>
        </div>
    `).join('');
}

function renderPromptTab() {
    const items = DATA.prompt_compilations || [];

    let html = `
        <div class="panel-card">
            <div class="panel-card-body">
                <div class="section-title">
                    <div class="section-title-left">
                        <i class="fas fa-magic"></i>
                        PromptCompilations
                    </div>
                </div>
    `;

    if (sessionId <= 0) {
        html += emptyState('fa-magic', 'Sin sesión', 'Selecciona una sesión para ver prompts compilados.');
    } else if (items.length === 0) {
        html += emptyState('fa-magic', 'Sin prompts compilados', 'No hay registros en PromptCompilations para esta sesión.');
    } else {
        items.forEach(item => {
            html += `
                <div class="subcard">
                    <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center;">
                        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                            <span class="badge-mini ${promptStatusClass(item.status)}">${escapeHtml(PROMPT_STATUS[item.status] || item.status)}</span>
                            <span class="badge-mini">msg ${Number(item.user_msg_id || 0)}</span>
                            <span class="badge-mini">${Number(item.compiled_length || 0).toLocaleString()} caracteres</span>
                        </div>

                        <button class="btn-control" onclick="openPromptForm(${Number(item.id_)})">
                            <i class="fas fa-pen"></i> Ver/Editar
                        </button>
                    </div>

                    <div class="info-grid" style="margin-top:12px; margin-bottom:12px;">
                        <div class="info-item">
                            <div class="info-label">Creado</div>
                            <div class="info-value">${formatDate(item.created_at)}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Editado por usuario</div>
                            <div class="info-value">${Number(item.was_edited_by_user || 0) === 1 ? 'Sí' : 'No'}</div>
                        </div>
                    </div>

                    <div class="mono-preview">${escapeHtml(item.compiled_preview || '')}</div>
                </div>
            `;
        });
    }

    html += `
            </div>
        </div>
    `;

    return html;
}

function renderCacheTab() {
    const phaseCache = DATA.phase_cache || [];
    const toolCalls = DATA.tool_calls || [];

    let html = `
        <div class="panel-card">
            <div class="panel-card-body">
                <div class="section-title">
                    <div class="section-title-left">
                        <i class="fas fa-layer-group"></i>
                        PhaseCache
                    </div>
                    ${projectId > 0 && phaseCache.length > 0 ? `
                        <button class="btn-control danger" onclick="clearPhaseCache()">
                            <i class="fas fa-trash"></i> Limpiar caché
                        </button>
                    ` : ''}
                </div>
    `;

    if (projectId <= 0) {
        html += emptyState('fa-layer-group', 'Sin proyecto', 'Selecciona un proyecto para ver PhaseCache.');
    } else if (phaseCache.length === 0) {
        html += emptyState('fa-layer-group', 'Sin caché', 'No hay entradas en PhaseCache para este proyecto.');
    } else {
        phaseCache.forEach(item => {
            html += `
                <div class="subcard">
                    <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center;">
                        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                            <span class="badge-mini badge-accent">${escapeHtml(item.phase || '')}</span>
                            <span class="badge-mini">hits: ${Number(item.hit_count || 0)}</span>
                            <span class="badge-mini">${Number(item.payload_length || 0).toLocaleString()} chars</span>
                        </div>

                        <button class="btn-control danger" onclick="deletePhaseCache(${Number(item.id_)})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>

                    <div style="margin-top:10px; color:var(--text-soft); font-size:0.75rem;">
                        key: <span style="font-family:monospace;">${escapeHtml(item.cache_key || '')}</span><br>
                        creado: ${formatDate(item.created_at)} · expira: ${formatDate(item.expires_at)}
                    </div>

                    <div class="mono-preview" style="margin-top:8px;">${escapeHtml(item.payload_preview || '')}</div>
                </div>
            `;
        });
    }

    html += `
            </div>
        </div>

        <div class="panel-card">
            <div class="panel-card-body">
                <div class="section-title">
                    <div class="section-title-left">
                        <i class="fas fa-tools"></i>
                        ToolCalls
                    </div>
                </div>
    `;

    if (toolCalls.length === 0) {
        html += emptyState('fa-tools', 'Sin ToolCalls', 'No hay llamadas a herramientas para esta sesión/proyecto.');
    } else {
        toolCalls.forEach(item => {
            html += `
                <div class="subcard">
                    <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center;">
                        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                            <span class="badge-mini badge-warning">${escapeHtml(item.tool || '')}</span>
                            <span class="badge-mini ${item.status === 'ok' ? 'badge-success' : 'badge-danger'}">${escapeHtml(item.status || '')}</span>
                            <span class="badge-mini">${Number(item.duration_ms || 0)} ms</span>
                        </div>

                        <button class="btn-control danger" onclick="deleteToolCall(${Number(item.id_)})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>

                    <div class="info-grid" style="margin-top:12px; margin-bottom:12px;">
                        <div class="info-item">
                            <div class="info-label">Target</div>
                            <div class="info-value">${escapeHtml(item.target_path || '—')}</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Creado</div>
                            <div class="info-value">${formatDate(item.created_at)}</div>
                        </div>
                    </div>

                    <div class="mono-preview">${escapeHtml(item.params_preview || '')}</div>
                    <div class="mono-preview" style="margin-top:8px;">${escapeHtml(item.result_preview || '')}</div>
                </div>
            `;
        });
    }

    html += `
            </div>
        </div>
    `;

    return html;
}

// =====================================================================
// SESIÓN
// =====================================================================
async function saveSessionData() {
    if (sessionId <= 0) {
        notify('Selecciona una sesión válida.', true);
        return;
    }

    const payload = {
        action: 'update_session_data',
        session_id: sessionId,
        meta: document.getElementById('sessMeta').value,
        context_summary: document.getElementById('sessContextSummary').value,
        context_level: document.getElementById('sessContextLevel').value,
        pending_summary: document.getElementById('sessPendingSummary').checked ? 1 : 0
    };

    try {
        await apiPost(payload);
        notify('Sesión guardada correctamente.');
        await loadData();
    } catch (e) {
        notify(e.message, true);
    }
}

// =====================================================================
// PROJECT CONTEXT
// =====================================================================
function openProjectContextForm(id) {
    if (projectId <= 0) {
        notify('Selecciona un proyecto válido.', true);
        return;
    }

    const item = id ? (DATA.project_context || []).find(x => Number(x.id_) === id) : {};
    window.__pcEmbeddingLoaded = Number(item.embedding_length || 0) === 0;

    const typeOptions = Object.keys(PROJECT_TYPES).map(t => `
        <option value="${t}" ${(item.type || 'note') === t ? 'selected' : ''}>
            ${PROJECT_TYPES[t]} (${t})
        </option>
    `).join('');

    openModal(
        id ? 'Editar ProjectContext' : 'Agregar ProjectContext',
        `
            <label class="form-label">Tipo</label>
            <select id="pcType" class="form-select-dark">${typeOptions}</select>

            <label class="form-label">Título</label>
            <input id="pcTitle" class="form-control-dark" type="text" value="${escapeAttr(item.title || '')}">

            <label class="form-label">Contenido</label>
            <textarea id="pcContent" class="form-control-dark">${escapeHtml(item.content || '')}</textarea>

            <label class="form-label">source_chunk_id</label>
            <input id="pcSourceChunkId" class="form-control-dark" type="number" min="0" value="${Number(item.source_chunk_id || 0)}">

            <label class="form-label">Embedding</label>
            <div style="font-size:0.75rem; color:var(--text-soft); margin-bottom:6px;">
                Embedding actual: ${Number(item.embedding_length || 0).toLocaleString()} caracteres.
                ${Number(item.embedding_length || 0) > 0 ? 'Si quieres editarlo, primero cárgalo completo.' : ''}
            </div>
            ${Number(item.embedding_length || 0) > 0 ? `
                <div style="margin-bottom:8px;">
                    <button class="btn-control" onclick="loadProjectEmbedding(${id})">
                        <i class="fas fa-download"></i> Cargar embedding completo
                    </button>
                </div>
            ` : ''}
            <textarea id="pcEmbedding" class="form-control-dark" style="min-height:100px;">${escapeHtml(item.embedding_preview || '')}</textarea>
        `,
        `
            <button class="btn-control" onclick="closeModal()">Cancelar</button>
            <button class="btn-control" onclick="saveProjectContext(${id})">
                <i class="fas fa-save"></i> Guardar
            </button>
        `
    );
}

async function loadProjectEmbedding(id) {
    try {
        const res = await apiPost({
            action: 'get_project_context_embedding',
            id: id,
            project_id: projectId
        });

        document.getElementById('pcEmbedding').value = res.embedding || '';
        window.__pcEmbeddingLoaded = true;
        notify('Embedding cargado.');
    } catch (e) {
        notify(e.message, true);
    }
}

async function saveProjectContext(id) {
    const payload = {
        action: id ? 'update_project_context' : 'create_project_context',
        project_id: projectId,
        type: document.getElementById('pcType').value,
        title: document.getElementById('pcTitle').value,
        content: document.getElementById('pcContent').value,
        source_chunk_id: document.getElementById('pcSourceChunkId').value,
        embedding: document.getElementById('pcEmbedding').value
    };

    if (id) {
        payload.id = id;
        payload.update_embedding = window.__pcEmbeddingLoaded ? 1 : 0;
    }

    try {
        await apiPost(payload);
        closeModal();
        notify('ProjectContext guardado.');
        await loadData();
    } catch (e) {
        notify(e.message, true);
    }
}

async function deleteProjectContext(id) {
    if (!confirm('¿Eliminar este registro de ProjectContext?')) return;

    try {
        await apiPost({
            action: 'delete_project_context',
            id: id,
            project_id: projectId
        });

        notify('Registro eliminado.');
        await loadData();
    } catch (e) {
        notify(e.message, true);
    }
}

// =====================================================================
// SOURCE CHUNKS
// =====================================================================
function toggleEmbeddings(chunkId) {
    const el = document.getElementById(`chunk-embeddings-${chunkId}`);
    if (!el) return;

    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

async function openChunkForm(id) {
    const chunk = (DATA.source_chunks || []).find(x => Number(x.id_) === id);
    if (!chunk) return;

    openModal('Editar SourceChunk', '<div class="loading">Cargando contenido completo...</div>', '');

    try {
        const full = await apiPost({
            action: 'get_source_chunk_full',
            id: id,
            project_id: projectId
        });

        openModal(
            'Editar SourceChunk',
            `
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Nombre</div>
                        <div class="info-value">${escapeHtml(full.name || '')}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Tokens</div>
                        <input id="chunkTokenCount" class="form-control-dark" type="number" min="0" value="${Number(full.token_count || 0)}">
                    </div>
                </div>

                <label class="form-label">Contenido completo</label>
                <textarea id="chunkContent" class="form-control-dark" style="min-height:260px;">${escapeHtml(full.content || '')}</textarea>

                <label class="form-label">Meta (JSON)</label>
                <textarea id="chunkMeta" class="form-control-dark">${escapeHtml(full.meta_pretty || '')}</textarea>
            `,
            `
                <button class="btn-control" onclick="closeModal()">Cancelar</button>
                <button class="btn-control" onclick="saveChunk(${id})">
                    <i class="fas fa-save"></i> Guardar chunk
                </button>
            `
        );
    } catch (e) {
        closeModal();
        notify(e.message, true);
    }
}

async function saveChunk(id) {
    const payload = {
        action: 'update_source_chunk',
        id: id,
        project_id: projectId,
        content: document.getElementById('chunkContent').value,
        token_count: document.getElementById('chunkTokenCount').value,
        meta: document.getElementById('chunkMeta').value
    };

    try {
        await apiPost(payload);
        closeModal();
        notify('Chunk actualizado.');
        await loadData();
    } catch (e) {
        notify(e.message, true);
    }
}

// =====================================================================
// CHUNK EMBEDDINGS
// =====================================================================
async function openEmbeddingForm(id) {
    openModal('Editar ChunkEmbedding', '<div class="loading">Cargando embedding JSON...</div>', '');

    try {
        const full = await apiPost({
            action: 'get_chunk_embedding_full',
            id: id,
            project_id: projectId
        });

        openModal(
            'Editar ChunkEmbedding',
            `
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Modelo</div>
                        <div class="info-value">${escapeHtml(full.model_id || '')}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Dimensiones actuales</div>
                        <div class="info-value">${Number(full.dimensions || 0)}</div>
                    </div>
                </div>

                <div style="font-size:0.75rem; color:var(--warn); margin:8px 0;">
                    <i class="fas fa-exclamation-triangle"></i>
                    Editar embeddings puede afectar la búsqueda semántica. Solo hazlo si sabes exactamente qué vector estás guardando.
                </div>

                <label class="form-label">embedding_json</label>
                <textarea id="embeddingJson" class="form-control-dark" style="min-height:260px;">${escapeHtml(full.embedding_json_pretty || full.embedding_json || '')}</textarea>
            `,
            `
                <button class="btn-control" onclick="closeModal()">Cancelar</button>
                <button class="btn-control" onclick="saveEmbedding(${id})">
                    <i class="fas fa-save"></i> Guardar embedding
                </button>
            `
        );
    } catch (e) {
        closeModal();
        notify(e.message, true);
    }
}

async function saveEmbedding(id) {
    const payload = {
        action: 'update_chunk_embedding_json',
        id: id,
        project_id: projectId,
        embedding_json: document.getElementById('embeddingJson').value
    };

    try {
        await apiPost(payload);
        closeModal();
        notify('Embedding actualizado.');
        await loadData();
    } catch (e) {
        notify(e.message, true);
    }
}

// =====================================================================
// PROMPT COMPILATIONS
// =====================================================================
async function openPromptForm(id) {
    openModal('Editar PromptCompilado', '<div class="loading">Cargando prompt...</div>', '');

    try {
        const full = await apiPost({
            action: 'get_prompt_compilation_full',
            id: id,
            session_id: sessionId
        });

        const statusOptions = Object.keys(PROMPT_STATUS).map(s => `
            <option value="${s}" ${full.status === s ? 'selected' : ''}>
                ${PROMPT_STATUS[s]} (${s})
            </option>
        `).join('');

        openModal(
            'Editar PromptCompilado',
            `
                <label class="form-label">Estado</label>
                <select id="promptStatus" class="form-select-dark">${statusOptions}</select>

                <label class="form-label">Prompt compilado</label>
                <textarea id="promptCompiled" class="form-control-dark" style="min-height:260px;">${escapeHtml(full.compiled_prompt || '')}</textarea>

                <label class="form-label">Notas para el usuario</label>
                <textarea id="promptNotes" class="form-control-dark">${escapeHtml(full.notes_for_user || '')}</textarea>
            `,
            `
                <button class="btn-control" onclick="closeModal()">Cancelar</button>
                <button class="btn-control" onclick="savePrompt(${id})">
                    <i class="fas fa-save"></i> Guardar prompt
                </button>
            `
        );
    } catch (e) {
        closeModal();
        notify(e.message, true);
    }
}

async function savePrompt(id) {
    const payload = {
        action: 'update_prompt_compilation',
        id: id,
        session_id: sessionId,
        compiled_prompt: document.getElementById('promptCompiled').value,
        notes_for_user: document.getElementById('promptNotes').value,
        status: document.getElementById('promptStatus').value
    };

    try {
        await apiPost(payload);
        closeModal();
        notify('Prompt compilado actualizado.');
        await loadData();
    } catch (e) {
        notify(e.message, true);
    }
}

// =====================================================================
// PHASE CACHE / TOOL CALLS
// =====================================================================
async function deletePhaseCache(id) {
    if (!confirm('¿Eliminar esta entrada de PhaseCache?')) return;

    try {
        await apiPost({
            action: 'delete_phase_cache',
            id: id,
            project_id: projectId
        });

        notify('Caché eliminada.');
        await loadData();
    } catch (e) {
        notify(e.message, true);
    }
}

async function clearPhaseCache() {
    if (!confirm('¿Eliminar TODA la PhaseCache de este proyecto?')) return;

    try {
        await apiPost({
            action: 'clear_phase_cache',
            project_id: projectId
        });

        notify('Caché limpiada.');
        await loadData();
    } catch (e) {
        notify(e.message, true);
    }
}

async function deleteToolCall(id) {
    if (!confirm('¿Eliminar este ToolCall?')) return;

    try {
        await apiPost({
            action: 'delete_tool_call',
            id: id
        });

        notify('ToolCall eliminado.');
        await loadData();
    } catch (e) {
        notify(e.message, true);
    }
}

// =====================================================================
// UTILIDADES UI
// =====================================================================
async function apiPost(payload) {
    const response = await fetch('ai_data_api.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        credentials: 'same-origin',
        cache: 'no-cache',
        body: JSON.stringify(payload)
    });

    const text = await response.text();

    let data;
    try {
        data = JSON.parse(text);
    } catch (e) {
        throw new Error('Respuesta inválida del servidor: ' + text.slice(0, 220));
    }

    if (!data.ok) {
        throw new Error(data.error || 'Error desconocido');
    }

    return data;
}

function openModal(title, bodyHtml, footerHtml) {
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalBody').innerHTML = bodyHtml;
    document.getElementById('modalFooter').innerHTML = footerHtml || '';

    document.getElementById('modal').classList.add('open');
    document.getElementById('modalBackdrop').classList.add('open');
}

function closeModal() {
    document.getElementById('modal').classList.remove('open');
    document.getElementById('modalBackdrop').classList.remove('open');
}

function notify(message, isError = false) {
    const div = document.createElement('div');
    div.className = 'notify-toast' + (isError ? ' error' : '');
    div.textContent = message;
    document.body.appendChild(div);

    setTimeout(() => {
        if (div.parentNode) div.remove();
    }, 3500);
}

function emptyState(icon, title, text) {
    return `
        <div class="empty-state">
            <i class="fas ${icon}"></i>
            <h3>${escapeHtml(title)}</h3>
            <p>${escapeHtml(text)}</p>
        </div>
    `;
}

function formatDate(dt) {
    if (!dt) return '—';

    try {
        return new Date(String(dt).replace(' ', 'T')).toLocaleString('es-ES');
    } catch (e) {
        return dt;
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function escapeAttr(text) {
    return escapeHtml(text)
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function promptStatusClass(status) {
    if (status === 'approved') return 'badge-success';
    if (status === 'rejected') return 'badge-danger';
    return 'badge-warning';
}

loadData();
</script>
</body>
</html>