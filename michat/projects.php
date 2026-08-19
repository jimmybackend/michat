<?php
// projects.php
// CRUD de proyectos para el chat con IA
// Acciones: list, create, update, delete
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

/* ============================
Helpers
============================ */
function jexit($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function next_id(mysqli $db, $table, $col) {
    $table = preg_replace('/[^A-Za-z0-9_]+/','',$table);
    $col   = preg_replace('/[^A-Za-z0-9_]+/','',$col);
    $rs = $db->query("SELECT COALESCE(MAX($col), 0) + 1 AS nxt FROM $table");
    if (!$rs) return 1;
    $row = $rs->fetch_assoc();
    return (int)($row['nxt'] ?? 1);
}

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
Bootstrap
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

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    jexit(['ok'=>false,'error'=>'DB no disponible'], 500);
}

/* ============================
User ID
============================ */
$user_id = 0;
if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
    $user_id = (int)$_SESSION['user_id'];
}
if (!$user_id && isset($_POST['user_id']) && is_numeric($_POST['user_id'])) {
    $user_id = (int)$_POST['user_id'];
}
if (!$user_id && isset($_GET['user_id']) && is_numeric($_GET['user_id'])) {
    $user_id = (int)$_GET['user_id'];
}
if (!$user_id) $user_id = 1;

/* ============================
Acción
============================ */
$action = isset($_POST['action']) ? trim($_POST['action']) : (isset($_GET['action']) ? trim($_GET['action']) : 'list');

/* ============================
LISTAR proyectos
============================ */
if ($action === 'list') {
    $sql = "SELECT id_, user_id_, name, slug, description, language, framework, root_prefix, status, meta, created_at, updated_at
            FROM Projects
            WHERE user_id_ = ? AND status <> 'deleted'
            ORDER BY updated_at DESC";
    $stmt = $db_connection->prepare($sql);
    if (!$stmt) jexit(['ok'=>false,'error'=>'Error preparando: '.$db_connection->error], 500);
    $stmt->bind_param('i', $user_id);
    if (!$stmt->execute()) {
        $e = $stmt->error; $stmt->close();
        jexit(['ok'=>false,'error'=>'Error ejecutando: '.$e], 500);
    }
    $res = $stmt->get_result();
    $projects = [];
    while ($row = $res->fetch_assoc()) {
        $projects[] = [
            'id'            => (int)$row['id_'],
            'user_id'       => (int)$row['user_id_'],
            'name'          => (string)$row['name'],
            'slug'          => (string)$row['slug'],
            'description'   => (string)$row['description'],
            'language'      => (string)$row['language'],
            'framework'     => (string)$row['framework'],
            'root_prefix'   => (string)$row['root_prefix'],
            'status'        => (string)$row['status'],
            'meta'          => $row['meta'] ? json_decode($row['meta'], true) : null,
            'created_at'    => (string)$row['created_at'],
            'updated_at'    => (string)$row['updated_at'],
        ];
    }
    $stmt->close();
    jexit(['ok' => true, 'projects' => $projects]);
}

/* ============================
CREAR proyecto
============================ */
if ($action === 'create') {
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $slug = isset($_POST['slug']) ? trim($_POST['slug']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $language = isset($_POST['language']) ? trim($_POST['language']) : '';
    $framework = isset($_POST['framework']) ? trim($_POST['framework']) : '';

    if ($name === '' || $slug === '') {
        jexit(['ok'=>false,'error'=>'Faltan campos obligatorios: name, slug'], 400);
    }

    // Validar slug (solo letras, números, guiones)
    if (!preg_match('/^[a-z0-9-]+$/i', $slug)) {
        jexit(['ok'=>false,'error'=>'El slug solo puede contener letras, números y guiones'], 400);
    }

    // Verificar que el slug no exista ya para este usuario
    $sqlCheck = "SELECT id_ FROM Projects WHERE user_id_ = ? AND slug = ?";
    $stmtCheck = $db_connection->prepare($sqlCheck);
    if (!$stmtCheck) jexit(['ok'=>false,'error'=>'Error preparando validación: '.$db_connection->error], 500);
    $stmtCheck->bind_param('is', $user_id, $slug);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();
    if ($resCheck->num_rows > 0) {
        $stmtCheck->close();
        jexit(['ok'=>false,'error'=>'Ya existe un proyecto con ese slug'], 400);
    }
    $stmtCheck->close();

    // ✅ SEGURIDAD / AISLAMIENTO MULTI-USUARIO: el root_prefix (la ruta base en S3
    // donde se guardan TODOS los archivos del proyecto) se calcula SIEMPRE en el
    // servidor a partir del user_id autenticado. Nunca se usa el valor que mande
    // el cliente: si dos usuarios crean un proyecto con el mismo slug el mismo día,
    // un root_prefix basado solo en fecha+slug produciría la MISMA ruta de S3 para
    // ambos, y sus archivos se sobrescribirían entre sí. Prefijar con el user_id
    // hace que las rutas de cada usuario nunca puedan colisionar.
    // ID primero: la ruta canónica del proyecto depende del project_id.
    $id_ = next_id($db_connection, 'Projects', 'id_');
    $root_prefix = 'Data/Chat/Uploads/' . $user_id . '/' . $id_ . '/';

    $meta_json = isset($_POST['meta']) ? trim($_POST['meta']) : null;
    
    $sql = "INSERT INTO Projects (id_, user_id_, name, slug, description, language, framework, root_prefix, status, meta)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)";
    $stmt = $db_connection->prepare($sql);
    if (!$stmt) jexit(['ok'=>false,'error'=>'Error preparando INSERT: '.$db_connection->error], 500);
    
    // Nota: 's' al final es para el campo meta (string/json)
    $stmt->bind_param('iisssssss', $id_, $user_id, $name, $slug, $description, $language, $framework, $root_prefix, $meta_json);
    if (!$stmt->execute()) {
        $e = $stmt->error; $stmt->close();
        jexit(['ok'=>false,'error'=>'Error insertando: '.$e], 500);
    }
    $stmt->close();
    
    jexit([
        'ok' => true,
        'id' => $id_,
        'message' => 'Proyecto creado correctamente'
    ]);
}

/* ============================
ACTUALIZAR proyecto
============================ */
if ($action === 'update') {
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    if ($project_id <= 0) jexit(['ok'=>false,'error'=>'project_id inválido'], 400);
    
    // Verificar que el proyecto pertenezca al usuario
    $sqlCheck = "SELECT id_ FROM Projects WHERE id_ = ? AND user_id_ = ?";
    $stmtCheck = $db_connection->prepare($sqlCheck);
    if (!$stmtCheck) jexit(['ok'=>false,'error'=>'Error preparando validación: '.$db_connection->error], 500);
    $stmtCheck->bind_param('ii', $project_id, $user_id);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();
    if ($resCheck->num_rows === 0) {
        $stmtCheck->close();
        jexit(['ok'=>false,'error'=>'Proyecto no encontrado o no tienes permisos'], 404);
    }
    $stmtCheck->close();
    
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $slug = isset($_POST['slug']) ? trim($_POST['slug']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $language = isset($_POST['language']) ? trim($_POST['language']) : '';
    $framework = isset($_POST['framework']) ? trim($_POST['framework']) : '';

    if ($name === '' || $slug === '') {
        jexit(['ok'=>false,'error'=>'Faltan campos obligatorios'], 400);
    }

    // ✅ El root_prefix es inmutable después de creado: los archivos ya subidos
    // (ProjectSources.s3_key) fueron calculados con el prefijo original, y ese
    // prefijo ya incluye el user_id (aislamiento entre usuarios). Cambiarlo aquí
    // dejaría huérfanos los archivos existentes y, si se aceptara un valor del
    // cliente, reabriría el riesgo de colisión de rutas entre usuarios.
    $meta_json = isset($_POST['meta']) ? trim($_POST['meta']) : null;

    $sql = "UPDATE Projects SET name = ?, slug = ?, description = ?, language = ?, framework = ?, meta = ?
            WHERE id_ = ? AND user_id_ = ?";
    $stmt = $db_connection->prepare($sql);
    if (!$stmt) jexit(['ok'=>false,'error'=>'Error preparando UPDATE: '.$db_connection->error], 500);

    // Nota: 's' antes de los últimos dos 'i' es para el campo meta
    $stmt->bind_param('ssssssii', $name, $slug, $description, $language, $framework, $meta_json, $project_id, $user_id);
    if (!$stmt->execute()) {
        $e = $stmt->error; $stmt->close();
        jexit(['ok'=>false,'error'=>'Error actualizando: '.$e], 500);
    }
    $stmt->close();
    
    jexit(['ok' => true, 'message' => 'Proyecto actualizado']);
}

/* ============================
ELIMINAR proyecto (soft delete)
============================ */
if ($action === 'delete') {
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    if ($project_id <= 0) jexit(['ok'=>false,'error'=>'project_id inválido'], 400);
    
    $sql = "UPDATE Projects SET status = 'deleted' WHERE id_ = ? AND user_id_ = ?";
    $stmt = $db_connection->prepare($sql);
    if (!$stmt) jexit(['ok'=>false,'error'=>'Error preparando DELETE: '.$db_connection->error], 500);
    $stmt->bind_param('ii', $project_id, $user_id);
    if (!$stmt->execute()) {
        $e = $stmt->error; $stmt->close();
        jexit(['ok'=>false,'error'=>'Error eliminando: '.$e], 500);
    }
    $stmt->close();
    
    jexit(['ok' => true, 'message' => 'Proyecto eliminado']);
}

jexit(['ok' => false, 'error' => 'Acción no válida'], 400);