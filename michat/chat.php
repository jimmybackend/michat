<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once '../vendor/autoload.php';
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
 * Configuración dinámica de modelos de IA.
 *
 * Cada tarea real que llama a Bedrock tiene su propio agent_key.
 * Los text_block siguen siendo únicamente plantillas/instrucciones.
 */
$aiRuntimeUserId = (int)($_SESSION['user_id'] ?? 0);

$aiRuntimeAllowedAgents = [
    'chat_main' => [
        'type' => 'chat',
        'force_active' => true,
    ],
    'prompt_compiler' => [
        'type' => 'chat',
        'force_active' => false,
    ],
    'embedding_main' => [
        'type' => 'embedding',
        'force_active' => false,
    ],
    'smart_memory_general' => [
        'type' => 'chat',
        'force_active' => false,
    ],
    'smart_memory_code' => [
        'type' => 'chat',
        'force_active' => false,
    ],
];

/**
 * Endpoint AJAX de la propia página de chat para cambiar modelo/estado.
 * Para usuarios != 1 crea un override copiando la configuración global.
 */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'update_ai_runtime_agent'
) {
    header('Content-Type: application/json; charset=UTF-8');

    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (
        $csrf === ''
        || empty($_SESSION['csrf_token'])
        || !hash_equals((string)$_SESSION['csrf_token'], $csrf)
    ) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'error' => 'Token CSRF inválido. Recarga la página.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($aiRuntimeUserId <= 0) {
        http_response_code(401);
        echo json_encode([
            'ok' => false,
            'error' => 'Usuario no válido.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $agentKey = trim((string)($_POST['agent_key'] ?? ''));
    $modelId = trim((string)($_POST['model_id'] ?? ''));
    $requestedActive = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

    if (!isset($aiRuntimeAllowedAgents[$agentKey])) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'agent_key no permitido.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (
        $modelId === ''
        || strlen($modelId) > 180
        || !preg_match('/^[A-Za-z0-9._:-]+$/', $modelId)
    ) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'model_id inválido.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $agentMeta = $aiRuntimeAllowedAgents[$agentKey];

    // Evita usar un embedding como modelo conversacional y viceversa.
    $looksEmbedding = (
        stripos($modelId, 'embed') !== false
        || stripos($modelId, 'marengo') !== false
    );

    if ($agentKey === 'embedding_main') {
        $supportedEmbeddingModels = [
            'amazon.titan-embed-text-v2:0',
            'amazon.titan-embed-text-v1',
            'cohere.embed-v4:0',
            'cohere.embed-english-v3',
            'cohere.embed-multilingual-v3',
        ];
        if (!in_array($modelId, $supportedEmbeddingModels, true)) {
            http_response_code(400);
            echo json_encode([
                'ok' => false,
                'error' => 'Modelo de embedding no soportado por los adaptadores instalados (Titan Text V2/V1 y Cohere Embed v4/v3).'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if ($agentMeta['type'] === 'embedding' && !$looksEmbedding) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'Este agente requiere un modelo de embeddings.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($agentMeta['type'] === 'chat' && $looksEmbedding) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'Un modelo de embeddings no puede usarse como modelo de respuesta.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $isActive = !empty($agentMeta['force_active']) ? 1 : $requestedActive;

    // Perfil técnico del adaptador. Se mezcla con extra_config sin borrar
    // umbrales RAG ni otras preferencias ya existentes.
    $embeddingProfile = null;
    if ($agentKey === 'embedding_main') {
        $embeddingProfiles = [
            'amazon.titan-embed-text-v2:0' => [
                'adapter' => 'titan_text_v2',
                'dimensions' => 1024,
                'normalize' => true,
                'input_max_chars' => 8000,
            ],
            'amazon.titan-embed-text-v1' => [
                'adapter' => 'titan_text_v1',
                'dimensions' => 1536,
                'input_max_chars' => 8000,
            ],
            'cohere.embed-v4:0' => [
                'adapter' => 'cohere_embed_v4',
                'dimensions' => 1024,
                'input_max_chars' => 8000,
                'document_input_type' => 'search_document',
                'query_input_type' => 'search_query',
                'truncate' => 'RIGHT',
                'embedding_types' => ['float'],
            ],
            'cohere.embed-english-v3' => [
                'adapter' => 'cohere_embed_v3',
                'dimensions' => 1024,
                'input_max_chars' => 2048,
                'document_input_type' => 'search_document',
                'query_input_type' => 'search_query',
                'truncate' => 'END',
                'embedding_types' => ['float'],
            ],
            'cohere.embed-multilingual-v3' => [
                'adapter' => 'cohere_embed_v3',
                'dimensions' => 1024,
                'input_max_chars' => 2048,
                'document_input_type' => 'search_document',
                'query_input_type' => 'search_query',
                'truncate' => 'END',
                'embedding_types' => ['float'],
            ],
        ];
        $embeddingProfile = $embeddingProfiles[$modelId] ?? null;
    }

    try {
        /**
         * Crea/actualiza un override por usuario copiando TODOS los campos
         * del registro global (user_id_=1). Así no se pierden instrucciones,
         * JSON ni parámetros del agente.
         */
        $sql = "
            INSERT INTO UserAIAgentConfigs (
                user_id_, agent_key, agent_group, display_name, description,
                model_id, fallback_model_id, model_ladder_json,
                system_instruction, user_prompt_template,
                temperature, max_tokens_prompt, max_tokens_output,
                top_p, seed, max_attempts, extra_config,
                token_usage_phase, is_active, sort_order,
                created_at, updated_at
            )
            SELECT
                ?, agent_key, agent_group, display_name, description,
                ?, fallback_model_id, model_ladder_json,
                system_instruction, user_prompt_template,
                temperature, max_tokens_prompt, max_tokens_output,
                top_p, seed, max_attempts, extra_config,
                token_usage_phase, ?, sort_order,
                NOW(), NOW()
            FROM UserAIAgentConfigs
            WHERE user_id_ = 1
              AND agent_key = ?
            LIMIT 1
            ON DUPLICATE KEY UPDATE
                model_id = ?,
                is_active = ?,
                updated_at = NOW()
        ";

        $stmt = $db_connection->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar UPDATE de configuración: ' . $db_connection->error);
        }

        $stmt->bind_param(
            'isissi',
            $aiRuntimeUserId,
            $modelId,
            $isActive,
            $agentKey,
            $modelId,
            $isActive
        );

        if (!$stmt->execute()) {
            throw new RuntimeException($stmt->error);
        }

        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected === 0) {
            // Un UPDATE sin cambios también devuelve 0 filas afectadas.
            // Verificamos que la configuración global exista antes de considerarlo error.
            $check = $db_connection->prepare(
                "SELECT id_ FROM UserAIAgentConfigs WHERE user_id_ = 1 AND agent_key = ? LIMIT 1"
            );
            if (!$check) {
                throw new RuntimeException('No se pudo verificar la configuración global.');
            }
            $check->bind_param('s', $agentKey);
            $check->execute();
            $existsResult = $check->get_result();
            $globalExists = $existsResult && $existsResult->num_rows > 0;
            $check->close();

            if (!$globalExists) {
                throw new RuntimeException(
                    "No existe la configuración global '{$agentKey}'. Ejecuta primero el SQL de agentes dinámicos."
                );
            }
        }

        if ($agentKey === 'embedding_main' && is_array($embeddingProfile)) {
            $cfgStmt = $db_connection->prepare(
                "SELECT extra_config FROM UserAIAgentConfigs WHERE user_id_ = ? AND agent_key = 'embedding_main' LIMIT 1"
            );
            if (!$cfgStmt) {
                throw new RuntimeException('No se pudo leer extra_config de embedding_main.');
            }
            $cfgStmt->bind_param('i', $aiRuntimeUserId);
            $cfgStmt->execute();
            $cfgRow = $cfgStmt->get_result()->fetch_assoc();
            $cfgStmt->close();

            $extra = json_decode((string)($cfgRow['extra_config'] ?? ''), true);
            if (!is_array($extra)) $extra = [];
            foreach ($embeddingProfile as $profileKey => $profileValue) {
                $extra[$profileKey] = $profileValue;
            }
            $extraJson = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($extraJson === false) {
                throw new RuntimeException('No se pudo serializar extra_config de embedding_main.');
            }

            $updExtra = $db_connection->prepare(
                "UPDATE UserAIAgentConfigs SET extra_config = ?, updated_at = NOW() WHERE user_id_ = ? AND agent_key = 'embedding_main'"
            );
            if (!$updExtra) {
                throw new RuntimeException('No se pudo preparar extra_config de embedding_main.');
            }
            $updExtra->bind_param('si', $extraJson, $aiRuntimeUserId);
            if (!$updExtra->execute()) {
                $err = $updExtra->error;
                $updExtra->close();
                throw new RuntimeException('No se pudo actualizar extra_config de embedding_main: ' . $err);
            }
            $updExtra->close();
        }

        echo json_encode([
            'ok' => true,
            'agent_key' => $agentKey,
            'model_id' => $modelId,
            'is_active' => $isActive,
            'user_id_' => $aiRuntimeUserId,
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Throwable $e) {
        error_log('INDEX_AI_RUNTIME_UPDATE: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * Cargar configuración efectiva:
 * 1) registro del usuario actual;
 * 2) si no existe, registro global user_id_=1.
 */
$aiRuntimeConfigs = [];
try {
    $keys = array_keys($aiRuntimeAllowedAgents);
    $quotedKeys = "'" . implode("','", array_map(
        static fn($v) => $db_connection->real_escape_string($v),
        $keys
    )) . "'";

    $sqlLoad = "
        SELECT
            user_id_, agent_key, model_id, is_active,
            temperature, max_tokens_prompt, max_tokens_output,
            top_p, seed, extra_config
        FROM UserAIAgentConfigs
        WHERE agent_key IN ({$quotedKeys})
          AND user_id_ IN (1, ?)
        ORDER BY (user_id_ = ?) DESC, user_id_ ASC
    ";

    $stmtLoad = $db_connection->prepare($sqlLoad);
    if ($stmtLoad) {
        $stmtLoad->bind_param('ii', $aiRuntimeUserId, $aiRuntimeUserId);
        $stmtLoad->execute();
        $resLoad = $stmtLoad->get_result();

        while ($row = $resLoad->fetch_assoc()) {
            $key = (string)$row['agent_key'];
            if (!isset($aiRuntimeConfigs[$key])) {
                $aiRuntimeConfigs[$key] = $row;
            }
        }
        $stmtLoad->close();
    }
} catch (Throwable $e) {
    error_log('INDEX_AI_RUNTIME_LOAD: ' . $e->getMessage());
}

$aiRuntimeDefaults = [
    'chat_main' => [
        'model_id' => 'amazon.nova-micro-v1:0',
        'is_active' => 1,
    ],
    'prompt_compiler' => [
        'model_id' => 'amazon.nova-micro-v1:0',
        'is_active' => 1,
    ],
    'embedding_main' => [
        'model_id' => 'amazon.titan-embed-text-v2:0',
        'is_active' => 1,
    ],
    'smart_memory_general' => [
        'model_id' => 'amazon.nova-micro-v1:0',
        'is_active' => 1,
    ],
    'smart_memory_code' => [
        'model_id' => 'anthropic.claude-3-5-haiku-20241022-v1:0',
        'is_active' => 1,
    ],
];

foreach ($aiRuntimeDefaults as $key => $default) {
    if (!isset($aiRuntimeConfigs[$key])) {
        $aiRuntimeConfigs[$key] = $default;
    }
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
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
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
    <!-- 🔬 TRAZABILIDAD DE LA CONVERSACIÓN ACTUAL     -->
    <!-- ============================================= -->
    <button id="chatTraceExplorerBtn"
            type="button"
            class="btn btn-sm btn-outline-info"
            title="Ver trazabilidad completa de esta conversación"
            aria-label="Ver trazabilidad de la conversación"
            disabled>
      <i class="fas fa-microscope mr-1"></i>
      <span class="d-none d-md-inline">Trazabilidad</span>
    </button>

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
      <a href="dashboard_viewer.php" 
         class="chat-stats-mini-link" 
         target="_blank" 
         rel="noopener"
         title="Ver estadísticas completas">
        <i class="fas fa-chart-line"></i>
      </a>
    </div>
    <a href="task_center.php"
       class="btn btn-sm btn-outline-info"
       title="Abrir Task Center"
       aria-label="Abrir Task Center de agentes">
      <i class="fas fa-robot mr-1"></i>
      <span class="d-none d-md-inline">Agentes</span>
    </a>
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


<div class="card-footer chat-composer" id="chatComposer">
  <div class="chat-composer-meta">
    <small id="chat2Status" class="text-muted" aria-live="polite"></small>
    <div id="chat2Usage" class="text-muted small"></div>
  </div>

  <div class="chat-composer-input-wrap">
    <textarea id="chat2Input" class="form-control" rows="1" placeholder="Escribe tu mensaje…" aria-label="Escribe tu mensaje"></textarea>
  </div>

  <div id="chat2Queue" class="chat-file-queue d-none">
    <div id="chat2QueueList" class="d-flex align-items-center flex-wrap" style="gap:.35rem;"></div>
  </div>

  <div class="chat-composer-actions">
    <div class="chat-composer-tools-scroll" aria-label="Herramientas del chat">
      <div class="chat-composer-primary-tools">
        <input id="chat2File" type="file" style="display:none" multiple accept="image/*,video/*,audio/*,text/plain,.txt,.md,.csv,.json,.xml,.log,application/pdf">
        <button id="chat2Attach" class="btn btn-sm btn-outline-secondary" title="Adjuntar archivos" aria-label="Adjuntar archivos"><i class="fas fa-paperclip"></i></button>
        <button id="chat2BtnGenImg" class="btn btn-sm btn-outline-primary" title="Generar imagen" aria-label="Generar imagen"><i class="fas fa-image"></i></button>
        <button id="chat2BtnGenVid" class="btn btn-sm btn-outline-primary" title="Generar video" aria-label="Generar video"><i class="fas fa-film"></i></button>
        <button id="chat2BtnSonic" class="btn btn-sm btn-outline-secondary" title="Voz" aria-label="Voz"><i class="fas fa-microphone"></i></button>
      </div>
      <div class="btn-group chat-composer-code-tools" role="group" aria-label="Herramientas de proyecto">
        <button class="btn btn-sm btn-outline-info" data-tool="grep" title="Buscar texto" aria-label="Buscar texto"><i class="fas fa-search"></i></button>
        <button class="btn btn-sm btn-outline-info" data-tool="view" title="Ver código" aria-label="Ver código"><i class="fas fa-eye"></i></button>
        <button class="btn btn-sm btn-outline-info" data-tool="str_replace" title="Editar código" aria-label="Editar código"><i class="fas fa-exchange-alt"></i></button>
        <button class="btn btn-sm btn-outline-info" data-tool="search" title="Búsqueda semántica" aria-label="Búsqueda semántica"><i class="fas fa-brain"></i></button>
        <button class="btn btn-sm btn-outline-success" id="btnRunTestsManual" title="Ejecutar tests del proyecto" aria-label="Ejecutar tests"><i class="fas fa-vial"></i></button>
        <button class="btn btn-sm btn-outline-warning" id="btnRollbackEdit" title="Deshacer última edición" aria-label="Deshacer última edición"><i class="fas fa-undo-alt"></i></button>
      </div>
    </div>
    <button id="chat2Send" class="btn btn-primary chat-composer-send" aria-label="Enviar mensaje">
      <i class="fas fa-paper-plane"></i><span class="chat-send-label">Enviar</span>
    </button>
  </div>

  <div id="chatToasts" class="chat-toasts"></div>
  <div id="incomingToasts" class="chat-toasts"></div>
</div>



</main>
<!-- fin de cuerpo -->

</div>
<!-- fin de app-container -->

<div id="sidebar-backdrop" class="sidebar-backdrop"></div>

<!-- =========================================================
     PANEL DERECHO: PROCESO REAL DE CADA RESPUESTA
     Muestra telemetría operacional guardada en ChatActivityEvents.
     No muestra chain-of-thought privado del modelo.
     ========================================================= -->
<div id="chatActivityDrawerBackdrop" class="chat-activity-drawer-backdrop" aria-hidden="true"></div>
<aside id="chatActivityDrawer" class="chat-activity-drawer" aria-hidden="true" aria-label="Proceso de la respuesta">
  <div class="chat-activity-drawer-header">
    <div>
      <div class="chat-activity-drawer-eyebrow"><i class="fas fa-wave-square mr-1"></i>Proceso de la respuesta</div>
      <h2 id="chatActivityDrawerTitle" class="chat-activity-drawer-title">Actividad del agente</h2>
      <div id="chatActivityDrawerMeta" class="chat-activity-drawer-meta"></div>
    </div>
    <button id="chatActivityDrawerClose" type="button" class="chat-activity-drawer-close" aria-label="Cerrar panel" title="Cerrar">
      <i class="fas fa-times"></i>
    </button>
  </div>
  <div class="chat-activity-drawer-notice">
    <i class="fas fa-shield-alt mr-1"></i>
    Se muestran prompts, consultas, RAG, memoria, herramientas, modelos, tokens y tiempos reales de la aplicación; el razonamiento privado interno del modelo no se expone.
  </div>
  <div id="chatActivityDrawerBody" class="chat-activity-drawer-body">
    <div class="chat-activity-empty">Selecciona <strong>Proceso</strong> en una respuesta.</div>
  </div>
  <div class="chat-activity-drawer-footer">
    <a id="chatActivityDrawerOpen" class="btn btn-sm btn-outline-primary" href="#" target="_blank" rel="noopener">
      <i class="fas fa-external-link-alt mr-1"></i>Abrir en pestaña
    </a>
    <a id="chatActivityDrawerTxt" class="btn btn-sm btn-outline-secondary" href="#">
      <i class="fas fa-file-alt mr-1"></i>TXT
    </a>
    <a id="chatActivityDrawerJson" class="btn btn-sm btn-outline-secondary" href="#">
      <i class="fas fa-file-code mr-1"></i>JSON
    </a>
  </div>
</aside>

<!-- Preferencias -->
<?php require __DIR__ . '/includes/preferences/modal.php'; ?>
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
<!-- Fase 4.1.1: sidebar-sessions-projects.js queda como compatibilidad; chat.js es el único dueño de sesiones/proyectos. -->
<script src="js/sidebar-sessions-projects.js"></script>
<!-- Preferencias primero: expone chatPreferencesReady/modelo antes de crear sesiones. -->
<script src="js/user_preferences.js"></script>
<!-- chat.js administra sesiones/proyectos y exporta window.chatUtils. -->
<script src="js/chat.js"></script>
<script src="js/context-viewer.js"></script> 
<script src="js/ai-data-control.js"></script>
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
    
    const maintenanceCsrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
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
            const url = 'process_embedding_queue.php?batch=10';
            const r = await fetch(url, { method:'POST', credentials:'same-origin', headers:{'X-CSRF-Token':maintenanceCsrf} });
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
            const url = 'compress_session_context.php';
            const r = await fetch(url, { method:'POST', credentials:'same-origin', headers:{'X-CSRF-Token':maintenanceCsrf} });
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


<script>
// =====================================================================
// 🤖 CONFIGURACIÓN DINÁMICA DE MODELOS -> UserAIAgentConfigs
// =====================================================================
(function () {
    'use strict';

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const runtimeConfig = <?= json_encode($aiRuntimeConfigs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const mainModel = document.getElementById('chat2Model');
    const mainStatus = document.getElementById('aiRuntimeModelStatus');
    const internalStatus = document.getElementById('aiRuntimeInternalStatus');

    const bindings = [
        {
            key: 'prompt_compiler',
            selectId: 'aiPromptCompilerModel',
            activeId: 'aiPromptCompilerActive'
        },
        {
            key: 'embedding_main',
            selectId: 'aiEmbeddingModel',
            activeId: 'aiEmbeddingActive'
        },
        {
            key: 'smart_memory_general',
            selectId: 'aiSmartMemoryGeneralModel',
            activeId: 'aiSmartMemoryGeneralActive'
        },
        {
            key: 'smart_memory_code',
            selectId: 'aiSmartMemoryCodeModel',
            activeId: 'aiSmartMemoryCodeActive'
        }
    ];

    function setStatus(el, message, ok = true) {
        if (!el) return;
        el.textContent = message;
        el.className = 'small mt-2 ' + (ok ? 'text-success' : 'text-danger');
    }

    function isEmbeddingModel(modelId) {
        const v = String(modelId || '').toLowerCase();
        return v.includes('embed') || v.includes('marengo');
    }

    // El selector principal sigue mostrando el catálogo completo por compatibilidad,
    // pero un embedding no puede seleccionarse como modelo de chat.
    if (mainModel) {
        Array.from(mainModel.querySelectorAll('optgroup')).forEach(group => {
            const label = (group.label || '').toLowerCase();
            if (
                label.includes('embeddings')
                || label.includes('rerank')
                || label.includes('imagen (')
                || label.includes('video (')
                || label.includes('voz /')
                || label.includes('seguridad /')
            ) {
                Array.from(group.querySelectorAll('option')).forEach(opt => {
                    opt.disabled = true;
                });
            }
        });
    }

    async function saveRuntimeAgent(agentKey, modelId, isActive, statusEl) {
        const body = new URLSearchParams();
        body.set('action', 'update_ai_runtime_agent');
        body.set('csrf_token', csrfToken);
        body.set('agent_key', agentKey);
        body.set('model_id', modelId);
        body.set('is_active', isActive ? '1' : '0');

        setStatus(statusEl, 'Guardando configuración…', true);

        try {
            const response = await fetch(window.location.pathname, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body.toString()
            });

            const data = await response.json();

            if (!response.ok || !data.ok) {
                throw new Error(data.error || ('HTTP ' + response.status));
            }

            if (!runtimeConfig[agentKey]) runtimeConfig[agentKey] = {};
            runtimeConfig[agentKey].model_id = data.model_id;
            runtimeConfig[agentKey].is_active = Number(data.is_active);

            setStatus(
                statusEl,
                'Guardado: ' + data.agent_key + ' → ' + data.model_id
                    + (data.is_active ? ' (activo)' : ' (inactivo)'),
                true
            );

            return data;
        } catch (error) {
            console.error('AI runtime config:', error);
            setStatus(statusEl, 'No se pudo guardar: ' + error.message, false);
            throw error;
        }
    }

    function applyInitialValue(select, value) {
        if (!select || !value) return;
        const option = Array.from(select.options).find(o => o.value === value);
        if (option && !option.disabled) {
            select.value = value;
        }
    }

    // Modelo principal: se toma de UserAIAgentConfigs, no del selected hardcodeado.
    if (mainModel) {
        const cfg = runtimeConfig.chat_main || {};
        applyInitialValue(mainModel, cfg.model_id);

        mainModel.addEventListener('change', async function () {
            const selected = this.value;

            if (isEmbeddingModel(selected)) {
                setStatus(mainStatus, 'Ese modelo es de embeddings y no puede responder por converse().', false);
                applyInitialValue(this, runtimeConfig.chat_main?.model_id);
                return;
            }

            const previous = runtimeConfig.chat_main?.model_id || '';
            try {
                await saveRuntimeAgent('chat_main', selected, true, mainStatus);
            } catch (e) {
                applyInitialValue(this, previous);
            }
        });
    }

    // Procesos auxiliares: modelo + activar/desactivar.
    bindings.forEach(binding => {
        const select = document.getElementById(binding.selectId);
        const active = document.getElementById(binding.activeId);
        const cfg = runtimeConfig[binding.key] || {};

        if (select) applyInitialValue(select, cfg.model_id);
        if (active) active.checked = Number(cfg.is_active ?? 1) === 1;

        const save = async () => {
            if (!select || !active) return;
            const previousModel = runtimeConfig[binding.key]?.model_id || '';
            const previousActive = Number(runtimeConfig[binding.key]?.is_active ?? 1) === 1;

            try {
                await saveRuntimeAgent(
                    binding.key,
                    select.value,
                    active.checked,
                    internalStatus
                );
            } catch (e) {
                applyInitialValue(select, previousModel);
                active.checked = previousActive;
            }
        };

        select?.addEventListener('change', save);
        active?.addEventListener('change', save);
    });
})();
</script>

</body>
</html>
