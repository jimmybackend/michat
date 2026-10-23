<?php
declare(strict_types=1);
require_once __DIR__.'/DocumentProcessingException.php';

/** Bounded download of the already authorized exact object revision. */
final class S3DocumentDownload
{
    public static function save(object $s3,string $bucket,string $key,array $head,string $path,int $limit=67108864): void
    {
        $expected=(int)($head['ContentLength']??-1);
        if ($expected===0) throw new DocumentProcessingException('source_download','empty_object','El objeto original de S3 está vacío; no hay contenido que indexar.');
        if ($expected<0 || empty($head['ETag'])) throw new DocumentProcessingException('source_download','invalid_object_metadata','No se pudo verificar el tamaño y la revisión del original en S3.');
        if ($expected>$limit) throw new DocumentProcessingException('source_download','resource_limit','El archivo excede 64 MiB y requiere procesamiento por lotes.');
        $result=null;
        for($attempt=0;$attempt<3;$attempt++) {
            try {
                // SaveAs is supported by the AWS SDK; Body fallback also handles
                // compatible clients that return a stream without writing SaveAs.
                $result=$s3->getObject(['Bucket'=>$bucket,'Key'=>$key,'IfMatch'=>(string)$head['ETag'],'SaveAs'=>$path]);
                break;
            } catch(Throwable $e) {
                $status=method_exists($e,'getStatusCode')?(int)$e->getStatusCode():0;
                $transient=in_array($status,[429,500,502,503,504],true);
                if(!$transient || $attempt===2) throw $e;
                @unlink($path);usleep(100000*($attempt+1));
            }
        }
        $body=$result['Body']??null;
        try {
            clearstatcache(true,$path);
            if (!is_file($path) || filesize($path)!==$expected) {
                if (is_object($body) && method_exists($body,'isSeekable') && $body->isSeekable()) $body->rewind();
                $out=@fopen($path,'wb');
                if(!$out) throw new DocumentProcessingException('source_download','temporary_storage','No se pudo escribir la copia temporal del archivo. Revisa espacio y permisos del servidor.');
                try {
                    $written=0;
                    if(is_string($body)) {
                        if(strlen($body)>$limit) throw new DocumentProcessingException('source_download','resource_limit','La descarga excede el límite de 64 MiB.');
                        self::write($out,$body);$written=strlen($body);
                    } elseif(is_resource($body) || (is_object($body)&&method_exists($body,'read')&&method_exists($body,'eof'))) {
                        while(is_resource($body)?!feof($body):!$body->eof()) {
                            $chunk=is_resource($body)?fread($body,65536):$body->read(65536);
                            if(!is_string($chunk) || $chunk==='') {
                                if(is_resource($body)?feof($body):$body->eof()) break;
                                throw new DocumentProcessingException('source_download','incomplete_download','La descarga del original se interrumpió; no se indexó una copia incompleta.');
                            }
                            $written+=strlen($chunk);
                            if($written>$limit || $written>$expected) throw new DocumentProcessingException('source_download','size_mismatch','El tamaño descargado no corresponde a la revisión original; reintenta.');
                            self::write($out,$chunk);
                        }
                    }
                } finally {fclose($out);}
            }
            clearstatcache(true,$path);
            if(!is_file($path) || filesize($path)!==$expected) throw new DocumentProcessingException('source_download','incomplete_download','No se descargó el archivo completo de S3. El índice no se marcó como terminado.');
        } finally {
            if(is_resource($body)) fclose($body);
            elseif(is_object($body)&&method_exists($body,'close')) $body->close();
        }
    }
    private static function write($out,string $bytes): void
    {
        while($bytes!=='') {
            $n=fwrite($out,$bytes);
            if($n===false || $n===0) throw new DocumentProcessingException('source_download','temporary_storage','No se pudo completar la escritura temporal. Revisa el espacio disponible.');
            $bytes=substr($bytes,$n);
        }
    }
}
