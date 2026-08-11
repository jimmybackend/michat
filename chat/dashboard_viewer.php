<?php
/**
 * dashboard_viewer.php
 *
 * Dashboard visual de monitoreo IA - Solo visualización
 * Muestra tokens, costos, modelos usados y estado de compresión
 *
 * Usa el design-system.css y chat2.css existentes.
 *
 * Uso: dashboard_viewer.php?month=2026-08
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

// Mes por defecto: mes actual
$selectedMonth = isset($_GET['month']) ? trim($_GET['month']) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}

// Formatear nombre del mes para mostrar
$monthTimestamp = strtotime($selectedMonth . '-01');
$monthName = $monthTimestamp ? strftime('%B de %Y', $monthTimestamp) : $selectedMonth;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard IA · <?= htmlspecialchars($monthName) ?></title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.3.1/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link rel="icon" href="asistente-de-inteligencia-artificial.gif" type="image/x-icon">
<link rel="stylesheet" href="css/chat2.css">
<link rel="stylesheet" href="css/design-system.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    body {
        background: var(--bg) !important;
        color: var(--text) !important;
        font-family: 'Inter', var(--font-sans) !important;
        min-height: 100vh;
        padding: 0;
        margin: 0;
    }

    .dashboard-header {
        background: var(--bg2) !important;
        border-bottom: 1px solid var(--border) !important;
        padding: 20px 30px;
        position: sticky;
        top: 0;
        z-index: 100;
        backdrop-filter: blur(8px);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
    }

    .dashboard-header h1 {
        color: var(--text-strong) !important;
        font-size: 1.5rem;
        font-weight: 700;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .dashboard-header h1 i {
        color: var(--accent);
    }

    .dashboard-controls {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    .month-select {
        background: var(--bg3) !important;
        color: var(--text) !important;
        border: 1px solid var(--border) !important;
        border-radius: var(--radius) !important;
        padding: 8px 14px;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
    }

    .month-select:focus {
        outline: none;
        border-color: var(--accent) !important;
        box-shadow: 0 0 0 3px rgba(var(--accent-rgb), 0.15);
    }

    .dashboard-container {
        max-width: 1600px;
        margin: 0 auto;
        padding: 30px;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 16px;
        margin-bottom: 28px;
    }

    .stat-card {
        background: var(--bg2) !important;
        border: 1px solid var(--border) !important;
        border-radius: var(--radius) !important;
        padding: 20px;
        transition: all 0.15s ease;
        position: relative;
        overflow: hidden;
    }

    .stat-card:hover {
        border-color: var(--accent) !important;
        box-shadow: 0 0 0 2px rgba(var(--accent-rgb), 0.1);
        transform: translateY(-2px);
    }

    .stat-card.accent { border-left: 3px solid var(--accent) !important; }
    .stat-card.success { border-left: 3px solid var(--ok) !important; }
    .stat-card.warning { border-left: 3px solid var(--warn) !important; }
    .stat-card.danger  { border-left: 3px solid var(--danger) !important; }
    .stat-card.info    { border-left: 3px solid #58a6ff !important; }

    .stat-icon {
        position: absolute;
        top: 16px;
        right: 18px;
        font-size: 1.6rem;
        opacity: 0.15;
        color: var(--text-strong);
    }

    .stat-label {
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--text-soft) !important;
        margin-bottom: 8px;
    }

    .stat-value {
        font-size: 2rem;
        font-weight: 800;
        color: var(--text-strong) !important;
        font-variant-numeric: tabular-nums;
        line-height: 1;
        margin-bottom: 6px;
    }

    .stat-value.cost {
        color: var(--ok) !important;
    }

    .stat-description {
        font-size: 0.75rem;
        color: var(--text-soft) !important;
        line-height: 1.4;
    }

    .section-title {
        font-size: 0.85rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--accent) !important;
        margin: 32px 0 16px 0;
        padding-bottom: 10px;
        border-bottom: 2px solid var(--border-soft) !important;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .section-title i {
        font-size: 1rem;
    }

    .data-table-wrapper {
        background: var(--bg2) !important;
        border: 1px solid var(--border) !important;
        border-radius: var(--radius) !important;
        overflow: hidden;
        margin-bottom: 24px;
    }

    .data-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.85rem;
    }

    .data-table thead {
        background: var(--bg3) !important;
        border-bottom: 2px solid var(--border) !important;
    }

    .data-table th {
        padding: 12px 16px;
        text-align: left;
        font-weight: 700;
        color: var(--text-strong) !important;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .data-table td {
        padding: 12px 16px;
        border-bottom: 1px solid var(--border-soft) !important;
        color: var(--text) !important;
        vertical-align: middle;
    }

    .data-table tbody tr {
        transition: background 0.1s ease;
    }

    .data-table tbody tr:hover {
        background: rgba(var(--accent-rgb), 0.04) !important;
    }

    .data-table tbody tr:last-child td {
        border-bottom: none;
    }

    .data-table .text-right {
        text-align: right;
    }

    .data-table .text-center {
        text-align: center;
    }

    .model-name {
        font-weight: 600;
        color: var(--text-strong) !important;
        display: block;
    }

    .model-full-id {
        font-size: 0.7rem;
        color: var(--text-soft) !important;
        font-family: 'JetBrains Mono', monospace;
        display: block;
        margin-top: 2px;
    }

    .phase-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 0.68rem;
        font-weight: 700;
        margin: 2px 2px 2px 0;
        text-transform: lowercase;
    }

    .phase-compile  { background: rgba(88,166,255,.15);  color: #58a6ff; border: 1px solid rgba(88,166,255,.4); }
    .phase-respond  { background: rgba(63,185,80,.15);   color: var(--ok); border: 1px solid rgba(63,185,80,.4); }
    .phase-embedding{ background: rgba(210,153,34,.15);  color: var(--warn); border: 1px solid rgba(210,153,34,.4); }
    .phase-lint_fix { background: rgba(248,81,73,.15);   color: var(--danger); border: 1px solid rgba(248,81,73,.4); }
    .phase-rag      { background: rgba(163,113,247,.15); color: #a371f7; border: 1px solid rgba(163,113,247,.4); }

    .level-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 999px;
        font-size: 0.7rem;
        font-weight: 700;
    }

    .level-0 { background: rgba(var(--accent-rgb),.15); color: var(--accent); border: 1px solid var(--accent); }
    .level-1 { background: rgba(88,166,255,.15);  color: #58a6ff; border: 1px solid #58a6ff; }
    .level-2 { background: rgba(210,153,34,.15);  color: var(--warn); border: 1px solid var(--warn); }
    .level-3 { background: rgba(248,81,73,.15);   color: var(--danger); border: 1px solid var(--danger); }

    .status-active {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 10px;
        border-radius: 999px;
        font-size: 0.7rem;
        font-weight: 700;
        background: rgba(var(--ok-rgb, 63,185,80),.15) !important;
        color: var(--ok) !important;
        border: 1px solid var(--ok) !important;
    }

    .cost-highlight {
        font-weight: 800;
        color: var(--danger) !important;
        font-family: 'JetBrains Mono', monospace;
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

    .btn-dashboard {
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

    .btn-dashboard:hover {
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
        transition: color 0.15s ease;
    }

    .btn-back:hover {
        color: var(--accent) !important;
        text-decoration: none;
    }

    .loading {
        text-align: center;
        padding: 80px 20px;
        color: var(--text-soft) !important;
    }

    .loading i {
        color: var(--accent);
    }

    .total-row {
        background: var(--bg3) !important;
        font-weight: 800;
        border-top: 2px solid var(--border) !important;
    }

    .total-row td {
        color: var(--text-strong) !important;
        padding: 14px 16px !important;
    }

    @media (max-width: 768px) {
        .dashboard-container { padding: 16px; }
        .dashboard-header { padding: 16px; }
        .dashboard-header h1 { font-size: 1.2rem; }
        .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
        .stat-value { font-size: 1.5rem; }
        .data-table { font-size: 0.75rem; }
        .data-table th, .data-table td { padding: 8px 10px; }
    }
</style>
</head>
<body class="ui-theme theme-neon-green">

<div class="dashboard-header">
    <div style="display: flex; align-items: center; gap: 16px;">
        <a href="index.php" class="btn-back">
            <i class="fas fa-arrow-left"></i> Volver al chat
        </a>
        <div>
            <h1>
                <i class="fas fa-chart-line"></i>
                Dashboard de Monitoreo IA
            </h1>
        </div>
    </div>

    <div class="dashboard-controls">
        <label style="margin: 0; font-size: 0.8rem; color: var(--text-soft);">Período:</label>
        <input type="month"
               id="monthFilter"
               class="month-select"
               value="<?= htmlspecialchars($selectedMonth) ?>">
        <button class="btn-dashboard" onclick="loadDashboard()">
            <i class="fas fa-sync"></i> Actualizar
        </button>
    </div>
</div>

<div class="dashboard-container">
    <div id="dashboard-content">
        <div class="loading">
            <i class="fas fa-spinner fa-spin fa-3x"></i>
            <p style="margin-top: 20px; font-size: 0.95rem;">Cargando estadísticas...</p>
        </div>
    </div>
</div>

<script>
const monthFilter = document.getElementById('monthFilter');

monthFilter.addEventListener('change', () => {
    const newMonth = monthFilter.value;
    const url = new URL(window.location.href);
    url.searchParams.set('month', newMonth);
    window.location.href = url.toString();
});

function renderPhaseChart(byPhase) {
    const canvas = document.getElementById('chartTokenUsage');
    if (!canvas) return;

    if (window.tokenPhaseChart) {
        window.tokenPhaseChart.destroy();
    }

    window.tokenPhaseChart = new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: ['Compilación', 'Respuesta', 'Corrección Lint', 'Embeddings'],
            datasets: [{
                data: [
                    byPhase.compile || 0,
                    byPhase.respond || 0,
                    byPhase.lint_fix || 0,
                    byPhase.embedding || 0
                ],
                backgroundColor: [
                    '#ffc107',
                    '#3fb950',
                    '#f85149',
                    '#58a6ff'
                ],
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
                    labels: {
                        color: '#f0f6fc',
                        padding: 20,
                        usePointStyle: true
                    }
                }
            }
        }
    });
}

async function loadDashboard() {
    const container = document.getElementById('dashboard-content');
    const month = monthFilter.value || new Date().toISOString().slice(0, 7);

    try {
        const response = await fetch(`dashboard_stats.php?month=${encodeURIComponent(month)}`, {
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

        renderDashboard(data);
    } catch (error) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-exclamation-triangle"></i>
                <h3>Error al cargar dashboard</h3>
                <p>${escapeHtml(error.message)}</p>
                <button class="btn-dashboard" onclick="loadDashboard()" style="margin-top: 20px;">
                    <i class="fas fa-sync"></i> Reintentar
                </button>
            </div>
        `;
    }
}

function renderDashboard(data) {
    const container = document.getElementById('dashboard-content');

    const totalTokens = data.tokens && data.tokens.total ? data.tokens.total : 0;
    const totalCost = data.tokens && data.tokens.cost ? data.tokens.cost : 0;
    const activeSessions = data.sessions ? data.sessions.length : 0;
    const ladderSuccess = calculateLadderSuccess(data.ladder);
    const byPhase = data.tokens && data.tokens.by_phase ? data.tokens.by_phase : {};

    let html = `
        <!-- Tarjetas principales -->
        <div class="stats-grid">
            <div class="stat-card info">
                <i class="fas fa-coins stat-icon"></i>
                <div class="stat-label">Tokens Totales</div>
                <div class="stat-value">${totalTokens.toLocaleString()}</div>
                <div class="stat-description">Input + Output acumulados</div>
            </div>

            <div class="stat-card success">
                <i class="fas fa-dollar-sign stat-icon"></i>
                <div class="stat-label">Costo Estimado</div>
                <div class="stat-value cost">$${totalCost.toFixed(4)}</div>
                <div class="stat-description">Consumo del mes (USD)</div>
            </div>

            <div class="stat-card warning">
                <i class="fas fa-comments stat-icon"></i>
                <div class="stat-label">Sesiones Activas</div>
                <div class="stat-value">${activeSessions}</div>
                <div class="stat-description">Con actividad en el período</div>
            </div>

            <div class="stat-card accent">
                <i class="fas fa-layer-group stat-icon"></i>
                <div class="stat-label">Éxito Escalera Modelos</div>
                <div class="stat-value">${ladderSuccess}%</div>
                <div class="stat-description">Tasa de resolución automática</div>
            </div>
        </div>

        <!-- Desglose por fase -->
        <div class="section-title">
            <i class="fas fa-chart-pie"></i>
            Uso de Tokens por Fase del Pipeline
        </div>
        <div class="data-table-wrapper">
            <div style="min-height: 320px; padding: 20px;">
                <canvas id="chartTokenUsage"></canvas>
            </div>
        </div>
        
        <!-- Desglose por fase -->
        <div class="section-title">
            <i class="fas fa-chart-pie"></i>
            Uso de Tokens por Fase del Pipeline
        </div>
        <div class="data-table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Fase</th>
                        <th class="text-right">Tokens</th>
                        <th class="text-right">% del Total</th>
                    </tr>
                </thead>
                <tbody>
                    ${renderPhaseRow('Compilación', byPhase.compile || 0, totalTokens, 'compile')}
                    ${renderPhaseRow('Respuesta', byPhase.respond || 0, totalTokens, 'respond')}
                    ${renderPhaseRow('Embeddings', byPhase.embedding || 0, totalTokens, 'embedding')}
                    ${renderPhaseRow('Corrección Lint', byPhase.lint_fix || 0, totalTokens, 'lint_fix')}
                </tbody>
            </table>
        </div>


        <!-- Tabla de modelos -->
        <div class="section-title">
            <i class="fas fa-robot"></i>
            Todos los Modelos IA Utilizados
            <span style="font-size: 0.72rem; color: var(--text-soft); font-weight: 500; text-transform: none; margin-left: 8px;">
                Desglose histórico completo
            </span>
        </div>
        ${renderModelsTable(data.models)}

        <!-- Tabla de escalera de modelos -->
        <div class="section-title">
            <i class="fas fa-stairs"></i>
            Rendimiento de la Escalera de Modelos (Linting)
        </div>
        ${renderLadderTable(data.ladder)}

        <!-- Tabla de compresión -->
        <div class="section-title">
            <i class="fas fa-compress-alt"></i>
            Estado de Compresión de Contexto por Sesión
        </div>
        ${renderCompressionTable(data.sessions)}
    `;

    container.innerHTML = html;
    renderPhaseChart(byPhase);
}

function renderPhaseRow(name, tokens, total, phase) {
    const percentage = total > 0 ? ((tokens / total) * 100).toFixed(1) : '0.0';
    const phaseClass = `phase-${phase}`;

    return `
        <tr>
            <td>
                <span class="phase-badge ${phaseClass}">${name}</span>
            </td>
            <td class="text-right">${tokens.toLocaleString()}</td>
            <td class="text-right">
                <strong>${percentage}%</strong>
            </td>
        </tr>
    `;
}

function renderModelsTable(models) {
    if (!models || models.length === 0) {
        return `
            <div class="empty-state">
                <i class="fas fa-robot"></i>
                <h3>Sin datos de modelos</h3>
                <p>No hay uso de modelos registrado en este período.</p>
            </div>
        `;
    }

    const sortedModels = [...models].sort((a, b) => (b.total_cost || 0) - (a.total_cost || 0));

    let grandTotalInput = 0;
    let grandTotalOutput = 0;
    let grandTotalCost = 0;
    let grandTotalUses = 0;

    let rows = sortedModels.map(model => {
        grandTotalInput += model.total_input || 0;
        grandTotalOutput += model.total_output || 0;
        grandTotalCost += model.total_cost || 0;
        grandTotalUses += model.usage_count || 0;

        const shortName = (model.model_id || '')
            .replace('amazon.', '').replace('anthropic.', '').replace('meta.', '')
            .replace('mistral.', '').replace('cohere.', '').replace('qwen.', '').replace('deepseek.', '');

        let phasesHtml = '';
        if (model.phases) {
            const phaseLabels = {
                'compile': 'Compilación',
                'respond': 'Respuesta',
                'embedding': 'Embedding',
                'lint_fix': 'Lint Fix',
                'rag': 'RAG'
            };

            phasesHtml = Object.entries(model.phases).map(([phase, data]) => {
                const phaseClass = `phase-${phase}`;
                return `<span class="phase-badge ${phaseClass}" title="${phaseLabels[phase] || phase}: ${data.count} usos">${phase} (${data.count})</span>`;
            }).join('');
        }

        return `
            <tr>
                <td>
                    <span class="model-name">${escapeHtml(shortName)}</span>
                    <span class="model-full-id">${escapeHtml(model.model_id || '')}</span>
                </td>
                <td class="text-center">${(model.usage_count || 0).toLocaleString()}</td>
                <td class="text-right">${(model.total_input || 0).toLocaleString()}</td>
                <td class="text-right">${(model.total_output || 0).toLocaleString()}</td>
                <td class="text-right cost-highlight">$${(model.total_cost || 0).toFixed(6)}</td>
                <td>${phasesHtml || '<span style="color: var(--text-soft);">—</span>'}</td>
            </tr>
        `;
    }).join('');

    rows += `
        <tr class="total-row">
            <td>TOTAL (${sortedModels.length} modelos)</td>
            <td class="text-center">${grandTotalUses.toLocaleString()}</td>
            <td class="text-right">${grandTotalInput.toLocaleString()}</td>
            <td class="text-right">${grandTotalOutput.toLocaleString()}</td>
            <td class="text-right cost-highlight">$${grandTotalCost.toFixed(6)}</td>
            <td>—</td>
        </tr>
    `;

    return `
        <div class="data-table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Modelo</th>
                        <th class="text-center">Usos</th>
                        <th class="text-right">Tokens Input</th>
                        <th class="text-right">Tokens Output</th>
                        <th class="text-right">Costo (USD)</th>
                        <th>Fases del Pipeline</th>
                    </tr>
                </thead>
                <tbody>
                    ${rows}
                </tbody>
            </table>
        </div>
    `;
}

function renderLadderTable(ladder) {
    if (!ladder || ladder.length === 0) {
        return `
            <div class="data-table-wrapper">
                <div class="empty-state" style="padding: 40px 20px;">
                    <i class="fas fa-stairs" style="font-size: 2rem;"></i>
                    <h3>Sin intentos de linting</h3>
                    <p>No hay datos de escalera de modelos en este período.</p>
                </div>
            </div>
        `;
    }

    let totalAttempts = 0;
    let totalSuccess = 0;

    const rows = ladder.map(row => {
        const attempts = parseInt(row.total_attempts || 0);
        const success = parseInt(row.success_count || 0);
        totalAttempts += attempts;
        totalSuccess += success;

        const rate = attempts > 0 ? ((success / attempts) * 100).toFixed(1) : '0.0';
        const modelName = (row.model_used || '').split('.').pop()
            .replace(/-\d{8}-v1:0/g, '').replace(/-v1:0/g, '');

        const rateColor = rate >= 80 ? 'var(--ok)' : (rate >= 50 ? 'var(--warn)' : 'var(--danger)');

        return `
            <tr>
                <td><strong>${escapeHtml(modelName)}</strong></td>
                <td class="text-center">${attempts}</td>
                <td class="text-center" style="color: var(--ok);">${success}</td>
                <td class="text-center">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <div style="flex: 1; height: 8px; background: var(--bg); border-radius: 4px; overflow: hidden;">
                            <div style="width: ${rate}%; height: 100%; background: ${rateColor}; border-radius: 4px;"></div>
                        </div>
                        <strong style="min-width: 45px; text-align: right;">${rate}%</strong>
                    </div>
                </td>
            </tr>
        `;
    }).join('');

    return `
        <div class="data-table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Modelo</th>
                        <th class="text-center">Intentos</th>
                        <th class="text-center">Éxitos</th>
                        <th class="text-center">Tasa de Éxito</th>
                    </tr>
                </thead>
                <tbody>
                    ${rows}
                </tbody>
            </table>
        </div>
    `;
}

function renderCompressionTable(sessions) {
    if (!sessions || sessions.length === 0) {
        return `
            <div class="data-table-wrapper">
                <div class="empty-state" style="padding: 40px 20px;">
                    <i class="fas fa-compress-alt" style="font-size: 2rem;"></i>
                    <h3>Sin sesiones activas</h3>
                    <p>No hay sesiones con actividad en este período.</p>
                </div>
            </div>
        `;
    }

    const levelLabels = {
        '0': 'Nivel 0 (Crudo)',
        '1': 'Nivel 1 (Resumen x5)',
        '2': 'Nivel 2 (Macro x20)',
        '3': 'Nivel 3 (Épico x80)'
    };

    const rows = sessions.map(s => {
        const level = String(s.context_level || '0');
        const levelLabel = levelLabels[level] || `Nivel ${level}`;
        const levelClass = `level-${level}`;
        const lastComp = s.last_compressed_at
            ? new Date(s.last_compressed_at).toLocaleString('es-ES')
            : 'Nunca';

        return `
            <tr>
                <td>
                    <strong>${escapeHtml(s.title || 'Sesión #' + (s.id_ || '?'))}</strong>
                </td>
                <td>
                    <span class="level-badge ${levelClass}">${escapeHtml(levelLabel)}</span>
                </td>
                <td class="text-center">${s.block_count || 0}</td>
                <td>${escapeHtml(lastComp)}</td>
                <td>
                    <span class="status-active">
                        <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
                        Activa
                    </span>
                </td>
            </tr>
        `;
    }).join('');

    return `
        <div class="data-table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Sesión</th>
                        <th>Nivel de Compresión</th>
                        <th class="text-center">Bloques Activos</th>
                        <th>Última Compresión</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    ${rows}
                </tbody>
            </table>
        </div>
    `;
}

function calculateLadderSuccess(ladder) {
    if (!ladder || ladder.length === 0) return '0.0';

    let totalAttempts = 0;
    let totalSuccess = 0;

    ladder.forEach(row => {
        totalAttempts += parseInt(row.total_attempts || 0);
        totalSuccess += parseInt(row.success_count || 0);
    });

    if (totalAttempts === 0) return '0.0';
    return ((totalSuccess / totalAttempts) * 100).toFixed(1);
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Cargar al iniciar
loadDashboard();
</script>
</body>
</html>