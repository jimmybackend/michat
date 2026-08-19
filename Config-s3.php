<?php
declare(strict_types=1);

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Textract\TextractClient;
use Aws\S3\S3Client;

final class Config
{
    /** ============ AJUSTA ESTO ============ */
    public const REGION      = 'us-east-1';
    public const BUCKET      = 'yours3bucket';

    /**
     * Recomendado:
     * - NO hardcodear en producción.
     * - Preferir variables de entorno AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY.
     *
     * Si las dejas aquí, al menos mantén este archivo fuera de public_html.
     */
     
    public const ACCESS_KEY = 'AxxooXXooxxxoxoxoxoxQ';
    public const SECRET_KEY = 'uUYxxooXXooxxxoxoxoxox+IS';

    public const DEFAULT_USER_ID = 1;

    public const RUTA_RAIZ      = 'Data/';
    public const RUTA_COMPARTIDA = 'Data/Compartidos/';

    /** ============ IMPORTANTE ============ */
    public static function bootAwsEnv(): void
    {
        // Evita que el SDK intente 169.254.169.254 (IMDS) cuando NO estás en EC2
        putenv('AWS_EC2_METADATA_DISABLED=true');
    }

    /** Credenciales: ENV → constantes */
   public static function getAwsCredentials(): array
        {
            self::bootAwsEnv();
        
            $key = getenv('AWS_ACCESS_KEY_ID');
            $sec = getenv('AWS_SECRET_ACCESS_KEY');
        
            if (!$key || !$sec) {
                // si no hay env vars, cae a constantes
                $key = defined(__CLASS__ . '::ACCESS_KEY') ? self::ACCESS_KEY : '';
                $sec = defined(__CLASS__ . '::SECRET_KEY') ? self::SECRET_KEY : '';
            }
        
            $key = is_string($key) ? trim($key) : '';
            $sec = is_string($sec) ? trim($sec) : '';
        
            if ($key === '' || $sec === '') {
                throw new RuntimeException('Faltan credenciales AWS. Define AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY o Config::ACCESS_KEY/SECRET_KEY.');
            }
        
            return ['key' => $key, 'secret' => $sec];
        }
        
        public static function getS3(): S3Client
        {
            return new S3Client([
                'region'      => self::REGION,
                'version'     => 'latest',
                'credentials' => self::getAwsCredentials(),
            ]);
        }
        
        public static function getBedrockRuntime(): BedrockRuntimeClient
        {
            return new BedrockRuntimeClient([
                'region'      => self::REGION,
                'version'     => 'latest',
                'credentials' => self::getAwsCredentials(),
                'http'        => ['connect_timeout' => 20, 'timeout' => 240],
            ]);
        }
        
        public static function getTextract(): TextractClient
        {
            return new TextractClient([
                'region'      => self::REGION,
                'version'     => 'latest',
                'credentials' => self::getAwsCredentials(),
                'http'        => ['connect_timeout' => 15, 'timeout' => 120],
            ]);
        }
}

