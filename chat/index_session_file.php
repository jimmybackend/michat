<?php
// index_session_file.php
// Indexa un adjunto de sesión (chat simple sin proyecto):
//  - Chunkea el archivo (~2000 caracteres)
//  - Inserta bloques 'file_chunk' en SessionContextBlocks (is_locked=1)
//  - Encola EmbeddingJobs 'session_block' para el cron
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/S3Manager.php';

function jexit($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function next_id(mysqli $db, $table, $col) {
    $rs = $db->query("SELECT COALESCE(MAX($col),0)+1 AS nxt FROM $table");
    if (!$rs) return 1;
    $row = $rs->fetch_assoc();
    return (int)($row['nxt'] ?? 1);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jexit(['ok'=>false,'error'=>'Método no permitido'], 405);

$userId = 0;
foreach (['user_id_','user_id','id_usuario','id_user','id'] as $k) {
    if (isset($_SESSION[$k]) && ctype_digit((string)$_SESSION[$k])) { $userId = (int)$_SESSION[$k]; break; }
}
if ($userId <= 0) jexit(['ok'=>false,'error'=>'Sesión inválida'], 401);

$file_id    = isset($_POST['file_id'])    ? (int)$_POST['file_id']    : 0;
$session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
if ($file_id <= 0)    jexit(['ok'=>false,'error'=>'file_id inválido'], 400);
if ($session_id <= 0) jexit(['ok'=>false,'error'=>'session_id inválido'], 400);

// 1) La sesión debe ser del usuario
$stmt = $db_connection->prepare("SELECT id_ FROM ChatSessions WHERE id_ = ? AND user_id_ = ? LIMIT 1");
$stmt->bind_param('ii', $session_id, $userId);
$stmt->execute();
$okSess = $stmt->get_result()->num_rows > 0;
$stmt->close();
if (!$okSess) jexit(['ok'=>false,'error'=>'La sesión no existe o no es tuya'], 403);

// 2) El archivo debe ser del usuario
$stmt = $db_connection->prepare("SELECT id_, Nombre, Encriptado, Ruta FROM FileS3 WHERE id_ = ? AND user_id_ = ? LIMIT 1");
$stmt->bind_param('ii', $file_id, $userId);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$file) jexit(['ok'=>false,'error'=>'El archivo no existe o no es tuyo'], 403);

// 3) Solo texto/código
$TEXT_EXTS = ['php','phtml','inc','js','mjs','cjs','jsx','ts','tsx','css','scss','less','html','htm','json','xml','yaml','yml','ini','conf','cfg','txt','md','markdown','sql','sh','bash','zsh','bat','cmd','ps1','py','rb','java','c','h','cpp','hpp','cs','go','rs','swift','kt','kts','vue','csv','tsv','log','srt','vtt','env','gitignore','htaccess'];
$ext = strtolower(pathinfo($file['Nombre'], PATHINFO_EXTENSION));
if (!in_array($ext, $TEXT_EXTS, true)) {
    jexit(['ok'=>false,'error'=>'Solo se indexan archivos de texto/código (.'.$ext.' no soportado)'], 422);
}

// 4) Clave S3 real
$enc  = str_replace('\\', '/', trim((string)$file['Encriptado']));
$ruta = rtrim(str_replace('\\', '/', (string)$file['Ruta']), '/');
$s3Key = (strpos($enc, $ruta) === 0) ? $enc : ($ruta . '/' . ltrim($enc, '/'));
$s3Key = preg_replace('~^(Data\d*)/\1/~i', '$1/', $s3Key);

// 5) Descargar de S3
try {
    $manager = new S3Manager($db_connection);
    $s3      = Config::getS3();
    $bucket  = $manager->getBucket();
    $result  = $s3->getObject(['Bucket'=>$bucket, 'Key'=>$s3Key]);
    $content = (string)$result['Body'];
} catch (Throwable $e) {
    jexit(['ok'=>false,'error'=>'No se pudo leer el archivo de S3: '.$e->getMessage()], 500);
}
if (trim($content) === '') jexit(['ok'=>false,'error'=>'El archivo está vacío'], 422);

// 6) Borrar indexación previa de este archivo
$stmt = $db_connection->prepare("DELETE FROM SessionContextBlocks WHERE session_id_ = ? AND s3_path = ? AND block_type IN ('file','file_chunk')");
$stmt->bind_param('is', $session_id, $s3Key);
$stmt->execute();
$stmt->close();

// 7) Chunks de ~2000 caracteres (mismo algoritmo que proyectos)
$chunks  = [];
$max_len = 2000;
if (mb_strlen($content) <= $max_len) {
    $chunks[] = $content;
} else {
    $lines = explode("\n", $content);
    $cur = ''; 
    foreach ($lines as $l) {
        if (mb_strlen($cur . "\n" . $l) > $max_len && trim($cur) !== '') {
            $chunks[] = trim($cur);
            $cur = $l;
        } else {
            $cur .= ($cur === '' ? '' : "\n") . $l;
        }
    }
    if (trim($cur) !== '') $chunks[] = trim($cur);
}

// 8) Insertar bloques + encolar jobs
$db_connection->begin_transaction();
try {
    foreach ($chunks as $i => $chunkText) {
        $blockId = next_id($db_connection, 'SessionContextBlocks', 'id_');
        $meta    = json_encode(['filename'=>$file['Nombre'],'files3_id'=>$file_id,'chunk'=>($i+1),'total'=>count($chunks)], JSON_UNESCAPED_UNICODE);
        $tokens  = (int)ceil(mb_strlen($chunkText) / 4);

        $stmt = $db_connection->prepare("INSERT INTO SessionContextBlocks (id_, session_id_, block_type, content_preview, s3_path, is_locked, source_ids, token_count) VALUES (?, ?, 'file_chunk', ?, ?, 1, ?, ?)");
        $stmt->bind_param('iisssi', $blockId, $session_id, $chunkText, $s3Key, $meta, $tokens);
        $stmt->execute();
        $stmt->close();

        $jobId = next_id($db_connection, 'EmbeddingJobs', 'id_');
        $stmt = $db_connection->prepare("INSERT INTO EmbeddingJobs (id_, target_type, target_id, model_id, status, attempts) VALUES (?, 'session_block', ?, 'amazon.titan-embed-text-v2:0', 'pending', 0)");
        $stmt->bind_param('ii', $jobId, $blockId);
        $stmt->execute();
        $stmt->close();
    }
    $db_connection->commit();
} catch (Throwable $e) {
    $db_connection->rollback();
    jexit(['ok'=>false,'error'=>'Error al indexar: '.$e->getMessage()], 500);
}

jexit([
    'ok' => true,
    'mensaje' => 'Archivo dividido en ' . count($chunks) . ' chunk(s). Los embeddings se generan en segundo plano con el cron.',
    'chunks' => count($chunks)
]);