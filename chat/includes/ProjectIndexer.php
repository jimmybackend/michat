<?php
/**
 * ProjectIndexer.php
 *
 * Indexación (chunking + embeddings) de una fuente de proyecto ya cargada
 * en memoria. Se usa desde code_edit.php justo después de crear/editar un
 * archivo para indexarlo en el mismo request (sin depender de una llamada
 * posterior del frontend a index_project_sources.php).
 */

// Este archivo llamaba a next_id() sin declararla: dependía de que el archivo
// que lo incluyera la hubiera declarado antes (solo code_edit.php lo hacía),
// así que cualquier otro endpoint que lo incluyera daba fatal error. La Fase 2
// eliminó next_id() por completo —todas las tablas tienen AUTO_INCREMENT— y con
// ella la dependencia oculta.
require_once __DIR__ . '/FileToolkit.php';

if (!function_exists('chunkFileContent')) {
    function chunkFileContent(string $content, string $filename, int $maxLen = 2000): array {
        $chunks = [];
        if (mb_strlen($content) <= $maxLen) {
            $chunks[] = [
                'type' => 'file', 'name' => $filename, 'content' => $content,
                'start' => 1, 'end' => mb_substr_count($content, "\n") + 1
            ];
            return $chunks;
        }

        $lines = explode("\n", $content);
        $current_chunk = ''; $start_line = 1; $current_line = 1;
        foreach ($lines as $line) {
            if (mb_strlen($current_chunk . "\n" . $line) > $maxLen && trim($current_chunk) !== '') {
                $chunks[] = ['type' => 'block', 'name' => $filename, 'content' => trim($current_chunk), 'start' => $start_line, 'end' => $current_line - 1];
                $current_chunk = $line;
                $start_line = $current_line;
            } else {
                $current_chunk .= ($current_chunk === '' ? '' : "\n") . $line;
            }
            $current_line++;
        }
        if (trim($current_chunk) !== '') {
            $chunks[] = ['type' => 'block', 'name' => $filename, 'content' => trim($current_chunk), 'start' => $start_line, 'end' => $current_line - 1];
        }
        return $chunks;
    }
}

if (!function_exists('indexProjectSourceContent')) {
    /**
     * Genera chunks + embeddings para el contenido de UNA fuente y la marca
     * como 'indexed'. Requiere que la fuente (ProjectSources) ya exista con
     * el id_ indicado.
     */
    function indexProjectSourceContent(mysqli $db, $bedrock, int $projectId, int $sourceId, string $filename, string $content): array {
        if (trim($content) === '') {
            return ['ok' => false, 'error' => "Archivo {$filename} vacío, no se indexó."];
        }
        if ($sourceId <= 0) {
            return ['ok' => false, 'error' => 'source_id inválido para indexar.'];
        }

        // Limpiar chunks/embeddings previos de esta fuente (reindexación idempotente)
        $stmtDelEmb = $db->prepare("DELETE ce FROM ChunkEmbeddings ce JOIN SourceChunks sc ON ce.chunk_id_ = sc.id_ WHERE sc.source_id_ = ?");
        $stmtDelEmb->bind_param('i', $sourceId);
        $stmtDelEmb->execute();
        $stmtDelEmb->close();

        $stmtDelChunk = $db->prepare("DELETE FROM SourceChunks WHERE source_id_ = ?");
        $stmtDelChunk->bind_param('i', $sourceId);
        $stmtDelChunk->execute();
        $stmtDelChunk->close();

        $chunks = chunkFileContent($content, $filename);
        $embedModel = 'amazon.titan-embed-text-v2:0';
        $totalInputTokens = 0;

        foreach ($chunks as $chunk) {
            $stmtChunk = $db->prepare("INSERT INTO SourceChunks (source_id_, project_id_, chunk_type, name, content, start_line, end_line) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtChunk->bind_param('iisssii', $sourceId, $projectId, $chunk['type'], $chunk['name'], $chunk['content'], $chunk['start'], $chunk['end']);
            $stmtChunk->execute();
            $chunk_id = (int) $db->insert_id;
            $stmtChunk->close();

            $body = json_encode(['inputText' => mb_substr($chunk['content'], 0, 8000)]);
            $emb_result = $bedrock->invokeModel([
                'modelId' => $embedModel,
                'contentType' => 'application/json',
                'accept' => 'application/json',
                'body' => $body
            ]);
            $emb_response = json_decode((string)$emb_result['body'], true);
            $embedding_array = $emb_response['embedding'] ?? [];
            $embedding_json = json_encode($embedding_array);
            $totalInputTokens += (int)($emb_response['inputTextTokenCount'] ?? 0);

            $stmtEmb = $db->prepare("INSERT INTO ChunkEmbeddings (chunk_id_, model_id, dimensions, embedding, embedding_json) VALUES (?, ?, ?, ?, ?)");
            $dims = count($embedding_array);
            $stmtEmb->bind_param('isiss', $chunk_id, $embedModel, $dims, $embedding_json, $embedding_json);
            $stmtEmb->execute();
            $stmtEmb->close();
        }

        $stmtUpdate = $db->prepare("UPDATE ProjectSources SET status = 'indexed', indexed_at = NOW() WHERE id_ = ?");
        $stmtUpdate->bind_param('i', $sourceId);
        $stmtUpdate->execute();
        $stmtUpdate->close();

        return ['ok' => true, 'chunks' => count($chunks), 'input_tokens' => $totalInputTokens, 'model' => $embedModel];
    }
}
