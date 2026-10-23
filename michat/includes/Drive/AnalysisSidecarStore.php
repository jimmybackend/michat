<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/session_file_extractor.php';

/** Private sibling JSON. No bucket listing, public ACL, source overwrite or DB bypass. */
final class AnalysisSidecarStore
{
    public const MAX_BYTES = 2097152;
    public function __construct(private object $s3,private string $bucket) {}
    public static function key(array $file): string
    {
        return idx_build_s3_key($file).'.michat-u'.(int)$file['user_id_'].'.analysis.v1.json';
    }
    public function identity(array $file): array
    {
        $key=idx_build_s3_key($file);
        $head=$this->s3->headObject(['Bucket'=>$this->bucket,'Key'=>$key]);
        $etag=(string)($head['ETag'] ?? '');
        if ($etag==='') throw new RuntimeException('No se pudo verificar la revisión del original');
        return ['file_id'=>(int)$file['id_'],'owner_id'=>(int)$file['user_id_'],'key'=>$key,'etag'=>$etag,
            'filename'=>(string)$file['Nombre'],'bytes'=>(int)($head['ContentLength'] ?? 0)];
    }
    public static function revisionKey(array $source): string
    {
        return hash('sha256',json_encode([$source['file_id'],$source['owner_id'],$source['key'],$source['etag'],$source['filename']],JSON_THROW_ON_ERROR));
    }
    public function read(array $file,?array $source=null): array
    {
        $source ??= $this->identity($file); $key=self::key($file); $etag=null;
        try {
            $head=$this->s3->headObject(['Bucket'=>$this->bucket,'Key'=>$key]);
            if ((int)($head['ContentLength'] ?? PHP_INT_MAX)>self::MAX_BYTES) throw new RuntimeException('El JSON de análisis excede 2 MiB; no se truncó');
            $object=$this->s3->getObject(['Bucket'=>$this->bucket,'Key'=>$key,'IfMatch'=>(string)$head['ETag']]);
            $body=(string)$object['Body'];
            if (strlen($body)>self::MAX_BYTES) throw new RuntimeException('JSON demasiado grande');
            $doc=json_decode($body,true,64,JSON_THROW_ON_ERROR);
            if (!is_array($doc) || ($doc['format'] ?? '')!=='michat.file-analysis' || ($doc['version'] ?? 0)!==1
                || ($doc['owner_id'] ?? 0)!==$source['owner_id'] || ($doc['file_id'] ?? 0)!==$source['file_id'] || ($doc['source_key'] ?? '')!==$source['key']
                || !is_array($doc['revisions'] ?? null)) throw new RuntimeException('El JSON existente no corresponde al archivo; no se sobrescribió');
            $etag=(string)$head['ETag'];
        } catch (Throwable $e) {
            $code=method_exists($e,'getAwsErrorCode') ? $e->getAwsErrorCode() : '';
            if (!in_array($code,['NoSuchKey','NotFound','404'],true)) throw $e;
            $doc=['format'=>'michat.file-analysis','version'=>1,'owner_id'=>$source['owner_id'],'file_id'=>$source['file_id'],
                'source_key'=>$source['key'],'revisions'=>[]];
        }
        $revision=self::revisionKey($source);
        return ['document'=>$doc,'etag'=>$etag,'source'=>$source,'revision_key'=>$revision,'analysis'=>$doc['revisions'][$revision] ?? null];
    }
    public function write(array $file,array $state,array $analysis): void
    {
        $current=$this->identity($file);
        if (self::revisionKey($current)!==$state['revision_key']) throw new RuntimeException('El original cambió durante el análisis; reintenta');
        $doc=$state['document'];$doc['revisions'][$state['revision_key']]=$analysis;$doc['updated_at']=gmdate('c');
        $body=json_encode($doc,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
        if (strlen($body)>self::MAX_BYTES) throw new RuntimeException('El historial JSON alcanzó 2 MiB. No se descartaron interacciones; conserva la copia en ChatMessages.');
        $args=['Bucket'=>$this->bucket,'Key'=>self::key($file),'Body'=>$body,'ContentType'=>'application/json','CacheControl'=>'private, no-store'];
        // Atomic conditional write also rejects concurrent writers outside this application.
        if ($state['etag']!==null) $args['IfMatch']=$state['etag']; else $args['IfNoneMatch']='*';
        $this->s3->putObject($args);
    }
}
