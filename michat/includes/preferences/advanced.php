<div class="settings-pane-intro settings-pane-intro-advanced">
    <div class="settings-pane-icon"><i class="fas fa-microchip"></i></div>
    <div>
        <h6 class="mb-1">Configuración avanzada</h6>
        <p class="mb-0 small text-muted">Ajustes del pipeline, RAG, memoria y mantenimiento. Los IDs y mecanismos de persistencia originales se conservan.</p>
    </div>
</div>

<section class="settings-card">
<div class="settings-section">
    <div class="settings-section-title">
        <i class="fas fa-network-wired mr-1"></i> Modelos internos dinámicos
    </div>
    <p class="small text-muted mb-3">
        Cada proceso que llama realmente a Bedrock tiene su propia configuración.
        Desactivar un proceso auxiliar hace que el chat continúe sin ejecutar esa etapa.
    </p>

    <div class="form-group">
        <div class="d-flex align-items-center justify-content-between flex-wrap" style="gap:.5rem;">
            <label for="aiPromptCompilerModel" class="small font-weight-bold mb-1">
                Compilador de prompts
            </label>
            <label class="small mb-1">
                <input type="checkbox" id="aiPromptCompilerActive"> Activo
            </label>
        </div>
        <select id="aiPromptCompilerModel" class="form-control form-control-sm">
            <option value="amazon.nova-micro-v1:0">Amazon — Nova Micro</option>
            <option value="amazon.nova-lite-v1:0">Amazon — Nova Lite</option>
            <option value="amazon.nova-pro-v1:0">Amazon — Nova Pro</option>
            <option value="anthropic.claude-3-5-haiku-20241022-v1:0">Anthropic — Claude 3.5 Haiku</option>
            <option value="anthropic.claude-3-5-sonnet-20241022-v2:0">Anthropic — Claude 3.5 Sonnet</option>
            <option value="anthropic.claude-sonnet-4-20250514-v1:0">Anthropic — Claude Sonnet 4</option>
        </select>
        <small class="text-muted">agent_key: <code>prompt_compiler</code></small>
    </div>

    <div class="form-group">
        <div class="d-flex align-items-center justify-content-between flex-wrap" style="gap:.5rem;">
            <label for="aiEmbeddingModel" class="small font-weight-bold mb-1">
                Embeddings / RAG / búsqueda semántica
            </label>
            <label class="small mb-1">
                <input type="checkbox" id="aiEmbeddingActive"> Activo
            </label>
        </div>
        <select id="aiEmbeddingModel" class="form-control form-control-sm">
            <option value="amazon.titan-embed-text-v2:0">Amazon — Titan Text Embeddings V2</option>
            <option value="amazon.titan-embed-text-v1">Amazon — Titan Embeddings G1 Text</option>
            <option value="cohere.embed-v4:0">Cohere — Embed v4 (1024 dim configurado)</option>
            <option value="cohere.embed-english-v3">Cohere — Embed English v3 (1024 dim)</option>
            <option value="cohere.embed-multilingual-v3">Cohere — Embed Multilingual v3 (1024 dim)</option>
        </select>
        <small class="text-muted">
            agent_key: <code>embedding_main</code>.
            Titan y Cohere se configuran automáticamente con su adaptador, dimensiones e input_type en <code>extra_config</code>.
        </small>
    </div>

    <div class="form-group">
        <div class="d-flex align-items-center justify-content-between flex-wrap" style="gap:.5rem;">
            <label for="aiSmartMemoryGeneralModel" class="small font-weight-bold mb-1">
                Memoria / semántica — contenido general
            </label>
            <label class="small mb-1">
                <input type="checkbox" id="aiSmartMemoryGeneralActive"> Activo
            </label>
        </div>
        <select id="aiSmartMemoryGeneralModel" class="form-control form-control-sm">
            <option value="amazon.nova-micro-v1:0">Amazon — Nova Micro</option>
            <option value="amazon.nova-lite-v1:0">Amazon — Nova Lite</option>
            <option value="amazon.nova-pro-v1:0">Amazon — Nova Pro</option>
            <option value="anthropic.claude-3-5-haiku-20241022-v1:0">Anthropic — Claude 3.5 Haiku</option>
        </select>
        <small class="text-muted">agent_key: <code>smart_memory_general</code>. También se reutiliza para semántica de adjuntos generales.</small>
    </div>

    <div class="form-group mb-0">
        <div class="d-flex align-items-center justify-content-between flex-wrap" style="gap:.5rem;">
            <label for="aiSmartMemoryCodeModel" class="small font-weight-bold mb-1">
                Memoria / semántica — código/programación
            </label>
            <label class="small mb-1">
                <input type="checkbox" id="aiSmartMemoryCodeActive"> Activo
            </label>
        </div>
        <select id="aiSmartMemoryCodeModel" class="form-control form-control-sm">
            <option value="anthropic.claude-3-5-haiku-20241022-v1:0">Anthropic — Claude 3.5 Haiku</option>
            <option value="amazon.nova-micro-v1:0">Amazon — Nova Micro</option>
            <option value="amazon.nova-pro-v1:0">Amazon — Nova Pro</option>
            <option value="anthropic.claude-3-5-sonnet-20241022-v2:0">Anthropic — Claude 3.5 Sonnet</option>
            <option value="anthropic.claude-sonnet-4-20250514-v1:0">Anthropic — Claude Sonnet 4</option>
        </select>
        <small class="text-muted">agent_key: <code>smart_memory_code</code>. También se reutiliza para semántica de adjuntos técnicos/código.</small>
    </div>

    <div id="aiRuntimeInternalStatus" class="small text-muted mt-2"></div>
</div>
</section>

<section class="settings-card">
    <div class="settings-card-heading">
        <div><span class="settings-card-kicker">Generación</span><h6>Parámetros técnicos</h6></div>
    </div>
    <div class="settings-parameter-grid">
        <label class="settings-parameter">
            <span>🎲 Seed</span>
            <input id="chat2Seed" type="number" class="form-control form-control-sm" step="1" min="0" max="999999999" value="42"
                   title="Semilla global. Si es mayor a 0, todas las IAs usarán esta semilla para respuestas más consistentes. Pon 0 para desactivar.">
            <small>0 desactiva la semilla.</small>
        </label>
        <label class="settings-parameter">
            <span>🌡 Temp compilador</span>
            <input id="chat2CompTemp" type="number" class="form-control form-control-sm" step="0.1" min="0" max="2" value="0.0"
                   title="Temperatura del compilador de prompts">
            <small>Variación del compilador.</small>
        </label>
        <label class="settings-parameter">
            <span>📏 Tokens prompt</span>
            <input id="chat2CompMax" type="number" class="form-control form-control-sm" step="1" min="100" max="4096" value="200"
                   title="Máximo de tokens para el prompt enriquecido que genera el compilador">
            <small>Límite del prompt compilado.</small>
        </label>
        <label class="settings-parameter">
            <span>📏 Tokens respuesta</span>
            <input id="chat2RespMax" type="number" class="form-control form-control-sm" step="1" min="100" max="4096" value="1000"
                   title="Máximo de tokens para la respuesta final del modelo principal">
            <small>Límite de salida principal.</small>
        </label>
        <label class="settings-parameter">
            <span>🎯 Top P</span>
            <input id="chat2CompTopP" type="number" class="form-control form-control-sm" step="0.05" min="0.05" max="1" value="0.1"
                   title="Top P del compilador de prompts">
            <small>Muestreo del compilador.</small>
        </label>
    </div>
</section>

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
        <span class="settings-switch-copy"><strong>Optimizar prompt</strong><small>Usa el compilador y la ventana de 5 segundos. OFF responde directamente con tu texto.</small></span>
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
        <span class="settings-switch-copy"><strong>Memoria selectiva Q&amp;A</strong><small>Sincronizado con el switch de Memoria selectiva de Preferencias esenciales.</small></span>
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

<section class="settings-card">
    <div class="settings-card-heading">
        <div><span class="settings-card-kicker">Memoria</span><h6>Ajuste fino de memoria selectiva</h6></div>
    </div>
    <p class="small text-muted mb-3">La activación y el alcance están en General. Aquí solo se ajusta cuánto contexto se examina y recupera.</p>
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
        <button id="btnAiDataControl" class="btn btn-sm btn-outline-warning" title="Control avanzado de datos internos de la IA" type="button">
            <i class="fas fa-sliders-h mr-1"></i> Control IA
        </button>
    </div>
    <small class="text-muted mt-2 d-block" id="proceduralExtractionStatus"></small>
</section>

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
