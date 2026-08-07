<?php
// session_attachments.php
// Gestión de adjuntos de sesión (archivos vinculados a una sesión de chat)
// Acciones: list, add, remove, reindex
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

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

// ====================================================================
// FUNCIONES AUXILIARES PARA GENERAR URLs
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
    if ($accessType === 'secure') {
        return ['edit' => null, 'view' => null, 'download' => null];
    }
    $s3key = build_file_s3_key($ruta, $encriptado);
    if (empty($s3key)) {
        return ['edit' => null, 'view' => null, 'download' => null];
    }
    $keyEncoded = urlencode($s3key);
    $ext = ext_de($nombre);
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
LISTAR adjuntos de una sesión
============================ */
if ($action === 'list') {
    $session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
    if ($session_id <= 0) {
        // Si no hay session_id, listar adjuntos sin sesión (huérfanos o del usuario)
        $sql = "SELECT sa.id_, sa.session_id, sa.files3_id, sa.s3_key, sa.filename, 
                       sa.mime_type, sa.size_bytes, sa.status, sa.created_at,
                       f.Ruta, f.Encriptado, f.AccessType
                FROM SessionAttachments sa
                LEFT JOIN FileS3 f ON f.id_ = sa.files3_id
                WHERE sa.user_id = ?
                ORDER BY sa.created_at DESC";
        $stmt = $db_connection->prepare($sql);
        if (!$stmt) jexit(['ok'=>false,'error'=>'Error preparando: '.$db_connection->error], 500);
        $stmt->bind_param('i', $user_id);
    } else {
        $sql = "SELECT sa.id_, sa.session_id, sa.files3_id, sa.s3_key, sa.filename, 
                       sa.mime_type, sa.size_bytes, sa.status, sa.created_at,
                       f.Ruta, f.Encriptado, f.AccessType
                FROM SessionAttachments sa
                LEFT JOIN FileS3 f ON f.id_ = sa.files3_id
                WHERE sa.session_id = ? AND sa.user_id = ?
                ORDER BY sa.created_at DESC";
        $stmt = $db_connection->prepare($sql);
        if (!$stmt) jexit(['ok'=>false,'error'=>'Error preparando: '.$db_connection->error], 500);
        $stmt->bind_param('ii', $session_id, $user_id);
    }
    
    if (!$stmt->execute()) {
        $e = $stmt->error; $stmt->close();
        jexit(['ok'=>false,'error'=>'Error ejecutando: '.$e], 500);
    }
    $res = $stmt->get_result();
    $attachments = [];
    while ($row = $res->fetch_assoc()) {
        $acciones = obtener_acciones_archivo(
            $row['Ruta'] ?? '',
            $row['Encriptado'] ?? '',
            $row['filename'],
            $row['AccessType'] ?? 'normal'
        );
        
        $attachments[] = [
            'id'            => (int)$row['id_'],
            'session_id'    => $row['session_id'] ? (int)$row['session_id'] : null,
            'files3_id'     => $row['files3_id'] ? (int)$row['files3_id'] : null,
            's3_key'        => (string)$row['s3_key'],
            'filename'      => (string)$row['filename'],
            'mime_type'     => (string)$row['mime_type'],
            'size_bytes'    => (int)$row['size_bytes'],
            'status'        => (string)$row['status'],
            'created_at'    => (string)$row['created_at'],
            'edit_url'      => $acciones['edit'],
            'view_url'      => $acciones['view'],
            'download_url'  => $acciones['download'],
        ];
    }
    $stmt->close();
    jexit(['ok' => true, 'attachments' => $attachments]);
}

/* ============================
ELIMINAR adjunto de sesión
============================ */
if ($action === 'remove') {
    $attachment_id = isset($_POST['attachment_id']) ? (int)$_POST['attachment_id'] : 0;
    if ($attachment_id <= 0) jexit(['ok'=>false,'error'=>'attachment_id inválido'], 400);
    
    $sqlCheck = "SELECT sa.id_ FROM SessionAttachments sa
                 WHERE sa.id_ = ? AND sa.user_id = ?";
    $stmtCheck = $db_connection->prepare($sqlCheck);
    if (!$stmtCheck) jexit(['ok'=>false,'error'=>'Error preparando: '.$db_connection->error], 500);
    $stmtCheck->bind_param('ii', $attachment_id, $user_id);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();
    if ($resCheck->num_rows === 0) {
        $stmtCheck->close();
        jexit(['ok'=>false,'error'=>'Adjunto no encontrado'], 404);
    }
    $stmtCheck->close();
    
    $sql = "DELETE FROM SessionAttachments WHERE id_ = ?";
    $stmt = $db_connection->prepare($sql);
    if (!$stmt) jexit(['ok'=>false,'error'=>'Error preparando: '.$db_connection->error], 500);
    $stmt->bind_param('i', $attachment_id);
    if (!$stmt->execute()) {
        $e = $stmt->error; $stmt->close();
        jexit(['ok'=>false,'error'=>'Error eliminando: '.$e], 500);
    }
    $stmt->close();
    
    jexit(['ok' => true, 'message' => 'Adjunto eliminado']);
}

/* ============================
REINDEXAR adjunto
============================ */
if ($action === 'reindex') {
    $attachment_id = isset($_POST['attachment_id']) ? (int)$_POST['attachment_id'] : 0;
    if ($attachment_id <= 0) jexit(['ok'=>false,'error'=>'attachment_id inválido'], 400);
    
    $sql = "UPDATE SessionAttachments SET status = 'pending' WHERE id_ = ?";
    $stmt = $db_connection->prepare($sql);
    if (!$stmt) jexit(['ok'=>false,'error'=>'Error preparando: '.$db_connection->error], 500);
    $stmt->bind_param('i', $attachment_id);
    if (!$stmt->execute()) {
        $e = $stmt->error; $stmt->close();
        jexit(['ok'=>false,'error'=>'Error actualizando: '.$e], 500);
    }
    $stmt->close();
    
    jexit(['ok' => true, 'message' => 'Adjunto marcado para reindexar']);
}

jexit(['ok' => false, 'error' => 'Acción no válida'], 400);
