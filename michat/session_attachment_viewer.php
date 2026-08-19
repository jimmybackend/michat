<?php
/**
 * session_attachment_viewer.php
 * 
 * Página visual para inspeccionar qué información tiene la IA
 * de los archivos adjuntos indexados (bloques file y file_chunk).
 * 
 * Usa el design-system.css y chat2.css existentes.
 * 
 * Uso: session_attachment_viewer.php?session_id=123
 */

session_start();
require_once __DIR__ . '/app_bootstrap.php';

if (!isset($_SESSION['usuario']) || empty($_SESSION['usuario'])) {
    header("Location: ../index.php");
    exit;
}

$sessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
$userId = 0;
foreach (['user_id_', 'user_id', 'id_usuario', 'id_user', 'id'] as $k) {
    if (isset($_SESSION[$k]) && ctype_digit((string)$_SESSION[$k])) {
        $userId = (int)$_SESSION[$k];
        break;
    }
}

if ($userId <= 0 || $sessionId <= 0) {
    die('Sesión o ID inválido');
}

// Validar permisos
$stmt = $db_connection->prepare("SELECT title FROM ChatSessions WHERE id_ = ? AND user_id_ = ?");
$stmt->bind_param('ii', $sessionId, $userId);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$session) {
    die('Sesión no encontrada o sin permisos');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inspector de Adjuntos · <?= htmlspecialchars($session['title']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.3.1/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="icon" href="asistente-de-inteligencia-artificial.gif" type="image/x-icon">
    <link rel="stylesheet" href="css/chat2.css">
    <link rel="stylesheet" href="css/design-system.css">
    
    <style>
        /* =========================================================
           INSPECTOR DE ADJUNTOS
           Usa las variables del design-system para integrarse
           ========================================================= */
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
        
        /* Stats grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 30px;
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
        
        .stat-value small {
            font-size: 0.9rem;
            color: var(--text-soft) !important;
            font-weight: 500;
        }
        
        /* File cards */
        .file-card {
            background: var(--bg2) !important;
            border: 1px solid var(--border) !important;
            border-radius: var(--radius) !important;
            margin-bottom: 20px;
            overflow: hidden;
            transition: border-color 0.15s ease;
        }
        
        .file-card:hover {
            border-color: rgba(var(--accent-rgb), 0.4) !important;
        }
        
        .file-header {
            background: var(--bg3) !important;
            padding: 16px 20px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            border-bottom: 1px solid var(--border-soft) !important;
            transition: background 0.15s ease;
        }
        
        .file-header:hover {
            background: rgba(var(--accent-rgb), 0.06) !important;
        }
        
        .file-header.collapsed .toggle-icon {
            transform: rotate(-90deg);
        }
        
        .toggle-icon {
            color: var(--accent);
            transition: transform 0.2s ease;
            font-size: 0.9rem;
        }
        
        .file-name {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-strong) !important;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .file-name i {
            color: var(--accent);
            font-size: 0.95rem;
        }
        
        .file-meta {
            font-size: 0.75rem;
            color: var(--text-soft) !important;
            margin-top: 4px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .file-meta-item {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .file-meta-item i {
            color: var(--accent);
            font-size: 0.7rem;
        }
        
        .file-content {
            display: none;
            padding: 20px;
        }
        
        .file-content.active {
            display: block;
        }
        
        /* Section titles */
        .section-title {
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--accent) !important;
            margin: 20px 0 10px 0;
            padding-bottom: 6px;
            border-bottom: 2px solid var(--border-soft) !important;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        
        .section-title:first-child {
            margin-top: 0;
        }
        
        .section-title-left {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* Content blocks */
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
        
        .content-block.chunk {
            border-left-color: var(--warn) !important;
        }
        
        .content-block.pending {
            border-left-color: var(--danger) !important;
            opacity: 0.75;
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
        }
        
        .block-type.summary { background: var(--ok) !important; color: #fff !important; }
        .block-type.chunk   { background: var(--warn) !important; color: #1c2128 !important; }
        .block-type.pending { background: var(--danger) !important; }
        
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
        
        .content-preview {
            font-family: 'JetBrains Mono', var(--font-mono) !important;
            font-size: 0.78rem;
            line-height: 1.65;
            white-space: pre-wrap;
            word-wrap: break-word;
            color: var(--text) !important;
            max-height: 500px;
            overflow-y: auto;
            background: var(--bg) !important;
            padding: 14px;
            border-radius: 6px;
            border: 1px solid var(--border-soft) !important;
        }
        
        /* Badges */
        .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.68rem;
            font-weight: 700;
            margin-left: 8px;
            letter-spacing: 0.02em;
        }
        
        .badge-success { 
            background: rgba(var(--ok), 0.15) !important; 
            color: var(--ok) !important; 
            border: 1px solid var(--ok) !important; 
        }
        .badge-pending { 
            background: rgba(var(--danger), 0.15) !important; 
            color: var(--danger) !important; 
            border: 1px solid var(--danger) !important; 
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: var(--text-soft) !important;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.3;
            color: var(--accent) !important;
        }
        
        .empty-state h3 {
            color: var(--text-strong) !important;
            margin-bottom: 8px;
        }
        
        /* Buttons */
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
        
        /* Loading */
        .loading {
            text-align: center;
            padding: 80px 20px;
            color: var(--text-soft) !important;
        }
        
        .loading i {
            color: var(--accent);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .inspector-container { padding: 20px 16px; }
            .inspector-header { padding: 16px; }
            .inspector-header h1 { font-size: 1.2rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .stat-value { font-size: 1.4rem; }
        }
        
        /* Scrollbar */
        .content-preview::-webkit-scrollbar { width: 8px; }
        .content-preview::-webkit-scrollbar-track { background: var(--bg); border-radius: 4px; }
        .content-preview::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }
        .content-preview::-webkit-scrollbar-thumb:hover { background: var(--text-soft); }
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
                    <i class="fas fa-microscope"></i>
                    Inspector de Adjuntos Indexados
                </h1>
                <div class="session-info">
                    <i class="fas fa-comment-dots"></i> 
                    <?= htmlspecialchars($session['title']) ?>
                    <span style="opacity: 0.6; margin-left: 8px;">(Sesión #<?= $sessionId ?>)</span>
                </div>
            </div>
        </div>
    </div>
    
    <div class="inspector-container">
        <div id="inspector-content">
            <div class="loading">
                <i class="fas fa-spinner fa-spin fa-3x"></i>
                <p style="margin-top: 20px; font-size: 0.95rem;">Cargando datos de adjuntos...</p>
            </div>
        </div>
    </div>

    <script>
    const sessionId = <?= $sessionId ?>;
    
    async function loadInspector() {
        const container = document.getElementById('inspector-content');
        
        try {
            const response = await fetch(`session_attachment_inspector.php?session_id=${sessionId}`, {
                credentials: 'same-origin',
                cache: 'no-cache'
            });
            
            const text = await response.text();
            
            // Intentar parsear como JSON
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                throw new Error('El servidor devolvió HTML en lugar de JSON. Revisa los logs de PHP. Respuesta: ' + text.slice(0, 300));
            }
            
            if (!data.ok) {
                throw new Error(data.error || 'Error desconocido');
            }
            
            renderInspector(data);
        } catch (error) {
            container.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h3>Error al cargar datos</h3>
                    <p style="max-width: 600px; margin: 0 auto;">${escapeHtml(error.message)}</p>
                    <button class="btn-inspector" onclick="loadInspector()" style="margin-top: 20px;">
                        <i class="fas fa-sync"></i> Reintentar
                    </button>
                </div>
            `;
        }
    }
    
    function renderInspector(data) {
        const { stats, files } = data;
        const container = document.getElementById('inspector-content');
        
        let html = `
            <div class="stats-grid">
                <div class="stat-card accent">
                    <div class="stat-label">Archivos Indexados</div>
                    <div class="stat-value">${stats.total_files}</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total de Chunks</div>
                    <div class="stat-value">${stats.total_chunks}</div>
                </div>
                <div class="stat-card ${stats.chunks_with_embedding === stats.total_chunks && stats.total_chunks > 0 ? 'success' : 'warning'}">
                    <div class="stat-label">Chunks con Embedding</div>
                    <div class="stat-value">${stats.chunks_with_embedding} <small>/ ${stats.total_chunks}</small></div>
                </div>
                <div class="stat-card ${stats.chunks_pending > 0 ? 'danger' : 'success'}">
                    <div class="stat-label">Chunks Pendientes</div>
                    <div class="stat-value">${stats.chunks_pending}</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Tokens Totales</div>
                    <div class="stat-value">${stats.total_tokens.toLocaleString()}</div>
                </div>
            </div>
            
            <div style="text-align: right; margin-bottom: 20px;">
                <button class="btn-inspector" onclick="loadInspector()">
                    <i class="fas fa-sync"></i> Actualizar
                </button>
            </div>
        `;
        
        if (!files || files.length === 0) {
            html += `
                <div class="empty-state">
                    <i class="fas fa-file-circle-xmark"></i>
                    <h3>No hay adjuntos indexados</h3>
                    <p>Esta sesión no tiene archivos procesados con los botones "Indexar" o "Semántica".</p>
                </div>
            `;
        } else {
            files.forEach((file, fileIndex) => {
                const summaryStatus = file.summary 
                    ? (file.summary.has_embedding ? 'success' : 'pending')
                    : null;
                
                const chunksReady = file.chunks.filter(c => c.has_embedding).length;
                const chunksTotal = file.chunks.length;
                const allReady = chunksReady === chunksTotal && chunksTotal > 0;
                
                html += `
                    <div class="file-card">
                        <div class="file-header" onclick="toggleFile(${fileIndex})">
                            <div style="min-width: 0; flex: 1;">
                                <div class="file-name">
                                    <i class="fas fa-file-code"></i> 
                                    ${escapeHtml(file.filename)}
                                    ${summaryStatus === 'success' ? '<span class="badge-status badge-success"><i class="fas fa-brain"></i> Semántica lista</span>' : ''}
                                    ${summaryStatus === 'pending' ? '<span class="badge-status badge-pending"><i class="fas fa-clock"></i> Semántica pendiente</span>' : ''}
                                    ${allReady ? '<span class="badge-status badge-success"><i class="fas fa-check"></i> Listo para RAG</span>' : ''}
                                </div>
                                <div class="file-meta">
                                    <div class="file-meta-item">
                                        <i class="fas fa-puzzle-piece"></i>
                                        ${chunksReady}/${chunksTotal} chunks con embedding
                                    </div>
                                    ${file.summary ? `
                                    <div class="file-meta-item">
                                        <i class="fas fa-coins"></i>
                                        ${file.summary.token_count} tokens (resumen)
                                    </div>
                                    ` : ''}
                                    <div class="file-meta-item" title="Ruta S3">
                                        <i class="fas fa-folder"></i>
                                        ${escapeHtml(file.s3_path || 'N/A')}
                                    </div>
                                </div>
                            </div>
                            <i class="fas fa-chevron-down toggle-icon"></i>
                        </div>
                        <div class="file-content" id="file-${fileIndex}">
                `;
                
                // Resumen semántico
                if (file.summary) {
                    html += `
                        <div class="section-title">
                            <div class="section-title-left">
                                <i class="fas fa-brain"></i> Resumen Semántico
                            </div>
                            <span class="badge-status ${file.summary.has_embedding ? 'badge-success' : 'badge-pending'}">
                                ${file.summary.has_embedding 
                                    ? '<i class="fas fa-check"></i> Embedding listo' 
                                    : '<i class="fas fa-clock"></i> Pendiente'}
                            </span>
                        </div>
                        <div class="content-block summary">
                            <div class="block-header">
                                <span class="block-type summary">Resumen</span>
                                <span class="block-meta">
                                    <span><i class="fas fa-coins"></i> ${file.summary.token_count} tokens</span>
                                    ${file.summary.embedding_model ? `<span><i class="fas fa-vector-square"></i> ${file.summary.embedding_dimensions}D</span>` : ''}
                                    ${file.summary.embedding_model ? `<span><i class="fas fa-robot"></i> ${escapeHtml(file.summary.embedding_model)}</span>` : ''}
                                    <span><i class="fas fa-clock"></i> ${escapeHtml(file.summary.created_at || '')}</span>
                                </span>
                            </div>
                            <div class="content-preview">${escapeHtml(file.summary.content_preview)}</div>
                        </div>
                    `;
                }
                
                // Chunks
                if (file.chunks && file.chunks.length > 0) {
                    html += `
                        <div class="section-title">
                            <div class="section-title-left">
                                <i class="fas fa-puzzle-piece"></i> Fragmentos Indexados (${file.chunks.length})
                            </div>
                        </div>
                    `;
                    
                    file.chunks.forEach((chunk, chunkIndex) => {
                        const chunkLabel = chunk.chunk_info 
                            ? `Chunk ${chunk.chunk_info.current} de ${chunk.chunk_info.total}` 
                            : `Chunk ${chunkIndex + 1}`;
                        
                        html += `
                            <div class="content-block ${chunk.has_embedding ? 'chunk' : 'pending'}">
                                <div class="block-header">
                                    <span class="block-type ${chunk.has_embedding ? 'chunk' : 'pending'}">
                                        ${chunk.has_embedding ? '<i class="fas fa-check" style="margin-right: 4px;"></i>' : '<i class="fas fa-clock" style="margin-right: 4px;"></i>'}
                                        ${chunkLabel}
                                    </span>
                                    <span class="block-meta">
                                        <span><i class="fas fa-coins"></i> ${chunk.token_count} tokens</span>
                                        ${chunk.has_embedding ? `<span><i class="fas fa-vector-square"></i> ${chunk.embedding_dimensions}D</span>` : ''}
                                        ${chunk.embedding_model ? `<span><i class="fas fa-robot"></i> ${escapeHtml(chunk.embedding_model)}</span>` : ''}
                                        <span><i class="fas fa-clock"></i> ${escapeHtml(chunk.created_at || '')}</span>
                                    </span>
                                </div>
                                <div class="content-preview">${escapeHtml(chunk.content_preview)}</div>
                            </div>
                        `;
                    });
                }
                
                html += `
                        </div>
                    </div>
                `;
            });
        }
        
        container.innerHTML = html;
    }
    
    function toggleFile(index) {
        const content = document.getElementById(`file-${index}`);
        const header = content.previousElementSibling;
        
        if (content.classList.contains('active')) {
            content.classList.remove('active');
            header.classList.add('collapsed');
        } else {
            content.classList.add('active');
            header.classList.remove('collapsed');
        }
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Cargar al iniciar
    loadInspector();
    </script>
</body>
</html>