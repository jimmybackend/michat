<?php
// project_sources.php
// Gestión de fuentes (archivos S3) vinculados a un proyecto
// Acciones: list, add, remove, reindex
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

function jexit($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
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

// ====================================================================
// FUNCIONES AUXILIARES PARA GENERAR URLs (extraídas de bloque_archivos.php)
// ====================================================================
function build_file_s3_key(string $ruta, string $encriptado): string {
    $ruta = rtrim(str_replace('\\', '/', trim($ruta)), '/') . '/';
    $enc  = ltrim(str_replace('\\', '/', trim($encriptado)), '/');
    if ($enc === '') return '';
    if (strpos($enc, $ruta) === 0) return $enc;
    return $ruta . $enc;
}

function ext_de($nombre){ return strtolower(pathinfo($nombre, PATHINFO_EXTENSION)); }

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
    $ext = ext_de($nombre);

    // Extensiones editables (igual que en $txtEditExt)
    $editExt = ['txt','srt','vtt','md','html','css','js','php','py','json','csv','sql','jas'];

    $acciones = [];
    $acciones['edit']   = in_array($ext, $editExt) ? "editor.php?archivo={$keyEncoded}" : null;
    $acciones['view']   = "ver_archivo.php?archivo={$keyEncoded}";
    $acciones['download'] = "descargar.php?archivo={$keyEncoded}";

    return $acciones;
}
// ====================================================================

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

$action = isset($_POST['action']) ? trim($_POST['action']) : (isset($_GET['action']) ? trim($_GET['action']) : 'list');

/* ============================
LISTAR fuentes de un proyecto
============================ */
if ($action === 'list') {
    $project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
    if ($project_id <= 0) jexit(['ok'=>false,'error'=>'project_id inválido'], 400);
    
    // Verificar que el proyecto pertenezca al usuario
    $sqlCheck = "SELECT id_ FROM Projects WHERE id_ = ? AND user_id_ = ?";
    $stmtCheck = $db_connection->prepare($sqlCheck);
    if (!$stmtCheck) jexit(['ok'=>false,'error'=>'Error preparando: '.$db_connection->error], 500);
    $stmtCheck->bind_param('ii', $project_id, $user_id);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();
    if ($resCheck->num_rows === 0) {
        $stmtCheck->close();
        jexit(['ok'=>false,'error'=>'Proyecto no encontrado'], 404);
    }
    $stmtCheck->close();
    
    // MODIFICADO: Ahora hacemos LEFT JOIN con FileS3 para obtener Ruta, Encriptado, AccessType
    $sql = "SELECT ps.id_, ps.project_id_, ps.files3_id_, ps.s3_key, ps.filename, 
                   ps.mime_type, ps.size_bytes, ps.language, ps.sha256, ps.status, 
                   ps.indexed_at, ps.created_at,
                   f.Ruta, f.Encriptado, f.AccessType
            FROM ProjectSources ps
            LEFT JOIN FileS3 f ON f.id_ = ps.files3_id_
            WHERE ps.project_id_ = ?
            ORDER BY ps.filename ASC";
    $stmt = $db_connection->prepare($sql);
    if (!$stmt) jexit(['ok'=>false,'error'=>'Error preparando: '.$db_connection->error], 500);
    $stmt->bind_param('i', $project_id);
    if (!$stmt->execute()) {
        $e = $stmt->error; $stmt->close();
        jexit(['ok'=>false,'error'=>'Error ejecutando: '.$e], 500);
    }
    $res = $stmt->get_result();
    $sources = [];
    while ($row = $res->fetch_assoc()) {
        // MODIFICADO: obtener URLs de acciones
        $acciones = obtener_acciones_archivo(
            $row['Ruta'] ?? '',                // ruta base
            $row['Encriptado'] ?? '',          // nombre encriptado
            $row['filename'],                  // nombre original
            $row['AccessType'] ?? 'normal'     // AccessType
        );
        
        $sources[] = [
            'id'            => (int)$row['id_'],
            'project_id'    => (int)$row['project_id_'],
            'files3_id'     => $row['files3_id_'] ? (int)$row['files3_id_'] : null,
            's3_key'        => (string)$row['s3_key'],
            'filename'      => (string)$row['filename'],
            'mime_type'     => (string)$row['mime_type'],
            'size_bytes'    => (int)$row['size_bytes'],
            'language'      => (string)$row['language'],
            'sha256'        => (string)$row['sha256'],
            'status'        => (string)$row['status'],
            'indexed_at'    => (string)$row['indexed_at'],
            'created_at'    => (string)$row['created_at'],
            // NUEVOS: URLs para acciones
            'edit_url'      => $acciones['edit'],
            'view_url'      => $acciones['view'],
            'download_url'  => $acciones['download'],
        ];
    }
    $stmt->close();
    jexit(['ok' => true, 'sources' => $sources]);
}

/* ============================
AGREGAR fuentes al proyecto
============================ */
if ($action === 'add') {
    $project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
    if ($project_id <= 0) jexit(['ok'=>false,'error'=>'project_id inválido'], 400);
    
    // Verificar proyecto
    $sqlCheck = "SELECT id_, root_prefix FROM Projects WHERE id_ = ? AND user_id_ = ?";
    $stmtCheck = $db_connection->prepare($sqlCheck);
    if (!$stmtCheck) jexit(['ok'=>false,'error'=>'Error preparando: '.$db_connection->error], 500);
    $stmtCheck->bind_param('ii', $project_id, $user_id);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();
    if ($resCheck->num_rows === 0) {
        $stmtCheck->close();
        jexit(['ok'=>false,'error'=>'Proyecto no encontrado'], 404);
    }
    $project = $resCheck->fetch_assoc();
    $stmtCheck->close();
    
    $s3_keys = isset($_POST['s3_keys']) ? $_POST['s3_keys'] : [];
    if (!is_array($s3_keys) || empty($s3_keys)) {
        jexit(['ok'=>false,'error'=>'No se proporcionaron archivos'], 400);
    }
    
    $added = 0;
    foreach ($s3_keys as $s3_key) {
        $s3_key = trim($s3_key);
        if ($s3_key === '') continue;
        
        // Extraer filename de la key
        $filename = basename($s3_key);
        
        // Detectar lenguaje por extensión
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $language_map = [
            'php' => 'php', 'js' => 'javascript', 'ts' => 'typescript',
            'py' => 'python', 'java' => 'java', 'cpp' => 'cpp', 'c' => 'c',
            'cs' => 'csharp', 'go' => 'go', 'rs' => 'rust', 'rb' => 'ruby',
            'html' => 'html', 'css' => 'css', 'scss' => 'scss',
            'json' => 'json', 'xml' => 'xml', 'yaml' => 'yaml', 'yml' => 'yaml',
            'md' => 'markdown', 'txt' => 'text', 'sql' => 'sql',
            'sh' => 'shell', 'bash' => 'shell',
        ];
        $language = $language_map[$ext] ?? 'unknown';
        
        // Insertar en ProjectSources
        $sql = "INSERT INTO ProjectSources (project_id_, files3_id_, s3_key, filename, mime_type, size_bytes, language, sha256, status)
                VALUES (?, NULL, ?, ?, NULL, 0, ?, NULL, 'pending')";
        $stmt = $db_connection->prepare($sql);
        if (!$stmt) continue;
        $stmt->bind_param('isss', $project_id, $s3_key, $filename, $language);
        if ($stmt->execute()) {
            $added++;
        }
        $stmt->close();
    }
    
    jexit(['ok' => true, 'added' => $added, 'message' => "$added archivos agregados"]);
}

/* ============================
ELIMINAR fuente del proyecto
============================ */
if ($action === 'remove') {
    $source_id = isset($_POST['source_id']) ? (int)$_POST['source_id'] : 0;
    if ($source_id <= 0) jexit(['ok'=>false,'error'=>'source_id inválido'], 400);
    
    // Verificar que la fuente pertenezca a un proyecto del usuario
    $sqlCheck = "SELECT ps.id_ FROM ProjectSources ps
                 JOIN Projects p ON p.id_ = ps.project_id_
                 WHERE ps.id_ = ? AND p.user_id_ = ?";
    $stmtCheck = $db_connection->prepare($sqlCheck);
    if (!$stmtCheck) jexit(['ok'=>false,'error'=>'Error preparando: '.$db_connection->error], 500);
    $stmtCheck->bind_param('ii', $source_id, $user_id);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();
    if ($resCheck->num_rows === 0) {
        $stmtCheck->close();
        jexit(['ok'=>false,'error'=>'Fuente no encontrada'], 404);
    }
    $stmtCheck->close();
    
    // Eliminar fuente (cascada eliminará chunks y embeddings)
    $sql = "DELETE FROM ProjectSources WHERE id_ = ?";
    $stmt = $db_connection->prepare($sql);
    if (!$stmt) jexit(['ok'=>false,'error'=>'Error preparando: '.$db_connection->error], 500);
    $stmt->bind_param('i', $source_id);
    if (!$stmt->execute()) {
        $e = $stmt->error; $stmt->close();
        jexit(['ok'=>false,'error'=>'Error eliminando: '.$e], 500);
    }
    $stmt->close();
    
    jexit(['ok' => true, 'message' => 'Fuente eliminada']);
}

/* ============================
REINDEXAR fuente (marcar como pending para que el worker la reprocese)
============================ */
if ($action === 'reindex') {
    $source_id = isset($_POST['source_id']) ? (int)$_POST['source_id'] : 0;
    if ($source_id <= 0) jexit(['ok'=>false,'error'=>'source_id inválido'], 400);
    
    $sql = "UPDATE ProjectSources SET status = 'pending', indexed_at = NULL WHERE id_ = ?";
    $stmt = $db_connection->prepare($sql);
    if (!$stmt) jexit(['ok'=>false,'error'=>'Error preparando: '.$db_connection->error], 500);
    $stmt->bind_param('i', $source_id);
    if (!$stmt->execute()) {
        $e = $stmt->error; $stmt->close();
        jexit(['ok'=>false,'error'=>'Error actualizando: '.$e], 500);
    }
    $stmt->close();
    
    jexit(['ok' => true, 'message' => 'Fuente marcada para reindexar']);
}

jexit(['ok' => false, 'error' => 'Acción no válida'], 400);