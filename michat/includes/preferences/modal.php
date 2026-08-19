<!-- Preferencias por subsistemas. Los controles conservan sus IDs originales
     para mantener compatibilidad con JS, UserPreferences, UserAIAgentConfigs
     y UserPipelineFeatures. -->
<div id="settings-modal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true" aria-labelledby="settings-title">
    <div class="modal-dialog modal-dialog-centered modal-xl settings-modal-dialog" role="document">
        <div class="modal-content settings-modal-content">
            <div class="modal-header settings-modal-header">
                <div>
                    <div class="settings-modal-eyebrow">Configuración del chat</div>
                    <h5 class="modal-title" id="settings-title"><i class="fas fa-sliders-h mr-2"></i>Preferencias</h5>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
            </div>

            <div class="modal-body settings-modal-body">
                <nav class="settings-tabs" aria-label="Subsistemas de preferencias">
                    <div class="nav nav-pills" id="settings-tab" role="tablist">
                        <a class="nav-link active" id="settings-general-tab" data-toggle="pill" href="#settings-general" role="tab" aria-controls="settings-general" aria-selected="true">
                            <span class="settings-tab-icon"><i class="fas fa-user-cog"></i></span>
                            <span class="settings-tab-copy"><strong>General</strong><small>Interfaz y sesión</small></span>
                        </a>
                        <a class="nav-link" id="settings-models-tab" data-toggle="pill" href="#settings-models" role="tab" aria-controls="settings-models" aria-selected="false">
                            <span class="settings-tab-icon"><i class="fas fa-robot"></i></span>
                            <span class="settings-tab-copy"><strong>Modelos IA</strong><small>Bedrock y parámetros</small></span>
                        </a>
                        <a class="nav-link" id="settings-pipeline-tab" data-toggle="pill" href="#settings-pipeline" role="tab" aria-controls="settings-pipeline" aria-selected="false">
                            <span class="settings-tab-icon"><i class="fas fa-project-diagram"></i></span>
                            <span class="settings-tab-copy"><strong>Pipeline</strong><small>Funciones ON / OFF</small></span>
                        </a>
                        <a class="nav-link" id="settings-memory-tab" data-toggle="pill" href="#settings-memory" role="tab" aria-controls="settings-memory" aria-selected="false">
                            <span class="settings-tab-icon"><i class="fas fa-brain"></i></span>
                            <span class="settings-tab-copy"><strong>Memoria</strong><small>Continuidad y aprendizaje</small></span>
                        </a>
                        <a class="nav-link" id="settings-maintenance-tab" data-toggle="pill" href="#settings-maintenance" role="tab" aria-controls="settings-maintenance" aria-selected="false">
                            <span class="settings-tab-icon"><i class="fas fa-tools"></i></span>
                            <span class="settings-tab-copy"><strong>Mantenimiento</strong><small>Embeddings y compresión</small></span>
                        </a>
                        <a class="nav-link" id="settings-administration-tab" data-toggle="pill" href="#settings-administration" role="tab" aria-controls="settings-administration" aria-selected="false">
                            <span class="settings-tab-icon"><i class="fas fa-shield-alt"></i></span>
                            <span class="settings-tab-copy"><strong>Administración</strong><small>Datos y zona peligrosa</small></span>
                        </a>
                    </div>
                </nav>

                <div class="tab-content settings-tab-content" id="settings-tab-content">
                    <div class="tab-pane fade show active" id="settings-general" role="tabpanel" aria-labelledby="settings-general-tab">
                        <?php require __DIR__ . '/general.php'; ?>
                    </div>
                    <div class="tab-pane fade" id="settings-models" role="tabpanel" aria-labelledby="settings-models-tab">
                        <?php require __DIR__ . '/models.php'; ?>
                    </div>
                    <div class="tab-pane fade" id="settings-pipeline" role="tabpanel" aria-labelledby="settings-pipeline-tab">
                        <?php require __DIR__ . '/pipeline.php'; ?>
                    </div>
                    <div class="tab-pane fade" id="settings-memory" role="tabpanel" aria-labelledby="settings-memory-tab">
                        <?php require __DIR__ . '/memory.php'; ?>
                    </div>
                    <div class="tab-pane fade" id="settings-maintenance" role="tabpanel" aria-labelledby="settings-maintenance-tab">
                        <?php require __DIR__ . '/maintenance.php'; ?>
                    </div>
                    <div class="tab-pane fade" id="settings-administration" role="tabpanel" aria-labelledby="settings-administration-tab">
                        <?php require __DIR__ . '/administration.php'; ?>
                    </div>
                </div>
            </div>

            <div class="modal-footer settings-modal-footer">
                <div class="settings-footer-note"><i class="fas fa-database mr-1"></i>Los controles conservan los mecanismos actuales de persistencia.</div>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>
