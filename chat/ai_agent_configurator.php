<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';

if (!isset($_SESSION['usuario']) || empty($_SESSION['usuario'])) {
    header("Location: ../index.php");
    exit;
}

// ✅ Generar Token CSRF para el JS
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Verificar si es administrador (solo admin puede editar configuraciones globales)
$is_admin = isset($_SESSION['user_id']) && ($_SESSION['user_id'] == 1);
$user_id = (int)($_SESSION['user_id'] ?? 1);

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Configuración de Agentes IA</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.3.1/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link rel="icon" href="asistente-de-inteligencia-artificial.gif" type="image/x-icon">

<meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="css/chat2.css" />
<link rel="stylesheet" href="css/design-system.css" />

<style>
/* Estilos específicos para la página de configuración de agentes */
.agent-config-wrapper {
    padding: 1.5rem;
    max-width: 1400px;
    margin: 0 auto;
}

.agent-config-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--border);
}

.agent-card {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 1.25rem;
    margin-bottom: 1rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: all 0.2s ease;
}

.agent-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
    transform: translateY(-2px);
}

.agent-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid var(--border-soft);
}

.agent-card-title {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-strong);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.agent-card-badge {
    font-size: 0.65rem;
    padding: 0.2rem 0.5rem;
    border-radius: 999px;
    background: var(--accent);
    color: #fff;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.form-group label {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-strong);
    margin-bottom: 0.35rem;
}

.form-group small {
    color: var(--text-soft);
    font-size: 0.7rem;
}

textarea.form-control {
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.8rem;
    min-height: 100px;
    resize: vertical;
}

.config-section {
    margin-bottom: 1.5rem;
}

.config-section-title {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-soft);
    margin-bottom: 0.75rem;
    padding-bottom: 0.4rem;
    border-bottom: 1px solid var(--border-soft);
}

.btn-save-agent {
    background: var(--accent);
    border-color: var(--accent);
    color: #fff;
    font-weight: 600;
    padding: 0.5rem 1.25rem;
    border-radius: 10px;
}

.btn-save-agent:hover {
    background: var(--accent-2);
    border-color: var(--accent-2);
    color: #fff;
}

.inline-controls {
    display: flex;
    gap: 0.75rem;
    align-items: center;
    flex-wrap: wrap;
}

.control-group {
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.control-group input[type="number"] {
    width: 70px;
}

.switch-toggle {
    position: relative;
    display: inline-block;
    width: 44px;
    height: 24px;
}

.switch-toggle input {
    opacity: 0;
    width: 0;
    height: 0;
}

.slider-toggle {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: var(--border);
    transition: .3s;
    border-radius: 24px;
}

.slider-toggle:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .3s;
    border-radius: 50%;
}

input:checked + .slider-toggle {
    background-color: var(--accent);
}

input:checked + .slider-toggle:before {
    transform: translateX(20px);
}

/* Toast notifications */
.agent-toast-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.agent-toast {
    min-width: 300px;
    max-width: 450px;
    padding: 1rem 1.25rem;
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    animation: slideInRight 0.3s ease-out;
}

.agent-toast.success {
    border-left: 4px solid var(--ok);
}

.agent-toast.error {
    border-left: 4px solid #e74c60;
}

.agent-toast.warning {
    border-left: 4px solid var(--warn);
}

.agent-toast.info {
    border-left: 4px solid var(--accent);
}

@keyframes slideInRight {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

.agent-toast-close {
    margin-left: auto;
    background: none;
    border: none;
    color: var(--text-soft);
    cursor: pointer;
    font-size: 1.1rem;
    padding: 0;
    line-height: 1;
}

.agent-toast-close:hover {
    color: var(--text);
}

.agent-toast-icon {
    font-size: 1.2rem;
    flex-shrink: 0;
}

.agent-toast.success .agent-toast-icon { color: var(--ok); }
.agent-toast.error .agent-toast-icon { color: #e74c60; }
.agent-toast.warning .agent-toast-icon { color: var(--warn); }
.agent-toast.info .agent-toast-icon { color: var(--accent); }

.agent-toast-content {
    flex: 1;
}

.agent-toast-title {
    font-weight: 600;
    font-size: 0.9rem;
    margin-bottom: 0.25rem;
    color: var(--text-strong);
}

.agent-toast-message {
    font-size: 0.8rem;
    color: var(--text);
}

/* Estilos para la vista de detalles del agente */
.agent-details-view {
    padding: 1rem 0;
}

.detail-section {
    margin-bottom: 1.5rem;
    padding: 1rem;
    background: var(--bg3);
    border-radius: var(--radius);
    border: 1px solid var(--border-soft);
}

.detail-section-title {
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--accent);
    margin-bottom: 0.75rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    padding: 0.4rem 0;
    border-bottom: 1px solid var(--border-soft);
    font-size: 0.85rem;
}

.detail-row:last-child {
    border-bottom: none;
}

.detail-label {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-strong);
    margin-bottom: 0.35rem;
}

.detail-content {
    font-size: 0.8rem;
    color: var(--text);
    background: var(--bg2);
    padding: 0.75rem;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border-soft);
}

.detail-content pre {
    margin: 0;
    white-space: pre-wrap;
    word-wrap: break-word;
}
</style>
</head>
<body class="ui-theme theme-neon-green theme-light vision-normal ascii-on">
<input type="hidden" id="configUserId" value="<?= (int)$_SESSION['user_id'] ?>">

<div class="app-container">

<!-- cuerpo -->
<main id="chat-main" class="chat-main" style="width:100%;max-width:100%;">
<header class="chat-header">
  <div class="header-left">
    <h1 class="chat-title">
        <i class="fas fa-robot mr-2"></i>Configuración de Agentes IA
    </h1>
  </div>
  <div class="header-right">
    <button id="btnRefresh" class="btn btn-sm btn-outline-secondary" title="Recargar lista">
        <i class="fas fa-sync-alt"></i>
    </button>
    <button id="btnAddAgent" class="btn btn-sm btn-primary" title="Nuevo agente">
        <i class="fas fa-plus"></i> Nuevo Agente
    </button>
  </div>
</header>

<div class="agent-config-wrapper">
        
        <!-- Filtros -->
        <div class="card mb-3" style="background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius-lg);">
            <div class="card-body py-2">
                <div class="d-flex justify-content-between align-items-center flex-wrap" style="gap:0.75rem;">
                    <div class="inline-controls">
                        <div class="form-group mb-0">
                            <label for="filterGroup" class="mb-1">Filtrar por grupo:</label>
                            <select id="filterGroup" class="form-control form-control-sm" style="width:180px;">
                                <option value="">Todos los grupos</option>
                                <option value="chat">Chat</option>
                                <option value="text_block">Text Block</option>
                                <option value="other">Otro</option>
                            </select>
                        </div>
                        <div class="form-group mb-0">
                            <label>&nbsp;</label>
                            <button id="btnRefresh" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-sync-alt"></i> Recargar
                            </button>
                        </div>
                    </div>
                    <?php if ($is_admin): ?>
                    <button id="btnAddAgent" class="btn-save-agent">
                        <i class="fas fa-plus mr-2"></i>Nuevo Agente
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Lista de Agentes -->
        <div id="agentsList" class="agents-list">
            <!-- Se carga dinámicamente -->
            <div class="text-center py-5">
                <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
                <p class="text-muted mt-3">Cargando configuraciones...</p>
            </div>
        </div>

    </div>
    
    <!-- Contenedor de toasts (también en el body al final) -->
    <div id="agentToastContainer" class="agent-toast-container"></div>
    
</main>
</div>

<!-- Modal para Editar/Crear Agente -->
<div id="agentModal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl" role="document">
        <div class="modal-content" style="background:var(--bg2);border:1px solid var(--border);">
            <div class="modal-header" style="border-bottom:1px solid var(--border);">
                <h5 class="modal-title" id="agentModalTitle">
                    <i class="fas fa-edit mr-2"></i>Editar Agente
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                    <span style="color:var(--text);">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="agentForm">
                    <input type="hidden" id="agentId" value="">
                    
                    <!-- Información Básica -->
                    <div class="config-section">
                        <div class="config-section-title">Información Básica</div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="agentKey">Agent Key *</label>
                                    <input type="text" class="form-control" id="agentKey" required 
                                           placeholder="ej: chat_main, prompt_compiler">
                                    <small class="text-muted">Identificador único del agente</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="agentGroup">Grupo</label>
                                    <select class="form-control" id="agentGroup">
                                        <option value="chat">Chat</option>
                                        <option value="text_block">Text Block</option>
                                        <option value="other">Otro</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="displayName">Nombre Visible</label>
                                    <input type="text" class="form-control" id="displayName" 
                                           placeholder="Nombre descriptivo">
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="sortOrder">Orden de Visualización</label>
                            <input type="number" class="form-control" id="sortOrder" value="0" min="0">
                        </div>
                    </div>

                    <!-- Configuración del Modelo -->
                    <div class="config-section">
                        <div class="config-section-title">Configuración del Modelo</div>
                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label for="modelId">Modelo IA *</label>
                                    <select class="form-control" id="modelId" required>
                                        <optgroup label="💬 Chat y Razonamiento">
                                            <option value="amazon.nova-micro-v1:0">Amazon Nova Micro</option>
                                            <option value="amazon.nova-pro-v1:0">Amazon Nova Pro</option>
                                            <option value="anthropic.claude-3-5-sonnet-20241022-v2:0">Claude 3.5 Sonnet</option>
                                            <option value="anthropic.claude-sonnet-4-20250514-v1:0">Claude Sonnet 4</option>
                                            <option value="meta.llama3-70b-instruct-v1:0">Llama 3 70B</option>
                                            <option value="mistral.mistral-large-2402-v1:0">Mistral Large</option>
                                        </optgroup>
                                    </select>
                                    <small class="text-muted">Modelo que usará este agente</small>
                                </div>
                            </div>
                        </div>

                        <div class="inline-controls mt-3">
                            <div class="control-group">
                                <label for="temperature"><i class="fas fa-thermometer-half"></i> Temperatura:</label>
                                <input type="number" class="form-control form-control-sm" id="temperature" 
                                       step="0.1" min="0" max="2" value="0.7">
                            </div>
                            <div class="control-group">
                                <label for="maxTokens"><i class="fas fa-arrows-alt-h"></i> Max Tokens:</label>
                                <input type="number" class="form-control form-control-sm" id="maxTokens" 
                                       step="1" min="100" max="8192" value="2048">
                            </div>
                            <div class="control-group">
                                <label for="topP"><i class="fas fa-bullseye"></i> Top P:</label>
                                <input type="number" class="form-control form-control-sm" id="topP" 
                                       step="0.05" min="0" max="1" value="0.9">
                            </div>
                            <div class="control-group">
                                <label for="seed"><i class="fas fa-dice"></i> Seed:</label>
                                <input type="number" class="form-control form-control-sm" id="seed" 
                                       step="1" min="0" value="0" placeholder="0=auto">
                            </div>
                        </div>
                    </div>

                    <!-- Instrucciones -->
                    <div class="config-section">
                        <div class="config-section-title">Instrucciones y Prompts</div>
                        
                        <div class="form-group">
                            <label for="systemInstruction">System Instruction (Prompt del Sistema)</label>
                            <textarea class="form-control" id="systemInstruction" rows="6" 
                                      placeholder="Define el comportamiento base del agente..."></textarea>
                            <small class="text-muted">Instrucciones fundamentales que definen cómo debe comportarse el agente</small>
                        </div>

                        <div class="form-group">
                            <label for="userPromptTemplate">User Prompt Template</label>
                            <textarea class="form-control" id="userPromptTemplate" rows="4" 
                                      placeholder="Plantilla para procesar las preguntas del usuario..."></textarea>
                            <small class="text-muted">Plantilla opcional para formatear las entradas del usuario</small>
                        </div>
                    </div>

                    <!-- Configuración Extra (JSON) -->
                    <div class="config-section">
                        <div class="config-section-title">Configuración Avanzada (JSON)</div>
                        <div class="form-group">
                            <label for="extraConfig">Extra Config</label>
                            <textarea class="form-control" id="extraConfig" rows="6" 
                                      placeholder='{"key": "value"}'></textarea>
                            <small class="text-muted">Configuración adicional en formato JSON. Ej: {"type_labels": {...}, "custom_param": "value"}</small>
                        </div>
                    </div>

                    <!-- Estado -->
                    <div class="config-section">
                        <div class="config-section-title">Estado</div>
                        <div class="form-group">
                            <label class="switch-toggle">
                                <input type="checkbox" id="isActive" checked>
                                <span class="slider-toggle"></span>
                            </label>
                            <span style="margin-left:0.5rem;font-weight:600;">Agente Activo</span>
                            <small class="text-muted d-block mt-1">Si se desactiva, el agente no será cargado automáticamente</small>
                        </div>
                    </div>

                </form>
            </div>
            <div class="modal-footer" style="border-top:1px solid var(--border);">
                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancelar</button>
                <?php if ($is_admin): ?>
                <button type="button" class="btn btn-danger mr-auto" id="btnDeleteAgent" style="display:none;">
                    <i class="fas fa-trash"></i> Eliminar
                </button>
                <button type="button" class="btn-save-agent" id="btnSaveAgent">
                    <i class="fas fa-save mr-2"></i>Guardar Cambios
                </button>
                <?php else: ?>
                <small class="text-muted">Solo administradores pueden editar configuraciones</small>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.3.1/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
    const csrfToken = $('meta[name="csrf-token"]').attr('content');
    let currentAgents = [];

    // Cargar lista de agentes
    function loadAgents() {
        $('#agentsList').html(`
            <div class="text-center py-5">
                <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
                <p class="text-muted mt-3">Cargando configuraciones...</p>
            </div>
        `);

        $.ajax({
            url: 'get_ai_agents.php',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    currentAgents = response.agents || [];
                    renderAgents(currentAgents);
                } else {
                    showError('Error al cargar agentes: ' + (response.message || 'Error desconocido'));
                }
            },
            error: function(xhr, status, error) {
                showError('Error de conexión: ' + error);
            }
        });
    }

    // Renderizar lista de agentes como tabla completa
    function renderAgents(agents) {
        const filterGroup = $('#filterGroup').val();
        const filtered = filterGroup 
            ? agents.filter(a => a.agent_group === filterGroup)
            : agents;

        if (filtered.length === 0) {
            $('#agentsList').html(`
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No hay agentes configurados para este grupo</p>
                </div>
            `);
            return;
        }

        let html = `
            <div class="table-responsive" style="background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;">
                <table class="table table-hover mb-0" style="width:100%;">
                    <thead style="background:var(--bg3);border-bottom:2px solid var(--border);">
                        <tr>
                            <th style="width:60px;">ID</th>
                            <th style="width:200px;">Agent Key</th>
                            <th style="width:150px;">Grupo</th>
                            <th style="width:200px;">Nombre Visible</th>
                            <th style="width:250px;">Modelo</th>
                            <th style="width:80px;" class="text-center">Temp</th>
                            <th style="width:80px;" class="text-center">Max Tok</th>
                            <th style="width:70px;" class="text-center">Top P</th>
                            <th style="width:70px;" class="text-center">Seed</th>
                            <th style="width:80px;" class="text-center">Orden</th>
                            <th style="width:90px;" class="text-center">Estado</th>
                            <th style="width:220px;" class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        filtered.forEach((agent, filteredIndex) => {
            const originalIndex = agents.findIndex(a => a.id_ === agent.id_);
            const modelDisplay = agent.model_id ? agent.model_id.split('.').pop() : 'N/A';
            const tempValue = agent.temperature !== null && agent.temperature !== undefined ? agent.temperature.toFixed(1) : '-';
            const maxTokens = agent.max_tokens_output || '-';
            const topP = agent.top_p !== null && agent.top_p !== undefined ? agent.top_p.toFixed(2) : '-';
            const seed = agent.seed || '0';
            const sortOrder = agent.sort_order || '0';
            
            html += `
                <tr class="agent-row" data-agent-id="${agent.id_}" style="cursor:pointer;" onclick="openAgentModalByIndex(${originalIndex}, false)">
                    <td class="align-middle"><strong>#${agent.id_}</strong></td>
                    <td class="align-middle"><code style="font-size:0.75rem;">${escapeHtml(agent.agent_key)}</code></td>
                    <td class="align-middle"><span class="badge badge-info">${escapeHtml(agent.agent_group)}</span></td>
                    <td class="align-middle">${escapeHtml(agent.display_name || agent.agent_key)}</td>
                    <td class="align-middle">
                        <span class="badge ${agent.model_id ? 'badge-primary' : 'badge-secondary'}" style="font-size:0.7rem;">
                            ${escapeHtml(modelDisplay)}
                        </span>
                    </td>
                    <td class="align-middle text-center">${tempValue}</td>
                    <td class="align-middle text-center">${maxTokens}</td>
                    <td class="align-middle text-center">${topP}</td>
                    <td class="align-middle text-center">${seed}</td>
                    <td class="align-middle text-center">${sortOrder}</td>
                    <td class="align-middle text-center">
                        <span class="badge ${agent.is_active ? 'badge-success' : 'badge-secondary'}" style="min-width:70px;">
                            ${agent.is_active ? '<i class="fas fa-check"></i> Activo' : '<i class="fas fa-times"></i> Inactivo'}
                        </span>
                    </td>
                    <td class="align-middle text-center">
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-primary btn-edit-agent" 
                                    onclick="event.stopPropagation(); openAgentModalByIndex(${originalIndex}, false)" 
                                    title="Editar">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button type="button" class="btn btn-outline-info btn-view-agent" 
                                    onclick="event.stopPropagation(); viewAgentDetails(${originalIndex}, false)" 
                                    title="Ver detalles completos">
                                <i class="fas fa-eye"></i>
                            </button>
                            <?php if ($is_admin): ?>
                            <button type="button" class="btn btn-outline-danger btn-delete-agent" 
                                    onclick="event.stopPropagation(); deleteAgentById(${agent.id_})" 
                                    title="Eliminar">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            `;
        });

        html += `
                    </tbody>
                </table>
            </div>
            <div class="mt-3 text-muted small">
                <i class="fas fa-info-circle"></i> 
                Mostrando ${filtered.length} de ${agents.length} agente(s). 
                Haz clic en una fila para editar o usa los botones de acción.
            </div>
        `;

        $('#agentsList').html(html);
        
        // Guardar referencia global para acceso por índice - usar filtered cuando hay filtro, todos si no
        window.currentFilteredAgents = filtered;
        window.currentAllAgents = agents;
        window.isFilterActive = !!filterGroup;
    }
    
    // Abrir modal por índice del array (ahora siempre usamos el índice original)
    window.openAgentModalByIndex = function(index, useFiltered) {
        const agent = window.currentAllAgents[index];
        if (agent) {
            openAgentModal(agent);
        }
    };

    // Ver detalles completos del agente en un modal informativo
    window.viewAgentDetails = function(index, useFiltered) {
        const agent = window.currentAllAgents[index];
        if (!agent) return;
        
        const detailsHtml = `
            <div class="agent-details-view">
                <div class="detail-section">
                    <h6 class="detail-section-title"><i class="fas fa-id-badge"></i> Información Básica</h6>
                    <div class="detail-row"><strong>ID:</strong> #${agent.id_}</div>
                    <div class="detail-row"><strong>Agent Key:</strong> <code>${escapeHtml(agent.agent_key)}</code></div>
                    <div class="detail-row"><strong>Grupo:</strong> ${escapeHtml(agent.agent_group)}</div>
                    <div class="detail-row"><strong>Nombre Visible:</strong> ${escapeHtml(agent.display_name || agent.agent_key)}</div>
                    <div class="detail-row"><strong>Orden:</strong> ${agent.sort_order}</div>
                </div>
                
                <div class="detail-section">
                    <h6 class="detail-section-title"><i class="fas fa-brain"></i> Configuración del Modelo</h6>
                    <div class="detail-row"><strong>Modelo:</strong> ${escapeHtml(agent.model_id || 'N/A')}</div>
                    <div class="detail-row"><strong>Temperatura:</strong> ${agent.temperature !== null ? agent.temperature : 'default'}</div>
                    <div class="detail-row"><strong>Max Tokens:</strong> ${agent.max_tokens_output || 'default'}</div>
                    <div class="detail-row"><strong>Top P:</strong> ${agent.top_p !== null ? agent.top_p : 'default'}</div>
                    <div class="detail-row"><strong>Seed:</strong> ${agent.seed || '0'}</div>
                </div>
                
                <div class="detail-section">
                    <h6 class="detail-section-title"><i class="fas fa-file-code"></i> Instrucciones</h6>
                    <div class="detail-label">System Instruction:</div>
                    <div class="detail-content">${agent.system_instruction ? '<pre style="white-space:pre-wrap;font-size:0.8rem;">' + escapeHtml(agent.system_instruction) + '</pre>' : '<em class="text-muted">Sin instrucciones de sistema</em>'}</div>
                    
                    <div class="detail-label mt-3">User Prompt Template:</div>
                    <div class="detail-content">${agent.user_prompt_template ? '<pre style="white-space:pre-wrap;font-size:0.8rem;">' + escapeHtml(agent.user_prompt_template) + '</pre>' : '<em class="text-muted">Sin plantilla de usuario</em>'}</div>
                </div>
                
                <div class="detail-section">
                    <h6 class="detail-section-title"><i class="fas fa-cog"></i> Configuración Extra (JSON)</h6>
                    <div class="detail-content"><pre style="white-space:pre-wrap;font-size:0.8rem;">${escapeHtml(agent.extra_config || '{}')}</pre></div>
                </div>
                
                <div class="detail-section">
                    <h6 class="detail-section-title"><i class="fas fa-toggle-on"></i> Estado</h6>
                    <div class="detail-row">
                        <span class="badge ${agent.is_active ? 'badge-success' : 'badge-secondary'}" style="font-size:0.9rem;">
                            ${agent.is_active ? '<i class="fas fa-check"></i> Activo' : '<i class="fas fa-times"></i> Inactivo'}
                        </span>
                    </div>
                </div>
            </div>
        `;
        
        $('#agentModalTitle').html('<i class="fas fa-eye mr-2"></i>Detalles del Agente');
        $('#agentForm').hide();
        $('.modal-body').append('<div id="agentDetailsContent"></div>');
        $('#agentDetailsContent').html(detailsHtml);
        $('#btnDeleteAgent').hide();
        $('#btnSaveAgent').hide();
        
        // Agregar botón para cerrar/cancelar
        if ($('.modal-footer .btn-cancel-details').length === 0) {
            $('.modal-footer').prepend('<button type="button" class="btn btn-outline-secondary btn-cancel-details" data-dismiss="modal"><i class="fas fa-times"></i> Cerrar</button>');
        }
        
        $('#agentModal').modal('show');
        
        // Limpiar al cerrar
        $('#agentModal').on('hidden.bs.modal', function() {
            $('#agentDetailsContent').remove();
            $('#agentForm').show();
            $('.btn-cancel-details').remove();
        }, { once: true });
    };

    // Abrir modal para editar/crear agente
    function openAgentModal(agent = null) {
        $('#agentForm')[0].reset();
        
        if (agent && agent.id_) {
            // Modo edición
            $('#agentModalTitle').html('<i class="fas fa-edit mr-2"></i>Editar Agente');
            $('#agentId').val(agent.id_);
            $('#agentKey').val(agent.agent_key).prop('readonly', true);
            $('#agentGroup').val(agent.agent_group || 'other');
            $('#displayName').val(agent.display_name || '');
            $('#sortOrder').val(agent.sort_order || 0);
            $('#modelId').val(agent.model_id || '');
            $('#temperature').val(agent.temperature !== null && agent.temperature !== undefined ? agent.temperature : 0.7);
            $('#maxTokens').val(agent.max_tokens_output || 2048);
            $('#topP').val(agent.top_p !== null && agent.top_p !== undefined ? agent.top_p : 0.9);
            $('#seed').val(agent.seed || 0);
            $('#systemInstruction').val(agent.system_instruction || '');
            $('#userPromptTemplate').val(agent.user_prompt_template || '');
            $('#extraConfig').val(agent.extra_config || '{}');
            $('#isActive').prop('checked', agent.is_active == 1);
            $('#btnDeleteAgent').show();
        } else {
            // Modo creación
            $('#agentModalTitle').html('<i class="fas fa-plus mr-2"></i>Nuevo Agente');
            $('#agentId').val('');
            $('#agentKey').prop('readonly', false);
            $('#btnDeleteAgent').hide();
        }

        $('#agentModal').modal('show');
    }

    // Guardar agente
    $('#btnSaveAgent').on('click', function() {
        const formData = {
            id_: $('#agentId').val(),
            agent_key: $('#agentKey').val(),
            agent_group: $('#agentGroup').val(),
            display_name: $('#displayName').val(),
            sort_order: parseInt($('#sortOrder').val()) || 0,
            model_id: $('#modelId').val() || null,
            temperature: parseFloat($('#temperature').val()) || 0.7,
            max_tokens_output: parseInt($('#maxTokens').val()) || 2048,
            top_p: parseFloat($('#topP').val()) || 0.9,
            seed: parseInt($('#seed').val()) || 0,
            system_instruction: $('#systemInstruction').val(),
            user_prompt_template: $('#userPromptTemplate').val(),
            extra_config: $('#extraConfig').val(),
            is_active: $('#isActive').is(':checked') ? 1 : 0
        };

        // Validar JSON en extra_config
        try {
            if (formData.extra_config) {
                JSON.parse(formData.extra_config);
            }
        } catch (e) {
            showError('Extra Config debe ser JSON válido: ' + e.message);
            return;
        }

        $.ajax({
            url: 'save_ai_agent.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(formData),
            headers: { 'X-CSRF-Token': csrfToken },
            success: function(response) {
                if (response.success) {
                    showSuccess('Agente guardado correctamente');
                    $('#agentModal').modal('hide');
                    loadAgents();
                } else {
                    showError('Error al guardar: ' + (response.message || 'Error desconocido'));
                }
            },
            error: function(xhr, status, error) {
                showError('Error de conexión: ' + error);
            }
        });
    });

    // Eliminar agente (desde modal)
    $('#btnDeleteAgent').on('click', function() {
        const agentId = $('#agentId').val();
        if (!agentId) return;

        if (!confirm('¿Estás seguro de eliminar este agente? Esta acción no se puede deshacer.')) {
            return;
        }

        deleteAgentById(agentId);
    });
    
    // Función global para eliminar agente por ID (desde tabla)
    window.deleteAgentById = function(agentId) {
        if (!confirm('¿Estás seguro de eliminar este agente? Esta acción no se puede deshacer.')) {
            return;
        }

        $.ajax({
            url: 'delete_ai_agent.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ id_: agentId }),
            headers: { 'X-CSRF-Token': csrfToken },
            success: function(response) {
                if (response.success) {
                    showSuccess('Agente eliminado correctamente');
                    $('#agentModal').modal('hide');
                    loadAgents();
                } else {
                    showError('Error al eliminar: ' + (response.message || 'Error desconocido'));
                }
            },
            error: function(xhr, status, error) {
                showError('Error de conexión: ' + error);
            }
        });
    };

    // Filtrar por grupo
    $('#filterGroup').on('change', function() {
        renderAgents(currentAgents);
    });

    // Refrescar lista
    $('#btnRefresh').on('click', loadAgents);

    // Nuevo agente
    $('#btnAddAgent').on('click', function() {
        openAgentModal(null);
    });

    // Utilidades - Toast notifications con feedback visual
    function showToast(title, message, type = 'info') {
        const container = document.getElementById('agentToastContainer');
        if (!container) return;
        
        const toast = document.createElement('div');
        toast.className = `agent-toast ${type}`;
        
        let iconClass = 'fa-info-circle';
        if (type === 'success') iconClass = 'fa-check-circle';
        if (type === 'error') iconClass = 'fa-exclamation-circle';
        if (type === 'warning') iconClass = 'fa-exclamation-triangle';
        
        toast.innerHTML = `
            <i class="fas ${iconClass} agent-toast-icon"></i>
            <div class="agent-toast-content">
                <div class="agent-toast-title">${escapeHtml(title)}</div>
                <div class="agent-toast-message">${escapeHtml(message)}</div>
            </div>
            <button class="agent-toast-close" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        container.appendChild(toast);
        
        // Auto-remover después de 5 segundos
        setTimeout(() => {
            if (toast.parentElement) {
                toast.style.animation = 'slideInRight 0.3s ease-out reverse';
                setTimeout(() => { if (toast.parentElement) toast.remove(); }, 300);
            }
        }, 5000);
    }

    function showSuccess(message) {
        showToast('Éxito', message, 'success');
    }

    function showError(message) {
        showToast('Error', message, 'error');
    }

    function showWarning(message) {
        showToast('Advertencia', message, 'warning');
    }

    function showInfo(message) {
        showToast('Información', message, 'info');
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Cargar inicial
    loadAgents();
});
</script>

</body>
</html>
