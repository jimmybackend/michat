<?php
/**
 * trace_explorer.php · Fase 7.2
 *
 * Navegador jerárquico de trazabilidad:
 * Usuario → Proyecto / Chats libres → Sesión → Pregunta / Respuesta.
 *
 * La página no consulta tablas de trazabilidad directamente. Consume
 * exclusivamente chat_trace_api.php (Fase 7.1), de modo que 7.3 podrá añadir
 * el grafo sobre los mismos identificadores sin cambiar esta navegación.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/app_bootstrap.php';

if (!isset($_SESSION['usuario']) || empty($_SESSION['usuario'])) {
    header('Location: ../index.php');
    exit;
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
if ($currentUserId <= 0) {
    header('Location: ../index.php');
    exit;
}

// El usuario de la trazabilidad SIEMPRE proviene de la sesión autenticada.
// Nunca se acepta user_id desde GET.
$initialUserId = $currentUserId;

$requestedSessionId = isset($_GET['session_id']) && is_numeric($_GET['session_id'])
    ? max(0, (int)$_GET['session_id'])
    : 0;
$requestedProjectId = isset($_GET['project_id']) && is_numeric($_GET['project_id'])
    ? max(0, (int)$_GET['project_id'])
    : 0;

$initialSessionId = 0;
$initialProjectId = 0;

// Validar que la sesión solicitada pertenezca al usuario autenticado.
// Si pertenece a un proyecto, aprovechamos para derivar también su project_id.
if ($requestedSessionId > 0) {
    $stmt = $db_connection->prepare(
        'SELECT id_, project_id_ FROM ChatSessions WHERE id_ = ? AND user_id_ = ? LIMIT 1'
    );
    if ($stmt) {
        $stmt->bind_param('ii', $requestedSessionId, $currentUserId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if ($row) {
            $initialSessionId = (int)$row['id_'];
            $initialProjectId = (int)($row['project_id_'] ?? 0);
        }
    }
}

// project_id solo se acepta si también pertenece al usuario autenticado.
// Si ya fue derivado desde una sesión válida, esa relación tiene prioridad.
if ($initialProjectId <= 0 && $requestedProjectId > 0) {
    $stmt = $db_connection->prepare(
        'SELECT id_ FROM Projects WHERE id_ = ? AND user_id_ = ? AND status <> \'deleted\' LIMIT 1'
    );
    if ($stmt) {
        $stmt->bind_param('ii', $requestedProjectId, $currentUserId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if ($row) {
            $initialProjectId = (int)$row['id_'];
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Trazabilidad · Proyecto → Sesión → Q&amp;A</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.3.1/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link rel="stylesheet" href="css/chat2.css">
<link rel="stylesheet" href="css/design-system.css">
<link rel="stylesheet" href="css/trace-explorer.css">
</head>
<body class="ui-theme theme-neon-green trace-explorer-page"
      data-initial-user-id="<?= (int)$initialUserId ?>"
      data-initial-project-id="<?= (int)$initialProjectId ?>"
      data-initial-session-id="<?= (int)$initialSessionId ?>">

<header class="trace-header">
    <div class="trace-header-main">
        <div class="trace-header-title-wrap">
            <a href="chat.php" class="trace-back-link"><i class="fas fa-arrow-left"></i> Chat</a>
            <a href="ai_data_control.php<?= $initialSessionId > 0 ? '?session_id=' . (int)$initialSessionId : '' ?>" class="trace-back-link"><i class="fas fa-database"></i> Control IA</a>
            <div>
                <h1><i class="fas fa-diagram-project"></i> Explorador de trazabilidad</h1>
                <p>Tus proyectos → sesión → pregunta/respuesta. Cada respuesta conserva su acceso al proceso real.</p>
            </div>
        </div>
        <div class="trace-header-actions">
            <button type="button" class="trace-btn" id="traceRefreshAll">
                <i class="fas fa-rotate"></i> Actualizar
            </button>
        </div>
    </div>

    <div class="trace-context-bar">
        <!-- El selector se conserva para compatibilidad con trace-explorer.js,
             pero queda fuera de la interfaz: el usuario está fijado por $_SESSION. -->
        <div class="trace-context-field" id="traceUserField" hidden>
            <label for="traceUserSelect">Usuario</label>
            <select id="traceUserSelect" class="trace-select" aria-hidden="true" tabindex="-1">
                <option value="<?= (int)$initialUserId ?>" selected><?= htmlspecialchars((string)($_SESSION['usuario'] ?? 'Usuario'), ENT_QUOTES, 'UTF-8') ?></option>
            </select>
        </div>
        <div class="trace-breadcrumb" id="traceBreadcrumb" aria-live="polite">
            <span>Preparando navegador…</span>
        </div>
    </div>
</header>

<main class="trace-layout">
    <aside class="trace-column trace-scope-column">
        <div class="trace-column-header">
            <div>
                <span class="trace-kicker">Nivel 1</span>
                <h2>Proyectos</h2>
            </div>
            <span class="trace-count" id="traceProjectCount">0</span>
        </div>
        <div class="trace-filter-wrap">
            <i class="fas fa-magnifying-glass"></i>
            <input type="search" id="traceProjectSearch" placeholder="Buscar proyecto…" autocomplete="off">
        </div>
        <div class="trace-list" id="traceProjectList">
            <div class="trace-loading"><i class="fas fa-circle-notch fa-spin"></i> Cargando proyectos…</div>
        </div>
    </aside>

    <section class="trace-column trace-session-column">
        <div class="trace-column-header">
            <div>
                <span class="trace-kicker">Nivel 2</span>
                <h2>Sesiones</h2>
            </div>
            <span class="trace-count" id="traceSessionCount">0</span>
        </div>
        <div class="trace-filter-wrap">
            <i class="fas fa-magnifying-glass"></i>
            <input type="search" id="traceSessionSearch" placeholder="Buscar sesión…" autocomplete="off">
        </div>
        <div class="trace-list" id="traceSessionList">
            <div class="trace-empty-small">Selecciona un proyecto o Chats libres.</div>
        </div>
    </section>

    <section class="trace-column trace-turn-column">
        <div class="trace-turn-toolbar">
            <div class="trace-column-header trace-turn-heading">
                <div>
                    <span class="trace-kicker">Nivel 3</span>
                    <h2 id="traceTurnTitle">Preguntas y respuestas</h2>
                </div>
                <span class="trace-count" id="traceTurnCount">0</span>
            </div>
            <div class="trace-filter-wrap trace-turn-search-wrap">
                <i class="fas fa-magnifying-glass"></i>
                <input type="search" id="traceTurnSearch" placeholder="Buscar dentro de preguntas/respuestas…" autocomplete="off">
            </div>
        </div>

        <div class="trace-session-summary" id="traceSessionSummary" hidden></div>

        <div class="trace-turn-list" id="traceTurnList">
            <div class="trace-empty-state">
                <i class="fas fa-comments"></i>
                <h3>Selecciona una sesión</h3>
                <p>Aquí aparecerá cada pregunta enlazada con su respuesta y su trace.</p>
            </div>
        </div>
    </section>
</main>

<div class="trace-toast-host" id="traceToastHost" aria-live="polite"></div>

<script>
window.TRACE_EXPLORER_CONFIG = {
    api: 'chat_trace_api.php',
    processViewer: 'chat_activity_viewer.php',
    graphViewer: 'trace_graph.php',
    initialUserId: <?= (int)$initialUserId ?>,
    lockedUserId: <?= (int)$initialUserId ?>,
    initialProjectId: <?= (int)$initialProjectId ?>,
    initialSessionId: <?= (int)$initialSessionId ?>
};
</script>
<script src="js/trace-explorer.js"></script>
</body>
</html>
