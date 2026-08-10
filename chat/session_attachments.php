<?php
// session_attachments.php
// Gestión de adjuntos de sesión (archivos vinculados a una sesión de chat)
// Acciones: list, remove
// NOTA: reindex eliminado - la indexación se manejará por el nuevo sistema
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
    
    // Construir el prefijo de ruta para buscar en FileS3
    // Formato: Data/Chat/Uploads/{user_id}/{YYYY}/{MM}/{DD}/{session_id}/
    if ($session_id <= 0) {
        // Si no hay session_id, listar archivos del usuario en Chat/Uploads
        $prefixBase = "Data/Chat/Uploads/{$user_id}/";
        $sql = "SELECT f.id_ AS files3_id, f.Nombre AS filename, f.Encriptado AS s3_key,
                       f.Tamano AS size_bytes, f.Ruta, f.AccessType, f.Fecha AS created_at,
                       f.Metadatos
                FROM FileS3 f
                WHERE f.user_id_ = ?
                  AND f.Ruta LIKE CONCAT(?, '%')
                  AND f.Found = 1
                ORDER BY f.Fecha DESC";
        $stmt = $db_connection->prepare($sql);
        if (!$stmt) jexit(['ok'=>false,'error'=>'Error preparando: '.$db_connection->error], 500);
        $stmt->bind_param('is', $user_id, $prefixBase);
    } else {
        // Listar archivos de una sesión específica
        // Necesitamos obtener la fecha desde la sesión para construir el prefijo exacto
        // Primero obtenemos información de la sesión
        $sqlSession = "SELECT created_at FROM ChatSessions WHERE id_ = ? AND user_id_ = ?";
        $stmtSession = $db_connection->prepare($sqlSession);
        if ($stmtSession) {
            $stmtSession->bind_param('ii', $session_id, $user_id);
            $stmtSession->execute();
            $resSession = $stmtSession->get_result();
            if ($resSession->num_rows > 0) {
                $sessionRow = $resSession->fetch_assoc();
                $sessionDate = new DateTime($sessionRow['created_at']);
                $year = $sessionDate->format('Y');
                $month = $sessionDate->format('m');
                $day = $sessionDate->format('d');
                
                $prefixBase = "Data/Chat/Uploads/{$user_id}/{$year}/{$month}/{$day}/{$session_id}/";
                
                $sql = "SELECT f.id_ AS files3_id, f.Nombre AS filename, f.Encriptado AS s3_key,
                               f.Tamano AS size_bytes, f.Ruta, f.AccessType, f.Fecha AS created_at,
                               f.Metadatos
                        FROM FileS3 f
                        WHERE f.user_id_ = ?
                          AND f.Ruta = ?
                          AND f.Found = 1
                        ORDER BY f.Fecha DESC";
                $stmt = $db_connection->prepare($sql);
                if (!$stmt) jexit(['ok'=>false,'error'=>'Error preparando: '.$db_connection->error], 500);
                $stmt->bind_param('is', $user_id, $prefixBase);
            } else {
                $stmtSession->close();
                jexit(['ok'=>false,'error'=>'Sesión no encontrada'], 404);
            }
            $stmtSession->close();
        } else {
            jexit(['ok'=>false,'error'=>'Error preparando consulta de sesión: '.$db_connection->error], 500);
        }
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
            $row['s3_key'] ?? '',
            $row['filename'],
            $row['AccessType'] ?? 'normal'
        );
        
        $attachments[] = [
            'id'            => (int)$row['files3_id'],
            'files3_id'     => (int)$row['files3_id'],
            's3_key'        => (string)$row['s3_key'],
            'filename'      => (string)$row['filename'],
            'mime_type'     => '', // FileS3 no tiene mime_type directamente
            'size_bytes'    => (int)$row['size_bytes'],
            'status'        => 'indexed', // Por defecto, ya que FileS3 existe
            'created_at'    => (string)$row['created_at'],
            'ruta'          => (string)$row['Ruta'],
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
    $files3_id = isset($_POST['attachment_id']) ? (int)$_POST['attachment_id'] : 0;
    if ($files3_id <= 0) jexit(['ok'=>false,'error'=>'attachment_id inválido'], 400);
    
    // Verificar que el archivo existe y pertenece al usuario
    $sqlCheck = "SELECT f.id_, f.Ruta, f.Encriptado FROM FileS3 f
                 WHERE f.id_ = ? AND f.user_id_ = ? AND f.Found = 1";
    $stmtCheck = $db_connection->prepare($sqlCheck);
    if (!$stmtCheck) jexit(['ok'=>false,'error'=>'Error preparando: '.$db_connection->error], 500);
    $stmtCheck->bind_param('ii', $files3_id, $user_id);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();
    if ($resCheck->num_rows === 0) {
        $stmtCheck->close();
        jexit(['ok'=>false,'error'=>'Archivo no encontrado o no pertenece al usuario'], 404);
    }
    $fileRow = $resCheck->fetch_assoc();
    $stmtCheck->close();
    
    // Verificar que la ruta corresponde a Chat/Uploads
    $ruta = $fileRow['Ruta'] ?? '';
    if (strpos($ruta, 'Data/Chat/Uploads/') !== 0) {
        jexit(['ok'=>false,'error'=>'El archivo no es un adjunto de chat válido'], 400);
    }
    
    // Eliminar físicamente de S3
    try {
        require_once __DIR__ . '/S3Manager.php';
        $manager = new S3Manager();
        $bucket = $manager->getBucket();
        
        $s3Key = $fileRow['Encriptado'];
        if (!empty($s3Key)) {
            $s3Client = Config::getS3();
            $s3Client->deleteObject([
                'Bucket' => $bucket,
                'Key'    => $s3Key
            ]);
        }
    } catch (Throwable $e) {
        // Continuar aunque falle S3, al menos eliminamos de BD
        error_log('Warning: Error eliminando de S3: ' . $e->getMessage());
    }
    
    // Eliminar registro de FileS3 (marcar como no encontrado)
    $sql = "UPDATE FileS3 SET Found = 0 WHERE id_ = ?";
    $stmt = $db_connection->prepare($sql);
    if (!$stmt) jexit(['ok'=>false,'error'=>'Error preparando: '.$db_connection->error], 500);
    $stmt->bind_param('i', $files3_id);
    if (!$stmt->execute()) {
        $e = $stmt->error; $stmt->close();
        jexit(['ok'=>false,'error'=>'Error eliminando: '.$e], 500);
    }
    $stmt->close();
    
    jexit(['ok' => true, 'message' => 'Adjunto eliminado']);
}

/* ============================
REINDEXAR adjunto - NO IMPLEMENTADO
============================ */
if ($action === 'reindex') {
    // La indexación se manejará por el nuevo sistema de embeddings
    // Esta acción ya no es válida en la nueva arquitectura
    jexit(['ok' => false, 'error' => 'La acción reindex ha sido eliminada. La indexación se manejará por el sistema de embeddings.']);
}

jexit(['ok' => false, 'error' => 'Acción no válida'], 400);
