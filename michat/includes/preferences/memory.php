<div class="settings-pane-intro settings-pane-intro-memory">
    <div class="settings-pane-icon"><i class="fas fa-brain"></i></div>
    <div>
        <h6 class="mb-1">Memoria</h6>
        <p class="mb-0 small text-muted">Configura continuidad, alcance, recuperación selectiva y memoria procedural.</p>
    </div>
</div>

<section class="settings-card">
    <div class="settings-card-heading">
        <div>
            <span class="settings-card-kicker">Continuidad</span>
            <h6>Memoria selectiva</h6>
        </div>
        <span class="settings-card-badge"><i class="fas fa-brain"></i> Recomendado</span>
    </div>
    <p class="small text-muted mb-3">
        Compara la pregunta actual con preguntas anteriores y recupera únicamente el contexto relevante.
    </p>
    <label class="settings-switch-row mb-3" for="chatQuestionMemoryEnabled">
        <span class="settings-switch-copy">
            <strong>Usar memoria selectiva de preguntas anteriores</strong>
            <small>Ayuda a mantener continuidad sin inyectar todo el historial.</small>
        </span>
        <input type="checkbox" id="chatQuestionMemoryEnabled" checked
               title="Si está activo, el sistema buscará en preguntas anteriores antes de responder.">
    </label>

    <div class="settings-field-group">
        <div class="settings-field-label">Alcance de búsqueda</div>
        <div class="settings-choice-grid">
            <label class="settings-choice" for="chatQuestionMemoryScopeSession">
                <input type="radio" name="chatQuestionMemoryScope" id="chatQuestionMemoryScopeSession" value="session">
                <span><i class="fas fa-comment"></i><strong>Esta sesión</strong><small>Solo la conversación actual.</small></span>
            </label>
            <label class="settings-choice" for="chatQuestionMemoryScopeProject">
                <input type="radio" name="chatQuestionMemoryScope" id="chatQuestionMemoryScopeProject" value="project" checked>
                <span><i class="fas fa-briefcase"></i><strong>Todo el proyecto</strong><small>Busca contexto entre las sesiones del proyecto.</small></span>
            </label>
        </div>
    </div>
</section>

<section class="settings-card">
    <div class="settings-card-heading">
        <div><span class="settings-card-kicker">Memoria</span><h6>Ajuste fino de memoria selectiva</h6></div>
    </div>
    <p class="small text-muted mb-3">Configura cuánto contexto se examina y recupera cuando la memoria selectiva está activa.</p>
    <div class="settings-parameter-grid settings-parameter-grid-small">
        <label class="settings-parameter">
            <span>📋 Candidatas</span>
            <input id="chatQuestionMemoryMaxCandidates" type="number" class="form-control form-control-sm"
                   step="1" min="5" max="50" value="20"
                   title="Máximo de preguntas anteriores cuyos embeddings se comparan por similitud; no llama a otra IA">
            <small>Preguntas a comparar.</small>
        </label>
        <label class="settings-parameter">
            <span>📐 Ventana ±</span>
            <input id="chatQuestionMemoryWindowLines" type="number" class="form-control form-control-sm"
                   step="1" min="2" max="15" value="5"
                   title="Cantidad de líneas de contexto arriba y abajo de cada coincidencia">
            <small>Líneas alrededor de cada coincidencia.</small>
        </label>
    </div>
    <div id="chatQuestionMemoryStatus" class="small text-muted mt-3" style="display:none;">
        <i class="fas fa-info-circle mr-1"></i><span id="chatQuestionMemoryStatusText">—</span>
    </div>
</section>

<section class="settings-card">
    <div class="settings-card-heading">
        <div><span class="settings-card-kicker">Aprendizaje</span><h6>Memoria procedural</h6></div>
    </div>
    <p class="small text-muted mb-3">Patrones, preferencias y reglas aprendidas de tus conversaciones y aplicadas automáticamente.</p>
    <div class="settings-action-row">
        <button class="btn btn-sm btn-outline-success" id="btnForceProceduralExtraction" type="button">
            <i class="fas fa-sync-alt mr-1"></i> Re-analizar todas las sesiones
        </button>
        <button class="btn btn-sm btn-outline-info" id="btnOpenProceduralMemory" title="Ver, editar y eliminar memoria procedural" type="button">
            <i class="fas fa-brain mr-1"></i> Ver y editar memoria
        </button>
    </div>
    <small class="text-muted mt-2 d-block" id="proceduralExtractionStatus"></small>
</section>
