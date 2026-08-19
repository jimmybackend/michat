<div class="settings-pane-intro settings-pane-intro-maintenance">
    <div class="settings-pane-icon"><i class="fas fa-tools"></i></div>
    <div>
        <h6 class="mb-1">Mantenimiento</h6>
        <p class="mb-0 small text-muted">Procesos manuales para mantener embeddings y contexto consolidado al día.</p>
    </div>
</div>

<section class="settings-card">
    <div class="settings-card-heading">
        <div><span class="settings-card-kicker">Operación</span><h6>Mantenimiento de IA</h6></div>
    </div>
    <p class="small text-muted mb-3">Ejecuta manualmente los procesos. Orden recomendado: <strong>1. Embeddings → 2. Compresión</strong>.</p>
    <div class="settings-action-row">
        <button id="btnRunEmbeddings" class="btn btn-sm btn-outline-primary" type="button">
            <i class="fas fa-vector-square mr-1"></i> 1. Procesar Embeddings
        </button>
        <button id="btnRunCompression" class="btn btn-sm btn-outline-success" type="button">
            <i class="fas fa-compress-arrows-alt mr-1"></i> 2. Comprimir Sesiones
        </button>
        <button id="btnRunBoth" class="btn btn-sm btn-outline-warning" type="button">
            <i class="fas fa-sync-alt mr-1"></i> Ejecutar Ambos
        </button>
    </div>
    <div class="mt-3">
        <small class="text-muted d-block" id="maintenanceStatus"></small>
        <div class="progress d-none" id="maintenanceProgress" style="height:8px; margin-top:8px;">
            <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:0%"></div>
        </div>
    </div>
</section>
