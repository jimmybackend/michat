<?php
// index_project_sources.php
// Indexa manualmente las fuentes pendientes o desactualizadas (stale) de un proyecto
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
    foreach ([$rootFromDoc, realpath(__DIR__ . '/../../'), realpath(__DIR__ . '/../..'), realpath(__DIR__)] as $p) {
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

try {
    $bootstrap = __DIR__ . '/app_bootstrap.php';
    if (!is_file($bootstrap)) $bootstrap = __DIR__ . '/../app_bootstrap.php';
    if (!is_file($bootstrap)) {
        $bootstrap = find_file_in_candidates('app_bootstrap.php', resolve_root_candidates(), ['', 'public_html', 'api']);
    }
    if (!$bootstrap || !is_file($bootstrap)) throw new RuntimeException('app_bootstrap.php no encontrado.');
    require_once $bootstrap;
} catch (Throwable $e) {
    jexit(['ok' => false, 'error' => 'bootstrap: ' . $e->getMessage()], 500);
}

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    jexit(['ok'=>false,'error'=>'DB no disponible'], 500);
}

require_once __DIR__ . '/S3Manager.php';

$user_id = $_SESSION['user_id'] ?? 0;
$project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;

if ($project_id <= 0) jexit(['ok'=>false,'error'=>'project_id inválido'], 400);

// Verificar propiedad del proyecto
$stmtCheck = $db_connection->prepare("SELECT id_ FROM Projects WHERE id_ = ? AND user_id_ = ?");
$stmtCheck->bind_param('ii', $project_id, $user_id);
$stmtCheck->execute();
if ($stmtCheck->get_result()->num_rows === 0) {
    $stmtCheck->close();
    jexit(['ok'=>false,'error'=>'No tienes permisos para este proyecto'], 403);
}
$stmtCheck->close();

// ✅ CAMBIO 1: Buscar fuentes 'pending' Y 'stale'
$sql = "SELECT id_, s3_key, filename, language, status FROM ProjectSources WHERE project_id_ = ? AND status IN ('pending', 'stale')";
$stmt = $db_connection->prepare($sql);
$stmt->bind_param('i', $project_id);
$stmt->execute();
$res = $stmt->get_result();
$sources = [];
while ($row = $res->fetch_assoc()) $sources[] = $row;
$stmt->close();

if (empty($sources)) {
    jexit(['ok' => true, 'message' => 'No hay archivos pendientes o desactualizados por indexar.', 'indexed_count' => 0]);
}

// Inicializar S3 y Bedrock
$manager = new S3Manager();
$s3 = Config::getS3();
$bucket = $manager->getBucket();

$region = defined('Config::REGION') ? Config::REGION : 'us-east-1';
$creds = ['key' => getenv('AWS_ACCESS_KEY_ID') ?: Config::ACCESS_KEY, 'secret' => getenv('AWS_SECRET_ACCESS_KEY') ?: Config::SECRET_KEY];
$bedrock = new Aws\BedrockRuntime\BedrockRuntimeClient([
    'region' => $region, 'version' => 'latest', 'credentials' => $creds
]);

$indexed_count = 0;
$errors = [];

// ==========================================
// 1. OBTENER session_id_ Y message_id_ REALES DEL PROYECTO
// ==========================================
// Como la indexación es a nivel de proyecto, vinculamos los costos a la sesión 
// y al último mensaje generado en ese proyecto para cumplir tu regla de "nunca 0".
$sessionIdForLog = null;
$tcMsgId = null;

$stmtSess = $db_connection->prepare("SELECT id_ FROM ChatSessions WHERE project_id_ = ? ORDER BY id_ DESC LIMIT 1");
$stmtSess->bind_param('i', $project_id);
$stmtSess->execute();
$resSess = $stmtSess->get_result();
if ($rowSess = $resSess->fetch_assoc()) {
    $sessionIdForLog = (int)$rowSess['id_'];
    
    // Buscar el último mensaje real de esa sesión
    $stmtMsg = $db_connection->prepare("SELECT id_ FROM ChatMessages WHERE session_id_ = ? ORDER BY id_ DESC LIMIT 1");
    $stmtMsg->bind_param('i', $sessionIdForLog);
    $stmtMsg->execute();
    $resMsg = $stmtMsg->get_result();
    if ($rowMsg = $resMsg->fetch_assoc()) {
        $tcMsgId = (int)$rowMsg['id_']; // ¡Aquí está el ID real del mensaje!
    }
    $stmtMsg->close();
}
$stmtSess->close();

// FALLBACK: Si el proyecto es nuevo y no tiene sesiones/mensajes, buscar cualquiera en la BD para cumplir el FK
if (!$sessionIdForLog) {
    $fallbackSess = $db_connection->query("SELECT id_ FROM ChatSessions LIMIT 1");
    if ($fallbackSess && $rowFallback = $fallbackSess->fetch_assoc()) {
        $sessionIdForLog = (int)$rowFallback['id_'];
        $fallbackMsg = $db_connection->query("SELECT id_ FROM ChatMessages WHERE session_id_ = $sessionIdForLog LIMIT 1");
        if ($fallbackMsg && $rowMsgFallback = $fallbackMsg->fetch_assoc()) {
            $tcMsgId = (int)$rowMsgFallback['id_'];
        }
        if ($fallbackMsg) $fallbackMsg->free();
    }
    if ($fallbackSess) $fallbackSess->free();
}

foreach ($sources as $source) {
    try {
        // ✅ CAMBIO 2: Limpieza de chunks y embeddings antiguos antes de reindexar
        $stmtDelEmb = $db_connection->prepare("DELETE ce FROM ChunkEmbeddings ce JOIN SourceChunks sc ON ce.chunk_id_ = sc.id_ WHERE sc.source_id_ = ?");
        $stmtDelEmb->bind_param('i', $source['id_']);
        $stmtDelEmb->execute();
        $stmtDelEmb->close();
        
        $stmtDelChunk = $db_connection->prepare("DELETE FROM SourceChunks WHERE source_id_ = ?");
        $stmtDelChunk->bind_param('i', $source['id_']);
        $stmtDelChunk->execute();
        $stmtDelChunk->close();

        // 1. Descargar de S3
        $result = $s3->getObject(['Bucket' => $bucket, 'Key' => $source['s3_key']]);
        $content = (string)$result['Body'];
        if (trim($content) === '') {
            $errors[] = "Archivo {$source['filename']} vacío.";
            continue;
        }

        // 2. Dividir en chunks (máx ~2000 caracteres por chunk para no exceder límites de embedding)
        $chunks = [];
        $max_len = 2000;
        if (mb_strlen($content) <= $max_len) {
            $chunks[] = ['type' => 'file', 'name' => $source['filename'], 'content' => $content, 'start' => 1, 'end' => mb_substr_count($content, "\n") + 1];
        } else {
            $lines = explode("\n", $content);
            $current_chunk = ''; $start_line = 1; $current_line = 1;
            foreach ($lines as $line) {
                if (mb_strlen($current_chunk . "\n" . $line) > $max_len && trim($current_chunk) !== '') {
                    $chunks[] = ['type' => 'block', 'name' => $source['filename'], 'content' => trim($current_chunk), 'start' => $start_line, 'end' => $current_line - 1];
                    $current_chunk = $line;
                    $start_line = $current_line;
                } else {
                    $current_chunk .= ($current_chunk === '' ? '' : "\n") . $line;
                }
                $current_line++;
            }
            if (trim($current_chunk) !== '') {
                $chunks[] = ['type' => 'block', 'name' => $source['filename'], 'content' => trim($current_chunk), 'start' => $start_line, 'end' => $current_line - 1];
            }
        }

        // 3. Procesar cada chunk
        $db_connection->begin_transaction();
        $totalInputTokens = 0; // ✅ NUEVO: Acumulador para sumar los tokens de TODOS los chunks del archivo
        
        try {
            foreach ($chunks as $idx => $chunk) {
                // Insertar chunk
                $sqlChunk = "INSERT INTO SourceChunks (source_id_, project_id_, chunk_type, name, content, start_line, end_line) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmtChunk = $db_connection->prepare($sqlChunk);
                $stmtChunk->bind_param('iisssii', $source['id_'], $project_id, $chunk['type'], $chunk['name'], $chunk['content'], $chunk['start'], $chunk['end']);
                $stmtChunk->execute();
                $chunk_id = (int) $db_connection->insert_id;
                $stmtChunk->close();

                // Generar Embedding con Bedrock
                $model_id = 'amazon.titan-embed-text-v2:0';
                $body = json_encode(['inputText' => mb_substr($chunk['content'], 0, 8000)]);
                $emb_result = $bedrock->invokeModel([
                    'modelId' => $model_id,
                    'contentType' => 'application/json',
                    'accept' => 'application/json',
                    'body' => $body
                ]);
                $emb_response = json_decode((string)$emb_result['body'], true);
                $embedding_array = $emb_response['embedding'] ?? [];
                $embedding_json = json_encode($embedding_array);

                // ✅ NUEVO: Acumular tokens de cada chunk procesado
                $totalInputTokens += (int)($emb_response['inputTextTokenCount'] ?? 0);

                // Insertar Embedding
                $sqlEmb = "INSERT INTO ChunkEmbeddings (chunk_id_, model_id, dimensions, embedding, embedding_json) VALUES (?, ?, ?, ?, ?)";
                $stmtEmb = $db_connection->prepare($sqlEmb);
                $dims = count($embedding_array);
                $stmtEmb->bind_param('isiss', $chunk_id, $model_id, $dims, $embedding_json, $embedding_json);
                $stmtEmb->execute();
                $stmtEmb->close();
            }

            // ==========================================
            // 4. REGISTRO DE COSTOS: Indexación de fuentes (CORREGIDO)
            // ==========================================
            $inputTokens = $totalInputTokens; // ✅ Usamos el total acumulado, no solo el del último chunk
            $outputTokens = 0;
            
            // Solo registramos si conseguimos un session_id_ válido
            if ($sessionIdForLog) {
                try {
                    $tcPhase = 'embedding';
                    $tcModel = $model_id;
                    $tcCost = ($inputTokens / 1000) * 0.0001;
                    $tcDuration = 0;
                    
                    if ($tcMsgId === null) {
                        // Si por alguna razón extrema no hay mensajes en la BD, omitimos la columna para usar DEFAULT NULL
                        $sqlTC = "INSERT INTO TokenUsage (session_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms)
                                  VALUES (?, ?, ?, ?, ?, ?, ?)";
                        $stmtTC = $db_connection->prepare($sqlTC);
                        if ($stmtTC) {
                            $stmtTC->bind_param("issiddi", $sessionIdForLog, $tcPhase, $tcModel, $inputTokens, $outputTokens, $tcCost, $tcDuration);
                            $stmtTC->execute();
                            $stmtTC->close();
                        }
                    } else {
                        // ✅ CORREGIDO: Usamos el ID real del mensaje del proyecto, NUNCA 0
                        $sqlTC = "INSERT INTO TokenUsage (session_id_, message_id_, phase, model_id, input_tokens, output_tokens, estimated_cost_usd, duration_ms)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                        $stmtTC = $db_connection->prepare($sqlTC);
                        if ($stmtTC) {
                            $stmtTC->bind_param("iissiddi", $sessionIdForLog, $tcMsgId, $tcPhase, $tcModel, $inputTokens, $outputTokens, $tcCost, $tcDuration);
                            $stmtTC->execute();
                            $stmtTC->close();
                        }
                    }
                } catch (Throwable $e) {
                    $logMsg = "[" . date('Y-m-d H:i:s') . "] " . basename(__FILE__) . " | TokenUsage: " . $e->getMessage() . "\n";
                    @file_put_contents(__DIR__ . '/token_usage_debug.log', $logMsg, FILE_APPEND | LOCK_EX);
                }
            }

            // Marcar fuente como indexada
            $sqlUpdate = "UPDATE ProjectSources SET status = 'indexed', indexed_at = NOW() WHERE id_ = ?";
            $stmtUpdate = $db_connection->prepare($sqlUpdate);
            $stmtUpdate->bind_param('i', $source['id_']);
            $stmtUpdate->execute();
            $stmtUpdate->close();
            
            $db_connection->commit();
            $indexed_count++;
        } catch (Throwable $e) {
            $db_connection->rollback();
            $errors[] = "Error en chunk de {$source['filename']}: " . $e->getMessage();
        }
    } catch (Throwable $e) {
        $errors[] = "Error general con {$source['filename']}: " . $e->getMessage();
    }
}

jexit([
    'ok' => true,
    'message' => "Proceso completado. {$indexed_count} archivo(s) indexado(s).",
    'indexed_count' => $indexed_count,
    'errors' => $errors
]);