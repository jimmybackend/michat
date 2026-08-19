<?php
/**
 * trace_graph.php · Fase 7.7
 *
 * Grafo interactivo 2D/3D del flujo de ejecución y de los recursos de contexto de una respuesta.
 * Integra memoria estructurada/procedural/sesión/Q&A, RAG de proyecto y adjuntos.
 * Fase 7.5 permite editar el REGISTRO VIVO desde el nodo sin tocar el snapshot histórico.
 * Fase 7.6 añade un segundo renderizador 3D sobre el mismo modelo nodes + edges.
 * Fase 7.7 agrega métricas auditables de tokens, costos y rendimiento por scope.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/app_bootstrap.php';

if (!isset($_SESSION['usuario']) || empty($_SESSION['usuario'])) {
    header('Location: ../index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$traceGraphCsrf = (string)$_SESSION['csrf_token'];

function trace_graph_int(string $key): int
{
    return isset($_GET[$key]) && is_numeric($_GET[$key]) ? max(0, (int)$_GET[$key]) : 0;
}

$sessionId = trace_graph_int('session_id');
$projectId = trace_graph_int('project_id');
$userId = trace_graph_int('user_id');
$questionId = trace_graph_int('question_message_id');
$answerId = trace_graph_int('answer_message_id');
$traceId = trim((string)($_GET['trace_id'] ?? ''));
if ($traceId !== '' && !preg_match('/^[A-Za-z0-9_-]{16,36}$/', $traceId)) {
    $traceId = '';
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Grafo de ejecución · Respuesta<?= $answerId > 0 ? ' #' . $answerId : '' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.3.1/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link rel="stylesheet" href="css/chat2.css">
<link rel="stylesheet" href="css/design-system.css">
<link rel="stylesheet" href="css/trace-graph.css">
</head>
<body class="ui-theme theme-neon-green trace-graph-page">
<header class="trace-graph-header">
    <div class="trace-graph-header-left">
        <a id="graphBackExplorer" class="graph-link" href="trace_explorer.php"><i class="fas fa-arrow-left"></i> Trazabilidad</a>
        <a class="graph-link" href="index.php"><i class="fas fa-comments"></i> Chat</a>
        <div class="trace-graph-heading">
            <div class="trace-graph-kicker">Fase 7.7 · Ejecución + memoria + edición + 3D + métricas</div>
            <h1><i class="fas fa-diagram-project"></i> Grafo de ejecución y contexto <span id="graphResponseLabel"></span></h1>
            <p id="graphSubtitle">Cargando el trace real de esta respuesta…</p>
        </div>
    </div>
    <div class="trace-graph-header-actions">
        <div class="graph-view-toggle" role="group" aria-label="Vista del grafo">
            <button type="button" class="graph-btn graph-view-btn active" id="graphView2d" aria-pressed="true" title="Vista técnica 2D">
                <i class="fas fa-diagram-project"></i> 2D
            </button>
            <button type="button" class="graph-btn graph-view-btn" id="graphView3d" aria-pressed="false" title="Vista espacial 3D">
                <i class="fas fa-cube"></i> 3D
            </button>
        </div>
        <button type="button" class="graph-btn" id="graphModeToggle" title="Alternar camino efectivo / vista completa con candidatos descartados">
            <i class="fas fa-filter"></i> Esencial · usados
        </button>
        <button type="button" class="graph-btn" id="graphMetricsToggle" aria-expanded="false" title="Tokens, costos y rendimiento del turno/scope">
            <i class="fas fa-chart-line"></i> Métricas
        </button>
        <button type="button" class="graph-btn" id="graphFit"><i class="fas fa-expand"></i> Ajustar</button>
        <button type="button" class="graph-btn icon-only" id="graphZoomOut" title="Alejar"><i class="fas fa-minus"></i></button>
        <button type="button" class="graph-btn icon-only" id="graphZoomIn" title="Acercar"><i class="fas fa-plus"></i></button>
        <a class="graph-btn" id="graphProcessLink" href="#" target="_blank" rel="noopener"><i class="fas fa-wave-square"></i> Proceso</a>
        <a class="graph-btn" id="graphJsonLink" href="#" target="_blank" rel="noopener"><i class="fas fa-code"></i> JSON</a>
    </div>
</header>

<section class="trace-graph-stats" id="graphStats" aria-live="polite">
    <div class="graph-stat"><span>Estado</span><strong>—</strong></div>
    <div class="graph-stat"><span>Eventos</span><strong>—</strong></div>
    <div class="graph-stat"><span>Duración</span><strong>—</strong></div>
    <div class="graph-stat"><span>Tokens</span><strong>—</strong></div>
    <div class="graph-stat graph-stat-cost"><span>Costo est.</span><strong>—</strong></div>
    <div class="graph-stat"><span>Contexto usado</span><strong>—</strong></div>
    <div class="graph-stat"><span>Descartado</span><strong>—</strong></div>
    <div class="graph-stat"><span>Tools</span><strong>—</strong></div>
    <div class="graph-stat"><span>Escrituras memoria</span><strong>—</strong></div>
</section>
<section class="trace-metrics-drawer" id="graphMetricsDrawer" hidden aria-label="Métricas de trazabilidad">
    <div class="trace-metrics-shell">
        <div class="trace-metrics-head">
            <div>
                <div class="trace-metrics-kicker">Fase 7.7 · Métricas auditables</div>
                <h2><i class="fas fa-chart-line"></i> Tokens, costos y rendimiento</h2>
                <p id="graphMetricsNote">Costo histórico guardado + recálculo con la misma tabla de precios del dashboard.</p>
            </div>
            <div class="trace-metrics-head-actions">
                <label>Mes <input type="month" id="graphMetricsMonth"></label>
                <button type="button" class="graph-btn" id="graphMetricsRefresh"><i class="fas fa-sync"></i> Actualizar</button>
                <button type="button" class="graph-btn icon-only" id="graphMetricsClose" aria-label="Cerrar métricas"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <div class="trace-metrics-body" id="graphMetricsBody">
            <div class="trace-metrics-loading"><i class="fas fa-circle-notch fa-spin"></i> Calculando métricas del trace…</div>
        </div>
    </div>
</section>

<main class="trace-graph-layout">
    <section class="trace-graph-canvas-panel">
        <div class="graph-legend" id="graphLegend">
            <span class="legend-item compiler">Compilador</span>
            <span class="legend-item router">Router</span>
            <span class="legend-item feature_flags">Flags</span>
            <span class="legend-item ranking">Ranking</span>
            <span class="legend-item context">Contexto</span>
            <span class="legend-item retrieval">RAG/Embedding</span>
            <span class="legend-item prompt">Prompt</span>
            <span class="legend-item model">Modelo</span>
            <span class="legend-item tool">Tools</span>
            <span class="legend-item memory">Writer / memoria</span>
            <span class="legend-item project_memory">Memoria proyecto</span>
            <span class="legend-item procedural_memory">Procedural</span>
            <span class="legend-item session_memory">Sesión</span>
            <span class="legend-item question_memory">Q&amp;A</span>
            <span class="legend-item project_rag">RAG proyecto</span>
            <span class="legend-item attachment">Adjuntos</span>
            <span class="legend-item discarded">Descartado</span>
            <span class="legend-item response">Respuesta</span>
        </div>

        <div id="graph2dView" class="graph-render-view">
            <div class="graph-canvas-wrap" id="graphCanvasWrap">
                <div class="graph-loading" id="graphLoading">
                    <i class="fas fa-circle-notch fa-spin"></i>
                    <span>Construyendo ejecución, memorias y RAG desde el trace histórico…</span>
                </div>
                <svg id="traceGraphSvg" class="trace-graph-svg" role="img" aria-label="Grafo de ejecución 2D de la respuesta">
                    <defs>
                        <marker id="graphArrow" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                            <path d="M 0 0 L 10 5 L 0 10 z"></path>
                        </marker>
                        <filter id="graphGlow" x="-50%" y="-50%" width="200%" height="200%">
                            <feGaussianBlur stdDeviation="3" result="coloredBlur"></feGaussianBlur>
                            <feMerge><feMergeNode in="coloredBlur"></feMergeNode><feMergeNode in="SourceGraphic"></feMergeNode></feMerge>
                        </filter>
                    </defs>
                    <g id="graphViewport"></g>
                </svg>
            </div>
            <div class="graph-help">
                <span><i class="fas fa-hand-pointer"></i> clic en nodo: detalle</span>
                <span><i class="fas fa-arrows-up-down-left-right"></i> arrastrar: mover</span>
                <span><i class="fas fa-magnifying-glass-plus"></i> rueda: zoom</span>
                <span><i class="fas fa-circle-info"></i> línea sólida: orden real · línea punteada: relación de contexto</span>
                <span><i class="fas fa-check-circle"></i> Esencial muestra sólo recursos realmente usados</span>
            </div>
        </div>

        <div id="graph3dView" class="graph-render-view graph-render-view-3d" hidden>
            <div id="graph3dHost" class="graph-3d-host" aria-label="Grafo tridimensional de ejecución">
                <div id="graph3dLoading" class="graph-3d-loading">
                    <i class="fas fa-cube fa-spin"></i>
                    <span>Preparando vista 3D con el mismo trace…</span>
                </div>
                <div id="graph3dFallback" class="graph-3d-fallback" hidden></div>
                <div id="graph3dTooltip" class="graph-3d-tooltip" hidden></div>
                <div class="graph-3d-axis">
                    <span><b>X</b> subsistema</span>
                    <span><b>Y</b> tiempo</span>
                    <span><b>Z</b> conocimiento usado / descartado</span>
                </div>
                <div class="graph-3d-controls">
                    <button type="button" id="graph3dFit" class="graph-mini-btn"><i class="fas fa-compress-arrows-alt"></i> Encuadrar</button>
                    <button type="button" id="graph3dAutoRotate" class="graph-mini-btn" aria-pressed="false"><i class="fas fa-rotate"></i> Giro</button>
                    <button type="button" id="graph3dFocus" class="graph-mini-btn"><i class="fas fa-crosshairs"></i> Foco</button>
                </div>
            </div>
            <div class="graph-help graph-help-3d">
                <span><i class="fas fa-hand-pointer"></i> clic: seleccionar nodo</span>
                <span><i class="fas fa-mouse"></i> arrastrar: orbitar · botón derecho: desplazar</span>
                <span><i class="fas fa-magnifying-glass-plus"></i> rueda: acercar/alejar</span>
                <span><i class="fas fa-layer-group"></i> Z positivo: contexto usado · Z negativo: descartado</span>
            </div>
        </div>
    </section>

    <aside class="trace-graph-detail-panel">
        <div class="graph-detail-empty" id="graphDetailEmpty">
            <i class="fas fa-circle-nodes"></i>
            <h2>Selecciona un nodo</h2>
            <p>Verás eventos y también la memoria/RAG exactos del turno, con snapshot histórico y estado actual cuando exista.</p>
        </div>
        <div id="graphDetail" hidden>
            <div class="graph-detail-head">
                <span class="graph-detail-category" id="graphDetailCategory">evento</span>
                <span class="graph-detail-status" id="graphDetailStatus">info</span>
            </div>
            <h2 id="graphDetailTitle"></h2>
            <p class="graph-detail-summary" id="graphDetailSummary"></p>
            <div class="graph-detail-grid" id="graphDetailGrid"></div>
            <div class="graph-detail-actions" id="graphDetailActions"></div>
            <div class="graph-detail-section">
                <div class="graph-detail-section-title">Datos registrados</div>
                <pre id="graphDetailJson"></pre>
            </div>
        </div>
    </aside>
</main>

<div class="graph-edit-backdrop" id="graphEditBackdrop" hidden></div>
<div class="graph-edit-modal" id="graphEditModal" hidden role="dialog" aria-modal="true" aria-labelledby="graphEditTitle">
    <div class="graph-edit-card">
        <div class="graph-edit-head">
            <div>
                <div class="graph-edit-kicker">Fase 7.5 · Registro vivo</div>
                <h2 id="graphEditTitle">Editar nodo</h2>
                <p id="graphEditSubtitle">El trace histórico permanecerá intacto.</p>
            </div>
            <button type="button" class="graph-edit-close" id="graphEditClose" aria-label="Cerrar">&times;</button>
        </div>
        <div class="graph-edit-warning" id="graphEditWarning">
            <i class="fas fa-shield-alt"></i> Se editará únicamente el registro actual. La evidencia histórica de esta respuesta no se reescribe.
        </div>
        <form id="graphEditForm" class="graph-edit-form">
            <div id="graphEditFields"></div>
            <div class="graph-edit-status" id="graphEditStatus" hidden></div>
            <div class="graph-edit-footer">
                <button type="button" class="graph-btn" id="graphEditCancel">Cancelar</button>
                <button type="submit" class="graph-btn graph-btn-primary" id="graphEditSave"><i class="fas fa-save"></i> Guardar registro vivo</button>
            </div>
        </form>
    </div>
</div>

<div class="graph-error" id="graphError" hidden></div>

<script>
window.TRACE_GRAPH_CONFIG = {
    api: 'chat_trace_api.php',
    editApi: 'trace_node_edit_api.php',
    metricsApi: 'trace_metrics_api.php',
    csrfToken: <?= json_encode($traceGraphCsrf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    processViewer: 'chat_activity_viewer.php',
    explorer: 'trace_explorer.php',
    initialView: '3d',
    threeVersion: '0.185.1 (r185)',
    userId: <?= (int)$userId ?>,
    projectId: <?= (int)$projectId ?>,
    sessionId: <?= (int)$sessionId ?>,
    questionMessageId: <?= (int)$questionId ?>,
    answerMessageId: <?= (int)$answerId ?>,
    traceId: <?= json_encode($traceId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
};
</script>
<script type="importmap">
{
  "imports": {
    "three": "https://cdn.jsdelivr.net/npm/three@0.185.1/build/three.module.js",
    "three/addons/": "https://cdn.jsdelivr.net/npm/three@0.185.1/examples/jsm/"
  }
}
</script>
<script src="js/trace-graph.js"></script>
<script type="module" src="js/trace-graph-3d.js"></script>
</body>
</html>
