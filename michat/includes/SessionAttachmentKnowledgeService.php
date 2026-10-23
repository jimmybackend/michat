<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/S3Manager.php';
require_once __DIR__ . '/ai_agent_runtime.php';
require_once __DIR__ . '/session_file_extractor.php';
require_once __DIR__ . '/Memory/DocumentAccessRepository.php';

/**
 * Construye conocimiento utilizable a partir de adjuntos de una sesión.
 *
 * index(): crea file_chunk y deja sus embeddings listos cuando embedding_main está activo.
 * semantic(): crea/actualiza block_type=file con resumen semántico y su embedding.
 * process(): ejecuta ambos pasos para el upload automático.
 *
 * Si Bedrock falla al vectorizar, el bloque queda guardado y el EmbeddingJob permanece
 * pending para que Mantenimiento > Procesar Embeddings pueda reintentarlo.
 */
final class SessionAttachmentKnowledgeService
{
    private mysqli $db;
    private $bedrock = null;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /** @return array<string,mixed> */
    public function process(int $userId, int $sessionId, int $fileId): array
    {
        $this->loadRuntime($userId);
        $file = $this->ownedFile($userId, $sessionId, $fileId);
        if (getenv('MICHAT_DOCUMENT_KNOWLEDGE_ENABLED')==='1') {
            require_once __DIR__.'/Documents/DocumentKnowledgeService.php';
            require_once __DIR__.'/Documents/BedrockDocumentKnowledgeRuntime.php';
            return (new DocumentKnowledgeService($this->db,new BedrockDocumentKnowledgeRuntime($this->db)))->enqueue($userId,$sessionId,$fileId);
        }
        $extracted = idx_extract_files3_text($file, IDX_MAX_EXTRACTED_CHARS);
        $index = $this->indexPrepared($sessionId, $fileId, $file, $extracted);

        try {
            $semantic = $this->semanticPrepared($sessionId, $fileId, $file, $extracted);
        } catch (Throwable $e) {
            $semantic = [
                'ok' => false,
                'error' => $e->getMessage(),
                'embedding_ready' => false,
                'embedding_pending' => false,
            ];
        }

        return [
            'ok' => !empty($index['ok']),
            'file_id' => $fileId,
            'session_id' => $sessionId,
            'index' => $index,
            'semantic' => $semantic,
            'ready_for_attachment_rag' => !empty($index['embedding_ready']) && empty($index['truncated']),
            'lexical_rag_available' => !empty($index['ok']),
            'coverage' => !empty($index['truncated']) ? 'partial' : 'text_extracted',
        ];
    }

    /** @return array<string,mixed> */
    public function index(int $userId, int $sessionId, int $fileId): array
    {
        $this->loadRuntime($userId);
        $file = $this->ownedFile($userId, $sessionId, $fileId);
        $extracted = idx_extract_files3_text($file, IDX_MAX_EXTRACTED_CHARS);
        return $this->indexPrepared($sessionId, $fileId, $file, $extracted);
    }

    /** @return array<string,mixed> */
    public function semantic(int $userId, int $sessionId, int $fileId): array
    {
        $this->loadRuntime($userId);
        $file = $this->ownedFile($userId, $sessionId, $fileId);
        $s3Key = idx_build_s3_key($file);
        $maxChars = max(2000, (int)aiAgentExtra('attachment_semantic_prompt', 'max_content_chars', 24000));
        $extracted = idx_extract_files3_text($file, $maxChars);

        return $this->semanticPrepared($sessionId, $fileId, $file, $extracted);
    }

    /** Shared embedding adapter for central jobs; never silently truncates a chunk. */
    public function vectorizeDocumentChunk(int $userId,string $text,string $expectedModel): array
    {
        $this->loadRuntime($userId);
        $state=$this->embeddingState();
        if (!$state['active'] || $state['model']!==$expectedModel) throw new RuntimeException('Configuración de embedding cambió; solicita reintentar el documento');
        $isCohere3=str_contains(strtolower($expectedModel),'cohere.embed-') && str_contains(strtolower($expectedModel),'v3');
        $max=max(100,(int)aiAgentExtra('embedding_main','input_max_chars',$isCohere3?2048:8000));
        if ($isCohere3) $max=min($max,2048);
        if (mb_strlen($text)>$max) throw new RuntimeException('El chunk excede el límite configurado del modelo; no se vectorizó un prefijo');
        return $this->generateEmbedding($text,$expectedModel,'search_document');
    }

    private function loadRuntime(int $userId): void
    {
        if ($userId <= 0) throw new RuntimeException('Sesión inválida');
        aiRuntimeLoad($this->db, $userId);
    }

    /** @return array<string,mixed> */
    private function ownedFile(int $userId, int $sessionId, int $fileId): array
    {
        return (new DocumentAccessRepository($this->db))->requireFile($userId, $sessionId, $fileId);
    }

    /**
     * @param array<string,mixed> $file
     * @param array<string,mixed> $extracted
     * @return array<string,mixed>
     */
    private function indexPrepared(int $sessionId, int $fileId, array $file, array $extracted): array
    {
        $content = (string)($extracted['content'] ?? '');
        $ext = strtolower((string)($extracted['ext'] ?? ''));
        $s3Key = (string)($extracted['s3_key'] ?? idx_build_s3_key($file));
        if ($content === '') throw new RuntimeException('El archivo no contiene texto indexable');

        $chunks = idx_located_chunks($content, $ext, IDX_CHUNK_MAX_LEN);
        if (!$chunks) throw new RuntimeException('El archivo no generó chunks indexables');

        $embedding = $this->embeddingState();
        $blockIds = [];

        $this->db->begin_transaction();
        try {
            $this->deleteBlocks($sessionId, $s3Key, 'file_chunk');

            foreach ($chunks as $i => $located) {
                $chunkText = $located['content'];
                $meta = json_encode([
                    'filename' => (string)$file['Nombre'],
                    'files3_id' => $fileId,
                    'chunk' => $i + 1,
                    'locator' => $located['locator'],
                    'chunk_sha256' => $located['sha256'],
                    'source_sha256' => $extracted['source_sha256'] ?? null,
                    'source_etag' => $extracted['source_etag'] ?? null,
                    'total' => count($chunks),
                    'extractor' => (string)($extracted['extractor'] ?? 'unknown'),
                    'ext' => $ext,
                    'truncated' => !empty($extracted['truncated']),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $tokens = (int)ceil(mb_strlen($chunkText) / 4);

                $ins = $this->db->prepare("INSERT INTO SessionContextBlocks (session_id_,block_type,content_preview,s3_path,is_locked,source_ids,token_count) VALUES (?,'file_chunk',?,?,1,?,?)");
                if (!$ins) throw new RuntimeException('INSERT file_chunk: ' . $this->db->error);
                $ins->bind_param('isssi', $sessionId, $chunkText, $s3Key, $meta, $tokens);
                if (!$ins->execute()) {
                    $error = $ins->error;
                    $ins->close();
                    throw new RuntimeException('INSERT file_chunk: ' . $error);
                }
                $blockId = (int)$ins->insert_id;
                $ins->close();
                $blockIds[] = $blockId;

                if ($embedding['active']) {
                    $this->queueEmbedding($blockId, (string)$embedding['model']);
                }
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        $embeddingResult = $embedding['active']
            ? $this->embedBlocksNow($sessionId, $blockIds, (string)$embedding['model'])
            : ['ready' => 0, 'pending' => 0, 'errors' => []];

        return [
            'ok' => true,
            'chunks' => count($chunks),
            'extractor' => (string)($extracted['extractor'] ?? 'unknown'),
            'truncated' => !empty($extracted['truncated']),
            'coverage' => !empty($extracted['truncated']) ? 'partial' : 'text_extracted',
            'embedding_model' => $embedding['model'],
            'embedding_ready' => $embedding['active'] && $embeddingResult['ready'] === count($blockIds),
            'embedding_ready_count' => $embeddingResult['ready'],
            'embedding_pending_count' => $embeddingResult['pending'],
            'embedding_errors' => $embeddingResult['errors'],
            'semantic_preserved' => true,
        ];
    }

    /**
     * @param array<string,mixed> $file
     * @param array<string,mixed> $extracted
     * @return array<string,mixed>
     */
    private function semanticPrepared(int $sessionId, int $fileId, array $file, array $extracted): array
    {
        if (!aiAgentConfig('attachment_semantic_prompt')) {
            throw new RuntimeException('Falta attachment_semantic_prompt en UserAIAgentConfigs');
        }
        if (!aiAgentActive('attachment_semantic_prompt', false)) {
            throw new RuntimeException('La semántica de adjuntos está desactivada');
        }

        $maxChars = max(2000, (int)aiAgentExtra('attachment_semantic_prompt', 'max_content_chars', 24000));
        $s3Key = (string)($extracted['s3_key'] ?? idx_build_s3_key($file));
        $rawContent = (string)($extracted['content'] ?? '');
        $content = mb_substr($rawContent, 0, $maxChars);
        $extractor = (string)($extracted['extractor'] ?? 'unknown');
        $truncated = !empty($extracted['truncated']) || mb_strlen($rawContent) > $maxChars;
        if ($content === '') throw new RuntimeException('No hay contenido para generar semántica');

        $agentKey = $this->looksLikeCode((string)$file['Nombre'], $content)
            ? 'smart_memory_code'
            : 'smart_memory_general';
        if (!aiAgentConfig($agentKey)) throw new RuntimeException("Falta {$agentKey} en UserAIAgentConfigs");
        if (!aiAgentActive($agentKey, false)) throw new RuntimeException("{$agentKey} está desactivado");

        $modelId = aiAgentModel($agentKey, '');
        if ($modelId === '') throw new RuntimeException("{$agentKey} no tiene model_id");

        $system = aiAgentInstruction('attachment_semantic_prompt', '');
        $template = aiAgentUserTemplate('attachment_semantic_prompt', '');
        if ($system === '' || $template === '') {
            throw new RuntimeException('attachment_semantic_prompt no tiene instrucciones completas');
        }

        $prompt = aiRenderTemplate($template, [
            'filename' => (string)$file['Nombre'],
            'content' => mb_substr($content, 0, $maxChars),
        ]);
        $infer = [
            'maxTokens' => max(100, (int)aiAgentExtra('attachment_semantic_prompt', 'max_tokens', 800)),
            'temperature' => (float)aiAgentExtra('attachment_semantic_prompt', 'temperature', aiAgentValue($agentKey, 'temperature', 0.2)),
            'topP' => (float)aiAgentExtra('attachment_semantic_prompt', 'top_p', aiAgentValue($agentKey, 'top_p', 0.9)),
        ];
        $seed = max(0, (int)aiAgentValue($agentKey, 'seed', 0));
        if ($seed > 0) $infer['seed'] = $seed;

        $res = $this->bedrock()->converse([
            'modelId' => $modelId,
            'messages' => [['role' => 'user', 'content' => [['text' => $prompt]]]],
            'system' => [['text' => $system]],
            'inferenceConfig' => $infer,
        ]);

        $summary = '';
        foreach (($res['output']['message']['content'] ?? []) as $block) {
            if (isset($block['text'])) $summary .= (string)$block['text'];
        }
        $summary = trim($summary);
        if ($summary === '') throw new RuntimeException('La IA no devolvió resumen semántico');

        $usage = (array)($res['usage'] ?? []);
        $this->logSemanticUsage(
            $sessionId,
            $modelId,
            (int)($usage['inputTokens'] ?? 0),
            (int)($usage['outputTokens'] ?? 0)
        );

        $embedding = $this->embeddingState();
        $this->db->begin_transaction();
        try {
            $this->deleteBlocks($sessionId, $s3Key, 'file');
            $meta = json_encode([
                'filename' => (string)$file['Nombre'],
                'files3_id' => $fileId,
                'type' => 'semantic_summary',
                'source_etag' => $extracted['source_etag'] ?? null,
                'source_sha256' => $extracted['source_sha256'] ?? null,
                'semantic_model' => $modelId,
                'semantic_agent' => $agentKey,
                'extractor' => $extractor,
                'truncated' => $truncated,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $tokens = (int)ceil(mb_strlen($summary) / 4);

            $ins = $this->db->prepare("INSERT INTO SessionContextBlocks (session_id_,block_type,content_preview,s3_path,is_locked,source_ids,token_count) VALUES (?,'file',?,?,1,?,?)");
            if (!$ins) throw new RuntimeException('INSERT semantic block: ' . $this->db->error);
            $ins->bind_param('isssi', $sessionId, $summary, $s3Key, $meta, $tokens);
            if (!$ins->execute()) {
                $error = $ins->error;
                $ins->close();
                throw new RuntimeException('INSERT semantic block: ' . $error);
            }
            $blockId = (int)$ins->insert_id;
            $ins->close();
            if ($embedding['active']) $this->queueEmbedding($blockId, (string)$embedding['model']);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        $embeddingResult = $embedding['active']
            ? $this->embedBlocksNow($sessionId, [$blockId], (string)$embedding['model'])
            : ['ready' => 0, 'pending' => 0, 'errors' => []];

        return [
            'ok' => true,
            'resumen' => mb_substr($summary, 0, 300),
            'semantic_model' => $modelId,
            'semantic_agent' => $agentKey,
            'embedding_model' => $embedding['model'],
            'embedding_ready' => $embedding['active'] && $embeddingResult['ready'] === 1,
            'embedding_pending' => $embedding['active'] && $embeddingResult['ready'] !== 1,
            'embedding_errors' => $embeddingResult['errors'],
            'extractor' => $extractor,
        ];
    }

    private function deleteBlocks(int $sessionId, string $s3Key, string $blockType): void
    {
        $ids = [];
        $stmt = $this->db->prepare('SELECT id_ FROM SessionContextBlocks WHERE session_id_=? AND s3_path=? AND block_type=?');
        if (!$stmt) throw new RuntimeException('No se pudieron consultar bloques previos');
        $stmt->bind_param('iss', $sessionId, $s3Key, $blockType);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $ids[] = (int)$row['id_'];
        $stmt->close();

        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $types = str_repeat('i', count($ids));
            $jobs = $this->db->prepare("DELETE FROM EmbeddingJobs WHERE target_type='session_block' AND target_id IN ({$placeholders})");
            if ($jobs) {
                $jobs->bind_param($types, ...$ids);
                $jobs->execute();
                $jobs->close();
            }
        }

        $del = $this->db->prepare('DELETE FROM SessionContextBlocks WHERE session_id_=? AND s3_path=? AND block_type=?');
        if (!$del) throw new RuntimeException('No se pudieron eliminar bloques previos');
        $del->bind_param('iss', $sessionId, $s3Key, $blockType);
        $del->execute();
        $del->close();
    }

    /** @return array{active:bool,model:?string} */
    private function embeddingState(): array
    {
        $active = aiAgentActive('embedding_main', false);
        $model = $active ? trim((string)aiAgentModel('embedding_main', '')) : '';
        if ($active && $model === '') throw new RuntimeException('embedding_main está activo pero no tiene model_id');
        return ['active' => $active, 'model' => $model !== '' ? $model : null];
    }

    private function queueEmbedding(int $blockId, string $modelId): void
    {
        $stmt = $this->db->prepare("INSERT INTO EmbeddingJobs (target_type,target_id,model_id,status,attempts) VALUES ('session_block',?,?,'pending',0) ON DUPLICATE KEY UPDATE status='pending',attempts=0,error_message=NULL,updated_at=NOW()");
        if (!$stmt) throw new RuntimeException('No se pudo encolar embedding: ' . $this->db->error);
        $stmt->bind_param('is', $blockId, $modelId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('No se pudo encolar embedding: ' . $error);
        }
        $stmt->close();
    }

    /**
     * @param int[] $blockIds
     * @return array{ready:int,pending:int,errors:array<int,string>}
     */
    private function embedBlocksNow(int $sessionId, array $blockIds, string $modelId): array
    {
        $ready = 0;
        $pending = 0;
        $errors = [];

        $immediateLimit = max(0, min(32, (int)aiAgentExtra('embedding_main', 'attachment_immediate_blocks', 8)));
        foreach ($blockIds as $position => $blockId) {
            if ($position >= $immediateLimit) { $pending++; continue; }
            try {
                $stmt = $this->db->prepare('SELECT content_preview FROM SessionContextBlocks WHERE id_=? AND session_id_=? LIMIT 1');
                if (!$stmt) throw new RuntimeException('No se pudo leer bloque para embedding');
                $stmt->bind_param('ii', $blockId, $sessionId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $text = trim((string)($row['content_preview'] ?? ''));
                if ($text === '') throw new RuntimeException('Bloque sin contenido vectorizable');

                $data = $this->generateEmbedding($text, $modelId, 'search_document');
                $vector = (array)($data['embedding'] ?? []);
                if (!$vector) throw new RuntimeException('Embedding vacío');
                $this->saveSessionBlockEmbedding($blockId, $vector, $modelId);

                $done = $this->db->prepare("UPDATE EmbeddingJobs SET status='completed',attempts=attempts+1,error_message=NULL,updated_at=NOW() WHERE target_type='session_block' AND target_id=? AND model_id=?");
                if ($done) {
                    $done->bind_param('is', $blockId, $modelId);
                    $done->execute();
                    $done->close();
                }
                $this->logEmbeddingUsage($sessionId, $modelId, (int)($data['inputTokens'] ?? 0));
                $ready++;
            } catch (Throwable $e) {
                $pending++;
                $errors[$blockId] = $e->getMessage();
                $message = mb_substr($e->getMessage(), 0, 1000);
                $retry = $this->db->prepare("UPDATE EmbeddingJobs SET status='pending',error_message=?,updated_at=NOW() WHERE target_type='session_block' AND target_id=? AND model_id=?");
                if ($retry) {
                    $retry->bind_param('sis', $message, $blockId, $modelId);
                    $retry->execute();
                    $retry->close();
                }
                error_log('SESSION_ATTACHMENT_EMBEDDING: ' . $e->getMessage());
            }
        }

        return ['ready' => $ready, 'pending' => $pending, 'errors' => $errors];
    }

    /** @return array<string,mixed> */
    private function generateEmbedding(string $text, string $modelId, string $inputType): array
    {
        $modelLower = strtolower($modelId);
        $configuredAdapter = strtolower(trim((string)aiAgentExtra('embedding_main', 'adapter', '')));
        if (strpos($modelLower, 'amazon.titan-embed-text-v2') !== false) $adapter = 'titan_text_v2';
        elseif (strpos($modelLower, 'amazon.titan-embed-text-v1') !== false) $adapter = 'titan_text_v1';
        elseif (strpos($modelLower, 'cohere.embed-v4') !== false) $adapter = 'cohere_embed_v4';
        elseif (strpos($modelLower, 'cohere.embed-english-v3') !== false || strpos($modelLower, 'cohere.embed-multilingual-v3') !== false) $adapter = 'cohere_embed_v3';
        else $adapter = $configuredAdapter;

        if (!in_array($adapter, ['titan_text_v2','titan_text_v1','cohere_embed_v4','cohere_embed_v3'], true)) {
            throw new RuntimeException("El modelo de embedding '{$modelId}' no tiene adaptador soportado");
        }

        $defaultMaxChars = $adapter === 'cohere_embed_v3' ? 2048 : 8000;
        $maxChars = max(100, (int)aiAgentExtra('embedding_main', 'input_max_chars', $defaultMaxChars));
        if ($adapter === 'cohere_embed_v3') $maxChars = min($maxChars, 2048);
        $input = mb_substr($text, 0, $maxChars);
        $dimensions = $adapter === 'titan_text_v1'
            ? 1536
            : ($adapter === 'cohere_embed_v3' ? 1024 : max(1, (int)aiAgentExtra('embedding_main', 'dimensions', 1024)));

        if ($adapter === 'titan_text_v2') {
            if (!in_array($dimensions, [256,512,1024], true)) throw new RuntimeException('Titan V2 requiere 256, 512 o 1024 dimensiones');
            $body = ['inputText'=>$input,'dimensions'=>$dimensions,'normalize'=>(bool)aiAgentExtra('embedding_main','normalize',true)];
            $cohereInputType = null;
        } elseif ($adapter === 'titan_text_v1') {
            $body = ['inputText'=>$input];
            $cohereInputType = null;
        } else {
            $isDocument = $inputType === 'search_document';
            $cohereInputType = (string)aiAgentExtra(
                'embedding_main',
                $isDocument ? 'document_input_type' : 'query_input_type',
                $isDocument ? 'search_document' : 'search_query'
            );
            if (!in_array($cohereInputType, ['search_document','search_query'], true)) {
                $cohereInputType = $isDocument ? 'search_document' : 'search_query';
            }
            if ($adapter === 'cohere_embed_v3') {
                $body = ['texts'=>[$input],'input_type'=>$cohereInputType,'truncate'=>(string)aiAgentExtra('embedding_main','truncate','END')];
            } else {
                if (!in_array($dimensions, [256,512,1024,1536], true)) throw new RuntimeException('Cohere Embed v4 requiere 256, 512, 1024 o 1536 dimensiones');
                $body = [
                    'texts'=>[$input],
                    'input_type'=>$cohereInputType,
                    'embedding_types'=>['float'],
                    'output_dimension'=>$dimensions,
                    'truncate'=>(string)aiAgentExtra('embedding_main','truncate','RIGHT'),
                ];
            }
        }

        $res = $this->bedrock()->invokeModel([
            'modelId'=>$modelId,
            'contentType'=>'application/json',
            'accept'=>'application/json',
            'body'=>json_encode($body, JSON_UNESCAPED_UNICODE),
        ]);
        $data = json_decode((string)$res['body'], true);
        if (!is_array($data)) throw new RuntimeException('Respuesta JSON inválida del modelo de embedding');

        if ($adapter === 'titan_text_v2' || $adapter === 'titan_text_v1') {
            $vector = $data['embedding'] ?? [];
            $inputTokens = (int)($data['inputTextTokenCount'] ?? 0);
        } else {
            $vectors = $data['embeddings'] ?? [];
            if (is_array($vectors) && isset($vectors['float'])) $vectors = $vectors['float'];
            $vector = is_array($vectors) && isset($vectors[0]) && is_array($vectors[0]) ? $vectors[0] : [];
            $inputTokens = 0;
        }
        if (!is_array($vector) || !$vector) throw new RuntimeException('Bedrock no devolvió embedding válido');
        if (count($vector) !== $dimensions) {
            throw new RuntimeException('Dimensión inesperada de embedding: ' . count($vector) . '; esperada ' . $dimensions);
        }

        foreach ($vector as $value) {
            if (!is_numeric($value) || !is_finite((float)$value)) throw new RuntimeException('Embedding contiene valores no numéricos o no finitos');
        }

        return [
            'embedding'=>$vector,
            'inputTokens'=>$inputTokens,
            'adapter'=>$adapter,
            'dimensions'=>$dimensions,
            'input_type'=>$cohereInputType,
        ];
    }

    /** @param float[] $vector */
    private function saveSessionBlockEmbedding(int $blockId, array $vector, string $modelId): void
    {
        $binary = '';
        foreach ($vector as $value) $binary .= pack('g', (float)$value);
        $json = json_encode($vector, JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new RuntimeException('No se pudo serializar embedding');

        $stmt = $this->db->prepare('UPDATE SessionContextBlocks SET embedding=?,embedding_json=?,embedding_model=? WHERE id_=?');
        if (!$stmt) throw new RuntimeException('No se pudo preparar guardado de embedding');
        $blob = null;
        $stmt->bind_param('bssi', $blob, $json, $modelId, $blockId);
        $stmt->send_long_data(0, $binary);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('No se pudo guardar embedding: ' . $error);
        }
        $stmt->close();
    }

    private function looksLikeCode(string $name, string $text): bool
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, ['php','phtml','inc','js','mjs','cjs','jsx','ts','tsx','css','scss','less','html','htm','json','xml','yaml','yml','sql','sh','bash','zsh','py','rb','java','c','h','cpp','hpp','cs','go','rs','swift','kt','kts','vue'], true)) return true;
        return preg_match('/\b(function|class|const|let|var|import|export|return|SELECT\s+.+\s+FROM|INSERT\s+INTO|<\?php|=>|endpoint|query|database|c[oó]digo|script|variable|api)\b/i', $text) === 1;
    }

    private function bedrock()
    {
        if ($this->bedrock === null) {
            $this->bedrock = Config::getBedrockRuntime(['http'=>['connect_timeout'=>10,'timeout'=>120]]);
        }
        return $this->bedrock;
    }

    private function logSemanticUsage(int $sessionId, string $modelId, int $input, int $output): void
    {
        try {
            $phase = 'summarize';
            $cost = 0.0;
            $duration = 0;
            $stmt = $this->db->prepare('INSERT INTO TokenUsage (session_id_,message_id_,phase,model_id,input_tokens,output_tokens,estimated_cost_usd,duration_ms) VALUES (?,NULL,?,?,?,?,?,?)');
            if ($stmt) {
                $stmt->bind_param('issiidi', $sessionId, $phase, $modelId, $input, $output, $cost, $duration);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('SESSION_ATTACHMENT_SEMANTIC_USAGE: ' . $e->getMessage());
        }
    }

    private function logEmbeddingUsage(int $sessionId, string $modelId, int $inputTokens): void
    {
        if ($inputTokens <= 0) return;
        try {
            $phase = (string)aiAgentValue('embedding_main', 'token_usage_phase', 'rag');
            $output = 0;
            $cost = round(($inputTokens / 1000000) * 0.10, 6);
            $duration = 0;
            $stmt = $this->db->prepare('INSERT INTO TokenUsage (session_id_,message_id_,phase,model_id,input_tokens,output_tokens,estimated_cost_usd,duration_ms) VALUES (?,NULL,?,?,?,?,?,?)');
            if ($stmt) {
                $stmt->bind_param('issiidi', $sessionId, $phase, $modelId, $inputTokens, $output, $cost, $duration);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('SESSION_ATTACHMENT_EMBEDDING_USAGE: ' . $e->getMessage());
        }
    }
}
