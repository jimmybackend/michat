<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/app_bootstrap.php';

if (!isset($_SESSION['usuario']) || empty($_SESSION['usuario'])) {
    header("Location: index.php");
    exit;
}

/**
 * Genera la URL para editar un archivo (si es editable) o verlo.
 * 
 * @param string $ruta       Ruta base del proyecto (ej: "Data/Chat/Uploads/2026/07/24/sistema-carito/")
 * @param string $encriptado Nombre encriptado del archivo (columna Encriptado)
 * @param string $nombre     Nombre original del archivo (para saber extensión)
 * @param string $accessType Tipo de acceso (para saber si está bloqueado)
 * @return array  ['edit' => url|null, 'view' => url|null, 'download' => url|null]
 */
function obtener_acciones_archivo($ruta, $encriptado, $nombre, $accessType = 'normal') {
    // Si el archivo está bloqueado, no mostrar acciones
    if ($accessType === 'secure') {
        return ['edit' => null, 'view' => null, 'download' => null];
    }

    // Construir la clave S3 (ruta + encriptado)
    $s3key = build_file_s3_key($ruta, $encriptado);
    if (empty($s3key)) {
        return ['edit' => null, 'view' => null, 'download' => null];
    }

    $keyEncoded = urlencode($s3key);
    $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));

    // Extensiones editables (igual que en tu $txtEditExt)
    $editExt = ['txt','srt','vtt','md','html','css','js','php','py','json','csv','sql','jas'];

    $acciones = [];
    $acciones['edit']   = in_array($ext, $editExt) ? "editor.php?archivo={$keyEncoded}" : null;
    $acciones['view']   = "ver_archivo.php?archivo={$keyEncoded}"; // o descarga directa
    $acciones['download'] = "descargar.php?archivo={$keyEncoded}";

    return $acciones;
}
function build_file_s3_key(string $ruta, string $encriptado): string {
    $ruta = rtrim(str_replace('\\', '/', trim($ruta)), '/') . '/';
    $enc  = ltrim(str_replace('\\', '/', trim($encriptado)), '/');
    if ($enc === '') return '';
    if (strpos($enc, $ruta) === 0) return $enc;
    return $ruta . $enc;
}
function ext_de($nombre){ return strtolower(pathinfo($nombre, PATHINFO_EXTENSION)); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Cloud Drive · Chat IA</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.3.1/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.9.3/min/dropzone.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link rel="icon" href="ellogo.png" type="image/x-icon">
<link rel="stylesheet" href="css/chat2-old.css" />
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="ui-theme theme-neon-green theme-light vision-normal ascii-on">
<input type="hidden" id="chatUserId" value="<?= (int)$_SESSION['user_id'] ?>">
<nav class="navbar navbar-expand-lg navbar-light bg-light px-3">
<a class="navbar-brand" href="s3.php">
<img src="ellogo.png" width="30" height="30" class="rounded-circle mr-2" alt="Logo"> Cloud Drive
</a>
<div class="form-inline my-2 my-lg-0 ml-auto">
<button id="btnRecargar" class="btn btn-primary ml-2" onclick="recargarPagina()" title="Recargar página">
<i class="fas fa-sync-alt"></i>
</button>
</div>
<ul class="navbar-nav ml-3">
<li class="nav-item dropdown ml-2">
<a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="temaMenu" role="button" data-toggle="dropdown">
<i class="fas fa-palette mr-1"></i> Diseño
</a>
<div class="dropdown-menu dropdown-menu-right" aria-labelledby="temaMenu" style="min-width:280px;">
<h6 class="dropdown-header">Color neón</h6>
<button class="dropdown-item js-set-theme" data-theme="theme-neon-green">Verde neón</button>
<button class="dropdown-item js-set-theme" data-theme="theme-neon-blue">Azul neón</button>
<button class="dropdown-item js-set-theme" data-theme="theme-neon-red">Rojo neón</button>
<button class="dropdown-item js-set-theme" data-theme="theme-neon-yellow">Amarillo neón</button>
<div class="dropdown-divider"></div>
<h6 class="dropdown-header">Modo</h6>
<button class="dropdown-item js-set-mode" data-mode="theme-dark">Oscuro</button>
<button class="dropdown-item js-set-mode" data-mode="theme-light">Claro</button><div class="dropdown-divider"></div>
<h6 class="dropdown-header">Visión</h6>
<button class="dropdown-item js-set-vision" data-vision="vision-normal">Normal</button>
<button class="dropdown-item js-set-vision" data-vision="vision-myopia">Miopía</button>
<button class="dropdown-item js-set-vision" data-vision="vision-protanopia">Protanopia</button>
<button class="dropdown-item js-set-vision" data-vision="vision-deuteranopia">Deuteranopia</button>
<button class="dropdown-item js-set-vision" data-vision="vision-tritanopia">Tritanopia</button>
<div class="dropdown-divider"></div>
<button class="dropdown-item" id="btnToggleAscii">
<i class="fas fa-terminal mr-1"></i> Alternar ASCII
</button>
</div>
</li>
<li class="nav-item dropdown">
<a class="nav-link dropdown-toggle d-flex align-items-center text-dark" href="#" id="usuarioMenu" role="button" data-toggle="dropdown">
<img src="logo1.png" alt="Perfil" class="rounded-circle mr-2" width="30" height="30">
<?= htmlspecialchars($_SESSION['usuario']) ?>
</a>
<div class="dropdown-menu dropdown-menu-right" aria-labelledby="usuarioMenu">
<button id="btnSyncS3" class="dropdown-item">
<i class="fas fa-rotate"></i> Sincronizar S3
</button>
<div class="dropdown-divider"></div>
<a class="dropdown-item text-danger" href="logout.php">
<i class="fas fa-sign-out-alt"></i> Cerrar sesión
</a>
</div>
</li>
</ul>
</nav>
<div class="container-fluid">
<div class="row">
<div class="col-md-2 sidebar-panel">
<div class="accordion-section">
<div class="accordion-header" data-toggle="collapse" data-target="#sbChats">
<span><i class="fas fa-comments mr-2"></i>Chats</span>
<button id="sbNewChat" class="btn btn-sm btn-outline-primary py-0 px-1" title="Nueva sesión">
<i class="fas fa-plus"></i>
</button>
</div>
<div id="sbChats" class="collapse show">
<div class="accordion-body">
<input id="sbChatSearch" class="form-control form-control-sm mb-2" placeholder="Buscar...">
<div id="sbChatList" style="max-height: 220px; overflow-y: auto;">
<div class="text-muted small">Cargando...</div>
</div>
</div>
</div>
</div>
<div class="accordion-section"><div class="accordion-header" data-toggle="collapse" data-target="#sbProjects">
<span><i class="fas fa-briefcase mr-2"></i>Proyectos</span>
<button id="sbNewProject" class="btn btn-sm btn-outline-primary py-0 px-1" title="Nuevo proyecto">
<i class="fas fa-plus"></i>
</button>
</div>
<div id="sbProjects" class="collapse show">
<div class="accordion-body">
<div id="sbProjectList" style="max-height: 220px; overflow-y: auto;">
<div class="text-muted small">Cargando...</div>
</div>
<button class="btn btn-sm btn-outline-secondary btn-block mt-2" id="sbManageProjects">
<i class="fas fa-cog"></i> Gestionar
</button>
</div>
</div>
</div>
<div class="accordion-section">
<div class="accordion-header" data-toggle="collapse" data-target="#sbContext">
<span><i class="fas fa-info-circle mr-2"></i>Contexto</span>
<i class="fas fa-chevron-down small"></i>
</div>
<div id="sbContext" class="collapse">
<div class="accordion-body">
<div class="small">
<strong>Proyecto:</strong>
<div id="sbCurrentProject" class="text-muted mb-2">Ninguno</div>
<strong>Sesión:</strong>
<div id="sbCurrentSession" class="text-muted mb-2">Ninguna</div>
<strong>Fuentes indexadas:</strong>
<div id="sbSourcesCount" class="text-muted">0</div>
</div>
</div>
</div>
</div>
</div>
<!-- cuerpo -->
<div class="col-md-10 p-0">
<!-- panel -->
<div id="pane-Chat2" class="tab-pane show active"> 
<ul class="nav nav-tabs" id="mainTabs" role="tablist">
<li class="nav-item">
<a class="nav-link" id="tab-Contexto" data-toggle="tab" href="#pane-Contexto" role="tab"><i class="fas fa-database"></i> Contexto
</a>
</li>
<li class="nav-item">
<a class="nav-link" id="tab-servicios" data-toggle="tab" href="#pane-servicios" role="tab">
<i class="fas fa-microscope"></i> Extracción de Hechos
</a>
</li>
<li class="nav-item">
<a class="nav-link active" id="tab-Dashboard" data-toggle="tab" href="#pane-Dashboard" role="tab">
<i class="fas fa-chart-line"></i> Dashboard
</a>
</li>
</ul>
<div class="tab-content" id="mainTabsContent">

<div class="tab-pane fade" id="pane-Contexto" role="tabpanel">
<div class="container-fluid py-3">
<div class="d-flex justify-content-between align-items-center mb-3">
<h4 class="mb-0"><i class="fas fa-database"></i> Contexto Activo</h4>
<button id="btnRefreshContext" class="btn btn-sm btn-outline-secondary" title="Actualizar contexto">
<i class="fas fa-sync-alt"></i> Actualizar
</button>
</div>

<div id="contextEmptyState" class="text-center text-muted py-5 d-none">
<i class="fas fa-folder-open fa-3x mb-3"></i>
<p>Selecciona un proyecto o una sesión de chat para ver su contexto acumulado.</p>
</div>
<div id="contextContent" class="row d-none">
<div class="col-md-6 mb-3">
<div class="card bg-light text-dark border-secondary h-100">
<div class="card-header d-flex justify-content-between align-items-center">
<span><i class="fas fa-briefcase"></i> Proyecto: <strong id="ctxProjectName">---</strong></span>
</div>
<div class="card-body p-0" style="overflow-y: auto; max-height: calc(100vh - 250px);">
<div id="ctxProjectList" class="p-2">
<div class="text-muted small text-center py-4"><i class="fas fa-spinner fa-spin"></i> Cargando...</div>
</div>
</div>
</div>
</div>
<div class="col-md-6 mb-3">
<div class="card bg-light text-dark border-secondary h-100">
<div class="card-header d-flex justify-content-between align-items-center">
<span><i class="fas fa-comments"></i> Sesión: <strong id="ctxSessionName">---</strong></span>
</div>
<div class="card-body p-0" style="overflow-y: auto; max-height: calc(100vh - 250px);">

<!-- ✅ NUEVO: Contenedor para el Resumen Maestro de la Sesión -->
<div id="ctxSessionSummary" class="p-2"></div>

<!-- Tu lista de bloques existente -->
<div id="ctxSessionList" class="p-2">
<div class="text-muted small text-center py-4"><i class="fas fa-spinner fa-spin"></i> Cargando...</div>
</div>
</div>
</div>
</div>
</div>
</div>
</div>




<div class="tab-pane fade" id="pane-servicios" role="tabpanel">
<div class="container-fluid py-3">
<div class="row">
<div class="col-md-12">
<h4><i class="fas fa-microscope"></i> Extracción de Hechos</h4>
<p class="text-muted">Análisis semántico de fuentes: clases, funciones, métodos, bloques lógicos, chunks con embeddings.</p>
<div id="chunksExtractorContainer"></div>
</div>
</div>
</div>
</div>



<div class="tab-pane fade show active" id="pane-Dashboard" role="tabpanel">
<div class="container-fluid py-3">
<div class="row">
<div class="col-md-12">
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <h4 class="mb-0"><i class="fas fa-chart-line"></i> Dashboard de Monitoreo IA</h4>
    <div class="form-group mb-0 mt-2 mt-md-0">
        <label class="small text-muted mr-2 mb-0">Período:</label>
        <input type="month" id="dashMonthFilter" class="form-control form-control-sm d-inline-block" style="width: 160px;">
    </div>
</div>
<div class="row mb-4">
<div class="col-md-3">
<div class="card bg-light text-dark border-secondary">
<div class="card-body text-center">
<h6 class="card-title text-muted">Tokens Totales</h6>
<h3 id="dashTotalTokens" class="text-info">0</h3>
<small class="text-muted">Input + Output acumulados</small>
</div>
</div>
</div>
<div class="col-md-3">
<div class="card bg-light text-dark border-secondary">
<div class="card-body text-center">
<h6 class="card-title text-muted">Costo Estimado</h6>
<h3 id="dashTotalCost" class="text-success">$0.0000</h3>
<small class="text-muted">Consumo del mes (USD)</small>
</div>
</div>
</div>
<div class="col-md-3">
<div class="card bg-light text-dark border-secondary">
<div class="card-body text-center">
<h6 class="card-title text-muted">Sesiones Activas</h6>
<h3 id="dashActiveSessions" class="text-warning">0</h3>
<small class="text-muted">Con actividad en el período</small>
</div>
</div>
</div>
<div class="col-md-3">
<div class="card bg-light text-dark border-secondary">
<div class="card-body text-center"><h6 class="card-title text-muted">Éxito Escalera Modelos</h6>
<h3 id="dashLadderSuccess" class="text-primary">0%</h3>
<small class="text-muted">Tasa de resolución automática</small>
</div>
</div>
</div>
</div>
<div class="row">
<div class="col-md-6 mb-4">
<div class="card bg-light text-dark border-secondary">
<div class="card-header"><i class="fas fa-chart-pie"></i> Uso de Tokens por Fase del Pipeline</div>
<div class="card-body" style="min-height: 300px;">
<canvas id="chartTokenUsage"></canvas>
</div>
</div>
</div>
<div class="col-md-6 mb-4">
<div class="card bg-light text-dark border-secondary">
<div class="card-header"><i class="fas fa-layer-group"></i> Rendimiento de la Escalera de Modelos (Linting)</div>
<div class="card-body">
<table class="table table-sm table-hover">
<thead>
<tr>
<th>Modelo</th>
<th>Intentos</th>
<th>Éxitos</th>
<th>Tasa de Éxito</th>
</tr>
</thead>
<tbody id="tableLadderStats">
<tr><td colspan="4" class="text-center text-muted">Cargando...</td></tr>
</tbody>
</table>
</div>
</div>
</div>
</div>
<!-- ✅ NUEVO: Tabla de Todos los Modelos IA Utilizados -->
<div class="row">
    <div class="col-md-12 mb-4">
        <div class="card bg-light text-dark border-secondary">
            <div class="card-header">
                <i class="fas fa-robot"></i> Todos los Modelos IA Utilizados (TokenUsage)
                <small class="text-muted float-right">Desglose histórico completo</small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>Modelo</th>
                                <th class="text-center">Usos</th>
                                <th class="text-right">Tokens Input</th>
                                <th class="text-right">Tokens Output</th>
                                <th class="text-right">Costo (USD)</th>
                                <th>Fases del Pipeline</th>
                            </tr>
                        </thead>
                        <tbody id="tableAllModels">
                            <tr><td colspan="6" class="text-center text-muted">Cargando...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- FIN NUEVO -->
<div class="card bg-light text-dark border-secondary">
<div class="card-header"><i class="fas fa-compress-alt"></i> Estado de Compresión de Contexto por Sesión</div>
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-sm table-hover mb-0">
<thead>
<tr>
<th>Sesión</th>
<th>Nivel de Compresión</th>
<th>Bloques Activos</th>
<th>Última Compresión</th>
<th>Estado</th>
</tr></thead>
<tbody id="tableCompressionStats">
<tr><td colspan="5" class="text-center text-muted">Cargando...</td></tr>
</tbody>
</table>
</div>
</div>
</div>
</div>
</div>
</div>
</div>



</div>
</div>
</div>
</div>
</div>
<div id="chatToasts" class="chat-toasts"></div>
<div id="incomingToasts" class="chat-toasts"></div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.9.3/min/dropzone.min.js"></script>
<script src="js/actualizar-hora.js"></script>
<script src="js/recargarPagina.js"></script>
<script src="js/chat.js"></script>
<script src="chat2-enhancements.js"></script>
<script src="js/sincronizar.js"></script>
<script src="js/estilo.js"></script>
<script>
window.UPLOAD_API = "api/upload.php";
</script>
<script src="js/subir-chunked.js"></script>
<script>
// =====================================================================
// 📋 CARGA DE SESIONES Y PROYECTOS PARA EL SIDEBAR
// =====================================================================
(function() {
    'use strict';
    
    const $ = (s) => document.querySelector(s);
    const esc = (s) => (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\"/g,'&quot;').replace(/'/g,'&#39;');
    
    // Elementos del sidebar
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
    
    // Obtener ID del usuario desde el hidden input si existe
    function getUserId() {
        const hid = document.getElementById('chatUserId');
        if (hid && hid.value) {
            const n = parseInt(hid.value, 10);
            if (Number.isFinite(n) && n > 0) return String(n);
        }
        return '';
    }
    
    // Formato de fecha compacto
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
    
    // Agrupar sesiones por fecha
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
    
    // Cargar sesiones desde API
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
    
    // Renderizar lista de sesiones
    function renderSessionsList() {
        if (!sbChatList) return;
        
        // Separar sesiones libres (sin proyecto) y sesiones de proyecto
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
        
        // Renderizar sesiones agrupadas por fecha
        const groups = groupSessionsByDate(freeSessions);
        let html = '';
        
        if (groups.length > 0) {
            html += '<div class="mb-2"><small class="text-muted" style="font-size: 0.7rem;"><i class="fas fa-comments mr-1"></i>Chats Libres</small></div>';
            groups.forEach(([label, arr]) => {
                html += `<div class="mb-2"><small class="text-muted" style="font-size: 0.7rem; padding-left: 8px;">${esc(label)}</small></div>`;
                arr.forEach(s => { html += renderItem(s); });
            });
        }
        
        // Sesiones de proyectos
        if (projectSessions.length > 0) {
            html += '<div class="mb-2 mt-2"><small class="text-muted" style="font-size: 0.7rem;"><i class="fas fa-briefcase mr-1"></i>Chats de Proyectos</small></div>';
            projectSessions.forEach(s => { html += renderItem(s); });
        }
        
        if (freeSessions.length === 0 && projectSessions.length === 0) {
            html = '<div class="text-muted small" style="font-size: 0.75rem;"><i class="fas fa-info-circle mr-1"></i>Sin chats</div>';
        }
        
        sbChatList.innerHTML = html;
        
        // Eventos de clic en sesiones
        sbChatList.querySelectorAll('.sb-item[data-type="session"]').forEach(item => {
            item.addEventListener('click', () => {
                const sid = parseInt(item.getAttribute('data-id'), 10);
                selectSession(sid);
            });
        });
    }
    
    // Cargar proyectos desde API
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
    
    // Renderizar lista de proyectos
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
            
            // Contar sesiones de este proyecto
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
        
        // Eventos de clic en proyectos
        sbProjectList.querySelectorAll('.sb-item[data-type="project"]').forEach(item => {
            item.addEventListener('click', () => {
                const pid = parseInt(item.getAttribute('data-id'), 10);
                selectProject(pid);
            });
        });
    }
    
    // Seleccionar sesión
    function selectSession(sessionId) {
        currentSessionId = sessionId;
        currentProjectId = null;
        
        // Actualizar UI
        if (sbChatList) {
            sbChatList.querySelectorAll('.sb-item').forEach(el => el.classList.remove('active'));
            const item = sbChatList.querySelector(`.sb-item[data-id="${sessionId}"]`);
            if (item) item.classList.add('active');
        }
        if (sbProjectList) {
            sbProjectList.querySelectorAll('.sb-item').forEach(el => el.classList.remove('active'));
        }
        
        // Actualizar panel de contexto
        if (sbCurrentSession) sbCurrentSession.textContent = `ID: ${sessionId}`;
        if (sbCurrentProject) sbCurrentProject.textContent = 'Ninguno';
        
        // Recargar contexto
        loadContextForSession(sessionId);
    }
    
    // Seleccionar proyecto
    function selectProject(projectId) {
        currentProjectId = projectId;
        currentSessionId = null;
        
        // Actualizar UI
        if (sbProjectList) {
            sbProjectList.querySelectorAll('.sb-item').forEach(el => el.classList.remove('active'));
            const item = sbProjectList.querySelector(`.sb-item[data-id="${projectId}"]`);
            if (item) item.classList.add('active');
        }
        if (sbChatList) {
            sbChatList.querySelectorAll('.sb-item').forEach(el => el.classList.remove('active'));
        }
        
        // Actualizar panel de contexto
        const proj = projects.find(p => (p.id == projectId) || (p.id_ == projectId));
        if (sbCurrentProject) sbCurrentProject.textContent = proj ? proj.name : `ID: ${projectId}`;
        if (sbCurrentSession) sbCurrentSession.textContent = 'Ninguna';
        
        // Recargar contexto
        loadContextForProject(projectId);
    }
    
    // Cargar contexto para una sesión
    async function loadContextForSession(sessionId) {
        const ctxContent = $('#contextContent');
        const ctxEmpty = $('#contextEmptyState');
        const ctxSessionName = $('#ctxSessionName');
        const ctxSessionList = $('#ctxSessionList');
        const ctxSessionSummary = $('#ctxSessionSummary');
        
        if (ctxEmpty) ctxEmpty.classList.add('d-none');
        if (ctxContent) ctxContent.classList.remove('d-none');
        
        if (ctxSessionName) ctxSessionName.textContent = `#${sessionId}`;
        
        // Buscar la sesión
        const session = sessions.find(s => (s.id == sessionId) || (s.id_ == sessionId));
        if (!session) {
            if (ctxSessionList) ctxSessionList.innerHTML = '<div class="text-muted small text-center">Sesión no encontrada</div>';
            return;
        }
        
        // Mostrar nombre de la sesión
        if (ctxSessionName) ctxSessionName.textContent = session.title || `Sesión #${sessionId}`;
        
        // Cargar mensajes/bloques de contexto
        if (ctxSessionList) {
            ctxSessionList.innerHTML = '<div class="text-muted small text-center"><i class="fas fa-spinner fa-spin"></i> Cargando contexto...</div>';
        }
        
        try {
            const qs = new URLSearchParams({ session_id: sessionId });
            const r = await fetch(`get_context.php?${qs.toString()}`, { credentials: 'same-origin' });
            const j = await r.json();
            
            if (j.ok && j.context) {
                // Mostrar resumen maestro si existe
                if (ctxSessionSummary && j.context.master_summary) {
                    ctxSessionSummary.innerHTML = `
                        <div class="alert alert-info py-2 px-3 mb-2" style="font-size: 0.8rem;">
                            <strong><i class="fas fa-brain mr-1"></i> Resumen Maestro:</strong>
                            <div class="mt-1">${esc(j.context.master_summary)}</div>
                        </div>
                    `;
                }
                
                // Mostrar bloques de contexto
                const blocks = j.context.blocks || [];
                if (blocks.length > 0) {
                    ctxSessionList.innerHTML = blocks.map(b => `
                        <div class="border border-secondary rounded p-2 mb-2" style="background: rgba(255,255,255,0.02); font-size: 0.75rem;">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <strong class="text-info"><i class="fas fa-cube mr-1"></i> Bloque #${b.block_index || '?'}</strong>
                                <small class="text-muted">${b.token_count || 0} tokens</small>
                            </div>
                            <div class="text-muted" style="max-height: 100px; overflow-y: auto;">
                                ${esc(b.summary || b.content_preview || 'Sin resumen')}
                            </div>
                        </div>
                    `).join('');
                } else {
                    ctxSessionList.innerHTML = '<div class="text-muted small text-center py-3"><i class="fas fa-info-circle mr-1"></i>Sin bloques de contexto comprimido</div>';
                }
            } else {
                if (ctxSessionList) ctxSessionList.innerHTML = '<div class="text-muted small text-center py-3"><i class="fas fa-info-circle mr-1"></i>Sin contexto disponible</div>';
            }
        } catch (e) {
            console.error('Error cargando contexto:', e);
            if (ctxSessionList) ctxSessionList.innerHTML = `<div class="text-danger small text-center">Error: ${esc(e.message)}</div>`;
        }
    }
    
    // Cargar contexto para un proyecto
    async function loadContextForProject(projectId) {
        const ctxContent = $('#contextContent');
        const ctxEmpty = $('#contextEmptyState');
        const ctxProjectName = $('#ctxProjectName');
        const ctxProjectList = $('#ctxProjectList');
        const ctxSourcesCount = $('#sbSourcesCount');
        
        if (ctxEmpty) ctxEmpty.classList.add('d-none');
        if (ctxContent) ctxContent.classList.remove('d-none');
        
        // Buscar el proyecto
        const proj = projects.find(p => (p.id == projectId) || (p.id_ == projectId));
        if (proj && ctxProjectName) ctxProjectName.textContent = proj.name;
        
        if (ctxProjectList) {
            ctxProjectList.innerHTML = '<div class="text-muted small text-center"><i class="fas fa-spinner fa-spin"></i> Cargando fuentes...</div>';
        }
        
        try {
            const qs = new URLSearchParams({ project_id: projectId });
            const r = await fetch(`project_sources.php?${qs.toString()}`, { credentials: 'same-origin' });
            const j = await r.json();
            
            if (j.ok && j.sources) {
                const sources = j.sources;
                if (ctxSourcesCount) ctxSourcesCount.textContent = sources.length;
                
                if (sources.length > 0) {
                    ctxProjectList.innerHTML = sources.map(s => `
                        <div class="border border-secondary rounded p-2 mb-2" style="background: rgba(255,255,255,0.02); font-size: 0.75rem;">
                            <div class="d-flex justify-content-between align-items-center">
                                <strong class="text-info" style="max-width: 70%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    <i class="fas fa-file-code mr-1"></i>${esc(s.filename || s.original_name || 'Archivo')}
                                </strong>
                                <small class="badge badge-secondary">${s.status || 'indexado'}</small>
                            </div>
                            <div class="text-muted mt-1" style="font-size: 0.65rem;">
                                ${s.chunk_count || 0} chunks · ${(s.file_size || 0).toLocaleString()} bytes
                            </div>
                        </div>
                    `).join('');
                } else {
                    ctxProjectList.innerHTML = '<div class="text-muted small text-center py-3"><i class="fas fa-info-circle mr-1"></i>Sin fuentes indexadas</div>';
                    if (ctxSourcesCount) ctxSourcesCount.textContent = '0';
                }
            } else {
                if (ctxProjectList) ctxProjectList.innerHTML = '<div class="text-muted small text-center py-3"><i class="fas fa-info-circle mr-1"></i>Sin fuentes disponibles</div>';
                if (ctxSourcesCount) ctxSourcesCount.textContent = '0';
            }
        } catch (e) {
            console.error('Error cargando fuentes del proyecto:', e);
            if (ctxProjectList) ctxProjectList.innerHTML = `<div class="text-danger small text-center">Error: ${esc(e.message)}</div>`;
            if (ctxSourcesCount) ctxSourcesCount.textContent = '0';
        }
    }
    
    // Event Listeners
    if (sbChatSearch) {
        sbChatSearch.addEventListener('input', () => loadSessions());
    }
    
    if (sbNewChat) {
        sbNewChat.addEventListener('click', async () => {
            try {
                const fd = new FormData();
                fd.append('title', 'Nueva conversación');
                const uid = getUserId();
                if (uid) fd.append('user_id', uid);
                
                const r = await fetch('chat2_session_create.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                const j = await r.json();
                if (j.ok && j.session_id) {
                    await loadSessions();
                    selectSession(j.session_id);
                }
            } catch (e) {
                console.error('Error creando sesión:', e);
            }
        });
    }
    
    if (sbNewProject) {
        sbNewProject.addEventListener('click', () => {
            const name = prompt('Nombre del nuevo proyecto:');
            if (name) {
                // Redirigir a la página de gestión de proyectos
                window.location.href = 'projects.php?action=new&name=' + encodeURIComponent(name);
            }
        });
    }
    
    if (sbManageProjects) {
        sbManageProjects.addEventListener('click', () => {
            window.location.href = 'projects.php';
        });
    }
    
    // Cargar inicial
    loadSessions();
    loadProjects();
    
})();
</script>
<script>
// Inicialización del filtro de mes
const monthFilter = document.getElementById('dashMonthFilter');
if (monthFilter) {
    // 1. Establecer el mes actual por defecto (formato YYYY-MM)
    monthFilter.value = new Date().toISOString().slice(0, 7);
    
    // 2. Recargar el dashboard automáticamente cuando el usuario cambie el mes
    monthFilter.addEventListener('change', () => {
        loadDashboard();
    });
}
</script>
<script>
async function loadDashboard() {
    try {
        // 1. Obtener el mes seleccionado (por defecto, el mes actual en formato YYYY-MM)
        const monthFilter = document.getElementById('dashMonthFilter');
        const month = monthFilter ? monthFilter.value : new Date().toISOString().slice(0, 7);
        
        // 2. Fetch enviando el parámetro ?month=YYYY-MM al backend
        const r = await fetch(`dashboard_stats.php?month=${encodeURIComponent(month)}`, { 
            credentials: 'same-origin', 
            cache: 'no-cache' 
        });
        
        const text = await r.text();
        let j;
        try { j = JSON.parse(text); } catch (e) {
            document.getElementById('dashTotalTokens').textContent = "Error JSON";
            return;
        }
        
        if (!j.ok) {
            document.getElementById('dashTotalTokens').textContent = "Error: " + (j.error || "Desconocido");
            return;
        }

        // 3. Actualizar tarjetas superiores
        document.getElementById('dashTotalTokens').textContent = parseInt(j.tokens.total || 0).toLocaleString();
        document.getElementById('dashTotalCost').textContent = '$' + parseFloat(j.tokens.cost || 0).toFixed(4);
        document.getElementById('dashActiveSessions').textContent = j.sessions ? j.sessions.length : 0;

        // 4. Renderizar Tabla de Escalera (Linting)
        let totalAttempts = 0; let totalSuccess = 0;
        const ladderTbody = document.getElementById('tableLadderStats');
        ladderTbody.innerHTML = '';
        if (j.ladder && j.ladder.length > 0) {
            j.ladder.forEach(row => {
                totalAttempts += parseInt(row.total_attempts || 0);
                totalSuccess += parseInt(row.success_count || 0);
                const tasa = totalAttempts > 0 ? ((parseInt(row.success_count || 0) / parseInt(row.total_attempts || 1)) * 100).toFixed(1) : 0;
                const modelName = (row.model_used || '').split('.').pop().replace(/-\d{8}-v1:0/g, '').replace(/-v1:0/g, '');
                ladderTbody.innerHTML += `
                <tr>
                    <td><span class="badge badge-secondary">${modelName}</span></td>
                    <td>${row.total_attempts}</td>
                    <td class="text-success">${row.success_count}</td>
                    <td>
                        <div class="progress" style="height: 8px; background: #444;">
                            <div class="progress-bar bg-success" style="width: ${tasa}%"></div>
                        </div>
                        <small>${tasa}%</small>
                    </td>
                </tr>`;
            });
            const globalTasa = totalAttempts > 0 ? ((totalSuccess / totalAttempts) * 100).toFixed(1) : 0;
            document.getElementById('dashLadderSuccess').textContent = globalTasa + '%';
        } else {
            ladderTbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Sin intentos de linting en este período.</td></tr>';
            document.getElementById('dashLadderSuccess').textContent = '0%';
        }

        // ✅ 5. NUEVO: Renderizar Tabla de TODOS los Modelos (TokenUsage)
        const allModelsTbody = document.getElementById('tableAllModels');
        if (allModelsTbody) {
            allModelsTbody.innerHTML = '';
            let grandTotalInput = 0, grandTotalOutput = 0, grandTotalCost = 0, grandTotalUses = 0;

            if (j.models && j.models.length > 0) {
                // Ordenar por costo descendente
                const sortedModels = [...j.models].sort((a, b) => b.total_cost - a.total_cost);

                sortedModels.forEach(model => {
                    grandTotalInput += parseInt(model.total_input || 0);
                    grandTotalOutput += parseInt(model.total_output || 0);
                    grandTotalCost += parseFloat(model.total_cost || 0);
                    grandTotalUses += parseInt(model.usage_count || 0);

                    // Acortar nombre para visualización limpia
                    const shortName = model.model_id
                        .replace('amazon.', '').replace('anthropic.', '').replace('meta.', '')
                        .replace('mistral.', '').replace('cohere.', '').replace('qwen.', '').replace('deepseek.', '');

                    // Construir badges de fases
                    let phasesHtml = '';
                    if (model.phases) {
                        const phaseColors = { 'compile': 'info', 'respond': 'success', 'embedding': 'warning', 'lint_fix': 'danger' };
                        for (const [phase, data] of Object.entries(model.phases)) {
                            const color = phaseColors[phase] || 'secondary';
                            phasesHtml += `<span class="badge badge-${color} mr-1 mb-1" style="font-size:0.7rem;" title="${phase}: ${data.count} usos, ${data.input} in / ${data.output} out tokens">${phase} (${data.count})</span>`;
                        }
                    }

                    allModelsTbody.innerHTML += `
                        <tr>
                            <td>
                                <strong class="text-info">${shortName}</strong>
                                <small class="text-muted d-block" style="font-size:0.65rem;">${model.model_id}</small>
                            </td>
                            <td class="text-center"><span class="badge badge-primary">${model.usage_count.toLocaleString()}</span></td>
                            <td class="text-right text-success">${model.total_input.toLocaleString()}</td>
                            <td class="text-right text-warning">${model.total_output.toLocaleString()}</td>
                            <td class="text-right text-danger"><strong>$${parseFloat(model.total_cost).toFixed(6)}</strong></td>
                            <td>${phasesHtml || '<span class="text-muted">-</span>'}</td>
                        </tr>
                    `;
                });

                // Fila de Totales
                allModelsTbody.innerHTML += `
                    <tr class="table-active border-top border-secondary" style="font-weight:bold;">
                        <td>TOTAL (${sortedModels.length} modelos)</td>
                        <td class="text-center">${grandTotalUses.toLocaleString()}</td>
                        <td class="text-right text-success">${grandTotalInput.toLocaleString()}</td>
                        <td class="text-right text-warning">${grandTotalOutput.toLocaleString()}</td>
                        <td class="text-right text-danger">$${grandTotalCost.toFixed(6)}</td>
                        <td>-</td>
                    </tr>
                `;
            } else {
                allModelsTbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Sin datos de modelos en este período.</td></tr>';
            }
        }

        // 6. Renderizar Tabla de Compresión de Sesiones
        const compTbody = document.getElementById('tableCompressionStats');
        compTbody.innerHTML = '';
        if (j.sessions && j.sessions.length > 0) {
            j.sessions.forEach(s => {
                let levelBadge = '<span class="badge badge-secondary">Nivel 0 (Crudo)</span>';
                if (s.context_level == 1) levelBadge = '<span class="badge badge-info">Nivel 1 (Resumen x5)</span>';
                if (s.context_level == 2) levelBadge = '<span class="badge badge-warning text-dark">Nivel 2 (Macro x20)</span>';
                if (s.context_level >= 3) levelBadge = '<span class="badge badge-danger">Nivel 3 (Épico x80)</span>';
                const lastComp = s.last_compressed_at ? new Date(s.last_compressed_at).toLocaleString() : 'Nunca';
                compTbody.innerHTML += `
                <tr>
                    <td>${s.title || 'Sesión #' + s.id_}</td>
                    <td>${levelBadge}</td>
                    <td>${s.block_count || 0}</td>
                    <td>${lastComp}</td>
                    <td><span class="badge badge-success">Activa</span></td>
                </tr>`;
            });
        } else {
            compTbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Sin sesiones con actividad en este período.</td></tr>';
        }

        // 7. Renderizar Gráfico
        if (j.tokens && j.tokens.by_phase) { 
            renderTokenChart(j.tokens.by_phase); 
        }
    } catch (e) {
        console.error("Error en loadDashboard:", e);
        document.getElementById('dashTotalTokens').textContent = "Error JS";
    }
}
function renderTokenChart(byPhase) {
const ctx = document.getElementById('chartTokenUsage');
if (!ctx) return;
const context = ctx.getContext('2d');
if (window.myTokenChart) window.myTokenChart.destroy();
window.myTokenChart = new Chart(context, {
type: 'doughnut',
data: {
labels: ['Compilación', 'Respuesta', 'Corrección Lint', 'Embeddings'],
datasets: [{
data: [
parseInt(byPhase.compile || 0),
parseInt(byPhase.respond || 0),
parseInt(byPhase.lint_fix || 0),
parseInt(byPhase.embedding || 0)
],
backgroundColor: ['#ffc107', '#007bff', '#28a745', '#17a2b8'],
borderWidth: 0,
hoverOffset: 10
}]
},
options: {
responsive: true,
maintainAspectRatio: false,
plugins: {
legend: {
position: 'bottom',
labels: { color: '#fff', padding: 20, usePointStyle: true }
}
}
}
});
}
const tabDashboard = document.getElementById('tab-Dashboard');
if (tabDashboard) {
tabDashboard.addEventListener('shown.bs.tab', function (e) { loadDashboard(); });
tabDashboard.addEventListener('click', function (e) { setTimeout(loadDashboard, 100); });
}
setTimeout(() => { loadDashboard(); }, 2000);
</script>

<script>
// =====================================================================
// 🧪 INTEGRACIÓN DE RUN_TESTS.PHP - EJECUCIÓN DE TESTS DESDE EL CHAT
// =====================================================================
(function() {
    'use strict';

    // 1. INTERCEPTAR RESPUESTAS DEL CHAT QUE CONTENGAN "test_command" (MODO AUTOMÁTICO)
    const messagesContainer = document.getElementById('chat2Messages');
    if (messagesContainer) {
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType === 1 && node.classList && node.classList.contains('chat-assistant')) {
                        const testCmdMatch = node.innerHTML.match(/data-test-command="([^"]+)"/);
                        if (testCmdMatch && !node.querySelector('.btn-run-tests')) {
                            injectTestButton(node, testCmdMatch[1]);
                        }
                    }
                });
            });
        });
        observer.observe(messagesContainer, { childList: true, subtree: true });
    }

    // 2. INYECTAR EL BOTÓN EN EL MENSAJE DEL ASISTENTE
    function injectTestButton(messageNode, testCommand) {
        const btnContainer = document.createElement('div');
        btnContainer.className = 'mt-2 d-flex align-items-center gap-2';
        btnContainer.innerHTML = `
            <button class="btn btn-sm btn-outline-success btn-run-tests" data-command="${escapeHtml(testCommand)}">
                <i class="fas fa-vial"></i> 🧪 Correr Tests
            </button>
            <small class="text-muted ml-2">
                <code style="font-size:0.7rem;">${escapeHtml(testCommand)}</code>
            </small>
        `;
        messageNode.appendChild(btnContainer);

        const btn = btnContainer.querySelector('.btn-run-tests');
        btn.addEventListener('click', () => executeTests(btn, testCommand));
    }

    // 3. FUNCIÓN PRINCIPAL: EJECUTAR TESTS VÍA AJAX
    async function executeTests(button, testCommand) {
        const originalHtml = button.innerHTML || '<i class="fas fa-vial"></i>';
        const sessionId = getCurrentSessionId();
        const projectId = getCurrentProjectId();

        if (!projectId) {
            showToast('⚠️ Atención', 'Debes seleccionar un proyecto primero.', 'warning');
            // Restaurar botón si era el manual
            if (button.id === 'btnRunTestsManual') {
                button.disabled = false;
                button.innerHTML = originalHtml;
            }
            return;
        }

        // Estado: Cargando
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Ejecutando...';
        button.classList.remove('btn-outline-success');
        button.classList.add('btn-outline-warning');

        try {
            const formData = new FormData();
            formData.append('session_id', sessionId || 0);
            formData.append('project_id', projectId);
            formData.append('test_command', testCommand);

            const response = await fetch('chat/run_tests.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            const result = await response.json();

            if (result.ok) {
                button.innerHTML = '<i class="fas fa-check"></i> Completado';
                button.classList.remove('btn-outline-warning');
                button.classList.add('btn-outline-info');
                
                appendTestResultToChat(result, testCommand);
                
                const statusIcon = result.status === 'ok' ? '✅' : '⚠️';
                showToast(
                    `${statusIcon} Tests ${result.status === 'ok' ? 'Exitosos' : 'Fallaron'}`,
                    `${result.files_processed} archivos en ${(result.duration_ms / 1000).toFixed(2)}s`,
                    result.status === 'ok' ? 'success' : 'warning'
                );
            } else {
                throw new Error(result.error || 'Error desconocido');
            }
        } catch (error) {
            button.innerHTML = '<i class="fas fa-times"></i> Error';
            button.classList.remove('btn-outline-warning');
            button.classList.add('btn-outline-danger');
            showToast('❌ Error ejecutando tests', error.message, 'danger');
        } finally {
            button.disabled = false;
            setTimeout(() => {
                button.innerHTML = originalHtml;
                button.classList.remove('btn-outline-info', 'btn-outline-danger');
                button.classList.add('btn-outline-success');
            }, 5000);
        }
    }

    // 4. MOSTRAR RESULTADO DE TESTS EN EL CHAT
    function appendTestResultToChat(result, command) {
        if (!messagesContainer) return;

        const msgDiv = document.createElement('div');
        msgDiv.className = 'chat-msg assistant';
        
        const formattedOutput = escapeHtml(result.output)
            .replace(/\n/g, '<br>')
            .replace(/(✅|OK|PASS)/gi, '<span style="color:#00ff66; font-weight:bold;">$1</span>')
            .replace(/(❌|FAIL|ERROR)/gi, '<span style="color:#ff5a5a; font-weight:bold;">$1</span>');

        const statusBadge = result.status === 'ok' 
            ? '<span class="badge badge-success">✅ PASSED</span>'
            : result.status === 'timeout'
            ? '<span class="badge badge-warning">⏱️ TIMEOUT</span>'
            : '<span class="badge badge-danger">❌ FAILED</span>';

        msgDiv.innerHTML = `
            <div class="chat-md">
                <div class="d-flex align-items-center mb-2">
                    <i class="fas fa-vial text-info mr-2"></i>
                    <strong>Resultado de Ejecución de Tests</strong>
                    ${statusBadge}
                    <small class="text-muted ml-auto">${(result.duration_ms / 1000).toFixed(2)}s</small>
                </div>
                <div class="small text-muted mb-2">
                    <code>${escapeHtml(command)}</code>
                </div>
                <pre style="background:#050505; color:#dbe4ee; padding:0.75rem; border-radius:6px; max-height:300px; overflow-y:auto; font-size:0.75rem; border:1px solid rgba(0,255,102,0.2);">${formattedOutput}</pre>
                <div class="mt-2 small text-muted">
                    📁 ${result.files_processed} archivos procesados desde S3
                </div>
            </div>
        `;
        
        messagesContainer.appendChild(msgDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    // 5. UTILIDADES
    function escapeHtml(text) {
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
        
        container.appendChild(toast);
        setTimeout(() => { if (toast.parentNode) toast.remove(); }, 8000);
    }

    // 6. OBTENER SESSION_ID Y PROJECT_ID ACTUALES
    function getCurrentSessionId() {
        if (typeof window.currentSessionId !== 'undefined' && window.currentSessionId) return parseInt(window.currentSessionId);
        if (typeof window.currentSession !== 'undefined' && window.currentSession && window.currentSession.id_) return parseInt(window.currentSession.id_);
        const badge = document.getElementById('chat2SessionBadge');
        if (badge && badge.dataset.sessionId) return parseInt(badge.dataset.sessionId);
        return 0;
    }

    function getCurrentProjectId() {
        const projectSelect = document.getElementById('chat2Project');
        if (projectSelect && projectSelect.value) return parseInt(projectSelect.value);
        if (typeof window.currentProjectId !== 'undefined') return parseInt(window.currentProjectId);
        return 0;
    }

    // 7. INYECCIÓN DEL BOTÓN MANUAL EN LA BARRA DE HERRAMIENTAS (FALLBACK GARANTIZADO)
    function injectManualTestButton() {
        // Buscamos el grupo de botones de herramientas en el footer del chat
        const toolGroup = document.querySelector('.card-footer .btn-group[role="group"]');
        if (!toolGroup) return;

        // Evitar duplicados
        if (document.getElementById('btnRunTestsManual')) return;

        const btn = document.createElement('button');
        btn.id = 'btnRunTestsManual';
        btn.className = 'btn btn-sm btn-outline-success';
        btn.title = 'Ejecutar Tests del Proyecto';
        btn.innerHTML = '<i class="fas fa-vial"></i>';
        
        btn.addEventListener('click', () => {
            const projectId = getCurrentProjectId();
            if (!projectId) {
                showToast('⚠️ Atención', 'Debes seleccionar un proyecto primero.', 'warning');
                return;
            }

            const defaultCmd = 'vendor/bin/phpunit';
            const testCommand = prompt("Ingresa el comando de tests a ejecutar:", defaultCmd);
            
            if (!testCommand || testCommand.trim() === '') return;

            // Creamos un botón "falso" para reutilizar la función executeTests
            const fakeBtn = document.createElement('button');
            executeTests(fakeBtn, testCommand.trim());
        });

        toolGroup.appendChild(btn);
    }

    // Ejecutar la inyección del botón manual al cargar el script
    injectManualTestButton();

})();
</script>

<script>
// =====================================================================
// ↩️ ROLLBACK / DESHACER ÚLTIMA EDICIÓN DE ARCHIVO
// =====================================================================
(function() {
    'use strict';

    const btnRollback = document.getElementById('btnRollbackEdit');
    if (!btnRollback) return;

    btnRollback.addEventListener('click', async () => {
        const projectId = getCurrentProjectId();
        
        if (!projectId) {
            showToast('⚠️ Atención', 'Debes seleccionar un proyecto primero.', 'warning');
            return;
        }

        // 1. Obtener los últimos archivos editados de este proyecto para mostrar un selector
        try {
            const formData = new FormData();
            formData.append('project_id', projectId);
            formData.append('action', 'get_recent_edits');

            const res = await fetch('chat/rollback_edit.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            const data = await res.json();

            if (!data.ok || !data.recent_files || data.recent_files.length === 0) {
                showToast('ℹ️ Sin historial', 'No hay ediciones recientes para deshacer en este proyecto.', 'info');
                return;
            }

            // 2. Mostrar modal de selección de archivo
            showRollbackModal(data.recent_files, projectId);

        } catch (error) {
            console.error('Error obteniendo historial:', error);
            showToast('❌ Error', 'No se pudo cargar el historial de ediciones.', 'danger');
        }
    });

    // =====================================================================
    // MODAL DE SELECCIÓN DE ROLLBACK
    // =====================================================================
    function showRollbackModal(files, projectId) {
        // Crear modal dinámicamente si no existe
        let modal = document.getElementById('rollbackModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'rollbackModal';
            modal.className = 'modal fade';
            modal.tabIndex = -1;
            modal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-undo-alt mr-2"></i> Deshacer Edición</h5>
                            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                        </div>
                        <div class="modal-body">
                            <p class="small text-muted mb-3">
                                Selecciona el archivo que deseas revertir a su versión anterior. 
                                Esto restaurará el contenido desde S3 y marcará la versión actual como obsoleta.
                            </p>
                            <div id="rollbackFileList" class="list-group" style="max-height: 300px; overflow-y: auto;">
                                <!-- Se llena dinámicamente -->
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancelar</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }

        // Llenar la lista de archivos
        const listContainer = modal.querySelector('#rollbackFileList');
        listContainer.innerHTML = '';

        files.forEach(file => {
            const item = document.createElement('a');
            item.href = '#';
            item.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
            item.innerHTML = `
                <div>
                    <strong class="text-info"><i class="fas fa-file-code mr-1"></i> ${escapeHtml(file.filename)}</strong>
                    <small class="text-muted d-block">Versión actual: ${escapeHtml(file.current_version)}</small>
                </div>
                <span class="badge badge-warning badge-pill">${escapeHtml(file.edit_count)} ediciones</span>
            `;
            item.addEventListener('click', (e) => {
                e.preventDefault();
                executeRollback(file.filename, projectId, modal);
            });
            listContainer.appendChild(item);
        });

        // Mostrar modal
        $(modal).modal('show');
    }

    // =====================================================================
    // EJECUTAR ROLLBACK
    // =====================================================================
    async function executeRollback(filename, projectId, modal) {
        // Cerrar modal
        $(modal).modal('hide');

        // Mostrar toast de progreso
        showToast('⏳ Revertiendo...', `Restaurando ${filename} a su versión anterior...`, 'info');

        try {
            const formData = new FormData();
            formData.append('project_id', projectId);
            formData.append('target_filename', filename);

            const res = await fetch('chat/rollback_edit.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            const result = await res.json();

            if (result.ok) {
                showToast(
                    '✅ Rollback Exitoso', 
                    `${filename} revertido a la versión ${result.restored_version}.`, 
                    'success'
                );

                // Agregar mensaje al chat informando del rollback
                appendRollbackMessageToChat(filename, result);

                // Recargar la lista de fuentes del proyecto si existe la función
                if (typeof loadProjectSources === 'function') {
                    loadProjectSources();
                }
            } else {
                throw new Error(result.error || 'Error desconocido en el rollback');
            }
        } catch (error) {
            showToast('❌ Error en Rollback', error.message, 'danger');
        }
    }

    // =====================================================================
    // MENSAJE EN EL CHAT
    // =====================================================================
    function appendRollbackMessageToChat(filename, result) {
        const messagesContainer = document.getElementById('chat2Messages');
        if (!messagesContainer) return;

        const msgDiv = document.createElement('div');
        msgDiv.className = 'chat-msg assistant';
        msgDiv.innerHTML = `
            <div class="chat-md">
                <div class="d-flex align-items-center mb-2">
                    <i class="fas fa-undo-alt text-warning mr-2"></i>
                    <strong>Rollback Ejecutado</strong>
                    <span class="badge badge-success ml-2">✅ REVERTIDO</span>
                </div>
                <div class="small text-muted mb-2">
                    <i class="fas fa-file-code mr-1"></i> <code>${escapeHtml(filename)}</code>
                </div>
                <ul class="small mb-0">
                    <li>Versión restaurada: <strong class="text-success">${escapeHtml(result.restored_version)}</strong></li>
                    <li>Versión descartada: <span class="text-danger text-decoration-line-through">${escapeHtml(result.previous_version)}</span></li>
                </ul>
                <div class="mt-2 small text-muted">
                    <i class="fas fa-info-circle mr-1"></i> El archivo ha sido restaurado desde S3. La versión anterior fue marcada como obsoleta.
                </div>
            </div>
        `;
        
        messagesContainer.appendChild(msgDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    // =====================================================================
    // UTILIDADES
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

})();
</script>
</body>
</html>