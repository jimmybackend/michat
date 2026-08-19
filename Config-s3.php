<?php
declare(strict_types=1);
use Aws\BedrockRuntime\BedrockRuntimeClient;use Aws\Textract\TextractClient;use Aws\S3\S3Client;
final class Config{
 public const REGION='us-east-1',BUCKET='yours3bucket',DEFAULT_USER_ID=1,RUTA_RAIZ='Data/',RUTA_COMPARTIDA='Data/Compartidos/';
 public static function getRegion():string{$v=trim((string)(getenv('AWS_REGION')?:getenv('AWS_DEFAULT_REGION')?:''));return$v!==''?$v:self::REGION;}
 public static function getBucket():string{$v=trim((string)(getenv('AWS_S3_BUCKET')?:''));return$v!==''?$v:self::BUCKET;}
 public static function getAwsCredentials():?array{$k=trim((string)(getenv('AWS_ACCESS_KEY_ID')?:''));$s=trim((string)(getenv('AWS_SECRET_ACCESS_KEY')?:''));if($k===''&&$s==='')return null;if($k===''||$s==='')throw new RuntimeException('La configuración AWS explícita está incompleta.');$c=['key'=>$k,'secret'=>$s];$t=trim((string)(getenv('AWS_SESSION_TOKEN')?:''));if($t!=='')$c['token']=$t;return$c;}
 public static function awsClientConfig(array$o=[]):array{$c=array_replace_recursive(['region'=>self::getRegion(),'version'=>'latest'],$o);$x=self::getAwsCredentials();if($x!==null)$c['credentials']=$x;return$c;}
 public static function getS3():S3Client{return new S3Client(self::awsClientConfig());}
 public static function getBedrockRuntime(array$o=[]):BedrockRuntimeClient{return new BedrockRuntimeClient(self::awsClientConfig(array_replace_recursive(['http'=>['connect_timeout'=>20,'timeout'=>240]],$o)));}
 public static function getTextract(array$o=[]):TextractClient{return new TextractClient(self::awsClientConfig(array_replace_recursive(['http'=>['connect_timeout'=>15,'timeout'=>120]],$o)));}
}
