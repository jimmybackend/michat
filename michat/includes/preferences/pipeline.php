<div class="settings-pane-intro settings-pane-intro-pipeline">
    <div class="settings-pane-icon"><i class="fas fa-project-diagram"></i></div>
    <div>
        <h6 class="mb-1">Pipeline</h6>
        <p class="mb-0 small text-muted">Activa o desactiva etapas independientes sin borrar datos ni cambiar la configuración de los modelos.</p>
    </div>
</div>

<section class="settings-card settings-card-primary">
    <div class="settings-card-heading">
        <div>
            <span class="settings-card-kicker">Fase 5</span>
            <h6>Funciones del pipeline</h6>
        </div>
        <span class="settings-card-badge"><i class="fas fa-sliders-h"></i> Modular</span>
    </div>
    <p class="small text-muted mb-3">
        Activa o desactiva cada etapa sin borrar datos. Todos los switches vienen activados para conservar el comportamiento actual.
    </p>

    <div class="settings-field-label mb-2">Generación y enrutamiento</div>
    <label class="settings-switch-row mb-2">
        <span class="settings-switch-copy"><strong>Optimizar prompt</strong><small>El compilador tiene un máximo de 5 segundos. Si falla, se agota o devuelve una salida inútil, continúa automáticamente con tu texto original. Si responde bien, conserva la ventana de 5 segundos para revisar la mejora.</small></span>
        <input type="checkbox" class="js-pipeline-feature" data-feature="prompt_compiler" checked>
    </label>
    <label class="settings-switch-row mb-3">
        <span class="settings-switch-copy"><strong>Memory Context Router</strong><small>Decide qué familia de memoria o contexto necesita la pregunta.</small></span>
        <input type="checkbox" class="js-pipeline-feature" data-feature="memory_router" checked>
    </label>

    <div class="settings-field-label mb-2">Lectura de memoria</div>
    <label class="settings-switch-row mb-2">
        <span class="settings-switch-copy"><strong>Memoria procedural</strong><small>Preferencias, reglas, correcciones, patrones y workflows.</small></span>
        <input type="checkbox" class="js-pipeline-feature" data-feature="procedural_memory_read" checked>
    </label>
    <label class="settings-switch-row mb-2">
        <span class="settings-switch-copy"><strong>Memoria estructurada del proyecto</strong><small>Lee decisiones, hechos, reglas y pendientes desde ProjectContext.</small></span>
        <input type="checkbox" class="js-pipeline-feature" data-feature="project_memory_read" checked>
    </label>
    <label class="settings-switch-row mb-2">
        <span class="settings-switch-copy"><strong>Memoria de sesión</strong><small>Permite usar resúmenes y contexto consolidado de la conversación.</small></span>
        <input type="checkbox" class="js-pipeline-feature" data-feature="session_memory_read" checked>
    </label>
    <label class="settings-switch-row mb-3">
        <span class="settings-switch-copy"><strong>Memoria selectiva Q&amp;A</strong><small>Sincronizado con el control principal de Memoria selectiva en la pestaña Memoria.</small></span>
        <input type="checkbox" class="js-pipeline-feature" data-feature="question_memory_read" checked>
    </label>

    <div class="settings-field-label mb-2">Recuperación semántica</div>
    <label class="settings-switch-row mb-2">
        <span class="settings-switch-copy"><strong>RAG del proyecto</strong><small>Busca fragmentos relevantes en archivos indexados del proyecto.</small></span>
        <input type="checkbox" class="js-pipeline-feature" data-feature="project_rag" checked>
    </label>
    <label class="settings-switch-row mb-2">
        <span class="settings-switch-copy"><strong>RAG de adjuntos</strong><small>Recupera adjuntos históricos relevantes; no bloquea archivos que acabas de subir.</small></span>
        <input type="checkbox" class="js-pipeline-feature" data-feature="attachment_rag" checked>
    </label>
    <label class="settings-switch-row mb-3">
        <span class="settings-switch-copy"><strong>Ranking de contexto</strong><small>ON usa ranking multi-señal; OFF usa selección determinista limitada.</small></span>
        <input type="checkbox" class="js-pipeline-feature" data-feature="context_ranking" checked>
    </label>

    <div class="settings-field-label mb-2">Aprendizaje y agente</div>
    <label class="settings-switch-row mb-2">
        <span class="settings-switch-copy"><strong>Backfill de memoria</strong><small>Rescata memoria histórica y la estructura cuando falta ProjectContext.</small></span>
        <input type="checkbox" class="js-pipeline-feature" data-feature="memory_backfill" checked>
    </label>
    <label class="settings-switch-row mb-2">
        <span class="settings-switch-copy"><strong>Herramientas del proyecto</strong><small>Autoriza Tool Use para leer, buscar, crear, editar o eliminar código cuando el Router lo pide.</small></span>
        <input type="checkbox" class="js-pipeline-feature" data-feature="project_tools" checked>
    </label>
    <label class="settings-switch-row mb-3">
        <span class="settings-switch-copy"><strong>Memory Writer</strong><small>Permite aprender y consolidar memorias nuevas después de responder. OFF no borra memorias existentes.</small></span>
        <input type="checkbox" class="js-pipeline-feature" data-feature="memory_writer" checked>
    </label>

    <div class="settings-action-row">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="pipelineFeaturesReset">
            <i class="fas fa-undo mr-1"></i> Restaurar configuración recomendada
        </button>
    </div>
    <div id="pipelineFeaturesStatus" class="small text-muted mt-2">Todas las funciones están activas.</div>
</section>
