<?php
declare(strict_types=1);

session_start();
header('Content-Type: text/html; charset=UTF-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/includes/Chat/ChatIdentity.php';

if (empty($_SESSION['usuario'])) {
    header('Location: ../index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$userId = ChatIdentity::resolveUserId($db_connection);
if ($userId <= 0) { header('Location: ../index.php'); exit; }
$isAdmin = ChatIdentity::canManageGlobalAiConfiguration();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Configuración de Agentes IA</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.3.1/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link rel="icon" href="asistente-de-inteligencia-artificial.gif" type="image/x-icon">
<link rel="stylesheet" href="css/chat2.css">
<link rel="stylesheet" href="css/design-system.css">
<meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

<style>
body { overflow: auto; }
.agent-config-wrapper {
    padding: 1rem 1.25rem 1.25rem;
    width: 100%;
    max-width: none;
}
.agent-toolbar {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: .8rem 1rem;
    margin-bottom: .8rem;
}
.agents-table-shell {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    overflow: auto;
    max-height: 52vh;
    min-height: 300px;
    scrollbar-gutter: stable both-edges;
}
.agents-table-shell table {
    min-width: 1720px;
    margin-bottom: 0;
}
.agents-table-shell thead th {
    position: sticky;
    top: 0;
    z-index: 5;
    background: var(--bg3);
    border-bottom: 2px solid var(--border);
    white-space: nowrap;
}
.agents-table-shell td { vertical-align: middle; }
.agent-key-code {
    font-family: 'JetBrains Mono', monospace;
    font-size: .75rem;
    white-space: nowrap;
}
.cell-ellipsis {
    max-width: 260px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.crud-log-card {
    margin-top: .8rem;
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
}
.crud-log {
    max-height: 130px;
    overflow-y: auto;
    padding: .65rem .9rem;
    font-family: 'JetBrains Mono', monospace;
    font-size: .75rem;
}
.crud-log-entry { padding: .2rem 0; border-bottom: 1px solid var(--border-soft); }
.crud-log-entry:last-child { border-bottom: 0; }
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
    max-width: 460px;
    padding: .9rem 1rem;
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: 0 4px 12px rgba(0,0,0,.16);
    display: flex;
    gap: .7rem;
    align-items: flex-start;
}
.agent-toast.success { border-left: 4px solid var(--ok); }
.agent-toast.error { border-left: 4px solid #e74c60; }
.agent-toast.info { border-left: 4px solid var(--accent); }
.agent-toast-close { margin-left: auto; border: 0; background: transparent; color: var(--text-soft); }
.modal-dialog.modal-xl { max-width: 1180px; }
.modal-body-scroll { max-height: calc(100vh - 190px); overflow-y: auto; }
.config-section {
    margin-bottom: 1.25rem;
    padding-bottom: .5rem;
    border-bottom: 1px solid var(--border-soft);
}
.config-section:last-child { border-bottom: 0; }
.config-section-title {
    font-size: .78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--text-soft);
    margin-bottom: .8rem;
}
.form-group label { font-size: .8rem; font-weight: 600; }
textarea.form-control, .json-field {
    font-family: 'JetBrains Mono', monospace;
    font-size: .8rem;
}
.details-pre {
    white-space: pre-wrap;
    word-break: break-word;
    background: var(--bg3);
    border: 1px solid var(--border-soft);
    border-radius: var(--radius-sm);
    padding: .75rem;
    font-size: .8rem;
}
@media (max-width: 768px) {
    .agents-table-shell { max-height: 48vh; min-height: 240px; }
}
</style>
</head>
<body class="ui-theme theme-neon-green theme-light vision-normal ascii-on">
<div class="app-container">
<main id="chat-main" class="chat-main" style="width:100%;max-width:100%;">
    <header class="chat-header">
        <div class="header-left">
            <h1 class="chat-title"><i class="fas fa-robot mr-2"></i>Configuración de Agentes IA</h1>
        </div>
        <div class="header-right">
            <button id="btnReloadAgents" class="btn btn-sm btn-outline-secondary" type="button" title="Recargar lista">
                <i class="fas fa-sync-alt"></i> Recargar
            </button>
            <?php if ($isAdmin): ?>
            <button id="btnAddAgent" class="btn btn-sm btn-primary" type="button" title="Nuevo agente">
                <i class="fas fa-plus"></i> Nuevo Agente
            </button>
            <?php endif; ?>
        </div>
    </header>

    <div class="agent-config-wrapper">
        <div class="agent-toolbar">
            <div class="d-flex align-items-end flex-wrap" style="gap:.8rem;">
                <div class="form-group mb-0">
                    <label for="filterGroup" class="mb-1">Filtrar por grupo:</label>
                    <select id="filterGroup" class="form-control form-control-sm" style="min-width:220px;">
                        <option value="">Todos los grupos</option>
                    </select>
                </div>
                <div class="small text-muted pb-1" id="recordCounter">Cargando...</div>
                <div class="small text-muted pb-1 ml-auto">
                    Orden: <strong>id_ ASC</strong>
                </div>
            </div>
        </div>

        <div id="agentsList">
            <div class="agents-table-shell d-flex align-items-center justify-content-center">
                <div class="text-center py-5">
                    <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
                    <p class="text-muted mt-3">Cargando configuraciones...</p>
                </div>
            </div>
        </div>

        <div class="crud-log-card">
            <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center">
                <strong class="small"><i class="fas fa-terminal mr-2"></i>Registro de operaciones CRUD</strong>
                <button id="btnClearCrudLog" type="button" class="btn btn-sm btn-outline-secondary">Limpiar</button>
            </div>
            <div id="crudLog" class="crud-log">
                <div class="text-muted">Aquí aparecerán las cargas, altas, cambios y eliminaciones realizadas por el JavaScript.</div>
            </div>
        </div>
    </div>
</main>
</div>

<div id="agentToastContainer" class="agent-toast-container"></div>

<!-- Modal CREATE / UPDATE -->
<div id="agentModal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl" role="document">
        <div class="modal-content" style="background:var(--bg2);border:1px solid var(--border);">
            <div class="modal-header">
                <h5 class="modal-title" id="agentModalTitle"><i class="fas fa-edit mr-2"></i>Agente IA</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
            </div>
            <div class="modal-body modal-body-scroll">
                <form id="agentForm" autocomplete="off">
                    <input type="hidden" id="agentId">

                    <div class="config-section">
                        <div class="config-section-title">Identidad y clasificación</div>
                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label for="agentKey">Agent Key *</label>
                                <input type="text" class="form-control" id="agentKey" maxlength="100" required>
                            </div>
                            <div class="col-md-4 form-group">
                                <label for="agentGroup">Grupo *</label>
                                <input type="text" class="form-control" id="agentGroup" maxlength="50" list="agentGroupList" required>
                                <datalist id="agentGroupList"></datalist>
                            </div>
                            <div class="col-md-4 form-group">
                                <label for="displayName">Nombre visible *</label>
                                <input type="text" class="form-control" id="displayName" maxlength="180" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-9 form-group">
                                <label for="description">Descripción</label>
                                <textarea class="form-control" id="description" rows="2"></textarea>
                            </div>
                            <div class="col-md-3 form-group">
                                <label for="sortOrder">Orden de visualización</label>
                                <input type="number" class="form-control" id="sortOrder" value="0" step="1">
                            </div>
                        </div>
                    </div>

                    <div class="config-section">
                        <div class="config-section-title">Modelos</div>
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label for="modelId">Model ID *</label>
                                <input type="text" class="form-control" id="modelId" maxlength="180" list="modelSuggestions" required>
                                <datalist id="modelSuggestions">
                                    <option value="none"></option>
                                    <option value="amazon.nova-micro-v1:0"></option>
                                    <option value="amazon.nova-pro-v1:0"></option>
                                    <option value="anthropic.claude-3-5-sonnet-20241022-v2:0"></option>
                                    <option value="anthropic.claude-sonnet-4-20250514-v1:0"></option>
                                </datalist>
                            </div>
                            <div class="col-md-6 form-group">
                                <label for="fallbackModelId">Fallback Model ID</label>
                                <input type="text" class="form-control" id="fallbackModelId" maxlength="180">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="modelLadderJson">Model Ladder JSON</label>
                            <textarea class="form-control json-field" id="modelLadderJson" rows="3" placeholder='["modelo-1", "modelo-2"]'></textarea>
                        </div>
                    </div>

                    <div class="config-section">
                        <div class="config-section-title">Parámetros</div>
                        <div class="row">
                            <div class="col-md-2 form-group"><label for="temperature">Temperature</label><input type="number" class="form-control" id="temperature" step="0.01" min="0" max="2"></div>
                            <div class="col-md-2 form-group"><label for="maxTokensPrompt">Max tokens prompt</label><input type="number" class="form-control" id="maxTokensPrompt" min="0" step="1"></div>
                            <div class="col-md-2 form-group"><label for="maxTokensOutput">Max tokens output</label><input type="number" class="form-control" id="maxTokensOutput" min="0" step="1"></div>
                            <div class="col-md-2 form-group"><label for="topP">Top P</label><input type="number" class="form-control" id="topP" step="0.001" min="0" max="1"></div>
                            <div class="col-md-2 form-group"><label for="seed">Seed</label><input type="number" class="form-control" id="seed" min="0" step="1" value="0"></div>
                            <div class="col-md-2 form-group"><label for="maxAttempts">Max attempts</label><input type="number" class="form-control" id="maxAttempts" min="1" max="255" step="1" value="1"></div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label for="tokenUsagePhase">Token usage phase</label>
                                <input type="text" class="form-control" id="tokenUsagePhase" maxlength="30" placeholder="compile, respond, rag, ...">
                            </div>
                            <div class="col-md-6 form-group d-flex align-items-center pt-md-4">
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="isActive" checked>
                                    <label class="custom-control-label" for="isActive">Agente activo</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="config-section">
                        <div class="config-section-title">Prompts</div>
                        <div class="form-group">
                            <label for="systemInstruction">System Instruction</label>
                            <textarea class="form-control" id="systemInstruction" rows="7"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="userPromptTemplate">User Prompt Template</label>
                            <textarea class="form-control" id="userPromptTemplate" rows="5"></textarea>
                        </div>
                    </div>

                    <div class="config-section">
                        <div class="config-section-title">Configuración extra</div>
                        <div class="form-group mb-0">
                            <label for="extraConfig">Extra Config JSON</label>
                            <textarea class="form-control json-field" id="extraConfig" rows="5" placeholder='{"key":"value"}'></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancelar</button>
                <?php if ($isAdmin): ?>
                <button type="button" class="btn btn-danger mr-auto" id="btnDeleteFromModal" style="display:none;"><i class="fas fa-trash mr-1"></i>Eliminar</button>
                <button type="button" class="btn btn-primary" id="btnSaveAgent"><i class="fas fa-save mr-1"></i>Guardar</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal READ -->
<div id="detailsModal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl" role="document">
        <div class="modal-content" style="background:var(--bg2);border:1px solid var(--border);">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-eye mr-2"></i>Detalle del agente</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
            </div>
            <div class="modal-body modal-body-scroll" id="agentDetailsContent"></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cerrar</button></div>
        </div>
    </div>
</div>

<script>
window.AI_AGENT_CONFIG = Object.freeze({
    csrfToken: <?= json_encode($_SESSION['csrf_token'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    isAdmin: <?= $isAdmin ? 'true' : 'false' ?>,
    userId: <?= $userId ?>,
    endpoints: {
        list: 'get_ai_agents.php',
        save: 'save_ai_agent.php',
        delete: 'delete_ai_agent.php'
    }
});
</script>
<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.3.1/dist/js/bootstrap.bundle.min.js"></script>
<script src="ai_agent_configurator.js"></script>
</body>
</html>
