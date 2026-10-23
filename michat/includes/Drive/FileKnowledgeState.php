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
    public static function failure(Throwable $error): array
    {
        $code=method_exists($error,'getAwsErrorCode')?(string)$error->getAwsErrorCode():'';
        $raw=$error->getMessage();
        if ($error instanceof PdfOcrPendingException) return ['status'=>'ocr_pending','error_code'=>'ocr_pending','message'=>$raw,'retryable'=>true];
        if ($code==='AccessDeniedException' || $code==='AccessDenied' || str_contains($raw,'AccessDenied')) return ['status'=>'failed','error_code'=>'provider_permission','message'=>'La aplicación no pudo leer o procesar el original con los permisos actuales de AWS. El vínculo no concede acceso al contenido.','retryable'=>true];
        if ($code==='UnsupportedDocumentException' || str_contains($raw,'UnsupportedDocumentException')) $message='Textract rechazó el formato del PDF; comprueba protección, integridad y compatibilidad. No se extrajo contenido.';
        elseif (str_contains($raw,'Firma PDF inválida')) $message='El archivo tiene extensión PDF, pero su contenido no tiene una firma PDF válida.';
        elseif (str_contains($raw,'desactivad') || str_contains($raw,'UserAIAgentConfigs') || str_contains($raw,'model_id')) $message='El análisis semántico no está disponible con la configuración actual. Los fragmentos extraídos se conservan.';
        elseif (str_contains($raw,'no produjo texto') || str_contains($raw,'texto extraíble') || str_contains($raw,'No se pudo extraer')) $message='No se pudo extraer texto: puede ser un PDF escaneado, protegido o faltar un lector/OCR disponible.';
        else $message='No se completó el procesamiento del contenido. Reintenta el archivo y revisa el registro del servidor si persiste.';
        return ['status'=>'failed','error_code'=>'processing_failed','message'=>$message,'retryable'=>true];
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
