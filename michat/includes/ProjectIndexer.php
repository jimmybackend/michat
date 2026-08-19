<?php
/**
 * includes/ProjectIndexer.php
 *
 * Fuente única para preparar la indexación de una fuente de proyecto:
 *   1) divide el contenido en SourceChunks
 *   2) limpia chunks/jobs anteriores de la fuente
 *   3) crea EmbeddingJobs usando embedding_main
 *   4) deja ProjectSources en pending hasta que process_embedding_queue.php
 *      complete TODOS los vectores del modelo configurado.
 *
 * IMPORTANTE: este helper NO invoca Bedrock directamente.
 */

declare(strict_types=1);

if (!function_exists('project_indexer_load_runtime')) {
    function project_indexer_load_runtime(mysqli $db, int $projectId): array
    {
        if (!function_exists('aiRuntimeLoad')) {
            $runtime = __DIR__ . '/ai_agent_runtime.php';
            if (!is_file($runtime)) {
                throw new RuntimeException('Falta includes/ai_agent_runtime.php');
            }
            require_once $runtime;
        }

        $stmt = $db->prepare("SELECT user_id_ FROM Projects WHERE id_ = ? LIMIT 1");
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la consulta del propietario del proyecto: ' . $db->error);
        }
        $stmt->bind_param('i', $projectId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new RuntimeException('Proyecto no encontrado.');
        }

        $userId = (int)($row['user_id_'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('El proyecto no tiene un user_id_ válido.');
        }

        aiRuntimeLoad($db, $userId);

        return ['user_id' => $userId];
    }
}

if (!function_exists('chunkFileContent')) {
    /**
     * Chunking simple orientado a líneas. Mantiene start/end para herramientas
     * de código y divide también líneas gigantes para no exceder el máximo.
     */
    function chunkFileContent(string $content, string $filename, int $maxLen = 2000): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $maxLen = max(500, $maxLen);

        if (trim($content) === '') {
            return [];
        }

        $lines = explode("\n", $content);
        $chunks = [];
        $buffer = '';
        $startLine = 1;
        $currentLine = 1;

        $flush = static function () use (&$chunks, &$buffer, &$startLine, &$currentLine, $filename): void {
            $preserved = rtrim($buffer, "\n");
            if (trim($preserved) === '') return;
            $chunks[] = [
                'type' => count($chunks) === 0 && $startLine === 1 && $currentLine >= 1 ? 'block' : 'block',
                'name' => $filename,
                'content' => $preserved,
                'start' => $startLine,
                'end' => max($startLine, $currentLine - 1),
            ];
            $buffer = '';
        };

        foreach ($lines as $line) {
            // Una línea individual puede ser mayor que el límite (JSON minificado, etc.).
            if (mb_strlen($line) > $maxLen) {
                if (trim($buffer) !== '') {
                    $flush();
                }

                $offset = 0;
                $lineLen = mb_strlen($line);
                while ($offset < $lineLen) {
                    $piece = mb_substr($line, $offset, $maxLen);
                    if (trim($piece) !== '') {
                        $chunks[] = [
                            'type' => 'block',
                            'name' => $filename,
                            'content' => $piece,
                            'start' => $currentLine,
                            'end' => $currentLine,
                        ];
                    }
                    $offset += $maxLen;
                }
                $currentLine++;
                $startLine = $currentLine;
                continue;
            }

            $candidate = $buffer === '' ? $line : ($buffer . "\n" . $line);
            if (mb_strlen($candidate) > $maxLen && trim($buffer) !== '') {
                $flush();
                $buffer = $line;
                $startLine = $currentLine;
            } else {
                $buffer = $candidate;
            }
            $currentLine++;
        }

        if (trim($buffer) !== '') {
            $flush();
        }

        if (count($chunks) === 1) {
            $chunks[0]['type'] = 'file';
        }

        return $chunks;
    }
}

if (!function_exists('projectDeleteEmbeddingJobsForSource')) {
    function projectDeleteEmbeddingJobsForSource(mysqli $db, int $sourceId): void
    {
        $stmt = $db->prepare("
            DELETE ej
            FROM EmbeddingJobs ej
            JOIN SourceChunks sc
              ON ej.target_type = 'source_chunk'
             AND ej.target_id = sc.id_
            WHERE sc.source_id_ = ?
        ");
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar limpieza de EmbeddingJobs: ' . $db->error);
        }
        $stmt->bind_param('i', $sourceId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('No se pudieron limpiar EmbeddingJobs: ' . $error);
        }
        $stmt->close();
    }
}

if (!function_exists('indexProjectSourceContent')) {
    /**
     * Conserva la firma histórica ($bedrock) para no romper code_edit.php.
     * $bedrock ya NO se utiliza: todos los embeddings pasan por la cola.
     */
    function indexProjectSourceContent(
        mysqli $db,
        $bedrock,
        int $projectId,
        int $sourceId,
        string $filename,
        string $content
    ): array {
        $filename = trim($filename);
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        if ($projectId <= 0 || $sourceId <= 0) {
            return ['ok' => false, 'error' => 'project_id/source_id inválido para indexar.'];
        }
        if (trim($content) === '') {
            $stmtErr = $db->prepare("UPDATE ProjectSources SET status='error', indexed_at=NULL WHERE id_=? AND project_id_=?");
            if ($stmtErr) {
                $stmtErr->bind_param('ii', $sourceId, $projectId);
                $stmtErr->execute();
                $stmtErr->close();
            }
            return ['ok' => false, 'error' => "Archivo {$filename} vacío, no se preparó para indexación."];
        }

        $stmtSource = $db->prepare("SELECT id_ FROM ProjectSources WHERE id_ = ? AND project_id_ = ? LIMIT 1");
        if (!$stmtSource) {
            return ['ok' => false, 'error' => 'No se pudo validar ProjectSources: ' . $db->error];
        }
        $stmtSource->bind_param('ii', $sourceId, $projectId);
        $stmtSource->execute();
        $exists = $stmtSource->get_result()->fetch_assoc();
        $stmtSource->close();
        if (!$exists) {
            return ['ok' => false, 'error' => 'La fuente no pertenece al proyecto indicado.'];
        }

        try {
            project_indexer_load_runtime($db, $projectId);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $embeddingActive = aiAgentActive('embedding_main', false);
        $embeddingModel = aiAgentModel('embedding_main', '');
        if ($embeddingActive && $embeddingModel === '') {
            return ['ok' => false, 'error' => "embedding_main está activo pero no tiene model_id."];
        }

        $chunkMax = max(500, (int)aiAgentExtra('embedding_main', 'project_chunk_max_chars', 2000));
        $chunks = chunkFileContent($content, $filename !== '' ? $filename : 'archivo', $chunkMax);
        if (!$chunks) {
            return ['ok' => false, 'error' => 'No se generaron chunks para la fuente.'];
        }

        $db->begin_transaction();
        try {
            // Primero jobs: EmbeddingJobs no tiene FK a SourceChunks.
            projectDeleteEmbeddingJobsForSource($db, $sourceId);

            // SourceChunks elimina ChunkEmbeddings por FK ON DELETE CASCADE.
            $stmtDel = $db->prepare("DELETE FROM SourceChunks WHERE source_id_ = ?");
            if (!$stmtDel) throw new RuntimeException('No se pudo preparar limpieza de SourceChunks: ' . $db->error);
            $stmtDel->bind_param('i', $sourceId);
            if (!$stmtDel->execute()) {
                $error = $stmtDel->error;
                $stmtDel->close();
                throw new RuntimeException('No se pudieron limpiar SourceChunks: ' . $error);
            }
            $stmtDel->close();

            $stmtChunk = $db->prepare("
                INSERT INTO SourceChunks
                    (source_id_, project_id_, chunk_type, name, content, start_line, end_line, token_count, checksum)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$stmtChunk) throw new RuntimeException('No se pudo preparar INSERT de SourceChunks: ' . $db->error);

            $stmtJob = null;
            if ($embeddingActive) {
                $stmtJob = $db->prepare("
                    INSERT IGNORE INTO EmbeddingJobs
                        (target_type, target_id, model_id, status, attempts)
                    VALUES ('source_chunk', ?, ?, 'pending', 0)
                ");
                if (!$stmtJob) {
                    $stmtChunk->close();
                    throw new RuntimeException('No se pudo preparar INSERT de EmbeddingJobs: ' . $db->error);
                }
            }

            $jobs = 0;
            $insertedChunks = 0;
            foreach ($chunks as $chunk) {
                $type = (string)($chunk['type'] ?? 'block');
                if (!in_array($type, ['file','block'], true)) $type = 'block';
                $name = (string)($chunk['name'] ?? $filename);
                $chunkContent = (string)($chunk['content'] ?? '');
                $start = max(1, (int)($chunk['start'] ?? 1));
                $end = max($start, (int)($chunk['end'] ?? $start));
                $tokenCount = (int)ceil(max(1, mb_strlen($chunkContent)) / 4);
                $checksum = hash('sha256', $chunkContent);

                $stmtChunk->bind_param(
                    'iisssiiis',
                    $sourceId,
                    $projectId,
                    $type,
                    $name,
                    $chunkContent,
                    $start,
                    $end,
                    $tokenCount,
                    $checksum
                );
                if (!$stmtChunk->execute()) {
                    throw new RuntimeException('Error insertando SourceChunk: ' . $stmtChunk->error);
                }
                $chunkId = (int)$stmtChunk->insert_id;
                $insertedChunks++;

                if ($stmtJob && $chunkId > 0) {
                    $stmtJob->bind_param('is', $chunkId, $embeddingModel);
                    if (!$stmtJob->execute()) {
                        throw new RuntimeException('Error encolando embedding: ' . $stmtJob->error);
                    }
                    if ($stmtJob->affected_rows > 0) $jobs++;
                }
            }

            $stmtChunk->close();
            if ($stmtJob) $stmtJob->close();

            $sha = hash('sha256', $content);
            if ($embeddingActive) {
                $stmtStatus = $db->prepare("UPDATE ProjectSources SET status='pending', indexed_at=NULL, sha256=? WHERE id_=? AND project_id_=?");
            } else {
                // Los chunks sirven para grep/view, pero RAG semántico queda pendiente.
                $stmtStatus = $db->prepare("UPDATE ProjectSources SET status='stale', indexed_at=NULL, sha256=? WHERE id_=? AND project_id_=?");
            }
            if (!$stmtStatus) throw new RuntimeException('No se pudo preparar estado de ProjectSources: ' . $db->error);
            $stmtStatus->bind_param('sii', $sha, $sourceId, $projectId);
            $stmtStatus->execute();
            $stmtStatus->close();

            $db->commit();

            return [
                'ok' => true,
                'indexed' => false,
                'queued' => $embeddingActive && $jobs > 0,
                'chunks' => $insertedChunks,
                'jobs' => $jobs,
                'model' => $embeddingActive ? $embeddingModel : null,
                'embedding_active' => $embeddingActive,
                'status' => $embeddingActive ? 'pending' : 'stale',
                'message' => $embeddingActive
                    ? "Fuente preparada: {$insertedChunks} chunk(s), {$jobs} embedding(s) en cola."
                    : "Fuente preparada en chunks, pero embedding_main está desactivado.",
            ];
        } catch (Throwable $e) {
            $db->rollback();
            $stmtErr = $db->prepare("UPDATE ProjectSources SET status='error', indexed_at=NULL WHERE id_=? AND project_id_=?");
            if ($stmtErr) {
                $stmtErr->bind_param('ii', $sourceId, $projectId);
                $stmtErr->execute();
                $stmtErr->close();
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
