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
</style>
</head>
<body class="ui-theme theme-neon-green theme-light vision-normal ascii-on">
<input type="hidden" id="configUserId" value="<?= (int)$_SESSION['user_id'] ?>">

<div class="app-container">
<!-- Panel lateral -->
<aside id="chat-sidebar" class="sidebar-panel">

<div class="sidebar-brand">
    <a href="index.php">
        <img src="asistente-de-inteligencia-artificial.gif" alt="IA" style="height: 2.2em; vertical-align: middle; display: inline-block;"> Config IA
    </a>
</div>

<div class="sidebar-section">
<div class="section-label">NAVEGACIÓN</div>
<div id="sbNavList" class="projects-list">
    <a href="index.php" class="sb-item" style="display:flex;align-items:center;gap:8px;text-decoration:none;color:var(--text);padding:0.5rem 0.75rem;border-radius:8px;transition:all 0.2s;" onmouseover="this.style.background='var(--bg3)'" onmouseout="this.style.background='transparent'">
        <i class="fas fa-comments"></i>
        <span>Chat Principal</span>
    </a>
    <a href="ai_agent_configurator.php" class="sb-item" style="display:flex;align-items:center;gap:8px;text-decoration:none;color:var(--accent);font-weight:600;padding:0.5rem 0.75rem;border-radius:8px;background:var(--bg3);">
        <i class="fas fa-robot"></i>
        <span>Configuración Agentes</span>
    </a>
    <a href="user_preferences.php" class="sb-item" style="display:flex;align-items:center;gap:8px;text-decoration:none;color:var(--text);padding:0.5rem 0.75rem;border-radius:8px;transition:all 0.2s;" onmouseover="this.style.background='var(--bg3)'" onmouseout="this.style.background='transparent'">
        <i class="fas fa-sliders-h"></i>
        <span>Preferencias Usuario</span>
    </a>
</div>
</div>

<div class="sidebar-footer">
<div class="user-profile">
<div class="user-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($_SESSION['usuario'], 0, 1))) ?></div>
<div class="user-info">
<div class="user-name"><?= htmlspecialchars($_SESSION['usuario']) ?></div>
<div class="user-role">Administrador IA</div>
</div>
</div>
</div>

</aside>

<button id="sidebar-toggle" class="sidebar-toggle" aria-label="Ocultar o mostrar barra lateral" title="Ocultar o mostrar barra lateral">
<i class="fas fa-chevron-left"></i>
</button>

<!-- cuerpo -->
<main id="chat-main" class="chat-main">
<header class="chat-header">
  <div class="header-left">
    <button id="sidebar-toggle-mobile" class="sidebar-toggle-mobile" aria-label="Abrir barra lateral" title="Abrir barra lateral" type="button">
      <i class="fas fa-bars"></i>
    </button>
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
                                <option value="prompt_compiler">Prompt Compiler</option>
                                <option value="chat_main">Chat Principal</option>
                                <option value="embeddings">Embeddings</option>
                                <option value="text_blocks">Text Blocks</option>
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
                                        <option value="prompt_compiler">Prompt Compiler</option>
                                        <option value="chat_main">Chat Principal</option>
                                        <option value="embeddings">Embeddings</option>
                                        <option value="text_blocks">Text Blocks</option>
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
                            <div class="col-md-6">
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
                                        <optgroup label="🧮 Embeddings">
                                            <option value="amazon.titan-embed-text-v2:0">Titan Embed Text V2</option>
                                            <option value="cohere.embed-v4-v1:0">Cohere Embed V4</option>
                                        </optgroup>
                                    </select>
                                    <small class="text-muted">Modelo que usará este agente</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="embeddingModel">Modelo Embedding (opcional)</label>
                                    <select class="form-control" id="embeddingModel">
                                        <option value="">-- Sin embedding --</option>
                                        <option value="amazon.titan-embed-text-v2:0">Titan Embed Text V2</option>
                                        <option value="cohere.embed-v4-v1:0">Cohere Embed V4</option>
                                    </select>
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

    // Renderizar lista de agentes
    function renderAgents(agents) {
        const filterGroup = $('#filterGroup').val();
        const filtered = filterGroup 
            ? agents.filter(a => a.agent_group === filterGroup)
            : agents;

        if (filtered.length === 0) {
            $('#agentsList').html(`
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No hay agentes configurados</p>
                </div>
            `);
            return;
        }

        let html = '';
        filtered.forEach(agent => {
            const modelBadge = agent.model_id ? agent.model_id.split('.').pop() : 'N/A';
            const tempValue = agent.temperature !== null ? agent.temperature : 'default';
            
            html += `
                <div class="agent-card" data-agent-id="${agent.id_}">
                    <div class="agent-card-header">
                        <div>
                            <h3 class="agent-card-title">
                                ${escapeHtml(agent.display_name || agent.agent_key)}
                                <small class="text-muted ml-2" style="font-size:0.85rem;font-weight:400;">(${escapeHtml(agent.agent_key)})</small>
                            </h3>
                            <small class="text-muted">Grupo: ${escapeHtml(agent.agent_group)}</small>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="agent-card-badge">${escapeHtml(modelBadge)}</span>
                            <span class="badge ${agent.is_active ? 'badge-success' : 'badge-secondary'}">
                                ${agent.is_active ? 'Activo' : 'Inactivo'}
                            </span>
                            <button class="btn btn-sm btn-outline-primary btn-edit-agent" data-agent='${JSON.stringify(agent).replace(/'/g, "&apos;")}'>
                                <i class="fas fa-edit"></i> Editar
                            </button>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <small class="text-muted d-block">Temperatura</small>
                            <strong>${tempValue}</strong>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted d-block">Max Tokens</small>
                            <strong>${agent.max_tokens_output || 'default'}</strong>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted d-block">Embedding</small>
                            <strong>${agent.embedding_model || 'No configurado'}</strong>
                        </div>
                    </div>
                    ${agent.system_instruction ? `
                    <div class="mt-3 p-3" style="background:var(--bg);border-radius:var(--radius);">
                        <small class="text-muted d-block mb-2">System Instruction (primeros 200 chars):</small>
                        <code style="font-size:0.8rem;display:block;white-space:pre-wrap;overflow:hidden;text-overflow:ellipsis;">
                            ${escapeHtml(agent.system_instruction.substring(0, 200))}${agent.system_instruction.length > 200 ? '...' : ''}
                        </code>
                    </div>
                    ` : ''}
                </div>
            `;
        });

        $('#agentsList').html(html);

        // Bind edit buttons
        $('.btn-edit-agent').on('click', function() {
            const agentData = $(this).data('agent');
            openAgentModal(agentData);
        });
    }

    // Abrir modal para editar/crear agente
    function openAgentModal(agent = null) {
        $('#agentForm')[0].reset();
        
        if (agent && agent.id_) {
            // Modo edición
            $('#agentModalTitle').html('<i class="fas fa-edit mr-2"></i>Editar Agente');
            $('#agentId').val(agent.id_);
            $('#agentKey').val(agent.agent_key).prop('readonly', true);
            $('#agentGroup').val(agent.agent_group);
            $('#displayName').val(agent.display_name);
            $('#sortOrder').val(agent.sort_order || 0);
            $('#modelId').val(agent.model_id);
            $('#embeddingModel').val(agent.embedding_model || '');
            $('#temperature').val(agent.temperature || 0.7);
            $('#maxTokens').val(agent.max_tokens_output || 2048);
            $('#topP').val(agent.top_p || 0.9);
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
            model_id: $('#modelId').val(),
            embedding_model: $('#embeddingModel').val(),
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

    // Eliminar agente
    $('#btnDeleteAgent').on('click', function() {
        const agentId = $('#agentId').val();
        if (!agentId) return;

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
    });

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
