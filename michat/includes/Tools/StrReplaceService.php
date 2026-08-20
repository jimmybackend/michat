<?php
declare(strict_types=1);

/** Server-side adapter for the existing ProjectSources/S3 str_replace flow. */
final class StrReplaceService
{
    public function __construct(private mysqli $db,private ?TaskCancellationGuard$cancellations=null) {}

    /** @param array<string,mixed> $arguments */
    public function execute(int $userId, int $projectId, array $arguments,int$taskId=0): ToolExecutionResult
    {
        try {
            $sourceId=(int)($arguments['source_id']??0);
            $old=$arguments['old_text']??$arguments['old_string']??null;
            $new=$arguments['new_text']??$arguments['new_string']??null;
            if($sourceId<1||!is_string($old)||$old===''||!is_string($new))throw new TaskValidationException('str_replace_arguments_invalid');

            $source=$this->ownedSource($userId,$projectId,$sourceId);
            $key=$this->safeProjectKey((string)$source['root_prefix'],(string)$source['s3_key']);
            $this->cancellations?->assertActive(['task_id'=>$taskId,'user_id'=>$userId]);
            $s3=Config::getS3();$bucket=Config::getBucket();
            $object=$s3->getObject(['Bucket'=>$bucket,'Key'=>$key]);
            $content=(string)$object['Body'];
            [$updated,$count]=self::replaceContent($content,$old,$new);
            $this->cancellations?->assertActive(['task_id'=>$taskId,'user_id'=>$userId]);
            $s3->putObject(['Bucket'=>$bucket,'Key'=>$key,'Body'=>$updated,'ContentType'=>(string)($source['mime_type']?:'text/plain'),'ACL'=>'private']);
            $this->cancellations?->assertActive(['task_id'=>$taskId,'user_id'=>$userId]);

            $index=['ok'=>false,'error'=>'ProjectIndexer no disponible'];
            if(function_exists('indexProjectSourceContent')){
                $index=indexProjectSourceContent($this->db,null,$projectId,$sourceId,(string)$source['filename'],$updated);
            }else{
                $stmt=$this->db->prepare("UPDATE ProjectSources SET status='stale',indexed_at=NULL WHERE id_=? AND project_id_=?");
                if($stmt){$stmt->bind_param('ii',$sourceId,$projectId);$stmt->execute();$stmt->close();}
            }
            $data=['file'=>(string)$source['filename'],'source_id'=>$sourceId,'replacements'=>$count,
                'status'=>!empty($index['ok'])?'embedding_queued':'marked_for_reindex','indexed'=>(bool)($index['indexed']??false),
                'index_queued'=>(bool)($index['queued']??false),'index_jobs'=>(int)($index['jobs']??0),
                'embedding_model'=>$index['model']??null,'needs_indexing'=>empty($index['ok']),
                'index_error'=>empty($index['ok'])?($index['error']??null):null];
            $artifacts=[['relation'=>'modified','resource_type'=>'project_source','resource_id'=>$sourceId]];
            return new ToolExecutionResult("str_replace modificó {$data['file']} ({$count} reemplazo(s)).",$artifacts,$data);
        }catch(TaskTransitionException $e){throw $e;
        }catch(Throwable $e){
            return new ToolExecutionResult('str_replace rechazado: '.$e->getMessage(),[],['error'=>$e->getMessage()],false,'error');
        }
    }

    /** Testable filesystem adapter using the same validation/replacement core; never used for repository files. */
    public static function replaceLocalFile(string $projectRoot,string $relativePath,string $old,string $new):array
    {
        if($old===''||str_contains($relativePath,"\0")||self::hasTraversal($relativePath))throw new InvalidArgumentException('str_replace_path_invalid');
        $root=realpath($projectRoot);$file=realpath(rtrim($projectRoot,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($relativePath,'/\\'));
        if($root===false||$file===false||!is_file($file)||($file!==$root&&!str_starts_with($file,$root.DIRECTORY_SEPARATOR)))throw new InvalidArgumentException('str_replace_file_forbidden');
        $content=file_get_contents($file);if($content===false)throw new RuntimeException('str_replace_read_failed');
        [$updated,$count]=self::replaceContent($content,$old,$new);
        if(file_put_contents($file,$updated,LOCK_EX)===false)throw new RuntimeException('str_replace_write_failed');
        return ['file'=>basename($file),'replacements'=>$count];
    }

    private function ownedSource(int$userId,int$projectId,int$sourceId):array
    {
        if($userId<1||$projectId<1)throw new TaskValidationException('tool_scope_invalid');
        $stmt=$this->db->prepare("SELECT ps.id_,ps.s3_key,ps.filename,ps.mime_type,p.root_prefix FROM ProjectSources ps JOIN Projects p ON p.id_=ps.project_id_ WHERE ps.id_=? AND ps.project_id_=? AND p.user_id_=? AND p.status<>'deleted' LIMIT 1");
        if(!$stmt)throw new RuntimeException('database_error');$stmt->bind_param('iii',$sourceId,$projectId,$userId);
        if(!$stmt->execute())throw new RuntimeException('database_error');$row=$stmt->get_result()->fetch_assoc();$stmt->close();
        if(!$row)throw new TaskValidationException('str_replace_source_forbidden');return$row;
    }

    private function safeProjectKey(string$rootPrefix,string$key):string
    {
        $root=trim(str_replace('\\','/',$rootPrefix),'/').'/';$key=ltrim(str_replace('\\','/',$key),'/');
        if($root==='/'||$key===''||self::hasTraversal($root)||self::hasTraversal($key)||!str_starts_with($key,$root))throw new TaskValidationException('str_replace_path_forbidden');
        return$key;
    }

    private static function hasTraversal(string$path):bool
    {
        return (bool)preg_match('~(?:^|[\\/])\.\.(?:[\\/]|$)~',$path);
    }

    /** @return array{0:string,1:int} */
    private static function replaceContent(string$content,string$old,string$new):array
    {
        $count=0;$updated=str_replace($old,$new,$content,$count);
        if($count===0)throw new TaskValidationException('str_replace_old_string_not_found');
        return[$updated,$count];
    }
}
