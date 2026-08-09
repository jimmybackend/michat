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
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Cloud Drive · Chat IA</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.3.1/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link rel="icon" href="ellogo.png" type="image/x-icon">
<link rel="stylesheet" href="css/chat2.css" />
<link rel="stylesheet" href="css/design-system.css" />
</head>
<body class="ui-theme theme-neon-green theme-light vision-normal ascii-on">
<input type="hidden" id="chatUserId" value="<?= (int)$_SESSION['user_id'] ?>">

<div class="app-container">
<!-- Panel lateral -->
<aside id="chat-sidebar" class="sidebar-panel">

<div class="sidebar-brand">
<a href="s3.php"><i class="fas fa-cloud"></i> Cloud Drive</a>
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
</div>
<div class="header-right">
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
<div id="chat2Usage" class="text-muted small mt-2"></div>
<div id="chatToasts" class="chat-toasts"></div>
<div id="incomingToasts" class="chat-toasts"></div>
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
</div>



</main>
<!-- fin de cuerpo -->

</div>
<!-- fin de app-container -->

<div id="sidebar-backdrop" class="sidebar-backdrop"></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>
<!-- Scripts base -->
<script src="js/actualizar-hora.js"></script>
<script src="js/recargarPagina.js"></script>
<!-- sidebar-sessions-projects.js debe cargar primero porque maneja las sesiones del sidebar -->
<script src="js/sidebar-sessions-projects.js"></script>
<!-- chat.js debe cargar segundo porque exporta window.chatUtils -->
<script src="js/chat.js"></script>
<!-- Módulos que dependen de chat.js -->
<script src="js/run-tests.js"></script>
<script src="js/rollback-edit.js"></script>
<script src="js/sincronizar.js"></script>
<script src="js/estilo.js"></script>
<script src="js/sidebar-responsive.js"></script>
<script src="js/subir-chunked.js"></script>
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
<div class="d-flex align-items-center flex-wrap mt-2" style="gap:.75rem;">
<div class="custom-control custom-switch">
<input type="checkbox" class="custom-control-input" id="chat2Auto" checked>
<label class="custom-control-label" for="chat2Auto" title="Auto-router">Auto-router</label>
</div>
<label class="mb-0 small d-flex align-items-center" style="gap:.35rem;">Temp
<input id="chat2Temp" type="number" class="form-control form-control-sm" step="0.1" min="0" max="2" value="0.7" title="temperature" style="width:70px;">
</label>
<label class="mb-0 small d-flex align-items-center" style="gap:.35rem;">Max tokens
<input id="chat2Max" type="number" class="form-control form-control-sm" step="1" min="1" max="4096" value="800" title="max_tokens" style="width:80px;">
</label>
<label class="mb-0 small d-flex align-items-center" style="gap:.35rem;">Top P
<input id="chat2TopP" type="number" class="form-control form-control-sm" step="0.05" min="0.05" max="1" value="0.9" title="top_p" style="width:70px;">
</label>
</div>
</div>

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
</div>

<hr>
<div class="settings-section">
<div class="settings-section-title">Apariencia</div>
<div class="mb-2">
<div class="small text-muted mb-1">Color de acento</div>
<div class="d-flex flex-wrap" style="gap:.4rem;">
<button class="btn btn-sm btn-outline-success js-set-theme" data-theme="theme-neon-green" type="button">Verde</button>
<button class="btn btn-sm btn-outline-info js-set-theme" data-theme="theme-neon-blue" type="button">Azul</button>
<button class="btn btn-sm btn-outline-danger js-set-theme" data-theme="theme-neon-red" type="button">Rojo</button>
<button class="btn btn-sm btn-outline-warning js-set-theme" data-theme="theme-neon-yellow" type="button">Amarillo</button>
</div>
</div>
<div class="mb-2">
<div class="small text-muted mb-1">Modo</div>
<div class="d-flex flex-wrap" style="gap:.4rem;">
<button class="btn btn-sm btn-outline-secondary js-set-mode" data-mode="theme-dark" type="button"><i class="fas fa-moon mr-1"></i>Oscuro</button>
<button class="btn btn-sm btn-outline-secondary js-set-mode" data-mode="theme-light" type="button"><i class="fas fa-sun mr-1"></i>Claro</button>
</div>
</div>
<div class="mb-2">
<div class="small text-muted mb-1">Visión</div>
<div class="d-flex flex-wrap" style="gap:.4rem;">
<button class="btn btn-sm btn-outline-secondary js-set-vision" data-vision="vision-normal" type="button">Normal</button>
<button class="btn btn-sm btn-outline-secondary js-set-vision" data-vision="vision-myopia" type="button">Miopía</button>
<button class="btn btn-sm btn-outline-secondary js-set-vision" data-vision="vision-protanopia" type="button">Protanopia</button>
<button class="btn btn-sm btn-outline-secondary js-set-vision" data-vision="vision-deuteranopia" type="button">Deuteranopia</button>
<button class="btn btn-sm btn-outline-secondary js-set-vision" data-vision="vision-tritanopia" type="button">Tritanopia</button>
</div>
</div>
<button class="btn btn-sm btn-outline-secondary" id="btnToggleAscii" type="button">
<i class="fas fa-terminal mr-1"></i> Alternar ASCII
</button>
</div>

<hr>
<div class="settings-section">
<div class="settings-section-title">Cuenta</div>
<div class="d-flex flex-wrap" style="gap:.5rem;">
<button id="btnSyncS3" class="btn btn-sm btn-outline-secondary" type="button">
<i class="fas fa-rotate mr-1"></i> Sincronizar S3
</button>
<button id="btnRecargar" class="btn btn-sm btn-outline-secondary" onclick="recargarPagina()" type="button">
<i class="fas fa-sync-alt mr-1"></i> Recargar página
</button>
<a href="https://drive.esforzados.com/michat/s3chatstats.php" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-info">
<i class="fas fa-comment-dots mr-1"></i> Ir al Stats
</a>
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
<small class="form-text text-muted">Los archivos se guardarán en: Data/Chat/Uploads/{FECHA}/{SLUG}/</small>
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
<code id="projectUploadPath" class="bg-light px-1">Data/Chat/Uploads/YYYY/MM/DD/slug-proyecto/</code>
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
</div>
</body>
</html>
