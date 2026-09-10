<?php
declare(strict_types=1);

/**
 * Contrato cerrado para generación de imágenes de MiChat.
 *
 * Mantiene separados los modelos de conversación/visión de los modelos que
 * realmente producen píxeles mediante Bedrock InvokeModel.
 */
final class ImageGenerationPolicy
{
    public const TITAN_IMAGE_V2 = 'amazon.titan-image-generator-v2:0';
    public const NOVA_CANVAS_V1 = 'amazon.nova-canvas-v1:0';
    public const DEFAULT_MODEL = self::TITAN_IMAGE_V2;

    /** @return list<string> */
    public static function allowedModels(): array
    {
        return [self::TITAN_IMAGE_V2, self::NOVA_CANVAS_V1];
    }

    public static function isAllowed(string $modelId): bool
    {
        return in_array(trim($modelId), self::allowedModels(), true);
    }

    public static function maxPromptChars(string $modelId): int
    {
        return trim($modelId) === self::NOVA_CANVAS_V1 ? 1024 : 512;
    }

    /**
     * 1024x1024 es una resolución segura compartida para la primera versión
     * de esta integración. El contrato queda preparado para ampliar presets
     * por modelo sin permitir dimensiones arbitrarias desde el navegador.
     *
     * @return array{width:int,height:int,quality:string,cfg_scale:float}
     */
    public static function defaults(): array
    {
        return [
            'width' => 1024,
            'height' => 1024,
            'quality' => 'standard',
            'cfg_scale' => 8.0,
        ];
    }

    /** @return array<string,mixed> */
    public static function defaultGlobalConfig(): array
    {
        return [
            'agent_key' => 'image_main',
            'agent_group' => 'image',
            'display_name' => 'Generación de imágenes',
            'description' => 'Genera imágenes desde texto con Amazon Bedrock y las guarda en el historial de MiChat.',
            'model_id' => self::DEFAULT_MODEL,
            'fallback_model_id' => self::NOVA_CANVAS_V1,
            'model_ladder_json' => json_encode(self::allowedModels(), JSON_UNESCAPED_SLASHES),
            'system_instruction' => 'Genera una imagen fiel a la solicitud del usuario. Prioriza composición clara, legibilidad visual y utilidad para ilustraciones, esquemas, diseños y material escolar.',
            'user_prompt_template' => '{{prompt}}',
            'temperature' => 0.0,
            'max_tokens_prompt' => 0,
            'max_tokens_output' => 0,
            'top_p' => 1.0,
            'seed' => 0,
            'max_attempts' => 1,
            'extra_config' => json_encode(self::defaults(), JSON_UNESCAPED_SLASHES),
            // TokenUsage no modela unidades de imagen; se conserva la fase
            // histórica de respuesta para telemetría de duración/modelo.
            'token_usage_phase' => 'respond',
            'is_active' => 1,
            'sort_order' => 350,
        ];
    }
}
