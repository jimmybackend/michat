<?php
// chat2_sessions.php
// RESP JSON: { ok, sessions:[ { id, user_id, title, model_id, provider, status, archived, created_at, updated_at } ] }

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

function jexit(array $arr, int $code = 200): void {
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

function esc_like(string $s): string {
  // escapa LIKE para MySQL
  return str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $s);
}

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
      $sub = ($sub === '' ? '' : '/' . trim($sub, '/'));
      $try = rtrim($base, '/') . $sub . '/' . $filename;
      if (is_file($try)) return $try;
    }
  }
  return null;
}

/* ============================
   Cargar bootstrap / conexión
   ============================ */
try {
  $bootstrap = __DIR__ . '/app_bootstrap.php';
  if (!is_file($bootstrap)) $bootstrap = __DIR__ . '/../app_bootstrap.php';

  if (!is_file($bootstrap)) {
    $bases = resolve_root_candidates();
    $bootstrap = find_file_in_candidates('app_bootstrap.php', $bases, ['', 'public_html', 'api', 'app', 'www']);
  }

  if (!$bootstrap || !is_file($bootstrap)) {
    throw new RuntimeException('app_bootstrap.php no encontrado.');
  }

  require_once $bootstrap;

} catch (Throwable $e) {
  jexit(['ok' => false, 'error' => 'bootstrap: ' . $e->getMessage()], 500);
}

/* ============================
   Detectar conexión (mysqli o PDO)
   ============================ */
$isMysqli = (isset($db_connection) && ($db_connection instanceof mysqli));
$isPdo    = (isset($pdo) && ($pdo instanceof PDO));

if (!$isMysqli && !$isPdo) {
  jexit(['ok' => false, 'error' => 'DB no disponible (no hay $db_connection mysqli ni $pdo PDO)'], 500);
}


/* ============================
   user_id
   ============================ */
$user_id = 0;

// 1. Intentar desde $_SESSION['user_id'] (por si ya existe)
if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
  $user_id = (int)$_SESSION['user_id'];
}

// 2. Si no, intentar obtenerlo desde $_SESSION['usuario'] (nombre de usuario)
if (!$user_id && isset($_SESSION['usuario']) && !empty($_SESSION['usuario'])) {
  $username = (string)$_SESSION['usuario'];
  
  // Buscar el ID en la tabla de usuarios (ajusta el nombre de tabla y columna)
  // Suponiendo que tienes una tabla 'usuarios' con columnas 'id' y 'username'
  if ($isMysqli) {
    $stmt = $db_connection->prepare("SELECT id FROM usuarios WHERE username = ?");
    if ($stmt) {
      $stmt->bind_param("s", $username);
      $stmt->execute();
      $res = $stmt->get_result();
      if ($row = $res->fetch_assoc()) {
        $user_id = (int)$row['id'];
      }
      $stmt->close();
    }
  } elseif ($isPdo) {
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE username = ?");
    $stmt->execute([$username]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $user_id = (int)$row['id'];
    }
  }
}

// 3. Fallback: permitir GET (por si el frontend lo envía)
if (!$user_id && isset($_GET['user_id']) && is_numeric($_GET['user_id'])) {
  $user_id = (int)$_GET['user_id'];
}

// 4. Si aún no hay user_id, devolver error
if (!$user_id) {
  jexit(['ok' => false, 'error' => 'No hay sesión (user_id) y no se pudo obtener desde el nombre de usuario'], 401);
}


/* ============================
   Parámetros
   ============================ */
$q        = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$archived = (isset($_GET['archived']) && (string)$_GET['archived'] === '1');

/* ============================
   SQL base
   ============================ */
$whereParts = [];
$params     = [];

$whereParts[] = 'user_id_ = ?';
$params[]     = $user_id;

if ($archived) {
  $whereParts[] = "status = 'archived'";
} else {
  $whereParts[] = "status <> 'archived'";
}

if ($q !== '') {
  if (ctype_digit($q)) {
    $whereParts[] = "(id_ = ? OR title LIKE CONCAT('%', ?, '%'))";
    $params[] = (int)$q;
    $params[] = esc_like($q);
  } else {
    $whereParts[] = "(title LIKE CONCAT('%', ?, '%'))";
    $params[] = esc_like($q);
  }
}

$whereSql = 'WHERE ' . implode(' AND ', $whereParts);

$sql = "
SELECT id_, user_id_, project_id_, title, model_id, provider, status, created_at, updated_at
FROM ChatSessions
$whereSql
ORDER BY updated_at DESC
LIMIT 200
";

/* ============================
   Ejecutar (mysqli)
   ============================ */
if ($isMysqli) {
  /** @var mysqli $db_connection */
  $stmt = $db_connection->prepare($sql);
  if (!$stmt) jexit(['ok' => false, 'error' => 'Error preparando SQL: ' . $db_connection->error], 500);

  // bind dinámico
  $types = '';
  foreach ($params as $p) {
    $types .= is_int($p) ? 'i' : 's';
  }
  if ($types !== '') {
    $stmt->bind_param($types, ...$params);
  }

  if (!$stmt->execute()) {
    $e = $stmt->error; $stmt->close();
    jexit(['ok' => false, 'error' => 'Error ejecutando SQL: ' . $e], 500);
  }

  $res = $stmt->get_result();
  $sessions = [];
while ($row = $res->fetch_assoc()) {
    $sessions[] = [
        'id'         => (int)$row['id_'],
        'user_id'    => (int)$row['user_id_'],
        'project_id' => $row['project_id_'] !== null ? (int)$row['project_id_'] : null,
        'title'      => (string)$row['title'],
        'model_id'   => (string)$row['model_id'],
        'provider'   => $row['provider'] !== null ? (string)$row['provider'] : null,
        'status'     => (string)$row['status'],
        'archived'   => ((string)$row['status'] === 'archived'),
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
    ];
}
  $stmt->close();

  jexit(['ok' => true, 'sessions' => $sessions]);
}

/* ============================
   Ejecutar (PDO)
   ============================ */
try {
  /** @var PDO $pdo */
  // Re-armar con named placeholders (más claro)
  $whereParts = [];
  $bind = [':user_id' => $user_id];

  $whereParts[] = 'user_id_ = :user_id';

  if ($archived) {
    $whereParts[] = "status = 'archived'";
  } else {
    $whereParts[] = "status <> 'archived'";
  }

  if ($q !== '') {
    if (ctype_digit($q)) {
      $whereParts[] = "(id_ = :idq OR title LIKE CONCAT('%', :q, '%'))";
      $bind[':idq'] = (int)$q;
      $bind[':q']   = esc_like($q);
    } else {
      $whereParts[] = "(title LIKE CONCAT('%', :q, '%'))";
      $bind[':q']   = esc_like($q);
    }
  }

  $whereSql = 'WHERE ' . implode(' AND ', $whereParts);

$sqlPdo = "
SELECT id_, user_id_, project_id_, title, model_id, provider, status, created_at, updated_at
FROM ChatSessions
$whereSql
ORDER BY updated_at DESC
LIMIT 200
";

  $stmt = $pdo->prepare($sqlPdo);
  $stmt->execute($bind);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $sessions = [];
foreach ($rows as $row) {
    $sessions[] = [
        'id'         => (int)$row['id_'],
        'user_id'    => (int)$row['user_id_'],
        'project_id' => $row['project_id_'] !== null ? (int)$row['project_id_'] : null,
        'title'      => (string)$row['title'],
        'model_id'   => (string)$row['model_id'],
        'provider'   => $row['provider'] !== null ? (string)$row['provider'] : null,
        'status'     => (string)$row['status'],
        'archived'   => ((string)$row['status'] === 'archived'),
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
    ];
}

  jexit(['ok' => true, 'sessions' => $sessions]);

} catch (Throwable $e) {
  jexit(['ok' => false, 'error' => 'PDO error: ' . $e->getMessage()], 500);
}
