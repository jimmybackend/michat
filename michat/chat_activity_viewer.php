<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

function viewerExit(string $message, int $code = 400): never {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function redactPrivateThinking($value) {
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) $out[$k] = redactPrivateThinking($v);
        return $out;
    }
    if (is_string($value)) {
        return preg_replace('/<thinking>[\s\S]*?<\/thinking>/i', '[razonamiento privado omitido]', $value);
    }
    return $value;
}

$userId = isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($userId <= 0) viewerExit('Sesión inválida.', 401);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$traceId = trim((string)($_GET['trace_id'] ?? ''));
$sessionId = (int)($_GET['session_id'] ?? 0);
$format = strtolower(trim((string)($_GET['format'] ?? 'html')));
if ($sessionId <= 0) viewerExit('session_id inválido.');
if ($traceId === '' || !preg_match('/^[A-Za-z0-9_-]{16,36}$/', $traceId)) viewerExit('trace_id inválido.');
if (!in_array($format, ['html','json','txt'], true)) $format = 'html';

try {
    $bootstrap = __DIR__ . '/app_bootstrap.php';
    if (!is_file($bootstrap)) $bootstrap = __DIR__ . '/../app_bootstrap.php';
    if (!is_file($bootstrap)) throw new RuntimeException('app_bootstrap.php no encontrado');
    require_once $bootstrap;
} catch (Throwable $e) {
    viewerExit('bootstrap: ' . $e->getMessage(), 500);
}
if (!isset($db_connection) || !($db_connection instanceof mysqli)) viewerExit('DB no disponible.', 500);

$stmtSession = $db_connection->prepare("SELECT id_, title, project_id_, created_at FROM ChatSessions WHERE id_=? AND user_id_=? LIMIT 1");
if (!$stmtSession) viewerExit('No se pudo validar la sesión.', 500);
$stmtSession->bind_param('ii', $sessionId, $userId);
$stmtSession->execute();
$session = $stmtSession->get_result()->fetch_assoc();
$stmtSession->close();
if (!$session) viewerExit('Sesión no encontrada o sin permisos.', 403);

$stmt = $db_connection->prepare("\n    SELECT id_, phase, event_key, status, title, summary, details_json, model_id, duration_ms, created_at\n    FROM ChatActivityEvents\n    WHERE trace_id=? AND session_id_=? AND user_id_=?\n    ORDER BY id_ ASC\n    LIMIT 1000\n");
if (!$stmt) viewerExit('Actividad no disponible: ' . $db_connection->error, 500);
$stmt->bind_param('sii', $traceId, $sessionId, $userId);
$stmt->execute();
$res = $stmt->get_result();
$events = [];
while ($row = $res->fetch_assoc()) {
    $details = null;
    if ($row['details_json'] !== null && $row['details_json'] !== '') {
        $decoded = json_decode((string)$row['details_json'], true);
        $details = is_array($decoded) ? $decoded : ['raw' => (string)$row['details_json']];
    }
    $events[] = [
        'id' => (int)$row['id_'],
        'phase' => (string)$row['phase'],
        'event_key' => (string)$row['event_key'],
        'status' => (string)$row['status'],
        'title' => (string)redactPrivateThinking((string)$row['title']),
        'summary' => $row['summary'] !== null ? (string)redactPrivateThinking((string)$row['summary']) : null,
        'details' => redactPrivateThinking($details),
        'model_id' => $row['model_id'] !== null ? (string)$row['model_id'] : null,
        'duration_ms' => $row['duration_ms'] !== null ? (int)$row['duration_ms'] : null,
        'created_at' => (string)$row['created_at'],
    ];
}
$stmt->close();

// Localizar la respuesta asociada al trace_id y su pregunta anterior.
$assistant = null;
$like = '%' . $traceId . '%';
$stmtMsg = $db_connection->prepare("\n    SELECT id_, content, model_id, prompt_tokens, completion_tokens, latency_ms, created_at\n    FROM ChatMessages\n    WHERE session_id_=? AND user_id_=? AND role='assistant' AND meta LIKE ?\n    ORDER BY id_ DESC\n    LIMIT 1\n");
if ($stmtMsg) {
    $stmtMsg->bind_param('iis', $sessionId, $userId, $like);
    $stmtMsg->execute();
    $assistant = $stmtMsg->get_result()->fetch_assoc() ?: null;
    $stmtMsg->close();
}
$question = null;
if ($assistant) {
    $assistantId = (int)$assistant['id_'];

    // La memoria selectiva ya guarda la pareja exacta question_msg_id/answer_msg_id.
    // Es más fiable que tomar simplemente el último mensaje user (podría ser un archivo adjunto).
    $stmtQ = $db_connection->prepare("\n        SELECT q.id_, q.content, q.created_at\n        FROM SessionContextBlocks scb\n        JOIN ChatMessages q ON q.id_ = scb.question_msg_id\n        WHERE scb.session_id_=?\n          AND scb.answer_msg_id=?\n          AND q.user_id_=?\n        ORDER BY scb.id_ DESC\n        LIMIT 1\n    ");
    if ($stmtQ) {
        $stmtQ->bind_param('iii', $sessionId, $assistantId, $userId);
        $stmtQ->execute();
        $question = $stmtQ->get_result()->fetch_assoc() ?: null;
        $stmtQ->close();
    }

    // Fallback para respuestas antiguas o flujos que no generaron level_0.
    if (!$question) {
        $stmtQ = $db_connection->prepare("\n            SELECT id_, content, created_at\n            FROM ChatMessages\n            WHERE session_id_=? AND user_id_=? AND role='user' AND content_type='text' AND id_ < ?\n            ORDER BY id_ DESC\n            LIMIT 1\n        ");
        if ($stmtQ) {
            $stmtQ->bind_param('iii', $sessionId, $userId, $assistantId);
            $stmtQ->execute();
            $question = $stmtQ->get_result()->fetch_assoc() ?: null;
            $stmtQ->close();
        }
    }
}

if ($question && isset($question['content'])) $question['content'] = redactPrivateThinking((string)$question['content']);
if ($assistant && isset($assistant['content'])) $assistant['content'] = redactPrivateThinking((string)$assistant['content']);

$payload = [
    'trace_id' => $traceId,
    'session' => [
        'id' => (int)$session['id_'],
        'title' => (string)$session['title'],
        'project_id' => $session['project_id_'] !== null ? (int)$session['project_id_'] : null,
        'created_at' => (string)$session['created_at'],
    ],
    'question' => $question ? [
        'id' => (int)$question['id_'],
        'content' => (string)redactPrivateThinking((string)$question['content']),
        'created_at' => (string)$question['created_at'],
    ] : null,
    'answer' => $assistant ? [
        'id' => (int)$assistant['id_'],
        'content' => (string)redactPrivateThinking((string)$assistant['content']),
        'model_id' => $assistant['model_id'] !== null ? (string)$assistant['model_id'] : null,
        'prompt_tokens' => $assistant['prompt_tokens'] !== null ? (int)$assistant['prompt_tokens'] : null,
        'completion_tokens' => $assistant['completion_tokens'] !== null ? (int)$assistant['completion_tokens'] : null,
        'latency_ms' => $assistant['latency_ms'] !== null ? (int)$assistant['latency_ms'] : null,
        'created_at' => (string)$assistant['created_at'],
    ] : null,
    'events' => $events,
    'event_count' => count($events),
    'notice' => 'Telemetría operacional real de la aplicación. No contiene chain-of-thought privado del modelo.',
];

$filenameBase = 'proceso_respuesta_' . ($assistant ? (int)$assistant['id_'] : substr($traceId, 0, 8));
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.json"');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($format === 'txt') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.txt"');
    echo "PROCESO REAL DE LA RESPUESTA\n";
    echo "Sesión: #{$sessionId} · {$session['title']}\n";
    echo "Trace: {$traceId}\n";
    echo "Nota: telemetría operacional; no chain-of-thought privado.\n\n";
    if ($question) echo "PREGUNTA #{$question['id_']}\n{$question['content']}\n\n";
    if ($assistant) echo "RESPUESTA #{$assistant['id_']}\n{$assistant['content']}\n\n";
    echo "EVENTOS (" . count($events) . ")\n" . str_repeat('=', 72) . "\n";
    foreach ($events as $ev) {
        echo "\n#{$ev['id']} [{$ev['status']}] {$ev['title']}\n";
        echo "fase={$ev['phase']} key={$ev['event_key']}";
        if ($ev['model_id']) echo " model={$ev['model_id']}";
        if ($ev['duration_ms'] !== null) echo " duration={$ev['duration_ms']}ms";
        echo "\n{$ev['created_at']}\n";
        if ($ev['summary']) echo $ev['summary'] . "\n";
        if ($ev['details'] !== null) echo json_encode($ev['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
    exit;
}

function h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function prettyDetails($value): string {
    if ($value === null) return '';
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json === false ? (string)$value : $json;
}

$jsonUrl = '?trace_id=' . rawurlencode($traceId) . '&session_id=' . $sessionId . '&format=json';
$txtUrl = '?trace_id=' . rawurlencode($traceId) . '&session_id=' . $sessionId . '&format=txt';
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Proceso de respuesta <?= $assistant ? '#' . (int)$assistant['id_'] : h(substr($traceId,0,8)) ?></title>
<style>
:root{color-scheme:dark;--bg:#0d1117;--panel:#161b22;--panel2:#1c2128;--border:#30363d;--text:#f0f6fc;--muted:#8b949e;--accent:#58a6ff;--ok:#3fb950;--warn:#d29922;--danger:#f85149}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.55 Inter,system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:1120px;margin:0 auto;padding:24px}.top{display:flex;gap:18px;justify-content:space-between;align-items:flex-start;margin-bottom:18px}.eyebrow{color:var(--accent);font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em}h1{font-size:24px;margin:4px 0}.meta{color:var(--muted);font:12px ui-monospace,SFMono-Regular,Menlo,monospace}.actions{display:flex;gap:8px;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;padding:8px 11px;border:1px solid var(--border);border-radius:9px;color:var(--text);text-decoration:none;background:var(--panel2)}.btn:hover{border-color:var(--accent);color:var(--accent)}.notice{padding:10px 12px;border:1px solid #244d74;background:#0e2235;border-radius:10px;color:#b7d9ff;margin-bottom:16px}.qa{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:18px}.card{border:1px solid var(--border);border-radius:12px;background:var(--panel);padding:14px;min-width:0}.card h2{font-size:13px;margin:0 0 8px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}.qa pre{white-space:pre-wrap;overflow-wrap:anywhere;margin:0;font:13px/1.55 inherit}.event{display:grid;grid-template-columns:28px 1fr;gap:10px;padding:13px 4px;border-bottom:1px solid #21262d}.event:last-child{border-bottom:0}.icon{font-weight:900}.completed .icon{color:var(--ok)}.error .icon{color:var(--danger)}.waiting .icon{color:var(--warn)}.started .icon{color:var(--accent)}.eventtop{display:flex;align-items:center;gap:6px;flex-wrap:wrap}.title{font-weight:750}.pill{border:1px solid var(--border);border-radius:999px;padding:2px 7px;font-size:10px;color:var(--muted);font-family:ui-monospace,SFMono-Regular,Menlo,monospace}.summary{color:var(--muted);margin-top:4px;font-size:12px}details{margin-top:7px;border:1px solid var(--border);border-radius:8px;background:var(--panel2);overflow:hidden}summary{cursor:pointer;padding:7px 9px;color:var(--accent);font-size:11px;font-weight:700}details pre{margin:0;border-top:1px solid var(--border);padding:10px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;font:11px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;background:#090c10}.empty{color:var(--muted);padding:20px}@media(max-width:760px){.wrap{padding:14px}.top{flex-direction:column}.qa{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div>
      <div class="eyebrow">Proceso real de la respuesta</div>
      <h1><?= $assistant ? 'Respuesta #' . (int)$assistant['id_'] : 'Actividad del agente' ?></h1>
      <div class="meta">Sesión #<?= $sessionId ?> · <?= h((string)$session['title']) ?><br>trace <?= h($traceId) ?></div>
    </div>
    <div class="actions">
      <a class="btn" href="<?= h($txtUrl) ?>">Descargar TXT</a>
      <a class="btn" href="<?= h($jsonUrl) ?>">Descargar JSON</a>
      <button class="btn" type="button" onclick="window.print()">Imprimir / PDF</button>
    </div>
  </div>

  <div class="notice">Se muestran datos reales que la aplicación consultó, construyó, envió y recibió. No se muestra ni se reconstruye razonamiento privado interno del modelo.</div>

  <?php if ($question || $assistant): ?>
  <div class="qa">
    <section class="card">
      <h2>Pregunta<?= $question ? ' #' . (int)$question['id_'] : '' ?></h2>
      <pre><?= h($question ? (string)$question['content'] : 'No localizada') ?></pre>
    </section>
    <section class="card">
      <h2>Respuesta<?= $assistant ? ' #' . (int)$assistant['id_'] : '' ?></h2>
      <pre><?= h($assistant ? (string)$assistant['content'] : 'No localizada') ?></pre>
    </section>
  </div>
  <?php endif; ?>

  <section class="card">
    <h2>Eventos del pipeline · <?= count($events) ?></h2>
    <?php if (!$events): ?>
      <div class="empty">No hay eventos guardados para esta traza.</div>
    <?php else: foreach ($events as $ev): ?>
      <article class="event <?= h($ev['status']) ?>">
        <div class="icon"><?= $ev['status']==='completed'?'✓':($ev['status']==='error'?'×':($ev['status']==='waiting'?'◷':'•')) ?></div>
        <div>
          <div class="eventtop">
            <span class="title"><?= h($ev['title']) ?></span>
            <span class="pill"><?= h(strtoupper($ev['phase'])) ?></span>
            <?php if ($ev['model_id']): ?><span class="pill"><?= h($ev['model_id']) ?></span><?php endif; ?>
            <?php if ($ev['duration_ms'] !== null): ?><span class="pill"><?= (int)$ev['duration_ms'] ?> ms</span><?php endif; ?>
          </div>
          <?php if ($ev['summary']): ?><div class="summary"><?= h($ev['summary']) ?></div><?php endif; ?>
          <?php if ($ev['details'] !== null): ?>
            <details>
              <summary>Ver datos reales</summary>
              <pre><?= h(prettyDetails($ev['details'])) ?></pre>
            </details>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; endif; ?>
  </section>
</div>
</body>
</html>
