<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Memory/DocumentAccessRepository.php';

final class FileKnowledgeState
{
    public function __construct(private mysqli $db) {}
    public function save(int $user,int $session,int $file,array $state): void
    {
        $this->db->begin_transaction();
        try {
            $s=$this->db->prepare('SELECT meta FROM ChatSessions WHERE id_=? AND user_id_=? FOR UPDATE');$s->bind_param('ii',$session,$user);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
            if (!$row) throw new RuntimeException('Conversación no autorizada');
            $meta=json_decode((string)$row['meta'],true) ?: [];
            $meta['knowledge_file_states'][(string)$file]=$state+['updated_at'=>gmdate('c')];
            $json=json_encode($meta,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $s=$this->db->prepare('UPDATE ChatSessions SET meta=? WHERE id_=? AND user_id_=?');$s->bind_param('sii',$json,$session,$user);$s->execute();$s->close();$this->db->commit();
        } catch(Throwable $e) {$this->db->rollback();throw $e;}
    }
    public static function failure(Throwable $error, string $stage = 'processing'): array
    {
        $code=method_exists($error,'getAwsErrorCode')?(string)$error->getAwsErrorCode():'';
        $raw=$error->getMessage();
        if (in_array($code,['NoSuchKey','NotFound','404'],true)) return ['status'=>'failed','error_code'=>'source_missing','message'=>'El archivo figura en la base de datos, pero no se pudo localizar el objeto original en S3. Comprueba su clave real y el bucket configurado.','retryable'=>true];
        if ($error instanceof PdfOcrPendingException) return ['status'=>'ocr_pending','error_code'=>'ocr_pending','message'=>$raw,'retryable'=>true];
        if ($code==='AccessDeniedException' || $code==='AccessDenied' || str_contains($raw,'AccessDenied')) return ['status'=>'failed','error_code'=>'provider_permission','message'=>'La aplicación no pudo leer o procesar el original con los permisos actuales de AWS. El vínculo no concede acceso al contenido.','retryable'=>true];
        if (str_contains($raw,'AWS_S3_BUCKET')) $message='Falta configurar el bucket real de la aplicación (AWS_S3_BUCKET). El bucket de ejemplo no permite analizar archivos.';
        elseif ($code==='InvalidS3ObjectException' || str_contains($raw,'InvalidS3ObjectException')) $message='Textract no pudo leer el PDF en S3. Revisa la clave del original, la región y el permiso de lectura de Textract.';
        elseif (str_contains(get_class($error),'CredentialsException') || str_contains($raw,'Error retrieving credentials')) $message='No se pudieron obtener las credenciales AWS del servidor para analizar el archivo.';
        elseif ($error instanceof mysqli_sql_exception) $message='Falló una operación de base de datos durante el análisis. Comprueba el esquema y los permisos de la aplicación con la referencia del error.';
        elseif (str_contains($raw,'Formato no soportado')) $message='Este formato todavía no dispone de un lector de contenido compatible. El archivo sigue almacenado.';
        elseif (str_contains($raw,'64 MiB') || str_contains($raw,'presupuesto') || str_contains($raw,'10000 entradas')) $message='El archivo excede el límite de procesamiento inmediato y requiere procesamiento por lotes. No se marcó como analizado.';
        elseif (str_contains($raw,'Config::getTextract')) $message='La configuración privada del servidor no dispone del cliente Textract requerido para el OCR. Actualiza su integración sin cambiar las credenciales.';
        elseif (str_contains($raw,'ZipArchive') || str_contains($raw,'SQLite3')) $message='Falta una extensión de PHP necesaria para leer este formato en el servidor.';
        elseif (str_contains($raw,'temporal')) $message='No se pudo preparar el almacenamiento temporal de extracción. Revisa espacio y permisos del directorio temporal del servidor.';
        elseif (str_contains($raw,'ya está siendo analizado')) $message='Este archivo ya está siendo analizado. Espera unos segundos y vuelve a consultar.';
        elseif ($code==='UnsupportedDocumentException' || str_contains($raw,'UnsupportedDocumentException')) $message='Textract rechazó el formato del PDF; comprueba protección, integridad y compatibilidad. No se extrajo contenido.';
        elseif (str_contains($raw,'Firma PDF inválida')) $message='El archivo tiene extensión PDF, pero su contenido no tiene una firma PDF válida.';
        elseif (str_contains($raw,'desactivad') || str_contains($raw,'UserAIAgentConfigs') || str_contains($raw,'model_id')) $message='El análisis semántico no está disponible con la configuración actual. Los fragmentos extraídos se conservan.';
        elseif (str_contains($raw,'no produjo texto') || str_contains($raw,'texto extraíble') || str_contains($raw,'No se pudo extraer')) $message='No se pudo extraer texto: puede ser un PDF escaneado, protegido o faltar un lector/OCR disponible.';
        else $message='No se completó el procesamiento del contenido. Reintenta el archivo y revisa el registro del servidor si persiste.';
        $errorCode='processing_failed';
        if ($error instanceof mysqli_sql_exception) $errorCode='database_error';
        elseif (str_contains($raw,'AWS_S3_BUCKET')) $errorCode='bucket_configuration';
        elseif ($stage==='source_verification') $errorCode='source_read_failed';
        return ['status'=>'failed','error_code'=>$errorCode,'message'=>$message,'retryable'=>true];
    }
    /** Public diagnostics are safe; server logs carry the correlated technical cause. */
    public static function reportFailure(Throwable $error, string $stage, array $context = []): array
    {
        $state=self::failure($error,$stage);$state['stage']=$stage;
        if ($state['status']==='ocr_pending') return $state;
        $id='AK-'.bin2hex(random_bytes(6));$state['error_id']=$id;
        $labels=['access_recheck'=>'verificación de acceso','summary_persistence'=>'guardado del resumen','lock'=>'coordinación del trabajo','source_verification'=>'lectura del original en S3',
            'index_lookup'=>'consulta del índice','extraction_index'=>'extracción e indexación',
            'summary'=>'resumen semántico','source_recheck'=>'verificación de la revisión','state_persistence'=>'guardado del estado'];
        $state['message'].=' Etapa: '.($labels[$stage] ?? 'procesamiento').'. Referencia: '.$id.'.';
        $raw=preg_replace('~https?://[^\s"<>]+~i','[URL omitida]',$error->getMessage()) ?? '';
        $raw=preg_replace('/(SecretAccessKey|AccessKeyId|session_token|password|Authorization)([\s:=]+)[^\s,;]+/i','$1$2[omitido]',$raw) ?? '';
        $record=['error_id'=>$id,'stage'=>$stage,'exception'=>get_class($error),'exception_code'=>$error->getCode(),
            'aws_code'=>method_exists($error,'getAwsErrorCode')?$error->getAwsErrorCode():null,
            'cause'=>mb_substr($raw,0,2000),'source'=>basename($error->getFile()).':'.$error->getLine()];
        foreach(['user_id','session_id','file_id'] as $key) if(isset($context[$key])) $record[$key]=(int)$context[$key];
        error_log('FILE_ANALYSIS '.json_encode($record,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
        return $state;
    }

    /** Accurate metadata context even when no extractor or embedding succeeded. */
    public function availability(int $user,int $session,string $query=''): array
    {
        $access=new DocumentAccessRepository($this->db);$row=$access->session($user,$session);if(!$row)return [];
        $meta=json_decode((string)$row['meta'],true) ?: [];$ids=array_slice((array)($meta['knowledge_file_ids'] ?? []),0,100);$files=[];
        foreach($ids as $id) {
            $file=$access->file($user,(int)$id);if(!$file)continue;
            $state=$meta['knowledge_file_states'][(string)(int)$id] ?? [];
            $files[]=['file_id'=>(int)$id,'filename'=>$file['Nombre'],'status'=>$state['status'] ?? 'linked',
                'message'=>$state['message'] ?? 'Está vinculado; todavía no se ha confirmado extracción de contenido.'];
        }
        $named=$query!==''?array_values(array_filter($files,static fn($file)=>mb_stripos($query,$file['filename'])!==false)):[];
        return $named ?: $files;
    }
    public static function unavailableReply(object $bundle): ?string
    {
        $state=$bundle->telemetry()['file_availability'] ?? [];
        if (empty($state['explicit_question']) || empty($state['files']) || $bundle->selectedItems('attachments')) return null;
        $parts=['El archivo está vinculado a esta conversación, pero no hay contenido verificable recuperado para responder:'];
        foreach($state['files'] as $file) $parts[]=$file['filename'].': '.$file['message'];
        if (empty($state['retrieval_enabled'])) $parts[]='La recuperación de adjuntos está desactivada en las preferencias de esta conversación.';
        $parts[]='No puedo describir su contenido ni citar páginas hasta que la extracción esté disponible. Reintenta «Seleccionar y analizar» o la indexación del archivo; no necesitas volver a subir el original.';
        return implode("\n\n",$parts);
    }
}
