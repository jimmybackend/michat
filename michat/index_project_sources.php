<?php
// index_project_sources.php
// Prepara fuentes pending/stale: extrae texto, crea SourceChunks y encola embeddings.
// NO invoca Bedrock directamente; process_embedding_queue.php es la única autoridad
// de vectorización (Titan/Cohere según embedding_main).

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

function jexit($arr, $code = 200): void {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    require_once __DIR__ . '/app_bootstrap.php';
    require_once __DIR__ . '/S3Manager.php';
    require_once __DIR__ . '/includes/ai_agent_runtime.php';
    require_once __DIR__ . '/includes/session_file_extractor.php';
    require_once __DIR__ . '/includes/ProjectIndexer.php';
} catch (Throwable $e) {
    jexit(['ok'=>false,'error'=>'Dependencias: '.$e->getMessage()], 500);
}

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    jexit(['ok'=>false,'error'=>'DB no disponible'], 500);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jexit(['ok'=>false,'error'=>'Método no permitido'], 405);
}

$userId = isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])
    ? (int)$_SESSION['user_id']
    : 0;
if ($userId <= 0) jexit(['ok'=>false,'error'=>'No autenticado'], 401);

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
if ($projectId <= 0) jexit(['ok'=>false,'error'=>'project_id inválido'], 400);

$stmtCheck = $db_connection->prepare("SELECT id_ FROM Projects WHERE id_=? AND user_id_=? AND status<>'deleted' LIMIT 1");
if (!$stmtCheck) jexit(['ok'=>false,'error'=>'No se pudo validar proyecto: '.$db_connection->error], 500);
$stmtCheck->bind_param('ii', $projectId, $userId);
$stmtCheck->execute();
$projectOk = $stmtCheck->get_result()->fetch_assoc();
$stmtCheck->close();
if (!$projectOk) jexit(['ok'=>false,'error'=>'No tienes permisos para este proyecto'], 403);

try {
    aiRuntimeLoad($db_connection, $userId);
} catch (Throwable $e) {
    jexit(['ok'=>false,'error'=>'No se pudo cargar UserAIAgentConfigs: '.$e->getMessage()], 500);
}

$embeddingActive = aiAgentActive('embedding_main', false);
$embeddingModel = aiAgentModel('embedding_main', '');
if ($embeddingActive && $embeddingModel === '') {
    jexit(['ok'=>false,'error'=>'embedding_main está activo pero no tiene model_id'], 500);
}

$stmt = $db_connection->prepare("
    SELECT ps.id_, ps.s3_key, ps.filename, ps.language, ps.status,
           (SELECT COUNT(*) FROM SourceChunks sc WHERE sc.source_id_=ps.id_) AS chunks_total,
           (SELECT COUNT(*) FROM SourceChunks sc
              JOIN ChunkEmbeddings ce ON ce.chunk_id_=sc.id_ AND ce.model_id=?
             WHERE sc.source_id_=ps.id_) AS chunks_ready
    FROM ProjectSources ps
    WHERE ps.project_id_=?
    ORDER BY ps.id_ ASC
");
if (!$stmt) jexit(['ok'=>false,'error'=>'No se pudo leer ProjectSources: '.$db_connection->error], 500);
$stmt->bind_param('si', $embeddingModel, $projectId);
$stmt->execute();
$res = $stmt->get_result();
$sources = [];
while ($row = $res->fetch_assoc()) {
    $storedStatus = (string)($row['status'] ?? 'pending');
    $total = (int)($row['chunks_total'] ?? 0);
    $ready = (int)($row['chunks_ready'] ?? 0);
    $modelMismatch = $embeddingActive && $storedStatus === 'indexed' && ($total === 0 || $ready < $total);
    if (in_array($storedStatus, ['pending','stale','error'], true) || $modelMismatch) {
        $sources[] = $row;
    }
}
$stmt->close();

if (!$sources) {
    jexit([
        'ok'=>true,
        'message'=>'No hay archivos pendientes o desactualizados por preparar.',
        'prepared_count'=>0,
        'indexed_count'=>0,
        'queued_jobs'=>0,
        'embedding_model'=>$embeddingActive ? $embeddingModel : null,
        'embedding_active'=>$embeddingActive,
        'errors'=>[],
    ]);
}

$prepared = 0;
$queuedJobs = 0;
$chunksTotal = 0;
$errors = [];
$details = [];

foreach ($sources as $source) {
    $sourceId = (int)$source['id_'];
    $filename = (string)$source['filename'];
    try {
        $extracted = idx_extract_s3_text(
            (string)$source['s3_key'],
            $filename,
            max(1000, (int)aiAgentExtra('embedding_main', 'project_source_max_chars', IDX_MAX_EXTRACTED_CHARS))
        );

        $idx = indexProjectSourceContent(
            $db_connection,
            null,
            $projectId,
            $sourceId,
            $filename,
            (string)$extracted['content']
        );

        if (empty($idx['ok'])) {
            throw new RuntimeException((string)($idx['error'] ?? 'No se pudo preparar la fuente'));
        }

        $prepared++;
        $queuedJobs += (int)($idx['jobs'] ?? 0);
        $chunksTotal += (int)($idx['chunks'] ?? 0);
        $details[] = [
            'source_id'=>$sourceId,
            'filename'=>$filename,
            'extractor'=>$extracted['extractor'] ?? null,
            'truncated'=>(bool)($extracted['truncated'] ?? false),
            'chunks'=>(int)($idx['chunks'] ?? 0),
            'jobs'=>(int)($idx['jobs'] ?? 0),
            'status'=>$idx['status'] ?? 'pending',
            'model'=>$idx['model'] ?? null,
        ];
    } catch (Throwable $e) {
        $stmtErr = $db_connection->prepare("UPDATE ProjectSources SET status='error', indexed_at=NULL WHERE id_=? AND project_id_=?");
        if ($stmtErr) {
            $stmtErr->bind_param('ii', $sourceId, $projectId);
            $stmtErr->execute();
            $stmtErr->close();
        }
        $errors[] = "{$filename}: " . $e->getMessage();
        $details[] = ['source_id'=>$sourceId,'filename'=>$filename,'status'=>'error','error'=>$e->getMessage()];
    }
}

$message = $embeddingActive
    ? "{$prepared} archivo(s) preparados; {$queuedJobs} embedding(s) quedaron en cola para {$embeddingModel}."
    : "{$prepared} archivo(s) preparados en chunks. embedding_main está desactivado; no se encolaron vectores.";

jexit([
    'ok'=>true,
    'message'=>$message,
    // Compatibilidad: ya NO significa que el vector esté terminado.
    'indexed_count'=>0,
    'prepared_count'=>$prepared,
    'queued_jobs'=>$queuedJobs,
    'chunks_total'=>$chunksTotal,
    'embedding_model'=>$embeddingActive ? $embeddingModel : null,
    'embedding_active'=>$embeddingActive,
    'errors'=>$errors,
    'details'=>$details,
]);
