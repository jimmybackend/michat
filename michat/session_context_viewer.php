<?php
/**
 * session_context_viewer.php
 *
 * Página visual para inspeccionar el Contexto Activo de Proyecto y Sesión,
 * con opciones de editar, eliminar y vaciar registros.
 *
 * Se apoya en:
 * - get_context.php (lectura)
 * - context_actions.php (escritura/borrado)
 *
 * Uso:
 * session_context_viewer.php?session_id=123&project_id=45
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
    die('Sesión o ID inválido');
}

// Validar sesión
$sessionTitle = null;
if ($sessionId > 0) {
    $stmt = $db_connection->prepare("SELECT title FROM ChatSessions WHERE id_ = ? AND user_id_ = ? LIMIT 1");
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
}

// Validar proyecto (ajustado para tolerar variantes comunes de columnas)
$projectName = null;
if ($projectId > 0) {
    $found = false;
    $idCols = ['id_', 'id'];
    $userCols = ['user_id_', 'user_id', 'id_usuario', 'id_user', 'usuario_id'];

    foreach ($idCols as $idCol) {
        foreach ($userCols as $userCol) {
            try {
                $stmt = $db_connection->prepare("SELECT * FROM Projects WHERE {$idCol} = ? AND {$userCol} = ? LIMIT 1");
                if (!$stmt) {
                    continue;
                }

                $stmt->bind_param('ii', $projectId, $userId);
                $stmt->execute();
                $res = $stmt->get_result();

                if ($res && ($row = $res->fetch_assoc())) {
                    $projectName = $row['name'] ?? $row['title'] ?? $row['nombre'] ?? ('Proyecto #' . $projectId);
                    $found = true;
                    $stmt->close();
                    break 2;
                }

                $stmt->close();
            } catch (Throwable $e) {
                continue;
            }
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
<title>Inspector de Contexto</title>

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
        padding: 0;
        margin: 0;
    }

    .inspector-header {
        background: var(--bg2) !important;
        border-bottom: 1px solid var(--border) !important;
        padding: 20px 30px;
        position: sticky;
        top: 0;
        z-index: 100;
        backdrop-filter: blur(8px);
    }

    .inspector-header h1 {
        color: var(--text-strong) !important;
        font-size: 1.5rem;
        font-weight: 700;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .inspector-header h1 i {
        color: var(--accent);
    }

    .inspector-header .session-info {
        color: var(--text-soft) !important;
        font-size: 0.85rem;
        margin-top: 6px;
    }

    .inspector-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 30px;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }

    .stat-card {
        background: var(--bg2) !important;
        border: 1px solid var(--border) !important;
        border-radius: var(--radius) !important;
        padding: 18px;
        transition: all 0.15s ease;
    }

    .stat-card:hover {
        border-color: var(--accent) !important;
        box-shadow: 0 0 0 2px rgba(var(--accent-rgb), 0.1);
    }

    .stat-card.accent { border-left: 3px solid var(--accent) !important; }
    .stat-card.success { border-left: 3px solid var(--ok) !important; }
    .stat-card.warning { border-left: 3px solid var(--warn) !important; }
    .stat-card.danger  { border-left: 3px solid var(--danger) !important; }

    .stat-label {
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--text-soft) !important;
        margin-bottom: 6px;
    }

    .stat-value {
        font-size: 1.8rem;
        font-weight: 800;
        color: var(--text-strong) !important;
        font-variant-numeric: tabular-nums;
        line-height: 1;
    }

    .stat-value-text {
        font-size: 1rem;
        font-weight: 700;
        line-height: 1.35;
        word-break: break-word;
    }

    .ctx-section {
        background: var(--bg2) !important;
        border: 1px solid var(--border) !important;
        border-radius: var(--radius) !important;
        overflow: hidden;
        margin-bottom: 24px;
    }

    .ctx-section-body {
        padding: 20px;
    }

    .section-title {
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--accent) !important;
        margin: 0 0 18px 0;
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

    .section-subtitle {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--text-soft) !important;
        margin: 18px 0 10px 0;
    }

    .content-block {
        background: var(--bg3) !important;
        padding: 14px 16px;
        border-radius: var(--radius) !important;
        margin-bottom: 12px;
        border-left: 3px solid var(--accent) !important;
    }

    .content-block.summary {
        border-left-color: var(--ok) !important;
    }

    .content-block.session-block {
        border-left-color: var(--warn) !important;
    }

    .block-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
        flex-wrap: wrap;
        gap: 8px;
    }

    .block-type {
        background: var(--accent) !important;
        color: #fff !important;
        padding: 3px 10px;
        border-radius: 999px;
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .block-type.summary { background: var(--ok) !important; color: #fff !important; }
    .block-type.session { background: var(--warn) !important; color: #1c2128 !important; }
    .block-type.project { background: var(--accent) !important; color: #fff !important; }

    .block-meta {
        color: var(--text-soft) !important;
        font-size: 0.72rem;
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    .block-meta i {
        color: var(--accent);
        font-size: 0.7rem;
    }

    .ctx-title {
        font-size: 1rem;
        font-weight: 700;
        color: var(--text-strong) !important;
        margin-bottom: 8px;
        word-break: break-word;
    }

    .content-preview {
        font-family: 'JetBrains Mono', var(--font-mono) !important;
        font-size: 0.78rem;
        line-height: 1.65;
        white-space: pre-wrap;
        word-wrap: break-word;
        color: var(--text) !important;
        max-height: 380px;
        overflow-y: auto;
        background: var(--bg) !important;
        padding: 14px;
        border-radius: 6px;
        border: 1px solid var(--border-soft) !important;
    }

    .empty-state {
        text-align: center;
        padding: 50px 20px;
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

    .btn-inspector {
        background: var(--bg3) !important;
        color: var(--text) !important;
        border: 1px solid var(--border) !important;
        padding: 8px 16px;
        border-radius: var(--radius) !important;
        cursor: pointer;
        font-weight: 600;
        font-size: 0.85rem;
        transition: all 0.15s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .btn-inspector:hover {
        border-color: var(--accent) !important;
        color: var(--accent) !important;
        background: rgba(var(--accent-rgb), 0.06) !important;
    }

    .btn-back {
        color: var(--text-soft) !important;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 0.85rem;
        margin-right: 16px;
        transition: color 0.15s ease;
    }

    .btn-back:hover {
        color: var(--accent) !important;
        text-decoration: none;
    }

    .ctx-title-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .ctx-actions {
        display: flex;
        gap: 8px;
        margin-top: 12px;
        flex-wrap: wrap;
    }

    .btn-ctx {
        background: var(--bg) !important;
        color: var(--text) !important;
        border: 1px solid var(--border) !important;
        padding: 6px 12px;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 600;
        font-size: 0.78rem;
        transition: all 0.15s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .btn-ctx:hover {
        border-color: var(--accent) !important;
        color: var(--accent) !important;
        background: rgba(var(--accent-rgb), 0.06) !important;
    }

    .btn-ctx.danger:hover {
        border-color: var(--danger) !important;
        color: var(--danger) !important;
        background: rgba(248,81,73,.08) !important;
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
        width: min(760px, calc(100vw - 40px));
        max-height: 85vh;
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

    .ctx-form label {
        display: block;
        font-size: 0.78rem;
        font-weight: 700;
        color: var(--text-strong);
        margin: 12px 0 6px 0;
    }

    .ctx-form label:first-child {
        margin-top: 0;
    }

    .ctx-input {
        width: 100%;
        background: var(--bg);
        color: var(--text);
        border: 1px solid var(--border);
        border-radius: 6px;
        padding: 10px 12px;
        font-size: 0.9rem;
    }

    textarea.ctx-input {
        min-height: 160px;
        resize: vertical;
        font-family: 'JetBrains Mono', monospace;
        font-size: 0.82rem;
        line-height: 1.6;
    }

    .ctx-input:focus {
        outline: none;
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(var(--accent-rgb), .15);
    }

    .content-preview::-webkit-scrollbar,
    .ctx-modal-body::-webkit-scrollbar {
        width: 8px;
    }

    .content-preview::-webkit-scrollbar-track,
    .ctx-modal-body::-webkit-scrollbar-track {
        background: var(--bg);
        border-radius: 4px;
    }

    .content-preview::-webkit-scrollbar-thumb,
    .ctx-modal-body::-webkit-scrollbar-thumb {
        background: var(--border);
        border-radius: 4px;
    }

    .content-preview::-webkit-scrollbar-thumb:hover,
    .ctx-modal-body::-webkit-scrollbar-thumb:hover {
        background: var(--text-soft);
    }

    @media (max-width: 768px) {
        .inspector-container { padding: 20px 16px; }
        .inspector-header { padding: 16px; }
        .inspector-header h1 { font-size: 1.2rem; }
        .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
        .stat-value { font-size: 1.4rem; }
        .section-title { flex-direction: column; align-items: flex-start; }
        .ctx-title-actions { justify-content: flex-start; }
    }
</style>
</head>
<body class="ui-theme theme-neon-green">

<div class="inspector-header">
    <div style="display: flex; align-items: center;">
        <a href="index.php" class="btn-back">
            <i class="fas fa-arrow-left"></i> Volver al chat
        </a>

        <div>
            <h1>
                <i class="fas fa-database"></i>
                Inspector de Contexto Activo
            </h1>
            <div class="session-info">
                <i class="fas fa-comment-dots"></i>
                <?= htmlspecialchars($sessionTitle ?: 'Sin sesión activa') ?>
                <span style="opacity: 0.6; margin-left: 8px;">
                    <?= $sessionId > 0 ? '(Sesión #' . $sessionId . ')' : '' ?>
                </span>
            </div>
        </div>
    </div>
</div>

<div class="inspector-container">
    <div id="context-content">
        <div class="loading">
            <i class="fas fa-spinner fa-spin fa-3x"></i>
            <p style="margin-top: 20px; font-size: 0.95rem;">Cargando contexto activo...</p>
        </div>
    </div>
</div>

<!-- Modal -->
<div id="ctxModalBackdrop" class="ctx-modal-backdrop" onclick="closeCtxModal()"></div>
<div id="ctxModal" class="ctx-modal" role="dialog" aria-modal="true">
    <div class="ctx-modal-header">
        <h3 id="ctxModalTitle" class="ctx-modal-title"></h3>
        <button type="button" class="ctx-modal-close" onclick="closeCtxModal()" aria-label="Cerrar">&times;</button>
    </div>
    <div id="ctxModalBody" class="ctx-modal-body"></div>
    <div id="ctxModalFooter" class="ctx-modal-footer"></div>
</div>

<script>
const sessionId = <?= (int)$sessionId ?>;
const projectId = <?= (int)$projectId ?>;
const sessionTitle = <?= json_encode((string)($sessionTitle ?? ''), JSON_UNESCAPED_UNICODE) ?>;
const projectName = <?= json_encode((string)($projectName ?? ''), JSON_UNESCAPED_UNICODE) ?>;

let ctxData = {
    project_context: [],
    session_context: [],
    session_summary: null
};

async function loadContext() {
    const container = document.getElementById('context-content');

    try {
        const qs = new URLSearchParams();
        if (sessionId > 0) qs.set('session_id', sessionId);
        if (projectId > 0) qs.set('project_id', projectId);

        const response = await fetch(`get_context.php?${qs.toString()}`, {
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

        ctxData = normalizeContextData(data);
        renderContext();
    } catch (error) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-exclamation-triangle"></i>
                <h3>Error al cargar contexto</h3>
                <p style="max-width: 700px; margin: 0 auto;">${escapeHtml(error.message)}</p>
                <button class="btn-inspector" onclick="loadContext()" style="margin-top: 20px;">
                    <i class="fas fa-sync"></i> Reintentar
                </button>
            </div>
        `;
    }
}

function normalizeContextData(data) {
    const out = {
        project_context: [],
        session_context: [],
        session_summary: null
    };

    if (Array.isArray(data.project_context)) {
        out.project_context = data.project_context;
    } else if (data.context && Array.isArray(data.context.project_context)) {
        out.project_context = data.context.project_context;
    }

    if (Array.isArray(data.session_context)) {
        out.session_context = data.session_context;
    } else if (data.context && Array.isArray(data.context.session_context)) {
        out.session_context = data.context.session_context;
    } else if (data.context && Array.isArray(data.context.blocks)) {
        out.session_context = data.context.blocks;
    }

    if (data.session_summary) {
        out.session_summary = data.session_summary;
    } else if (data.context && data.context.session_summary) {
        out.session_summary = data.context.session_summary;
    } else if (data.context && data.context.master_summary) {
        out.session_summary = { context_summary: data.context.master_summary };
    }

    return out;
}

function renderContext() {
    const container = document.getElementById('context-content');

    const projectCount = ctxData.project_context.length;
    const sessionCount = ctxData.session_context.length;
    const totalTokens = ctxData.session_context.reduce((sum, b) => sum + (parseInt(b.token_count, 10) || 0), 0);
    const level = ctxData.session_summary && ctxData.session_summary.context_level ? ctxData.session_summary.context_level : 0;

    let html = `
        <div class="stats-grid">
            <div class="stat-card accent">
                <div class="stat-label">Proyecto</div>
                <div class="stat-value stat-value-text">${escapeHtml(projectId > 0 ? (projectName || ('#' + projectId)) : 'Ninguno')}</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Sesión</div>
                <div class="stat-value stat-value-text">${escapeHtml(sessionId > 0 ? (sessionTitle || ('#' + sessionId)) : 'Ninguna')}</div>
            </div>

            <div class="stat-card ${projectCount > 0 ? 'success' : 'warning'}">
                <div class="stat-label">Contexto de Proyecto</div>
                <div class="stat-value">${projectCount}</div>
            </div>

            <div class="stat-card ${sessionCount > 0 ? 'success' : 'warning'}">
                <div class="stat-label">Bloques de Sesión</div>
                <div class="stat-value">${sessionCount}</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Nivel de Compresión</div>
                <div class="stat-value">${parseInt(level, 10) || 0}</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Tokens en Bloques</div>
                <div class="stat-value">${totalTokens.toLocaleString()}</div>
            </div>
        </div>

        <div style="text-align: right; margin-bottom: 20px;">
            <button class="btn-inspector" onclick="loadContext()">
                <i class="fas fa-sync"></i> Actualizar
            </button>
        </div>

        ${renderProjectSection()}
        ${renderSessionSection()}
    `;

    container.innerHTML = html;
}

function renderProjectSection() {
    const projectCount = ctxData.project_context.length;

    let html = `
        <div class="ctx-section">
            <div class="ctx-section-body">
                <div class="section-title">
                    <div class="section-title-left">
                        <i class="fas fa-briefcase"></i>
                        Contexto del Proyecto
                    </div>
                    <div class="ctx-title-actions">
                        ${projectId > 0 ? `
                            <button class="btn-ctx" onclick="openNewProjectContext()">
                                <i class="fas fa-plus"></i> Agregar
                            </button>
                        ` : ''}
                        ${projectId > 0 && projectCount > 0 ? `
                            <button class="btn-ctx danger" onclick="clearProjectContext()">
                                <i class="fas fa-trash"></i> Vaciar proyecto
                            </button>
                        ` : ''}
                    </div>
                </div>
    `;

    if (projectId <= 0) {
        html += `
            <div class="empty-state">
                <i class="fas fa-briefcase"></i>
                <h3>Proyecto: Ninguno</h3>
                <p>Sin contexto registrado para este proyecto.</p>
            </div>
        `;
    } else if (projectCount === 0) {
        html += `
            <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <h3>Proyecto: ${escapeHtml(projectName || ('#' + projectId))}</h3>
                <p>Sin contexto registrado para este proyecto.</p>
            </div>
        `;
    } else {
        ctxData.project_context.forEach(item => {
            html += renderProjectCard(item);
        });
    }

    html += `
            </div>
        </div>
    `;

    return html;
}

function renderProjectCard(item) {
    const id = Number(item.id_ || item.id || 0);
    const type = item.type || 'note';
    const title = item.title || 'Sin título';
    const content = item.content || '';
    const createdAt = item.created_at || '';

    return `
        <div class="content-block project">
            <div class="block-header">
                <span class="block-type project">
                    <i class="fas fa-layer-group"></i> ${escapeHtml(type)}
                </span>
                <span class="block-meta">
                    <span><i class="fas fa-hashtag"></i> ${id}</span>
                    ${createdAt ? `<span><i class="fas fa-clock"></i> ${escapeHtml(createdAt)}</span>` : ''}
                </span>
            </div>

            <div class="ctx-title">${escapeHtml(title)}</div>
            <div class="content-preview">${escapeHtml(content)}</div>

            <div class="ctx-actions">
                <button class="btn-ctx" onclick="editProjectContext(${id})">
                    <i class="fas fa-pen"></i> Editar
                </button>
                <button class="btn-ctx danger" onclick="deleteProjectContext(${id})">
                    <i class="fas fa-trash"></i> Eliminar
                </button>
            </div>
        </div>
    `;
}

function renderSessionSection() {
    const sessionCount = ctxData.session_context.length;

    let html = `
        <div class="ctx-section">
            <div class="ctx-section-body">
                <div class="section-title">
                    <div class="section-title-left">
                        <i class="fas fa-comments"></i>
                        Contexto de la Sesión
                    </div>
                    <div class="ctx-title-actions">
                        ${sessionId > 0 && sessionCount > 0 ? `
                            <button class="btn-ctx danger" onclick="clearSessionContext()">
                                <i class="fas fa-trash"></i> Vaciar sesión
                            </button>
                        ` : ''}
                    </div>
                </div>
    `;

    if (sessionId <= 0) {
        html += `
            <div class="empty-state">
                <i class="fas fa-comments"></i>
                <h3>Sesión: Ninguna</h3>
                <p>Selecciona una conversación activa para ver su contexto.</p>
            </div>
        `;
    } else {
        html += renderSessionSummary();

        html += `<div class="section-subtitle">Bloques de contexto</div>`;

        if (sessionCount === 0) {
            html += `
                <div class="empty-state">
                    <i class="fas fa-cube"></i>
                    <h3>Sesión: ${escapeHtml(sessionTitle || ('#' + sessionId))}</h3>
                    <p>Sin contexto registrado para esta sesión.</p>
                </div>
            `;
        } else {
            ctxData.session_context.forEach(item => {
                html += renderSessionBlock(item);
            });
        }
    }

    html += `
            </div>
        </div>
    `;

    return html;
}

function renderSessionSummary() {
    const summary = ctxData.session_summary && ctxData.session_summary.context_summary
        ? String(ctxData.session_summary.context_summary)
        : '';

    const level = ctxData.session_summary && ctxData.session_summary.context_level
        ? ctxData.session_summary.context_level
        : 0;

    const lastCompressed = ctxData.session_summary && ctxData.session_summary.last_compressed_at
        ? ctxData.session_summary.last_compressed_at
        : '';

    if (!summary) {
        return `
            <div class="section-subtitle">Resumen maestro</div>
            <div class="content-block summary">
                <div class="block-header">
                    <span class="block-type summary">
                        <i class="fas fa-brain"></i> Resumen
                    </span>
                    <span class="block-meta">
                        <span><i class="fas fa-layer-group"></i> Nivel ${parseInt(level, 10) || 0}</span>
                        ${lastCompressed ? `<span><i class="fas fa-clock"></i> ${escapeHtml(lastCompressed)}</span>` : ''}
                    </span>
                </div>

                <div class="content-preview">Sin resumen maestro.</div>

                <div class="ctx-actions">
                    <button class="btn-ctx" onclick="editSessionSummary()">
                        <i class="fas fa-pen"></i> Escribir resumen
                    </button>
                </div>
            </div>
        `;
    }

    return `
        <div class="section-subtitle">Resumen maestro</div>
        <div class="content-block summary">
            <div class="block-header">
                <span class="block-type summary">
                    <i class="fas fa-brain"></i> Resumen
                </span>
                <span class="block-meta">
                    <span><i class="fas fa-layer-group"></i> Nivel ${parseInt(level, 10) || 0}</span>
                    ${lastCompressed ? `<span><i class="fas fa-clock"></i> ${escapeHtml(lastCompressed)}</span>` : ''}
                </span>
            </div>

            <div class="content-preview">${escapeHtml(summary)}</div>

            <div class="ctx-actions">
                <button class="btn-ctx" onclick="editSessionSummary()">
                    <i class="fas fa-pen"></i> Editar
                </button>
                <button class="btn-ctx danger" onclick="deleteSessionSummary()">
                    <i class="fas fa-eraser"></i> Vaciar resumen
                </button>
            </div>
        </div>
    `;
}

function renderSessionBlock(item) {
    const id = Number(item.id_ || item.id || 0);
    const blockType = item.block_type || 'level_0';
    const tokenCount = parseInt(item.token_count, 10) || 0;
    const createdAt = item.created_at || '';
    const s3Path = item.s3_path || '';
    const content = item.content_preview || item.content || item.summary || '';

    return `
        <div class="content-block session-block">
            <div class="block-header">
                <span class="block-type session">
                    <i class="fas fa-cube"></i> ${escapeHtml(blockType)}
                </span>
                <span class="block-meta">
                    <span><i class="fas fa-hashtag"></i> ${id}</span>
                    <span><i class="fas fa-coins"></i> ${tokenCount} tokens</span>
                    ${createdAt ? `<span><i class="fas fa-clock"></i> ${escapeHtml(createdAt)}</span>` : ''}
                    ${s3Path ? `<span title="${escapeAttr(s3Path)}"><i class="fas fa-folder"></i> S3</span>` : ''}
                </span>
            </div>

            <div class="content-preview">${escapeHtml(content)}</div>

            <div class="ctx-actions">
                <button class="btn-ctx" onclick="editSessionBlock(${id})">
                    <i class="fas fa-pen"></i> Editar
                </button>
                <button class="btn-ctx danger" onclick="deleteSessionBlock(${id})">
                    <i class="fas fa-trash"></i> Eliminar
                </button>
            </div>
        </div>
    `;
}

function projectTypeOptions(selected) {
    const types = [
        { value: 'rule', label: 'rule' },
        { value: 'decision', label: 'decision' },
        { value: 'fact', label: 'fact' },
        { value: 'style', label: 'style' },
        { value: 'todo', label: 'todo' },
        { value: 'note', label: 'note' }
    ];

    return types.map(t => `
        <option value="${t.value}" ${selected === t.value ? 'selected' : ''}>
            ${t.label}
        </option>
    `).join('');
}

function blockTypeOptions(selected) {
    const types = ['primordial', 'level_0', 'level_1', 'level_2', 'level_3'];

    return types.map(t => `
        <option value="${t}" ${selected === t ? 'selected' : ''}>
            ${t}
        </option>
    `).join('');
}

function openCtxModal(title, bodyHtml, footerHtml) {
    document.getElementById('ctxModalTitle').textContent = title;
    document.getElementById('ctxModalBody').innerHTML = bodyHtml;
    document.getElementById('ctxModalFooter').innerHTML = footerHtml || '';

    document.getElementById('ctxModal').classList.add('open');
    document.getElementById('ctxModalBackdrop').classList.add('open');
}

function closeCtxModal() {
    document.getElementById('ctxModal').classList.remove('open');
    document.getElementById('ctxModalBackdrop').classList.remove('open');
}

function projectFormHtml(item) {
    item = item || {};

    const type = item.type || 'note';
    const title = item.title || '';
    const content = item.content || '';

    return `
        <div class="ctx-form">
            <label>Tipo</label>
            <select id="ctxProjectType" class="ctx-input">
                ${projectTypeOptions(type)}
            </select>

            <label>Título</label>
            <input id="ctxProjectTitle" class="ctx-input" type="text" value="${escapeAttr(title)}" placeholder="Ej: Regla de arquitectura">

            <label>Contenido</label>
            <textarea id="ctxProjectContent" class="ctx-input" placeholder="Escribe aquí el contexto del proyecto...">${escapeHtml(content)}</textarea>
        </div>
    `;
}

function sessionBlockFormHtml(item) {
    item = item || {};

    const blockType = item.block_type || 'level_0';
    const content = item.content_preview || item.content || item.summary || '';
    const tokenCount = parseInt(item.token_count, 10) || 0;

    return `
        <div class="ctx-form">
            <label>Tipo de bloque</label>
            <select id="ctxBlockType" class="ctx-input">
                ${blockTypeOptions(blockType)}
            </select>

            <label>Tokens (opcional)</label>
            <input id="ctxBlockTokens" class="ctx-input" type="number" min="0" value="${tokenCount}">

            <label>Contenido</label>
            <textarea id="ctxBlockContent" class="ctx-input">${escapeHtml(content)}</textarea>
        </div>
    `;
}

function openNewProjectContext() {
    if (projectId <= 0) {
        alert('Debes tener un proyecto activo para agregar contexto de proyecto.');
        return;
    }

    openCtxModal(
        'Agregar contexto de proyecto',
        projectFormHtml({}),
        `
            <button class="btn-ctx" onclick="closeCtxModal()">Cancelar</button>
            <button class="btn-ctx" onclick="createProjectContext()">
                <i class="fas fa-save"></i> Guardar
            </button>
        `
    );
}

function editProjectContext(id) {
    const item = ctxData.project_context.find(x => Number(x.id_ || x.id) === id);
    if (!item) return;

    openCtxModal(
        'Editar contexto de proyecto',
        projectFormHtml(item),
        `
            <button class="btn-ctx" onclick="closeCtxModal()">Cancelar</button>
            <button class="btn-ctx" onclick="saveProjectContext(${id})">
                <i class="fas fa-save"></i> Guardar
            </button>
        `
    );
}

async function createProjectContext() {
    if (projectId <= 0) return;

    const payload = {
        action: 'create_project_context',
        project_id: projectId,
        type: document.getElementById('ctxProjectType').value,
        title: document.getElementById('ctxProjectTitle').value,
        content: document.getElementById('ctxProjectContent').value
    };

    await runAction(payload);
}

async function saveProjectContext(id) {
    const payload = {
        action: 'update_project_context',
        project_id: projectId,
        id: id,
        type: document.getElementById('ctxProjectType').value,
        title: document.getElementById('ctxProjectTitle').value,
        content: document.getElementById('ctxProjectContent').value
    };

    await runAction(payload);
}

async function deleteProjectContext(id) {
    if (!confirm('¿Eliminar este registro de contexto del proyecto?')) return;

    await runAction({
        action: 'delete_project_context',
        project_id: projectId,
        id: id
    });
}

async function clearProjectContext() {
    if (!confirm('¿Eliminar TODO el contexto registrado para este proyecto?')) return;

    await runAction({
        action: 'clear_project_context',
        project_id: projectId
    });
}

function editSessionSummary() {
    const current = ctxData.session_summary && ctxData.session_summary.context_summary
        ? String(ctxData.session_summary.context_summary)
        : '';

    openCtxModal(
        'Editar resumen maestro de la sesión',
        `
            <div class="ctx-form">
                <label>Resumen</label>
                <textarea id="ctxSummaryContent" class="ctx-input" style="min-height: 220px;">${escapeHtml(current)}</textarea>
            </div>
        `,
        `
            <button class="btn-ctx" onclick="closeCtxModal()">Cancelar</button>
            <button class="btn-ctx" onclick="saveSessionSummary()">
                <i class="fas fa-save"></i> Guardar
            </button>
        `
    );
}

async function saveSessionSummary() {
    const payload = {
        action: 'update_session_summary',
        session_id: sessionId,
        context_summary: document.getElementById('ctxSummaryContent').value
    };

    await runAction(payload);
}

async function deleteSessionSummary() {
    if (!confirm('¿Vaciar el resumen maestro de esta sesión?')) return;

    await runAction({
        action: 'update_session_summary',
        session_id: sessionId,
        context_summary: ''
    });
}

function editSessionBlock(id) {
    const item = ctxData.session_context.find(x => Number(x.id_ || x.id) === id);
    if (!item) return;

    openCtxModal(
        'Editar bloque de sesión',
        sessionBlockFormHtml(item),
        `
            <button class="btn-ctx" onclick="closeCtxModal()">Cancelar</button>
            <button class="btn-ctx" onclick="saveSessionBlock(${id})">
                <i class="fas fa-save"></i> Guardar
            </button>
        `
    );
}

async function saveSessionBlock(id) {
    const payload = {
        action: 'update_session_block',
        session_id: sessionId,
        id: id,
        block_type: document.getElementById('ctxBlockType').value,
        content_preview: document.getElementById('ctxBlockContent').value,
        token_count: document.getElementById('ctxBlockTokens').value
    };

    await runAction(payload);
}

async function deleteSessionBlock(id) {
    if (!confirm('¿Eliminar este bloque de contexto de la sesión?')) return;

    await runAction({
        action: 'delete_session_block',
        session_id: sessionId,
        id: id
    });
}

async function clearSessionContext() {
    if (!confirm('¿Eliminar TODOS los bloques de contexto de esta sesión?')) return;

    await runAction({
        action: 'clear_session_context',
        session_id: sessionId
    });
}

async function sendContextAction(payload) {
    const response = await fetch('context_actions.php', {
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

async function runAction(payload) {
    try {
        await sendContextAction(payload);
        closeCtxModal();
        await loadContext();
    } catch (error) {
        alert(error.message);
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

loadContext();
</script>
</body>
</html>