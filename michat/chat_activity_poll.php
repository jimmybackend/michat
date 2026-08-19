<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (session_status() === PHP_SESSION_NONE) session_start();

function activityPollExit(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$userId = isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])
    ? (int)$_SESSION['user_id']
    : 0;
if ($userId <= 0) activityPollExit(['ok'=>false,'error'=>'Sesión inválida'], 401);

// Liberar el lock de PHP cuanto antes: este endpoint se consulta mientras
// bedrock_chat2.php sigue trabajando en otra petición del mismo navegador.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$traceId = trim((string)($_GET['trace_id'] ?? ''));
$sessionId = (int)($_GET['session_id'] ?? 0);
$afterId = max(0, (int)($_GET['after_id'] ?? 0));

if ($sessionId <= 0) activityPollExit(['ok'=>false,'error'=>'session_id inválido'], 400);
if ($traceId === '' || !preg_match('/^[A-Za-z0-9_-]{16,36}$/', $traceId)) {
    activityPollExit(['ok'=>false,'error'=>'trace_id inválido'], 400);
}

try {
    $bootstrap = __DIR__ . '/app_bootstrap.php';
    if (!is_file($bootstrap)) $bootstrap = __DIR__ . '/../app_bootstrap.php';
    if (!is_file($bootstrap)) throw new RuntimeException('app_bootstrap.php no encontrado');
    require_once $bootstrap;
} catch (Throwable $e) {
    activityPollExit(['ok'=>false,'error'=>'bootstrap: '.$e->getMessage()], 500);
}

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    activityPollExit(['ok'=>false,'error'=>'DB no disponible'], 500);
}

// El trace solo puede consultarlo el dueño de la sesión.
$check = $db_connection->prepare("SELECT id_ FROM ChatSessions WHERE id_=? AND user_id_=? LIMIT 1");
if (!$check) activityPollExit(['ok'=>false,'error'=>'No se pudo validar la sesión'], 500);
$check->bind_param('ii', $sessionId, $userId);
$check->execute();
$owned = $check->get_result()->fetch_assoc();
$check->close();
if (!$owned) activityPollExit(['ok'=>false,'error'=>'Sesión no encontrada o sin permisos'], 403);

try {
    $stmt = $db_connection->prepare("
        SELECT id_, trace_id, phase, event_key, status, title, summary,
               details_json, model_id, duration_ms, created_at
        FROM ChatActivityEvents
        WHERE trace_id=? AND session_id_=? AND user_id_=? AND id_>?
        ORDER BY id_ ASC
        LIMIT 250
    ");
    if (!$stmt) throw new RuntimeException($db_connection->error ?: 'No se pudo preparar la consulta');
    $stmt->bind_param('siii', $traceId, $sessionId, $userId, $afterId);
    $stmt->execute();
    $res = $stmt->get_result();
    $events = [];
    $lastId = $afterId;
    $terminal = false;
    while ($row = $res->fetch_assoc()) {
        $lastId = max($lastId, (int)$row['id_']);
        $details = null;
        if ($row['details_json'] !== null && $row['details_json'] !== '') {
            $decoded = json_decode((string)$row['details_json'], true);
            $details = is_array($decoded) ? $decoded : ['raw'=>(string)$row['details_json']];
        }
        $eventKey = (string)$row['event_key'];
        if (in_array($eventKey, ['trace_completed','trace_error','trace_cancelled'], true)) $terminal = true;
        $events[] = [
            'id' => (int)$row['id_'],
            'trace_id' => (string)$row['trace_id'],
            'phase' => (string)$row['phase'],
            'event_key' => $eventKey,
            'status' => (string)$row['status'],
            'title' => (string)$row['title'],
            'summary' => $row['summary'] !== null ? (string)$row['summary'] : null,
            'details' => $details,
            'model_id' => $row['model_id'] !== null ? (string)$row['model_id'] : null,
            'duration_ms' => $row['duration_ms'] !== null ? (int)$row['duration_ms'] : null,
            'created_at' => (string)$row['created_at'],
        ];
    }
    $stmt->close();

    activityPollExit([
        'ok'=>true,
        'trace_id'=>$traceId,
        'session_id'=>$sessionId,
        'after_id'=>$afterId,
        'last_id'=>$lastId,
        'terminal'=>$terminal,
        'events'=>$events,
    ]);
} catch (Throwable $e) {
    // Si el SQL todavía no fue instalado, devolver un error claro sin HTML.
    activityPollExit(['ok'=>false,'error'=>'Actividad no disponible: '.$e->getMessage()], 500);
}
