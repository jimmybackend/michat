<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/S3Manager.php';
require_once __DIR__ . '/ai_agent_runtime.php';
require_once __DIR__ . '/session_file_extractor.php';

/**
 * Convierte adjuntos de imagen en conocimiento textual reutilizable por el RAG.
 *
 * La imagen se analiza una sola vez con Bedrock Converse. La descripción visual
 * resultante se persiste como SessionContextBlocks.block_type='file' y se
 * vectoriza con embedding_main, igual que los resúmenes semánticos de documentos.
 */
final class SessionImageKnowledgeService
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    private const BEDROCK_MAX_IMAGE_BYTES = 3932160; // 3.75 MiB
    private const BEDROCK_MAX_IMAGE_DIMENSION = 8000;
    private const DEFAULT_VISION_MODEL = 'amazon.nova-lite-v1:0';

    private mysqli $db;
    private $bedrock = null;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public static function supportsFilename(string $filename): bool
    {
        $ext = strtolower(pathinfo(basename($filename), PATHINFO_EXTENSION));
        return in_array($ext, self::IMAGE_EXTENSIONS, true);
    }

    public function supports(int $userId, int $sessionId, int $fileId): bool
    {
        $file = $this->ownedFile($userId, $sessionId, $fileId);
        return self::supportsFilename((string)($file['Nombre'] ?? ''));
    }

    /** @return array<string,mixed> */
    public function process(int $userId, int $sessionId, int $fileId): array
    {
        $this->loadRuntime($userId);
        $file = $this->ownedFile($userId, $sessionId, $fileId);
        $filename = (string)($file['Nombre'] ?? 'imagen');
        if (!self::supportsFilename($filename)) {
            throw new InvalidArgumentException('El archivo no es una imagen soportada para visión.');
        }

        $s3Key = idx_build_s3_key($file);
        if ($s3Key === '') throw new RuntimeException('No se pudo resolver la clave S3 de la imagen.');

        $format = $this->bedrockImageFormat($filename);
        $image = $this->loadImageBytes($s3Key);
        $this->assertBedrockImageLimits($image['bytes'], $image['width'], $image['height']);

        $vision = $this->visionConfig();
        $prompt = $this->visionPrompt($filename);
        $system = $this->visionSystemInstruction();

        $res = $this->bedrock()->converse([
            'modelId' => $vision['model_id'],
            'messages' => [[
                'role' => 'user',
                'content' => [
                    [
                        'image' => [
                            'format' => $format,
                            'source' => ['bytes' => $image['bytes']],
                        ],
                    ],
                    ['text' => $prompt],
                ],
            ]],
            'system' => [['text' => $system]],
            'inferenceConfig' => [
                'maxTokens' => $vision['max_tokens'],
                'temperature' => $vision['temperature'],
                'topP' => $vision['top_p'],
            ],
        ]);

        $summary = '';
        foreach (($res['output']['message']['content'] ?? []) as $block) {
            if (isset($block['text']) && is_string($block['text'])) $summary .= $block['text'];
        }
        $summary = trim($summary);
        if ($summary === '') throw new RuntimeException('El modelo visual no devolvió una descripción utilizable.');

        $usage = is_array($res['usage'] ?? null) ? $res['usage'] : [];
        $this->logVisionUsage(
            $sessionId,
            $vision['model_id'],
            (int)($usage['inputTokens'] ?? 0),
            (int)($usage['outputTokens'] ?? 0)
        );

        $embedding = $this->embeddingState();
        $blockId = $this->persistVisualSummary(
            $sessionId,
            $fileId,
            $file,
            $s3Key,
            $summary,
            $vision['model_id'],
            $vision['source_agent'],
            $format,
            $image['width'],
            $image['height'],
            $embedding
        );

        $embeddingResult = $embedding['active']
            ? $this->embedBlocksNow($sessionId, [$blockId], (string)$embedding['model'])
            : ['ready' => 0, 'pending' => 0, 'errors' => []];
        $embeddingReady = $embedding['active'] && $embeddingResult['ready'] === 1;

        return [
            'ok' => true,
            'file_id' => $fileId,
            'session_id' => $sessionId,
            'type' => 'image',
            'extractor' => 'bedrock_converse_vision',
            'vision_model' => $vision['model_id'],
            'vision_agent' => $vision['source_agent'],
            'format' => $format,
            'width' => $image['width'],
            'height' => $image['height'],
            'resumen' => mb_substr($summary, 0, 300),
            'embedding_model' => $embedding['model'],
            'embedding_ready' => $embeddingReady,
            'embedding_pending' => $embedding['active'] && !$embeddingReady,
            'embedding_errors' => $embeddingResult['errors'],
            'ready_for_attachment_rag' => $embeddingReady,
            'semantic' => [
                'ok' => true,
                'type' => 'visual_summary',
                'model' => $vision['model_id'],
                'embedding_ready' => $embeddingReady,
            ],
        ];
    }

    private function loadRuntime(int $userId): void
    {
        if ($userId <= 0) throw new RuntimeException('Sesión inválida');
        aiRuntimeLoad($this->db, $userId);
    }

    /** @return array<string,mixed> */
    private function ownedFile(int $userId, int $sessionId, int $fileId): array
    {
        if ($userId <= 0 || $sessionId <= 0 || $fileId <= 0) {
            throw new InvalidArgumentException('user_id, session_id y file_id son obligatorios');
        }

        $session = $this->db->prepare('SELECT id_ FROM ChatSessions WHERE id_=? AND user_id_=? LIMIT 1');
        if (!$session) throw new RuntimeException('No se pudo validar la sesión');
        $session->bind_param('ii', $sessionId, $userId);
        $session->execute();
        $owned = $session->get_result()->num_rows > 0;
        $session->close();
        if (!$owned) throw new RuntimeException('La sesión no existe o no es tuya');

        $stmt = $this->db->prepare('SELECT id_,Nombre,Encriptado,Ruta,Found FROM FileS3 WHERE id_=? AND user_id_=? AND Found=1 LIMIT 1');
        if (!$stmt) throw new RuntimeException('No se pudo consultar FileS3');
        $stmt->bind_param('ii', $fileId, $userId);
        $stmt->execute();
        $file = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$file) throw new RuntimeException('El archivo no existe o no es tuyo');
        if (!idx_file_belongs_to_chat_session($file, $userId, $sessionId)) {
            throw new RuntimeException('El archivo no pertenece a esta sesión de chat');
        }
        return $file;
    }

    /** @return array{bytes:string,width:?int,height:?int} */
    private function loadImageBytes(string $s3Key): array
    {
        $manager = new S3Manager();
        $bucket = (string)$manager->getBucket();
        if ($bucket === '') throw new RuntimeException('Bucket S3 no configurado');

        $s3 = Config::getS3();
        try {
            $head = $s3->headObject(['Bucket' => $bucket, 'Key' => $s3Key]);
            $contentLength = (int)($head['ContentLength'] ?? 0);
            $configuredMax = (int)aiAgentExtra('attachment_vision', 'max_image_bytes', self::BEDROCK_MAX_IMAGE_BYTES);
            $maxBytes = max(1, min(self::BEDROCK_MAX_IMAGE_BYTES, $configuredMax));
            if ($contentLength > $maxBytes) {
                throw new RuntimeException('La imagen supera el límite de 3.75 MB permitido para análisis visual en Bedrock.');
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Si HeadObject no está disponible, getObject seguirá validando el tamaño después.
        }

        $result = $s3->getObject([
            'Bucket' => $bucket,
            'Key' => $s3Key,
        ]);
        $bytes = (string)($result['Body'] ?? '');
        if ($bytes === '') throw new RuntimeException('La imagen está vacía o no pudo leerse desde S3.');

        $width = null;
        $height = null;
        if (function_exists('getimagesizefromstring')) {
            $size = @getimagesizefromstring($bytes);
            if (is_array($size)) {
                $width = isset($size[0]) ? (int)$size[0] : null;
                $height = isset($size[1]) ? (int)$size[1] : null;
            }
        }
        return ['bytes' => $bytes, 'width' => $width, 'height' => $height];
    }

    private function assertBedrockImageLimits(string $bytes, ?int $width, ?int $height): void
    {
        $configuredMax = (int)aiAgentExtra('attachment_vision', 'max_image_bytes', self::BEDROCK_MAX_IMAGE_BYTES);
        $maxBytes = max(1, min(self::BEDROCK_MAX_IMAGE_BYTES, $configuredMax));
        if (strlen($bytes) > $maxBytes) {
            throw new RuntimeException('La imagen supera el límite de 3.75 MB permitido para análisis visual en Bedrock.');
        }
        if (($width !== null && $width > self::BEDROCK_MAX_IMAGE_DIMENSION)
            || ($height !== null && $height > self::BEDROCK_MAX_IMAGE_DIMENSION)) {
            throw new RuntimeException('La imagen supera el límite de 8000 px por dimensión permitido para análisis visual en Bedrock.');
        }
    }

    private function bedrockImageFormat(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'jpeg',
            'png' => 'png',
            'webp' => 'webp',
            'gif' => 'gif',
            default => throw new InvalidArgumentException('Formato de imagen no soportado para Bedrock Converse.'),
        };
    }

    /** @return array{model_id:string,max_tokens:int,temperature:float,top_p:float,source_agent:string} */
    private function visionConfig(): array
    {
        $cfg = aiAgentConfig('attachment_vision');
        if ($cfg && !aiAgentActive('attachment_vision', true)) {
            throw new RuntimeException('El análisis visual de adjuntos está desactivado.');
        }

        $modelId = trim((string)aiAgentModel('attachment_vision', ''));
        $sourceAgent = 'attachment_vision';
        if ($modelId === '') {
            $chatModel = trim((string)aiAgentModel('chat_main', ''));
            if ($this->looksVisionCapable($chatModel)) {
                $modelId = $chatModel;
                $sourceAgent = 'chat_main_fallback';
            } else {
                $modelId = self::DEFAULT_VISION_MODEL;
                $sourceAgent = 'builtin_fallback';
            }
        }
        if (!$this->looksVisionCapable($modelId)) {
            throw new RuntimeException("El modelo '{$modelId}' no está marcado como multimodal para adjuntos de imagen.");
        }

        return [
            'model_id' => $modelId,
            'max_tokens' => max(200, min(2000, (int)aiAgentExtra('attachment_vision', 'max_tokens', 1000))),
            'temperature' => max(0.0, min(1.0, (float)aiAgentValue('attachment_vision', 'temperature', 0.1))),
            'top_p' => max(0.01, min(1.0, (float)aiAgentValue('attachment_vision', 'top_p', 0.9))),
            'source_agent' => $sourceAgent,
        ];
    }

    private function looksVisionCapable(string $modelId): bool
    {
        $model = strtolower(trim($modelId));
        if ($model === '') return false;
        if (str_contains($model, 'embed') || str_contains($model, 'nova-micro') || str_contains($model, 'sonic')) return false;
        if (str_contains($model, 'amazon.nova-')) return true;
        return str_contains($model, 'anthropic.claude-3')
            || str_contains($model, 'anthropic.claude-4')
            || str_contains($model, 'anthropic.claude-sonnet-4')
            || str_contains($model, 'anthropic.claude-opus-4');
    }

    private function visionSystemInstruction(): string
    {
        return trim((string)aiAgentInstruction(
            'attachment_vision',
            'Analiza imágenes de forma objetiva para convertirlas en conocimiento recuperable. Describe solo lo observable. Extrae texto visible con fidelidad cuando sea legible, identifica estructuras, tablas, diagramas, código, interfaces, errores, objetos y relaciones relevantes. No inventes contenido oculto ni datos que no puedan verse.'
        ));
    }

    private function visionPrompt(string $filename): string
    {
        $template = aiAgentUserTemplate('attachment_vision', '');
        if ($template !== '') {
            return aiRenderTemplate($template, ['filename' => $filename]);
        }
        return "Analiza el archivo de imagen '{$filename}' y devuelve una descripción útil para futuras búsquedas semánticas. "
            . "Organiza la respuesta con: TIPO DE IMAGEN, DESCRIPCIÓN VISUAL, TEXTO VISIBLE, DATOS/OBJETOS IMPORTANTES, "
            . "RELACIONES O ESTRUCTURA, POSIBLES ERRORES/ALERTAS y TÉRMINOS CLAVE. Si una sección no aplica, indícalo brevemente. "
            . "Si contiene código, SQL, tablas, mensajes de error o nombres técnicos legibles, consérvalos con precisión.";
    }

    /** @param array{active:bool,model:?string} $embedding */
    private function persistVisualSummary(
        int $sessionId,
        int $fileId,
        array $file,
        string $s3Key,
        string $summary,
        string $modelId,
        string $sourceAgent,
        string $format,
        ?int $width,
        ?int $height,
        array $embedding
    ): int {
        $meta = json_encode([
            'filename' => (string)$file['Nombre'],
            'files3_id' => $fileId,
            'type' => 'visual_summary',
            'visual_model' => $modelId,
            'visual_agent' => $sourceAgent,
            'extractor' => 'bedrock_converse_vision',
            'format' => $format,
            'width' => $width,
            'height' => $height,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $tokens = (int)ceil(mb_strlen($summary) / 4);

        $this->db->begin_transaction();
        try {
            $this->deleteBlocks($sessionId, $s3Key, 'file');
            $ins = $this->db->prepare("INSERT INTO SessionContextBlocks (session_id_,block_type,content_preview,s3_path,is_locked,source_ids,token_count) VALUES (?,'file',?,?,1,?,?)");
            if (!$ins) throw new RuntimeException('INSERT visual summary: ' . $this->db->error);
            $ins->bind_param('isssi', $sessionId, $summary, $s3Key, $meta, $tokens);
            if (!$ins->execute()) {
                $error = $ins->error;
                $ins->close();
                throw new RuntimeException('INSERT visual summary: ' . $error);
            }
            $blockId = (int)$ins->insert_id;
            $ins->close();
            if ($embedding['active']) $this->queueEmbedding($blockId, (string)$embedding['model']);
            $this->db->commit();
            return $blockId;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    private function deleteBlocks(int $sessionId, string $s3Key, string $blockType): void
    {
        $ids = [];
        $stmt = $this->db->prepare('SELECT id_ FROM SessionContextBlocks WHERE session_id_=? AND s3_path=? AND block_type=?');
        if (!$stmt) throw new RuntimeException('No se pudieron consultar bloques visuales previos');
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
        if (!$del) throw new RuntimeException('No se pudieron eliminar bloques visuales previos');
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

    /** @param int[] $blockIds @return array{ready:int,pending:int,errors:array<int,string>} */
    private function embedBlocksNow(int $sessionId, array $blockIds, string $modelId): array
    {
        $ready = 0;
        $pending = 0;
        $errors = [];
        foreach ($blockIds as $blockId) {
            try {
                $stmt = $this->db->prepare('SELECT content_preview FROM SessionContextBlocks WHERE id_=? AND session_id_=? LIMIT 1');
                if (!$stmt) throw new RuntimeException('No se pudo leer bloque para embedding');
                $stmt->bind_param('ii', $blockId, $sessionId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $text = trim((string)($row['content_preview'] ?? ''));
                if ($text === '') throw new RuntimeException('Bloque visual sin contenido vectorizable');

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
                error_log('SESSION_IMAGE_EMBEDDING: ' . $e->getMessage());
            }
        }
        return ['ready' => $ready, 'pending' => $pending, 'errors' => $errors];
    }

    /** @return array<string,mixed> */
    private function generateEmbedding(string $text, string $modelId, string $inputType): array
    {
        $modelLower = strtolower($modelId);
        $configuredAdapter = strtolower(trim((string)aiAgentExtra('embedding_main', 'adapter', '')));
        if (str_contains($modelLower, 'amazon.titan-embed-text-v2')) $adapter = 'titan_text_v2';
        elseif (str_contains($modelLower, 'amazon.titan-embed-text-v1')) $adapter = 'titan_text_v1';
        elseif (str_contains($modelLower, 'cohere.embed-v4')) $adapter = 'cohere_embed_v4';
        elseif (str_contains($modelLower, 'cohere.embed-english-v3') || str_contains($modelLower, 'cohere.embed-multilingual-v3')) $adapter = 'cohere_embed_v3';
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

    private function bedrock()
    {
        if ($this->bedrock === null) {
            $this->bedrock = Config::getBedrockRuntime(['http'=>['connect_timeout'=>10,'timeout'=>120]]);
        }
        return $this->bedrock;
    }

    private function logVisionUsage(int $sessionId, string $modelId, int $input, int $output): void
    {
        try {
            $phase = (string)aiAgentValue('attachment_vision', 'token_usage_phase', 'vision');
            $cost = 0.0;
            $duration = 0;
            $stmt = $this->db->prepare('INSERT INTO TokenUsage (session_id_,message_id_,phase,model_id,input_tokens,output_tokens,estimated_cost_usd,duration_ms) VALUES (?,NULL,?,?,?,?,?,?)');
            if ($stmt) {
                $stmt->bind_param('issiidi', $sessionId, $phase, $modelId, $input, $output, $cost, $duration);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('SESSION_IMAGE_VISION_USAGE: ' . $e->getMessage());
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
            error_log('SESSION_IMAGE_EMBEDDING_USAGE: ' . $e->getMessage());
        }
    }
}
