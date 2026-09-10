<?php
declare(strict_types=1);

/**
 * Contrato cerrado para generación de video de MiChat.
 *
 * La generación de video usa exclusivamente modelos Amazon en Bedrock y se
 * mantiene separada de los modelos de conversación, visión e imágenes.
 */
final class VideoGenerationPolicy
{
    public const NOVA_REEL_V11 = 'amazon.nova-reel-v1:1';
    public const NOVA_REEL_V10 = 'amazon.nova-reel-v1:0';
    public const DEFAULT_MODEL = self::NOVA_REEL_V11;

    /** @return list<string> */
    public static function allowedModels(): array
    {
        return [self::NOVA_REEL_V11, self::NOVA_REEL_V10];
    }

    public static function isAllowed(string $modelId): bool
    {
        return in_array(trim($modelId), self::allowedModels(), true);
    }

    public static function maxPromptChars(string $modelId): int
    {
        return 512;
    }

    /** @return list<string> */
    public static function supportedRegions(string $modelId): array
    {
        return trim($modelId) === self::NOVA_REEL_V11
            ? ['us-east-1']
            : ['us-east-1', 'eu-west-1', 'ap-northeast-1'];
    }

    public static function supportsRegion(string $modelId, string $region): bool
    {
        return in_array(trim($region), self::supportedRegions($modelId), true);
    }

    public static function fallbackForRegion(string $region): string
    {
        return trim($region) === 'us-east-1' ? self::NOVA_REEL_V11 : self::NOVA_REEL_V10;
    }

    /** @return array{duration_seconds:int,fps:int,dimension:string} */
    public static function defaults(): array
    {
        return [
            'duration_seconds' => 6,
            'fps' => 24,
            'dimension' => '1280x720',
        ];
    }

    public static function normalizeStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'completed', 'complete', 'succeeded', 'success', 'done' => 'completed',
            'failed', 'failure', 'error' => 'failed',
            'inprogress', 'in_progress', 'processing', 'running', 'generating', 'submitted', 'pending', 'queued' => 'in_progress',
            default => strtolower(trim($status)),
        };
    }

    /** @return array<string,mixed> */
    public static function defaultGlobalConfig(): array
    {
        return [
            'agent_key' => 'video_main',
            'agent_group' => 'video',
            'display_name' => 'Generación de videos',
            'description' => 'Genera clips de video con Amazon Nova Reel mediante Bedrock Async Invoke y los guarda en S3.',
            'model_id' => self::DEFAULT_MODEL,
            'fallback_model_id' => self::NOVA_REEL_V10,
            'model_ladder_json' => json_encode(self::allowedModels(), JSON_UNESCAPED_SLASHES),
            'system_instruction' => 'Genera un clip fiel a la solicitud del usuario. Prioriza una escena clara, movimiento coherente y una descripción visual concreta.',
            'user_prompt_template' => '{{prompt}}',
            'temperature' => 0.0,
            'max_tokens_prompt' => 0,
            'max_tokens_output' => 0,
            'top_p' => 1.0,
            'seed' => 0,
            'max_attempts' => 1,
            'extra_config' => json_encode(self::defaults(), JSON_UNESCAPED_SLASHES),
            'token_usage_phase' => 'respond',
            'is_active' => 1,
            'sort_order' => 360,
        ];
    }
}
