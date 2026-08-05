<?php
// chat_notify_poll.php — devuelve videos completados recientes para el usuario
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

function jexit($arr, $code=200){ http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

/* ============================
   Resolver rutas (bootstrap)
   ============================ */
function resolve_root_candidates(): array {
  $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? (string)$_SERVER['DOCUMENT_ROOT'] : '';
  $rootFromDoc = $docRoot !== '' ? realpath($docRoot . '/..') : false;

  $candidates = [];
  foreach ([
    $rootFromDoc,
    realpath(__DIR__ . '/../../'),
    realpath(__DIR__ . '/../..'),
    realpath(__DIR__ . '/../../../'),
    realpath(__DIR__ . '/../'),
    realpath(__DIR__),
  ] as $p) {
    if ($p && is_dir($p)) $candidates[$p] = true;
  }
  return array_keys($candidates);
}

function find_file_in_candidates(string $filename, array $bases, array $subfolders): ?string {
  $filename = ltrim($filename, '/');
  foreach ($bases as $base) {
    foreach ($subfolders as $sub) {
      $sub = ($sub === '' ? '' : '/' . trim($sub,'/'));
      $try = rtrim($base,'/') . $sub . '/' . $filename;
      if (is_file($try)) return $try;
    }
  }
  return null;
}

/* ============================
   Cargar bootstrap (vendor + Config + db)
   ============================ */
try {
  $bootstrap = __DIR__ . '/app_bootstrap.php';
  if (!is_file($bootstrap)) $bootstrap = __DIR__ . '/../app_bootstrap.php';

  if (!is_file($bootstrap)) {
    $bases = resolve_root_candidates();
    $bootstrap = find_file_in_candidates('app_bootstrap.php', $bases, ['', 'public_html', 'api', 'app', 'www']);
  }

  if (!$bootstrap || !is_file($bootstrap)) throw new RuntimeException('app_bootstrap.php no encontrado.');
  require_once $bootstrap;

} catch (Throwable $e) {
  jexit(['ok'=>false,'error'=>'bootstrap: '.$e->getMessage()], 500);
}

// ===== Validar DB =====
if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
  jexit(['ok'=>false,'error'=>'DB no disponible (bootstrap)'], 500);
}

/* ============================
   user_id
   ============================ */
$user_id = 0;
if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) $user_id = (int)$_SESSION['user_id'];

// (Opcional) permitir override por GET si tu app lo usa
if (!$user_id && isset($_GET['user_id']) && is_numeric($_GET['user_id'])) $user_id = (int)$_GET['user_id'];

if (!$user_id) {
  // En vez de usar "1" silencioso, devolvemos 401 (más seguro y evita notificar a otro usuario)
  jexit(['ok'=>false,'error'=>'No hay sesión (user_id)'], 401);
}

$last_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;

// Si last_id=0, devolvemos últimas 5 y que el cliente filtre lo visto.
if ($last_id > 0) {
  $sql = "SELECT m.id_ AS id, m.session_id_ AS session_id, m.content AS content, m.s3_key, m.mime_type, m.meta, s.title
          FROM ChatMessages m
          JOIN ChatSessions s ON s.id_ = m.session_id_
          WHERE m.user_id_ = ? AND m.role='assistant' AND m.content_type='video'
            AND m.s3_key IS NOT NULL
            AND (m.meta LIKE '%\"status\":\"completed\"%')
            AND m.id_ > ?
          ORDER BY m.id_ ASC
          LIMIT 50";
  $stmt = $db_connection->prepare($sql);
  if (!$stmt) jexit(['ok'=>false,'error'=>'SQL prepare: '.$db_connection->error], 500);
  $stmt->bind_param('ii', $user_id, $last_id);
} else {
  $sql = "SELECT m.id_ AS id, m.session_id_ AS session_id, m.content AS content, m.s3_key, m.mime_type, m.meta, s.title
          FROM ChatMessages m
          JOIN ChatSessions s ON s.id_ = m.session_id_
          WHERE m.user_id_ = ? AND m.role='assistant' AND m.content_type='video'
            AND m.s3_key IS NOT NULL
            AND (m.meta LIKE '%\"status\":\"completed\"%')
          ORDER BY m.id_ DESC
          LIMIT 5";
  $stmt = $db_connection->prepare($sql);
  if (!$stmt) jexit(['ok'=>false,'error'=>'SQL prepare: '.$db_connection->error], 500);
  $stmt->bind_param('i', $user_id);
}

if (!$stmt->execute()){
  $e = $stmt->error;
  $stmt->close();
  jexit(['ok'=>false,'error'=>$e], 500);
}

$res = $stmt->get_result();
$items = [];
while($row = $res->fetch_assoc()){
  $items[] = [
    'id'         => (int)$row['id'],
    'session_id' => (int)$row['session_id'],
    'title'      => (string)($row['title'] ?: ('Sesión #'.$row['session_id'])),
    's3_key'     => (string)$row['s3_key'],
    'mime_type'  => (string)($row['mime_type'] ?: 'video/mp4'),
  ];
}
$stmt->close();

jexit(['ok'=>true,'items'=>$items]);
