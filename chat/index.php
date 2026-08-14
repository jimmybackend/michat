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
// ✅ AÑADE ESTO AQUÍ (Generar Token CSRF para el JS)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
/**
 * Genera la URL para editar un archivo (si es editable) o verlo.
 * 
 * @param string $ruta       Ruta base del proyecto (ej: "Data/Chat/Uploads/2026/07/24/sistema-carito/")
 * @param string $encriptado Nombre encriptado del archivo (columna Encriptado)
 * @param string $nombre     Nombre original del archivo (para saber extensión)
 * @param string $accessType Tipo de acceso (para saber si está bloqueado)
 * @return array  ['edit' => url|null, 'view' => url|null, 'download' => url|null]
 */
function obtener_acciones_archivo($ruta, $encriptado, $nombre, $accessType = 'normal') {
    // Si el archivo está bloqueado, no mostrar acciones
    if ($accessType === 'secure') {
        return ['edit' => null, 'view' => null, 'download' => null];
    }

    // Construir la clave S3 (ruta + encriptado)
    $s3key = build_file_s3_key($ruta, $encriptado);
    if (empty($s3key)) {
        return ['edit' => null, 'view' => null, 'download' => null];
    }

    $keyEncoded = urlencode($s3key);
    $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));

    // Extensiones editables (igual que en tu $txtEditExt)
    $editExt = ['txt','srt','vtt','md','html','css','js','php','py','json','csv','sql','jas'];

    $acciones = [];
    $acciones['edit']   = in_array($ext, $editExt) ? "editor.php?archivo={$keyEncoded}" : null;
    $acciones['view']   = "ver_archivo.php?archivo={$keyEncoded}"; // o descarga directa
    $acciones['download'] = "descargar.php?archivo={$keyEncoded}";

    return $acciones;
}
function build_file_s3_key(string $ruta, string $encriptado): string {
    $ruta = rtrim(str_replace('\\', '/', trim($ruta)), '/') . '/';
    $enc  = ltrim(str_replace('\\', '/', trim($encriptado)), '/');
    if ($enc === '') return '';
    if (strpos($enc, $ruta) === 0) return $enc;
    return $ruta . $enc;
}
function ext_de($nombre){ return strtolower(pathinfo($nombre, PATHINFO_EXTENSION)); }

?>
<?php
$mostrarTruncate = isset($_SESSION['user_id']) && (( $_SESSION['user_id'] ?? '') === 1);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Chat IA</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.3.1/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link rel="icon" href="asistente-de-inteligencia-artificial.gif" type="image/x-icon">

<meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="css/chat2.css" />
<link rel="stylesheet" href="css/design-system.css" />
</head>
<body class="ui-theme theme-neon-green theme-light vision-normal ascii-on">
<input type="hidden" id="chatUserId" value="<?= (int)$_SESSION['user_id'] ?>">

<div class="app-container">
<!-- Panel lateral -->
<aside id="chat-sidebar" class="sidebar-panel">

<div class="sidebar-brand">
    <a href="s3.php">
        <img src="asistente-de-inteligencia-artificial.gif" alt="IA" style="height: 2.2em; vertical-align: middle; display: inline-block;"> Chat+S3
    </a>
</div>

<div class="sidebar-header">
<button id="sbNewChat" class="btn-new-session" type="button">
<i class="fas fa-plus"></i> Nueva conversación
</button>
<div class="search-container">
<i class="fas fa-search icon-search"></i>
<input type="text" id="sbChatSearch" placeholder="Buscar conversaciones..." autocomplete="off">
</div>
</div>

    
    
<div class="sidebar-section">
<div class="section-label-row">
<div class="section-label">PROYECTOS</div>
<button id="sbNewProject" class="btn-icon-sm" title="Nuevo proyecto" type="button">
<i class="fas fa-plus"></i>
</button>
</div>
<div id="sbProjectList" class="projects-list">
<div class="empty-state-sidebar">Cargando...</div>
</div>
<button class="btn-create-project" id="sbManageProjects" type="button">
<i class="fas fa-cog"></i> Gestionar proyectos
</button>
</div>

<div class="sidebar-section conversations-section">
<div class="section-label">CONVERSACIONES</div>
<div id="sbChatList" class="sessions-list">
<div class="empty-state-sidebar">Cargando...</div>
</div>
</div>

<div class="sidebar-section adjuntos-section">

  <div class="section-label-row">
    <div class="section-label">
      <i class="fas fa-paperclip"></i>
      ARCHIVOS
    </div>
    <span class="chat-files-count" id="chatFilesCount">0</span>
  </div>



    <button id="btnAttachmentInspector" class="btn btn-sm btn-outline-info w-100 mt-2" style="font-size:0.75rem;" title="Ver qué información tiene la IA de los archivos adjuntos">
        <i class="fas fa-microscope"></i> Inspector de Adjuntos
    </button>
  <div id="chatSessionFilesList" class="chat-session-files-list">
    <!-- Los archivos se cargan dinámicamente desde chat.js -->
    <div class="empty-state-sidebar chat-files-empty">
      <!--<i class="fas fa-file"></i>
      <span class="text-muted" style="font-size:0.65rem;">Selecciona una conversación</span>-->
    </div>
  </div>
  <div class="chat-attachment-mode mb-2" style="font-size:0.75rem;">
    <label class="m-0 d-flex align-items-center" style="gap:6px; cursor:pointer;">
        <input type="checkbox" id="chatAttachmentsRagMode"  title="Si está activo, solo se inyectan archivos relacionados con la pregunta." checked>
        <small class="text-muted" style="font-size:0.65rem;">Usar adjuntos solo si son relevantes (RAG)</small>
    </label>
</div>

</div>

<div class="sidebar-footer">
<div class="user-profile">
<div class="user-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($_SESSION['usuario'], 0, 1))) ?></div>
<div class="user-info">
<div class="user-name"><?= htmlspecialchars($_SESSION['usuario']) ?></div>
<div class="user-role">Cloud Drive</div>
</div>
<button id="user-settings-btn" class="btn-settings" aria-label="Preferencias" title="Preferencias" type="button" data-toggle="modal" data-target="#settings-modal">
<i class="fas fa-cog"></i>
</button>
</div>
</div>

</aside>


<!-- Fin de Panel lateral -->

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
    <h1 class="chat-title" id="chat2Title">Nueva conversación</h1>
    <small class="text-muted ml-2 d-none" id="chat2SessionBadge"></small>
        <button id="chat2Rename" class="btn-icon-sm" title="Renombrar chat" type="button">
      <i class="fas fa-pen"></i>
    </button>
    <button id="chat2Archive" class="btn-icon-sm" title="Archivar chat" type="button">
      <i class="fas fa-archive"></i>
    </button>
    <button id="chat2Restore" class="btn-icon-sm d-none" title="Restaurar chat" type="button">
      <i class="fas fa-undo"></i>
    </button>
  </div>
  <div class="header-right">

    <!-- ============================================= -->
    <!-- 📊 MINI DASHBOARD: Tokens y Costo del mes     -->
    <!-- ============================================= -->
    <div class="chat-stats-mini" id="chatStatsMini" title="Consumo del mes actual">
      <div class="chat-stats-mini-item">
        <i class="fas fa-coins"></i>
        <div class="chat-stats-mini-data">
          <span class="chat-stats-mini-label">Tokens</span>
          <span class="chat-stats-mini-value" id="chatMiniTokens">—</span>
        </div>
      </div>
      <div class="chat-stats-mini-divider"></div>
      <div class="chat-stats-mini-item">
        <i class="fas fa-dollar-sign"></i>
        <div class="chat-stats-mini-data">
          <span class="chat-stats-mini-label">Costo</span>
          <span class="chat-stats-mini-value chat-stats-mini-cost" id="chatMiniCost">—</span>
        </div>
      </div>
      <a href="https://drive.esforzados.com/michat/dashboard_viewer.php" 
         class="chat-stats-mini-link" 
         target="_blank" 
         rel="noopener"
         title="Ver estadísticas completas">
        <i class="fas fa-chart-line"></i>
      </a>
    </div>
    <!-- ============================================= -->


  </div>
</header>
<!-- panel -->
<div id="pane-Chat2" class="tab-pane show active">   
<div class="card h-100 d-flex flex-column shadow-sm">
<div class="card-header">

<div id="chat2SourcesPanel" class="px-3 py-2 d-none" style="border-bottom: 1px solid var(--border, #333); background: rgba(255,255,255,0.02);">
<div class="d-flex align-items-center">
<h6 class="mb-0 small"><i class="fas fa-folder-open"></i> Fuentes del Proyecto (<span id="chat2SourcesCount">0</span>)</h6>
<button id="chat2IndexPending" class="btn btn-sm btn-outline-success ml-2" title="Indexar archivos pendientes/desactualizados">
<i class="fas fa-sync-alt"></i> Indexar
</button>
<button id="chat2SourcesAdd" class="btn btn-sm btn-outline-primary ml-auto" title="Agregar fuentes">
<i class="fas fa-plus"></i>
</button><button id="chat2SourcesRefresh" class="btn btn-sm btn-outline-secondary ml-1" title="Recargar">
<i class="fas fa-sync"></i>
</button>
</div>
<div id="chat2SourcesList" class="d-flex flex-wrap mt-1" style="max-height: 60px; overflow-y: auto; font-size:0.7rem; gap: 4px;"></div>
</div>

<div id="chat2AttachmentsPanel" class="px-3 py-2 d-none" style="border-bottom: 1px solid var(--border, #333); background: rgba(255,255,255,0.02);">
<div class="d-flex align-items-center">
<h6 class="mb-0 small"><i class="fas fa-paperclip"></i> Adjuntos de Sesión (<span id="chat2AttachmentsCount">0</span>)</h6>
<button id="chat2AttachmentsAdd" class="btn btn-sm btn-outline-primary ml-auto" title="Agregar adjuntos">
<i class="fas fa-plus"></i>
</button><button id="chat2AttachmentsRefresh" class="btn btn-sm btn-outline-secondary ml-1" title="Recargar">
<i class="fas fa-sync"></i>
</button>
</div>
<div id="chat2AttachmentsList" class="d-flex flex-wrap mt-1" style="max-height: 60px; overflow-y: auto; font-size:0.7rem; gap: 4px;"></div>
</div>

</div>
<!-- Contenido de Chat -->
<div id="chat2Messages" class="card-body flex-grow-1 overflow-auto" style="padding: 1rem 1.5rem;"></div>
</div>
</div>
<!-- fin de panel -->


<div class="card-footer">
<small id="chat2Status" class="text-muted"></small>
<div class="form-group mb-2">
<textarea id="chat2Input" class="form-control" rows="3" placeholder="Escribe tu mensaje… (Enter = enviar, Shift+Enter = salto)"></textarea>
</div>
<div id="chat2Queue" class="chat-file-queue d-none">
<div id="chat2QueueList" class="d-flex align-items-center flex-wrap" style="gap:.35rem;"></div>
</div>
<div class="d-flex align-items-center mt-2">
<div class="ml-2">
<input id="chat2File" type="file" style="display:none" multiple accept="image/*,video/*,audio/*,text/plain,.txt,.md,.csv,.json,.xml,.log,application/pdf">
<button id="chat2Attach" class="btn btn-sm btn-outline-secondary" title="Adjuntar archivos a la sesión">
<i class="fas fa-paperclip"></i>
</button>
</div>
<button id="chat2BtnGenImg" class="btn btn-sm btn-outline-primary ml-2" title="Generar imagen">
<i class="fas fa-image"></i>
</button>
<button id="chat2BtnGenVid" class="btn btn-sm btn-outline-primary ml-2" title="Generar video">
<i class="fas fa-film"></i>
</button>
<button id="chat2BtnSonic" class="btn btn-sm btn-outline-secondary ml-2" title="Voz">
<i class="fas fa-microphone"></i>
</button>
<div class="btn-group ml-2" role="group"><button class="btn btn-sm btn-outline-info" data-tool="grep"><i class="fas fa-search"></i></button>
<button class="btn btn-sm btn-outline-info" data-tool="view"><i class="fas fa-eye"></i></button>
<button class="btn btn-sm btn-outline-info" data-tool="str_replace"><i class="fas fa-exchange-alt"></i></button>
<button class="btn btn-sm btn-outline-info" data-tool="search"><i class="fas fa-brain"></i></button>
    <!-- 🚀 NUEVO: Botón Manual para Ejecutar Tests -->
    <button class="btn btn-sm btn-outline-success" id="btnRunTestsManual" title="Ejecutar Tests del Proyecto">
        <i class="fas fa-vial"></i>
    </button>
  <!-- 🚀 NUEVO: Botón de Rollback / Deshacer -->
  <button class="btn btn-sm btn-outline-warning" id="btnRollbackEdit" title="Deshacer última edición de un archivo">
    <i class="fas fa-undo-alt"></i>
  </button>
</div>
<button id="chat2Send" class="btn btn-primary ml-auto">
  <i class="fas fa-paper-plane"></i> Enviar
</button>
</div>

<div id="chat2Usage" class="text-muted small mt-2"></div>
<div id="chatToasts" class="chat-toasts"></div>
<div id="incomingToasts" class="chat-toasts"></div>

</div>



</main>
<!-- fin de cuerpo -->

</div>
<!-- fin de app-container -->

<div id="sidebar-backdrop" class="sidebar-backdrop"></div>


<!-- Modal para Adjuntos de settings-modal -->
<div id="settings-modal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered modal-lg" role="document">
<div class="modal-content">
<div class="modal-header">
<h5 class="modal-title" id="settings-title"><i class="fas fa-sliders-h mr-2"></i>Preferencias</h5>
<button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
</div>
<div class="modal-body">

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
    
    <option value="cohere.embed-v4-v1:0">Cohere — Embed v4 (Multimodal, Multilingual)</option>
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
<!-- ============================================= -->
<!-- 🎲 SEMILLA GLOBAL (todas las IAs)              -->
<!-- ============================================= -->
<hr>
<div class="d-flex align-items-center flex-wrap" style="gap:.75rem;">
    <label class="mb-0 small d-flex align-items-center" style="gap:.35rem;">
        <span title="Semilla para forzar respuestas deterministas en TODAS las IAs">🎲 Seed</span>
        <input id="chat2Seed" type="number" class="form-control form-control-sm" 
               step="1" min="0" max="999999999" value="42" 
               title="Semilla global. Si es mayor a 0, todas las IAs usarán esta semilla para respuestas más consistentes. Pon 0 para desactivar." 
               style="width:110px;">
    </label>
    <small class="text-muted">
        <i class="fas fa-info-circle"></i> 
        Mismo seed + misma pregunta = misma respuesta. Usa <strong>0</strong> para desactivar.
    </small>
</div>
<!-- ============================================= -->
<!-- 🧠 COMPILADOR DE PROMPTS (Fase 1)             -->
<!-- ============================================= -->
<hr>
<div class="settings-section-title">
    <i class="fas fa-magic mr-1"></i> Compilador de Prompts
</div>
<div class="d-flex align-items-center flex-wrap" style="gap:.75rem;">
    <label class="mb-0 small d-flex align-items-center" style="gap:.35rem;">
        <span title="Temperatura del compilador">🌡 Temp</span>
        <input id="chat2CompTemp" type="number" class="form-control form-control-sm" 
               step="0.1" min="0" max="2" value="0.0" 
               title="Temperatura del compilador de prompts" style="width:70px;">
    </label>
    <label class="mb-0 small d-flex align-items-center" style="gap:.35rem;">
        <span title="Máximo de tokens del prompt enriquecido (compilador)">📏 Max tokens Prompt</span>
        <input id="chat2CompMax" type="number" class="form-control form-control-sm" 
               step="1" min="100" max="4096" value="200" 
               title="Máximo de tokens para el prompt enriquecido que genera el compilador" style="width:80px;">
    </label>
    <label class="mb-0 small d-flex align-items-center" style="gap:.35rem;">
        <span title="Máximo de tokens de la respuesta final (modelo principal)">📏 Max tokens Respuesta</span>
        <input id="chat2RespMax" type="number" class="form-control form-control-sm" 
               step="1" min="100" max="4096" value="1000" 
               title="Máximo de tokens para la respuesta final del modelo principal" style="width:80px;">
    </label>
    <label class="mb-0 small d-flex align-items-center" style="gap:.35rem;">
        <span title="Top P del compilador">🎯 Top P</span>
        <input id="chat2CompTopP" type="number" class="form-control form-control-sm" 
               step="0.05" min="0.05" max="1" value="0.1" 
               title="Top P del compilador de prompts" style="width:70px;">
    </label>
</div>

<!-- ============================================= -->
<!-- 🧠 MEMORIA SELECTIVA DE PREGUNTAS ANTERIORES  -->
<!-- ============================================= -->
<hr>
<div class="settings-section">
<div class="settings-section-title">
<i class="fas fa-brain mr-1"></i> Memoria Selectiva de Preguntas
</div>
<p class="small text-muted mb-2">
Cuando haces una pregunta, el sistema busca en tus preguntas anteriores
si alguna contiene la respuesta. Extrae solo el fragmento útil y lo
inyecta como contexto. Reduce tokens y mejora la precisión.
</p>

<div class="d-flex flex-column" style="gap:.75rem;">

<!-- Activar/desactivar memoria selectiva -->
<label class="mb-0 small d-flex align-items-center" style="gap:.5rem; cursor:pointer;">
<input type="checkbox" id="chatQuestionMemoryEnabled" checked
title="Si está activo, el sistema buscará en preguntas anteriores antes de responder."
style="width:16px; height:16px;">
<span>
<strong>Usar memoria selectiva de preguntas anteriores</strong>
<br><small class="text-muted">Recomendado: activado siempre para respuestas más precisas</small>
</span>
</label>

<!-- Alcance de búsqueda -->
<div class="d-flex align-items-center flex-wrap" style="gap:.75rem; padding-left:1.5rem;">
<label class="mb-0 small" style="font-weight:600;">Alcance de búsqueda:</label>
<div class="form-check form-check-inline">
<input class="form-check-input" type="radio" name="chatQuestionMemoryScope"
id="chatQuestionMemoryScopeSession" value="session">
<label class="form-check-label small" for="chatQuestionMemoryScopeSession">
<i class="fas fa-comment mr-1"></i>Solo esta sesión
</label>
</div>
<div class="form-check form-check-inline">
<input class="form-check-input" type="radio" name="chatQuestionMemoryScope"
id="chatQuestionMemoryScopeProject" value="project" checked>
<label class="form-check-label small" for="chatQuestionMemoryScopeProject">
<i class="fas fa-briefcase mr-1"></i>Todo el proyecto
</label>
</div>
</div>

<!-- Opciones avanzadas -->
<div class="d-flex align-items-center flex-wrap" style="gap:.75rem; padding-left:1.5rem;">
<label class="mb-0 small d-flex align-items-center" style="gap:.35rem;">
<span title="Máximo de preguntas anteriores que se revisan como candidatas">📋 Candidatas</span>
<input id="chatQuestionMemoryMaxCandidates" type="number"
class="form-control form-control-sm"
step="1" min="5" max="50" value="20"
title="Máximo de preguntas anteriores que se pasan a la IA para evaluar relevancia"
style="width:70px;">
</label>
<label class="mb-0 small d-flex align-items-center" style="gap:.35rem;">
<span title="Líneas arriba y abajo de cada coincidencia encontrada">📐 Ventana ±</span>
<input id="chatQuestionMemoryWindowLines" type="number"
class="form-control form-control-sm"
step="1" min="2" max="15" value="5"
title="Cantidad de líneas de contexto arriba y abajo de cada coincidencia"
style="width:60px;">
</label>
</div>

<!-- Estado de memoria (informativo) -->
<div id="chatQuestionMemoryStatus" class="small text-muted" style="padding-left:1.5rem; display:none;">
<i class="fas fa-info-circle mr-1"></i>
<span id="chatQuestionMemoryStatusText">—</span>
</div>

</div>
</div>

<!-- ============================================= -->

</div>
<!--
<hr>
<div class="settings-section">
<div class="settings-section-title">Proyecto activo</div>
<select id="chat2Project" class="form-control form-control-sm" style="max-width:100%;">
<option value="">— Sin proyecto (chat libre) —</option>
</select>
<div class="small text-muted mt-2">
Proyecto: <span id="sbCurrentProject">Ninguno</span> ·
Sesión: <span id="sbCurrentSession">Ninguna</span> ·
Fuentes indexadas: <span id="sbSourcesCount">0</span>
</div>
</div>-->


<hr>
<div class="settings-section">
    <div class="settings-section-title">Memoria Procedural</div>
    <p class="small text-muted mb-2">
        Patrones, preferencias y reglas que la IA ha aprendido de tus conversaciones.
        Se aplican automáticamente en todas tus sesiones.
    </p>
    <div class="d-flex flex-wrap" style="gap:.5rem;">

        <button class="btn btn-sm btn-outline-success" id="btnForceProceduralExtraction" type="button">
            <i class="fas fa-sync-alt mr-1"></i> Re-analizar todas las sesiones
        </button>
        <button class="btn btn-sm btn-outline-info" id="btnOpenProceduralMemory" title="Ver, editar y eliminar memoria procedural" type="button">
            <i class="fas fa-brain mr-1"></i> Ver y editar memoria
        </button>
        <!--<button id="btnContextViewer" class="btn btn-sm btn-outline-info" title="Ver, editar y eliminar contexto activo de sesión y proyecto" type="button">
            <i class="fas fa-database"></i> Inspector de Contexto
        </button>-->
        <button id="btnAiDataControl" class="btn btn-sm btn-outline-warning" title="Control avanzado de datos internos de la IA" type="button">
            <i class="fas fa-sliders-h"></i> Control IA
        </button>

    </div>
    <small class="text-muted mt-1 d-block" id="proceduralExtractionStatus"></small>
    
<hr>
<div class="settings-section">
    <div class="settings-section-title">🔄 Mantenimiento de IA</div>
    <p class="small text-muted mb-2">
        Ejecuta manualmente los procesos de mantenimiento. Útil cuando necesitas resultados inmediatos.
        <br><strong>Orden:</strong> 1️⃣ Embeddings → 2️⃣ Compresión
    </p>
    <div class="d-flex flex-wrap" style="gap:.5rem;">
        <button id="btnRunEmbeddings" class="btn btn-sm btn-outline-primary" type="button">
            <i class="fas fa-vector-square mr-1"></i> 1. Procesar Embeddings
        </button>
        <button id="btnRunCompression" class="btn btn-sm btn-outline-success" type="button">
            <i class="fas fa-compress-arrows-alt mr-1"></i> 2. Comprimir Sesiones
        </button>
        <button id="btnRunBoth" class="btn btn-sm btn-outline-warning" type="button">
            <i class="fas fa-sync-alt mr-1"></i> Ejecutar Ambos (Secuencial)
        </button>
    </div>
    <div class="mt-2">
        <small class="text-muted d-block" id="maintenanceStatus"></small>
        <div class="progress d-none" id="maintenanceProgress" style="height: 8px; margin-top: 8px;">
            <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
        </div>
    </div>
</div>

</div>
<!-- ✅ BOTONES Y JS PARA TRUNCATE -->
<?php if ($mostrarTruncate): ?>
<div style="position:fixed; bottom:20px; right:20px; z-index:9999; background:#1a1a2e; border:2px solid #dc3545; border-radius:12px; padding:16px; max-width:350px; box-shadow:0 8px 32px rgba(220,53,69,0.3);">
    <h6 style="margin:0 0 8px 0; color:#dc3545; font-weight:700;">
        <i class="fas fa-exclamation-triangle mr-1"></i> Zona Peligrosa
    </h6>
    <p style="font-size:0.75rem; color:#ccc; margin-bottom:10px;">
        Trunca tablas excepto: <strong>Users</strong>, <strong>TokenUsage</strong>, 
        <strong>FileS3</strong>, <strong>S3Folders</strong>, <strong>Projects</strong>.
    </p>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <button type="button" onclick="adminTruncateTables('dry_run')" 
                class="btn btn-sm btn-outline-info" style="flex:1;">
            <i class="fas fa-eye mr-1"></i> Simular
        </button>
        <button type="button" onclick="adminTruncateTables('confirm')" 
                class="btn btn-sm btn-danger" style="flex:1;">
            <i class="fas fa-trash mr-1"></i> Truncar
        </button>
    </div>
    <pre id="truncate-result" style="white-space:pre-wrap; margin-top:10px; font-size:0.7rem; max-height:200px; overflow-y:auto; background:rgba(0,0,0,0.3); padding:8px; border-radius:6px; color:#0f0; display:none;"></pre>
</div>

<script>
async function adminTruncateTables(mode) {
    const resultBox = document.getElementById('truncate-result');
    const token = document.querySelector('meta[name="csrf-token"]')?.content;

    if (!token) {
        alert('Falta el token CSRF. Recarga la página.');
        return;
    }

    if (mode === 'confirm') {
        const ok = confirm(
            '⚠️ Esto truncará tablas excepto:\n\n' +
            '• Users\n• TokenUsage\n• FileS3\n• S3Folders\n• Projects\n\n' +
            'Esta acción NO se puede deshacer.\n\n¿Continuar?'
        );
        if (!ok) return;
    }

    const formData = new FormData();
    formData.append('action', 'admin_truncate_tables');
    formData.append('truncate_mode', mode);
    formData.append('csrf_token', token);

    if (resultBox) {
        resultBox.style.display = 'block';
        resultBox.textContent = '⏳ Procesando...';
    }

    try {
        // ✅ APUNTA DIRECTAMENTE A truncate.php
        const response = await fetch('truncate.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        const data = await response.json();

        if (!data.ok) {
            throw new Error(data.error || 'Error desconocido.');
        }

        let text = data.message + "\n\n";

        if (Array.isArray(data.tablas) && data.tablas.length) {
            text += (mode === 'confirm' ? "✅ Tablas truncadas:\n" : "🔍 Tablas que se truncarían:\n") + data.tablas.join("\n");
        } else {
            text += "No se procesaron tablas.";
        }

        if (Array.isArray(data.omitidas) && data.omitidas.length) {
            text += "\n\n⚠️ Tablas omitidas o inexistentes:\n" + data.omitidas.join("\n");
        }

        if (resultBox) resultBox.textContent = text;
    } catch (error) {
        if (resultBox) resultBox.textContent = '❌ Error: ' + error.message;
        else alert('Error: ' + error.message);
    }
}
</script>
<?php endif; ?>

<hr>
<div class="settings-section">
<div class="settings-section-title">Apariencia</div>
<div class="mb-2">
<div class="small text-muted mb-1">Modo</div>
<div class="d-flex flex-wrap" style="gap:.4rem;">
<button class="btn btn-sm btn-outline-secondary js-set-mode" data-mode="theme-dark" type="button"><i class="fas fa-moon mr-1"></i>Oscuro</button>
<button class="btn btn-sm btn-outline-secondary js-set-mode" data-mode="theme-light" type="button"><i class="fas fa-sun mr-1"></i>Claro</button>
</div>
</div>
</div>
<hr>
<div class="settings-section">
<div class="settings-section-title">Cuenta</div>
<div class="d-flex flex-wrap" style="gap:.5rem;">
<!--<button id="btnSyncS3" class="btn btn-sm btn-outline-secondary" type="button">
<i class="fas fa-rotate mr-1"></i> Sincronizar S3
</button>-->
<button id="btnRecargar" class="btn btn-sm btn-outline-secondary" onclick="recargarPagina()" type="button">
<i class="fas fa-sync-alt mr-1"></i> Recargar página
</button>
<a href="logout.php" class="btn btn-sm btn-outline-danger">
<i class="fas fa-sign-out-alt mr-1"></i> Cerrar sesión
</a>

</div>
</div>
</div>

<div class="modal-footer">
<button type="button" class="btn-ghost" data-dismiss="modal">Cerrar</button>
</div>
</div>
</div>
</div>
<!-- Modal para Adjuntos de ProjectManager -->
<div class="modal fade" id="modalProjectManager" tabindex="-1" role="dialog" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered modal-lg" role="document">
<div class="modal-content">
<div class="modal-header bg-primary text-white">
<h5 class="modal-title"><i class="fas fa-project-diagram"></i> Gestión de Proyectos</h5>
<button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button></div>
<div class="modal-body">
<div id="projectList" class="list-group mb-3"></div>
<hr>
<h6>Crear / Editar Proyecto</h6>
<form id="projectForm">
<input type="hidden" id="projectId" value="">
<div class="form-row">
<div class="form-group col-md-6">
<label>Nombre del proyecto *</label>
<input type="text" class="form-control" id="projectName" required placeholder="Ej: Mi Sistema Web">
</div>
<div class="form-group col-md-6">
<label>Slug (URL-friendly) *</label>
<input type="text" class="form-control" id="projectSlug" required placeholder="mi-sistema-web">
<small class="form-text text-muted">Sin espacios, solo letras, números y guiones. Esto definirá la carpeta.</small>
</div>
</div>
<div class="form-group">
<label>Descripción del proyecto (Informativa)</label>
<textarea class="form-control" id="projectDescription" rows="2" placeholder="Ej: Sistema de gestión de inventarios con PHP y MySQL"></textarea>
</div>
<div class="form-group">
<label class="text-warning"><i class="fas fa-robot"></i> Instrucciones / Reglas para la IA</label>
<textarea class="form-control" id="projectInstructions" rows="3" placeholder="Ej: Responde siempre en español. Usa nombres de variables en camelCase. No uses funciones obsoletas de PHP."></textarea>
<small class="form-text text-muted">Estas reglas se inyectarán en el contexto de cada chat de este proyecto.</small>
</div>
<div class="form-row">
<div class="form-group col-md-4">
<label>Lenguaje principal</label>
<select class="form-control" id="projectLanguage">
<option value="">— Seleccionar —</option>
<option value="php">PHP</option>
<option value="javascript">JavaScript</option>
<option value="python">Python</option>
<option value="other">Otro</option>
</select>
</div>
<div class="form-group col-md-4">
<label>Framework</label>
<input type="text" class="form-control" id="projectFramework" placeholder="Ej: Laravel, React...">
</div>
<div class="form-group col-md-4">
    <label>Prefijo S3 (Ruta base)</label>
    <input type="text" class="form-control bg-light" id="projectRootPrefix" readonly placeholder="Se genera automático">
    <small class="form-text text-muted">Los archivos se guardarán en: Data/Chat/Uploads/{user_id}/{project_id}/</small>
</div>
</div>
<div class="d-flex justify-content-end mt-3">
<button type="button" class="btn btn-secondary mr-2" id="btnCancelProject">Cancelar</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Proyecto</button>
</div>
</form>
</div>
</div>
</div>
</div>
<!-- Modal para Adjuntos de ProjectSources -->
<div class="modal fade" id="modalProjectSources" tabindex="-1" role="dialog" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered modal-lg" role="document">
<div class="modal-content">
<div class="modal-header bg-info text-white">
<h5 class="modal-title"><i class="fas fa-folder-plus"></i> Gestionar Fuentes del Proyecto</h5>
<button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
</div>
<div class="modal-body">
<div class="mb-3">
<div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-2">
<h6 class="mb-0"><i class="fas fa-list"></i> Fuentes del proyecto</h6>
<button type="button" id="btnIndexPending" class="btn btn-sm btn-outline-success" title="Procesar archivos pendientes con IA">
<i class="fas fa-sync-alt"></i> Indexar pendientes
</button>
</div>
<div id="modalSourcesList" class="list-group list-group-flush" style="max-height: 200px; overflow-y: auto;">
<div class="list-group-item text-muted small">Cargando fuentes...</div>
</div>
</div>
<hr>
<p class="text-muted small mb-2">
    <i class="fas fa-info-circle"></i> Los nuevos archivos se guardarán en:
    <code id="projectUploadPath" class="bg-light px-1">Data/Chat/Uploads/{user_id}/{project_id}/</code>
    <br>
    <small class="text-muted" style="font-size:0.65rem;">Todos los archivos del proyecto se almacenan en la misma carpeta, independientemente de las sesiones.</small>
</p>
<div class="form-group">
<label for="projectFilesInput"><i class="fas fa-upload"></i> Selecciona archivos nuevos</label>
<input type="file" class="form-control-file" id="projectFilesInput" multiple
accept=".php,.js,.ts,.py,.java,.c,.cpp,.cs,.go,.rs,.rb,.html,.css,.scss,.json,.xml,.yaml,.yml,.md,.txt,.sql,.sh,.bash,.pdf,.jpg,.png,.gif">
<small class="form-text text-muted">
Puedes seleccionar múltiples archivos. Se registrarán como "Pendientes" para ser indexados por la IA.
</small>
</div>
<div id="projectUploadProgress" class="d-none">
<div class="progress" style="height: 20px;">
<div id="projectUploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated"
role="progressbar" style="width: 0%">0%</div>
</div>
<small id="projectUploadStatus" class="text-muted d-block mt-1">Subiendo...</small>
</div>
<div id="projectUploadResult" class="d-none mt-2">
<div class="alert alert-success small mb-0">
<i class="fas fa-check-circle"></i> <span id="projectUploadSuccessMsg"></span>
</div></div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-primary" id="btnUploadProjectFiles">
<i class="fas fa-cloud-upload-alt"></i> Subir Archivos Seleccionados
</button>
<button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
</div>
</div>
</div>
</div>
<!-- Modal para Adjuntos de Sesión -->
<div class="modal fade" id="modalSessionAttachments" tabindex="-1" role="dialog" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered modal-lg" role="document">
<div class="modal-content">
<div class="modal-header bg-warning text-white">
<h5 class="modal-title"><i class="fas fa-paperclip"></i> Gestionar Adjuntos de Sesión</h5>
<button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
</div>
<div class="modal-body">
<div class="mb-3">
<div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-2">
<h6 class="mb-0"><i class="fas fa-list"></i> Adjuntos de la sesión</h6>
<button type="button" id="btnIndexSessionAttachments" class="btn btn-sm btn-outline-success" title="Procesar archivos pendientes con IA">
<i class="fas fa-sync-alt"></i> Indexar pendientes
</button>
</div>
<div id="modalSessionAttachmentsList" class="list-group list-group-flush" style="max-height: 200px; overflow-y: auto;">
<div class="list-group-item text-muted small">Cargando adjuntos...</div>
</div>
</div>
<hr>
<p class="text-muted small mb-2">
<i class="fas fa-info-circle"></i> Los nuevos archivos se guardarán en:
<code id="sessionUploadPath" class="bg-light px-1">Data/Chat/Uploads/{user_id}/YYYY/MM/</code>
</p>
<div class="form-group">
<label for="sessionFilesInput"><i class="fas fa-upload"></i> Selecciona archivos nuevos</label>
<input type="file" class="form-control-file" id="sessionFilesInput" multiple
accept=".php,.js,.ts,.py,.java,.c,.cpp,.cs,.go,.rs,.rb,.html,.css,.scss,.json,.xml,.yaml,.yml,.md,.txt,.sql,.sh,.bash,.pdf,.jpg,.png,.gif,.mp4,.webm,.mp3,.wav">
<small class="form-text text-muted">
Puedes seleccionar múltiples archivos. Se registrarán como adjuntos de esta sesión de chat.
</small>
</div>
<div id="sessionUploadProgress" class="d-none">
<div class="progress" style="height: 20px;">
<div id="sessionUploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated"
role="progressbar" style="width: 0%">0%</div>
</div>
<small id="sessionUploadStatus" class="text-muted d-block mt-1">Subiendo...</small>
</div>
<div id="sessionUploadResult" class="d-none mt-2">
<div class="alert alert-success small mb-0">
<i class="fas fa-check-circle"></i> <span id="sessionUploadSuccessMsg"></span>
</div></div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-primary" id="btnUploadSessionFiles">
<i class="fas fa-cloud-upload-alt"></i> Subir Archivos Seleccionados
</button>
<button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
</div>
</div>
</div>
</div>
<!-- Modal de Memoria Procedural -->
<div class="modal fade" id="modalProceduralMemory" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content" style="background:var(--bg2); border:1px solid var(--border); border-radius:var(--radius-lg,18px); max-height:90vh; display:flex; flex-direction:column;">
            <div class="modal-header" style="background:linear-gradient(135deg,var(--accent),var(--accent-2)); border-radius:var(--radius-lg,18px) var(--radius-lg,18px) 0 0; padding:16px 20px;">
                <h5 class="modal-title" style="color:#fff; font-weight:700; display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-brain"></i> Memoria Procedural
                </h5>
                <button type="button" class="close" data-dismiss="modal" style="color:#fff; opacity:.8;">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body" style="overflow-y:auto; flex:1; padding:20px;">
                <!-- Formulario para agregar nueva memoria -->
                <div style="background:var(--bg3); border:1px solid var(--border); border-radius:var(--radius,12px); padding:16px; margin-bottom:20px;">
                    <div style="font-size:0.8rem; font-weight:700; color:var(--text-strong); margin-bottom:10px; text-transform:uppercase; letter-spacing:.05em;">
                        <i class="fas fa-plus-circle" style="color:var(--accent);"></i> Agregar nueva regla
                    </div>
                    <div style="display:flex; gap:8px; margin-bottom:8px;">
                        <select id="pmNewType" class="form-control form-control-sm" style="max-width:160px; background:var(--bg); border-color:var(--border); color:var(--text);">
                            <option value="rule">📏 Regla</option>
                            <option value="preference">🎨 Preferencia</option>
                            <option value="correction">✏️ Corrección</option>
                            <option value="workflow">🔄 Flujo de trabajo</option>
                            <option value="pattern">🔁 Patrón</option>
                        </select>
                    </div>
                    <textarea id="pmNewContent" class="form-control form-control-sm" rows="2"
                        placeholder="Ej: Siempre responde en español. Usa nombres de variables en camelCase..."
                        style="background:var(--bg); border-color:var(--border); color:var(--text); font-size:0.85rem;"></textarea>
                    <div style="text-align:right; margin-top:8px;">
                        <button class="btn btn-sm btn-primary" id="pmBtnAdd" style="background:var(--accent); border-color:var(--accent); font-weight:600;">
                            <i class="fas fa-save mr-1"></i> Agregar
                        </button>
                    </div>
                </div>

                <!-- Lista de memorias -->
                <div id="pmList" style="display:flex; flex-direction:column; gap:10px;">
                    <div style="text-align:center; color:var(--text-soft); padding:30px;">
                        <i class="fas fa-spinner fa-spin"></i> Cargando...
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>
<!-- Scripts base -->
<script src="js/actualizar-hora.js"></script>
<script src="js/recargarPagina.js"></script>
<!-- sidebar-sessions-projects.js debe cargar primero porque maneja las sesiones del sidebar -->
<script src="js/sidebar-sessions-projects.js"></script>
<!-- chat.js debe cargar segundo porque exporta window.chatUtils -->
<script src="js/chat.js"></script>
<script src="js/context-viewer.js"></script> 
<script src="js/ai-data-control.js"></script>
<script src="js/user_preferences.js"></script>
<!-- Módulos que dependen de chat.js -->
<script src="js/run-tests.js"></script>
<script src="js/rollback-edit.js"></script>
<script src="js/sincronizar.js"></script>
<script src="js/estilo.js"></script>
<script src="js/sidebar-responsive.js"></script>
<script src="js/subir-chunked.js"></script>


<script>
// =====================================================================
// 📊 MINI DASHBOARD: Carga de tokens y costo del mes en el header
// =====================================================================
(function () {
  'use strict';

  const tokensEl = document.getElementById('chatMiniTokens');
  const costEl   = document.getElementById('chatMiniCost');
  if (!tokensEl || !costEl) return;

  const REFRESH_MS = 5 * 60 * 1000; // 5 minutos

  function fmtNumber(n) {
    return Number(n || 0).toLocaleString('es-MX');
  }

  function fmtCost(n) {
    const v = Number(n || 0);
    if (v < 0.01) return '$' + v.toFixed(4);
    if (v < 1)    return '$' + v.toFixed(4);
    return '$' + v.toFixed(2);
  }

  function setLoading(on) {
    [tokensEl, costEl].forEach(el => {
      el.classList.toggle('loading', on);
    });
  }

  async function loadMiniStats() {
    try {
      setLoading(true);
      const month = new Date().toISOString().slice(0, 7); // YYYY-MM
      const r = await fetch('dashboard_stats.php?month=' + encodeURIComponent(month), {
        credentials: 'same-origin',
        cache: 'no-cache'
      });

      if (!r.ok) throw new Error('HTTP ' + r.status);

      const j = await r.json();
      if (!j.ok) throw new Error(j.error || 'Respuesta inválida');

      tokensEl.textContent = fmtNumber(j.tokens && j.tokens.total);
      costEl.textContent   = fmtCost(j.tokens && j.tokens.cost);

      // Tooltip dinámico con más info
      const mini = document.getElementById('chatStatsMini');
      if (mini && j.tokens) {
        const byPhase = j.tokens.by_phase || {};
        mini.title =
          'Consumo del mes ' + month + '\n' +
          '━━━━━━━━━━━━━━━━━━━━━\n' +
          '• Compilación:  ' + fmtNumber(byPhase.compile)  + ' tokens\n' +
          '• Respuesta:    ' + fmtNumber(byPhase.respond)  + ' tokens\n' +
          '• Embeddings:   ' + fmtNumber(byPhase.embedding)+ ' tokens\n' +
          '• Lint fix:     ' + fmtNumber(byPhase.lint_fix) + ' tokens\n' +
          '━━━━━━━━━━━━━━━━━━━━━\n' +
          'Total: ' + fmtNumber(j.tokens.total) + ' tokens\n' +
          'Costo: ' + fmtCost(j.tokens.cost) + ' USD\n\n' +
          'Clic en el ícono para ver dashboard completo';
      }
    } catch (e) {
      console.error('Error cargando mini-stats:', e);
      tokensEl.textContent = '—';
      costEl.textContent   = '—';
    } finally {
      setLoading(false);
    }
  }

  // Cargar al inicio
  loadMiniStats();

  // Recargar cada 5 minutos
  setInterval(loadMiniStats, REFRESH_MS);

  // Recargar cada vez que se envíe un mensaje (la sesión ya se refresca,
  // así que aprovechamos para actualizar también las stats)
  const originalSend = document.getElementById('chat2Send');
  if (originalSend) {
    originalSend.addEventListener('click', () => {
      // Retraso de 3s para que el backend registre los tokens primero
      setTimeout(loadMiniStats, 3000);
    });
  }
})();
</script>

<script>
// =====================================================================
// 🔄 MANTENIMIENTO MANUAL: Botones para ejecutar crons desde UI
// =====================================================================
(function () {
    'use strict';
    
    const SECRET = 'Z1!xC6@vB3#nM8$kL4*jH9^gF2&dS7';
    const statusEl = document.getElementById('maintenanceStatus');
    const progressEl = document.getElementById('maintenanceProgress');
    const progressBar = progressEl?.querySelector('.progress-bar');
    
    // ✅ Helper seguro para mostrar toasts (compatible con chat.js)
    function safeToast(title, message, type = 'info') {
        if (window.chatUtils && typeof window.chatUtils.showToast === 'function') {
            window.chatUtils.showToast(title, message, type);
        } else {
            // Fallback: crear toast directamente
            const container = document.getElementById('chatToasts') || document.getElementById('incomingToasts');
            if (!container) { alert(title + ': ' + message); return; }
            const toast = document.createElement('div');
            toast.className = 'chat-toast';
            toast.innerHTML = '<div class="ct-title">' + title + '</div><div class="small">' + message + '</div>';
            if (type === 'success') toast.style.borderLeftColor = '#00ff66';
            if (type === 'danger') toast.style.borderLeftColor = '#ff5a5a';
            if (type === 'warning') toast.style.borderLeftColor = '#ffd861';
            container.appendChild(toast);
            setTimeout(() => { if (toast.parentNode) toast.remove(); }, 5000);
        }
    }
    
    function setStatus(msg, type = 'info') {
        if (!statusEl) return;
        statusEl.textContent = msg;
        statusEl.className = 'text-' + type + ' d-block mt-2';
    }
    
    function showProgress(show, percent = 0) {
        if (!progressEl || !progressBar) return;
        progressEl.classList.toggle('d-none', !show);
        if (show) progressBar.style.width = percent + '%';
    }
    
    async function runEmbeddings() {
        setStatus('⏳ Procesando embeddings pendientes...', 'info');
        showProgress(true, 30);
        try {
            const url = 'process_embedding_queue.php?batch=10&key=' + encodeURIComponent(SECRET);
            const r = await fetch(url, { credentials: 'same-origin' });
            const j = await r.json();
            
            if (j.ok) {
                const msg = '✅ Embeddings procesados: ' + j.succeeded + ' exitosos, ' + j.failed + ' fallidos';
                setStatus(msg, 'success');
                safeToast('Embeddings', msg, 'success');
            } else {
                throw new Error(j.error || j.message || 'Error desconocido');
            }
        } catch (e) {
            setStatus('❌ Error: ' + e.message, 'danger');
            safeToast('Error', e.message, 'danger');
        } finally {
            showProgress(false);
        }
    }
    
    async function runCompression() {
        setStatus('⏳ Comprimiendo sesiones...', 'info');
        showProgress(true, 60);
        try {
            const url = 'compress_session_context.php?key=' + encodeURIComponent(SECRET);
            const r = await fetch(url, { credentials: 'same-origin' });
            const j = await r.json();
            
            if (j.ok) {
                const msg = '✅ Compresión completada: ' + j.sessions_processed + ' sesiones, ' + j.extracted_knowledge + ' conocimientos extraídos';
                setStatus(msg, 'success');
                safeToast('Compresión', msg, 'success');
            } else {
                throw new Error((j.errors && j.errors.join(', ')) || j.message || 'Error desconocido');
            }
        } catch (e) {
            setStatus('❌ Error: ' + e.message, 'danger');
            safeToast('Error', e.message, 'danger');
        } finally {
            showProgress(false);
        }
    }
    
    async function runBoth() {
        setStatus('⏳ Ejecutando ambos procesos en secuencia...', 'info');
        showProgress(true, 10);
        
        try {
            // Paso 1: Embeddings
            setStatus('⏳ Paso 1/2: Procesando embeddings...', 'info');
            showProgress(true, 30);
            await runEmbeddings();
            
            // Espera de 5 segundos entre procesos 
            setStatus('⏳ Esperando 5 segundos antes del siguiente proceso...', 'info');
            showProgress(true, 50);
            await new Promise(resolve => setTimeout(resolve, 5000));
            
            // Paso 2: Compresión
            setStatus('⏳ Paso 2/2: Comprimiendo sesiones...', 'info');
            showProgress(true, 70);
            await runCompression();
            
            showProgress(true, 100);
            setStatus('✅ Ambos procesos completados exitosamente', 'success');
            safeToast('Mantenimiento', 'Procesos completados', 'success');
            
            setTimeout(() => showProgress(false), 2000);
        } catch (e) {
            setStatus('❌ Error en la secuencia: ' + e.message, 'danger');
        }
    }
    
    // Wire up buttons
    const btnEmbeddings = document.getElementById('btnRunEmbeddings');
    const btnCompression = document.getElementById('btnRunCompression');
    const btnBoth = document.getElementById('btnRunBoth');
    
    if (btnEmbeddings) btnEmbeddings.addEventListener('click', runEmbeddings);
    if (btnCompression) btnCompression.addEventListener('click', runCompression);
    if (btnBoth) btnBoth.addEventListener('click', runBoth);
    
})();
</script>
</body>
</html>
</body>
</html>
