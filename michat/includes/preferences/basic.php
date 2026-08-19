<div class="settings-pane-intro">
    <div class="settings-pane-icon"><i class="fas fa-user-cog"></i></div>
    <div>
        <h6 class="mb-1">Preferencias esenciales</h6>
        <p class="mb-0 small text-muted">Ajustes que cambian cómo responde y se presenta el chat en el uso diario.</p>
    </div>
</div>

<section class="settings-card settings-card-primary">
    <div class="settings-card-heading">
        <div>
            <span class="settings-card-kicker">Respuesta</span>
            <h6>Modelo principal</h6>
        </div>
        <span class="settings-card-badge"><i class="fas fa-comment-dots"></i> Chat</span>
    </div>
    <div class="settings-section">
<div class="settings-section-title">Modelo y generación</div>
<select id="chat2Model" class="form-control form-control-sm" style="max-width:520px;">
  
  <!-- ===================================================== -->
  <!-- CHAT Y RAZONAMIENTO (Texto) -->
  <!-- ===================================================== -->
  <optgroup label="💬 Chat y Razonamiento (Texto)">
    <option value="amazon.nova-micro-v1:0" selected>Amazon — Nova Micro (Ultra-rápido, baja latencia)</option>
    <option value="amazon.nova-lite-v1:0">Amazon — Nova Lite (Balance velocidad/calidad)</option>
    <option value="amazon.nova-pro-v1:0">Amazon — Nova Pro (Alta calidad, razonamiento complejo)</option>
    <option value="amazon.nova-premier-v1:0">Amazon — Nova Premier (Máximo rendimiento, agentes)</option>
    
    <option value="anthropic.claude-3-haiku-20240307-v1:0">Anthropic — Claude 3 Haiku (Rápido y eficiente)</option>
    <option value="anthropic.claude-3-5-haiku-20241022-v1:0">Anthropic — Claude 3.5 Haiku (Mejorado)</option>
    <option value="anthropic.claude-3-5-sonnet-20241022-v2:0">Anthropic — Claude 3.5 Sonnet (Excelente balance)</option>
    <option value="anthropic.claude-sonnet-4-20250514-v1:0">Anthropic — Claude Sonnet 4</option>
    <option value="anthropic.claude-sonnet-4-5-20250929-v1:0">Anthropic — Claude Sonnet 4.5</option>
    <option value="anthropic.claude-sonnet-5-v1:0">Anthropic — Claude Sonnet 5</option>
    <option value="anthropic.claude-opus-4-1-20250805-v1:0">Anthropic — Claude Opus 4.1</option>
    <option value="anthropic.claude-opus-4-5-20251101-v1:0">Anthropic — Claude Opus 4.5</option>
    <option value="anthropic.claude-opus-4-6-v1:0">Anthropic — Claude Opus 4.6</option>
    <option value="anthropic.claude-opus-4-7-v1:0">Anthropic — Claude Opus 4.7</option>
    <option value="anthropic.claude-opus-4-8-v1:0">Anthropic — Claude Opus 4.8</option>
    <option value="anthropic.claude-fable-5-v1:0">Anthropic — Claude Fable 5 (Autonomía agéntica)</option>

    <option value="meta.llama3-8b-instruct-v1:0">Meta — Llama 3 8B Instruct (Ligero y rápido)</option>
    <option value="meta.llama3-70b-instruct-v1:0">Meta — Llama 3 70B Instruct (Potente)</option>
    <option value="meta.llama3-1-8b-instruct-v1:0">Meta — Llama 3.1 8B Instruct</option>
    <option value="meta.llama3-1-70b-instruct-v1:0">Meta — Llama 3.1 70B Instruct</option>
    <option value="meta.llama3-3-70b-instruct-v1:0">Meta — Llama 3.3 70B Instruct (Tool Use avanzado)</option>

    <option value="mistral.mistral-small-2402-v1:0">Mistral — Mistral Small (24.02)</option>
    <option value="mistral.mixtral-8x7b-instruct-v0:1">Mistral — Mixtral 8x7B Instruct (MoE)</option>
    <option value="mistral.mistral-large-2402-v1:0">Mistral — Mistral Large (24.02)</option>
    <option value="mistral.mistral-large-3-v1:0">Mistral — Mistral Large 3</option>
    <option value="mistral.devstral-2-123b-v1:0">Mistral — Devstral 2 123B (Agentes de software)</option>
    <option value="mistral.ministral-3b-v1:0">Mistral — Ministral 3B</option>
    <option value="mistral.ministral-8b-v1:0">Mistral — Ministral 3 8B</option>
    <option value="mistral.ministral-14b-v1:0">Mistral — Ministral 14B 3.0</option>

    <option value="cohere.command-r-v1:0">Cohere — Command R (Optimizado para RAG)</option>
    <option value="cohere.command-r-plus-v1:0">Cohere — Command R+ (Máxima capacidad RAG)</option>
    
    <option value="deepseek.r1-v1:0">DeepSeek — DeepSeek-R1 (Razonamiento avanzado)</option>
    <option value="deepseek.v3-2-v1:0">DeepSeek — DeepSeek V3.2</option>
    
    <option value="writer.palmyra-x4-v1:0">Writer — Palmyra X4</option>
    <option value="writer.palmyra-x5-v1:0">Writer — Palmyra X5</option>
    
    <option value="qwen.qwen3-32b-v1:0">Qwen — Qwen3 32B (Dense)</option>
    <option value="qwen.qwen3-coder-30b-a3b-instruct-v1:0">Qwen — Qwen3 Coder 30B A3B</option>
    <option value="qwen.qwen3-coder-next-v1:0">Qwen — Qwen3 Coder Next</option>
    
    <option value="minimax.minimax-m2-v1:0">MiniMax — MiniMax M2</option>
    <option value="minimax.minimax-m2-1-v1:0">MiniMax — MiniMax M2.1</option>
    <option value="minimax.minimax-m2-5-v1:0">MiniMax — MiniMax M2.5</option>
    
    <option value="moonshot.kimi-k2-thinking-v1:0">Moonshot — Kimi K2 Thinking</option>
    <option value="moonshot.kimi-k2-5-v1:0">Moonshot — Kimi K2.5</option>
    
    <option value="zai.glm-4-7-flash-v1:0">Z.AI — GLM 4.7 Flash</option>
    <option value="zai.glm-4-7-v1:0">Z.AI — GLM 4.7</option>
    <option value="zai.glm-5-v1:0">Z.AI — GLM 5</option>
    
    <option value="nvidia.nemotron-nano-9b-v2">NVIDIA — Nemotron Nano 9B v2</option>
    <option value="nvidia.nemotron-nano-30b-v1:0">NVIDIA — Nemotron Nano 3 30B</option>
    <option value="nvidia.nemotron-3-super-120b-a12b-v1:0">NVIDIA — Nemotron 3 Super 120B A12B</option>
  </optgroup>

  <!-- ===================================================== -->
  <!-- CHAT MULTIMODAL (Texto + Imagen/Video) -->
  <!-- ===================================================== -->
  <optgroup label="🖼️ Chat Multimodal (Texto + Imagen/Video)">
    <option value="meta.llama3-2-11b-instruct-v1:0">Meta — Llama 3.2 11B Instruct (Vision)</option>
    <option value="meta.llama3-2-90b-instruct-v1:0">Meta — Llama 3.2 90B Instruct (Vision)</option>
    <option value="meta.llama4-scout-17b-instruct-v1:0">Meta — Llama 4 Scout 17B Instruct</option>
    <option value="meta.llama4-maverick-17b-instruct-v1:0">Meta — Llama 4 Maverick 17B Instruct</option>
    
    <option value="mistral.pixtral-large-2502-v1:0">Mistral — Pixtral Large (25.02)</option>
    
    <option value="qwen.qwen3-vl-235b-a22b-v1:0">Qwen — Qwen3 VL 235B A22B</option>
    
    <option value="writer.palmyra-vision-7b-v1:0">Writer — Palmyra Vision 7B</option>
  </optgroup>

  <!-- ===================================================== -->
  <!-- EMBEDDINGS (Vector) -->
  <!-- ===================================================== -->
  <optgroup label="🧮 Embeddings (Vectorización)">
    <option value="amazon.titan-embed-text-v2:0">Amazon — Titan Text Embeddings V2 (1024/512/256 dim)</option>
    <option value="amazon.titan-embed-text-v1">Amazon — Titan Embeddings G1 - Text (1536 dim)</option>
    <option value="amazon.titan-embed-image-v1">Amazon — Titan Multimodal Embeddings G1</option>
    <option value="amazon.nova-2-multimodal-embeddings-v1:0">Amazon — Nova Multimodal Embeddings</option>
    
    <option value="cohere.embed-v4:0">Cohere — Embed v4 (Multimodal, Multilingual)</option>
    <option value="cohere.embed-english-v3">Cohere — Embed English (1024 dim)</option>
    <option value="cohere.embed-multilingual-v3">Cohere — Embed Multilingual (1024 dim)</option>
    
    <option value="twelvelabs.marengo-embed-3-0-v1:0">TwelveLabs — Marengo Embed 3.0 (Video/Multimodal)</option>
    <option value="twelvelabs.marengo-embed-2-7-v1:0">TwelveLabs — Marengo Embed 2.7</option>
  </optgroup>

  <!-- ===================================================== -->
  <!-- RERANK -->
  <!-- ===================================================== -->
  <optgroup label="🔀 Rerank (Reordenamiento)">
    <option value="cohere.rerank-v3-5:0">Cohere — Rerank 3.5</option>
  </optgroup>

  <!-- ===================================================== -->
  <!-- IMAGEN (Generación/Edición) -->
  <!-- ===================================================== -->
  <optgroup label="🎨 Imagen (Generación y Edición)">
    <option value="amazon.nova-canvas-v1:0">Amazon — Nova Canvas</option>
    <option value="stability.stable-fast-upscale-v1:0">Stability AI — Stable Image Fast Upscale</option>
    <option value="stability.stable-image-creative-upscale-v1:0">Stability AI — Stable Image Creative Upscale</option>
    <option value="stability.stable-conservative-upscale-v1:0">Stability AI — Stable Image Conservative Upscale</option>
    <option value="stability.stable-outpaint-v1:0">Stability AI — Stable Image Outpaint</option>
    <option value="stability.stable-image-control-sketch-v1:0">Stability AI — Stable Image Control Sketch</option>
    <option value="stability.stable-image-control-structure-v1:0">Stability AI — Stable Image Control Structure</option>
    <option value="stability.stable-image-erase-object-v1:0">Stability AI — Stable Image Erase Object</option>
    <option value="stability.stable-image-inpaint-v1:0">Stability AI — Stable Image Inpaint</option>
    <option value="stability.stable-image-remove-background-v1:0">Stability AI — Stable Image Remove Background</option>
    <option value="stability.stable-image-search-recolor-v1:0">Stability AI — Stable Image Search and Recolor</option>
    <option value="stability.stable-image-search-replace-v1:0">Stability AI — Stable Image Search and Replace</option>
    <option value="stability.stable-image-style-guide-v1:0">Stability AI — Stable Image Style Guide</option>
    <option value="stability.stable-style-transfer-v1:0">Stability AI — Stable Image Style Transfer</option>
  </optgroup>

  <!-- ===================================================== -->
  <!-- VIDEO -->
  <!-- ===================================================== -->
  <optgroup label="🎬 Video (Generación y Análisis)">
    <option value="amazon.nova-reel-v1:0">Amazon — Nova Reel (Text/Image-to-Video)</option>
    <option value="twelvelabs.pegasus-1-2-v1:0">TwelveLabs — Pegasus 1.2 (Video-to-Text)</option>
  </optgroup>

  <!-- ===================================================== -->
  <!-- VOZ / SPEECH -->
  <!-- ===================================================== -->
  <optgroup label="🎙️ Voz / Speech">
    <option value="amazon.nova-sonic-v1:0">Amazon — Nova Sonic (Speech-to-Speech/Text)</option>
    <option value="amazon.nova-2-sonic-v1:0">Amazon — Nova 2 Sonic</option>
    <option value="mistral.voxtral-mini-3b-2507">Mistral — Voxtral Mini 3B 2507</option>
    <option value="mistral.voxtral-small-24b-2507">Mistral — Voxtral Small 24B 2507</option>
  </optgroup>

  <!-- ===================================================== -->
  <!-- SEGURIDAD / FILTRO -->
  <!-- ===================================================== -->
  <optgroup label="🛡️ Seguridad / Filtro (No es chat típico)">
    <option value="openai.gpt-oss-safeguard-20b">OpenAI — GPT OSS Safeguard 20B</option>
    <option value="openai.gpt-oss-safeguard-120b">OpenAI — GPT OSS Safeguard 120B</option>
  </optgroup>

</select>

<div id="aiRuntimeModelStatus" class="small text-muted mt-2">
    <i class="fas fa-database mr-1"></i>
    El modelo principal seleccionado se guarda en <code>UserAIAgentConfigs.chat_main</code>.
</div>
</section>

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
        <div>
            <span class="settings-card-kicker">Transparencia</span>
            <h6>Actividad del agente</h6>
        </div>
    </div>
    <label class="settings-switch-row mb-0" for="chatActivityEnabled">
        <span class="settings-switch-copy">
            <strong>Mostrar actividad real durante la respuesta</strong>
            <small>Muestra modelos, prompts de la aplicación, RAG, tool calls, tokens y tiempos; no expone razonamiento privado.</small>
        </span>
        <input type="checkbox" id="chatActivityEnabled" checked
               title="Muestra en el chat los eventos reales del pipeline mientras se genera la respuesta.">
    </label>
</section>

<div class="settings-two-column">
    <section class="settings-card mb-0">
        <div class="settings-card-heading">
            <div><span class="settings-card-kicker">Interfaz</span><h6>Apariencia</h6></div>
        </div>
        <div class="settings-field-label mb-2">Modo</div>
        <div class="settings-action-row">
            <button class="btn btn-sm btn-outline-secondary js-set-mode" data-mode="theme-dark" type="button">
                <i class="fas fa-moon mr-1"></i> Oscuro
            </button>
            <button class="btn btn-sm btn-outline-secondary js-set-mode" data-mode="theme-light" type="button">
                <i class="fas fa-sun mr-1"></i> Claro
            </button>
        </div>
    </section>

    <section class="settings-card mb-0">
        <div class="settings-card-heading">
            <div><span class="settings-card-kicker">Sesión</span><h6>Cuenta</h6></div>
        </div>
        <div class="settings-action-row">
            <button id="btnRecargar" class="btn btn-sm btn-outline-secondary" onclick="recargarPagina()" type="button">
                <i class="fas fa-sync-alt mr-1"></i> Recargar página
            </button>
            <a href="logout.php" class="btn btn-sm btn-outline-danger">
                <i class="fas fa-sign-out-alt mr-1"></i> Cerrar sesión
            </a>
        </div>
    </section>
</div>
