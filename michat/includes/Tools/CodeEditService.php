<?php
declare(strict_types=1);

/** Server-side code_edit adapter over the existing S3 + ProjectIndexer workflow. */
final class CodeEditService
{
    public function __construct(private mysqli $db,private ?TaskCancellationGuard$cancellations=null) {}

    /** @param array<string,mixed> $arguments */
    public function execute(int$userId,int$sessionId,int$projectId,array$arguments,int$taskId=0):ToolExecutionResult
    {
        try{
            $action=strtolower(trim((string)($arguments['action']??'write')));
            $filename=$this->safeFilename($arguments['target_filename']??null);
            $instruction=$arguments['instruction']??null;
            if(!in_array($action,['write','read','delete'],true)||($action==='write'&&(!is_string($instruction)||trim($instruction)==='')))throw new TaskValidationException('code_edit_arguments_invalid');
            $scope=$this->scope($userId,$sessionId,$projectId);
            $source=$this->source($projectId,$filename);
            $key=$this->safeKey((string)$scope['root_prefix'],(string)$source['s3_key']);
            $s3=Config::getS3();$bucket=Config::getBucket();
            $object=$s3->getObject(['Bucket'=>$bucket,'Key'=>$key]);$current=(string)$object['Body'];
            if($action==='read'){
                $readArtifacts=[['relation'=>'read','resource_type'=>'project_source','resource_id'=>(int)$source['id_']]];
                return new ToolExecutionResult("code_edit leyó {$filename}.",$readArtifacts,['action'=>'read','file'=>$filename,'source_id'=>(int)$source['id_'],'size_bytes'=>strlen($current),'content'=>$current]);
            }
            if($action==='delete'){
                $this->cancellations?->assertActive(['task_id'=>$taskId,'user_id'=>$userId]);
                $s3->deleteObject(['Bucket'=>$bucket,'Key'=>$key]);$this->deleteSource($projectId,(int)$source['id_']);
                return new ToolExecutionResult("code_edit eliminó {$filename}.",[],['action'=>'delete','file'=>$filename,'source_id'=>(int)$source['id_']]);
            }

            $this->cancellations?->assertActive(['task_id'=>$taskId,'user_id'=>$userId]);
            $updated=$this->generateEdit($userId,$filename,$current,trim((string)$instruction));
            self::validateContent($updated,$filename);
            if(hash_equals(hash('sha256',$current),hash('sha256',$updated))){
                return new ToolExecutionResult("code_edit no produjo cambios en {$filename}.",[],['action'=>'write','file'=>$filename,'source_id'=>(int)$source['id_'],'changed'=>false]);
            }
            $this->cancellations?->assertActive(['task_id'=>$taskId,'user_id'=>$userId]);
            $model=$this->modelId($userId);
            $version=$this->createFileVersion($projectId,$sessionId,$filename,(string)$scope['root_prefix'],trim((string)$instruction));
            $s3->putObject(['Bucket'=>$bucket,'Key'=>$key,'Body'=>$updated,'ContentType'=>(string)($source['mime_type']?:'text/plain'),'ACL'=>'private']);
            $this->cancellations?->assertActive(['task_id'=>$taskId,'user_id'=>$userId]);
            $index=indexProjectSourceContent($this->db,null,$projectId,(int)$source['id_'],$filename,$updated);
            $artifacts=[
                ['relation'=>'modified','resource_type'=>'project_source','resource_id'=>(int)$source['id_']],
                ['relation'=>'generated','resource_type'=>'file_version','resource_id'=>$version['id']],
            ];
            $data=['action'=>'write','file'=>$filename,'source_id'=>(int)$source['id_'],'file_version_id'=>$version['id'],'version'=>$version['version'],'model_used'=>$model,
                'summary'=>mb_substr(trim((string)$instruction),0,500),'indexed'=>(bool)($index['indexed']??false),
                'index_queued'=>(bool)($index['queued']??false),'index_jobs'=>(int)($index['jobs']??0),
                'embedding_model'=>$index['model']??null,'needs_indexing'=>empty($index['ok'])];
            return new ToolExecutionResult("code_edit editó {$filename}: {$data['summary']}",$artifacts,$data);
        }catch(TaskTransitionException$e){throw$e;
        }catch(Throwable$e){return new ToolExecutionResult('code_edit rechazado: '.$e->getMessage(),[],['error'=>$e->getMessage()],false,'error');}
    }

    /** Executes the same path/content guards against a temporary project fixture. */
    public static function editLocalFile(string$root,string$relativePath,callable$editor):array
    {
        if(str_contains($relativePath,"\0")||self::hasTraversal($relativePath))throw new InvalidArgumentException('code_edit_path_invalid');
        $realRoot=realpath($root);$file=realpath(rtrim($root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($relativePath,'/\\'));
        if($realRoot===false||$file===false||!is_file($file)||!str_starts_with($file,$realRoot.DIRECTORY_SEPARATOR))throw new InvalidArgumentException('code_edit_file_forbidden');
        $current=file_get_contents($file);if($current===false)throw new RuntimeException('code_edit_read_failed');
        $updated=$editor($current);if(!is_string($updated))throw new InvalidArgumentException('code_edit_content_invalid');
        self::validateContent($updated,basename($file));
        if(file_put_contents($file,$updated,LOCK_EX)===false)throw new RuntimeException('code_edit_write_failed');
        return['file'=>basename($file),'size_bytes'=>strlen($updated)];
    }

    private function scope(int$userId,int$sessionId,int$projectId):array
    {
        if($userId<1||$sessionId<1||$projectId<1)throw new TaskValidationException('tool_scope_invalid');
        $stmt=$this->db->prepare("SELECT p.root_prefix FROM ChatSessions cs JOIN Projects p ON p.id_=? AND p.user_id_=? AND p.status<>'deleted' WHERE cs.id_=? AND cs.user_id_=? AND cs.project_id_=p.id_ LIMIT 1");
        if(!$stmt)throw new RuntimeException('database_error');$stmt->bind_param('iiii',$projectId,$userId,$sessionId,$userId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
        if(!$row)throw new TaskValidationException('code_edit_scope_forbidden');return$row;
    }

    private function source(int$projectId,string$filename):array
    {
        $stmt=$this->db->prepare('SELECT id_,s3_key,filename,mime_type FROM ProjectSources WHERE project_id_=? AND filename=? LIMIT 1');if(!$stmt)throw new RuntimeException('database_error');
        $stmt->bind_param('is',$projectId,$filename);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$row)throw new TaskValidationException('code_edit_file_not_found');return$row;
    }

    private function safeFilename($value):string
    {
        if(!is_string($value))throw new TaskValidationException('code_edit_filename_invalid');$name=trim(str_replace('\\','/',$value));
        if($name===''||str_contains($name,"\0")||self::hasTraversal($name)||basename($name)!==$name||!preg_match('/^[A-Za-z0-9_.-]+$/D',$name))throw new TaskValidationException('code_edit_filename_invalid');return$name;
    }

    private function safeKey(string$root,string$key):string
    {
        $root=trim(str_replace('\\','/',$root),'/').'/';$key=ltrim(str_replace('\\','/',$key),'/');
        if($root==='/'||self::hasTraversal($root)||self::hasTraversal($key)||!str_starts_with($key,$root))throw new TaskValidationException('code_edit_path_forbidden');return$key;
    }

    private function generateEdit(int$userId,string$filename,string$current,string$instruction):string
    {
        $model=$this->modelId($userId);$bedrock=Config::getBedrockRuntime(['http'=>['connect_timeout'=>20,'timeout'=>120]]);
        $response=$bedrock->converse(['modelId'=>$model,'messages'=>[['role'=>'user','content'=>[['text'=>"FILE: {$filename}\nCURRENT CONTENT:\n{$current}\n\nUSER INSTRUCTION:\n{$instruction}"]]]],
            'system'=>[['text'=>'You are a surgical code editor. Return only the complete edited file, without markdown fences or explanation. Preserve unrelated content.']],
            'inferenceConfig'=>['maxTokens'=>8000,'temperature'=>0.2,'topP'=>0.9]]);
        $text='';foreach(($response['output']['message']['content']??[])as$block)if(isset($block['text']))$text.=$block['text'];
        $text=trim($text);if(preg_match('/^```[^\n]*\n([\s\S]*?)\n```$/',$text,$match))$text=$match[1];if($text==='')throw new RuntimeException('code_edit_empty_model_output');return$text;
    }

    private function modelId(int$userId):string
    {
        $configs=loadDynamicAIAgentConfigs($this->db,$userId);$model=trim((string)($configs['chat_main']['model_id']??''));if($model==='')throw new RuntimeException('code_edit_model_unavailable');return$model;
    }

    private function deleteSource(int$projectId,int$sourceId):void
    {
        projectDeleteEmbeddingJobsForSource($this->db,$sourceId);$stmt=$this->db->prepare('DELETE FROM ProjectSources WHERE id_=? AND project_id_=?');if(!$stmt)throw new RuntimeException('database_error');$stmt->bind_param('ii',$sourceId,$projectId);if(!$stmt->execute())throw new RuntimeException('database_error');$stmt->close();
    }

    /** @return array{id:int,version:string} Uses the legacy dotted-version increment policy. */
    private function createFileVersion(int$projectId,int$sessionId,string$filename,string$rootPrefix,string$instruction):array
    {
        $lock='code_edit_version:'.hash('sha256',$projectId.':'.$filename);$stmt=$this->db->prepare('SELECT GET_LOCK(?,10) acquired');if(!$stmt)throw new RuntimeException('database_error');
        $stmt->bind_param('s',$lock);$stmt->execute();$acquired=(int)($stmt->get_result()->fetch_assoc()['acquired']??0)===1;$stmt->close();if(!$acquired)throw new RuntimeException('code_edit_version_lock_failed');
        try{
            $stmt=$this->db->prepare('SELECT version FROM FileVersions WHERE project_id_=? AND original_filename=? ORDER BY id_ DESC LIMIT 1');if(!$stmt)throw new RuntimeException('database_error');
            $stmt->bind_param('is',$projectId,$filename);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
            if(!$row)$version='1';else{$parts=explode('.',(string)$row['version']);$last=count($parts)-1;$parts[$last]=(string)((int)$parts[$last]+1);$version=implode('.',$parts);}
            $summary=mb_substr($instruction,0,100).(mb_strlen($instruction)>100?'...':'');$path=rtrim($rootPrefix,'/').'/'.$filename.'.v'.$version;
            $stmt=$this->db->prepare('INSERT INTO FileVersions (project_id_,session_id_,message_id_,original_filename,version,s3_path,diff_summary,is_stable) VALUES (?,?,NULL,?,?,?,?,0)');
            if(!$stmt)throw new RuntimeException('database_error');$stmt->bind_param('iissss',$projectId,$sessionId,$filename,$version,$path,$summary);
            if(!$stmt->execute())throw new RuntimeException('database_error');$id=(int)$this->db->insert_id;$stmt->close();if($id<1)throw new RuntimeException('code_edit_file_version_id_invalid');
            $check=$this->db->prepare('SELECT id_ FROM FileVersions WHERE id_=? AND project_id_=? AND original_filename=? LIMIT 1');if(!$check)throw new RuntimeException('database_error');
            $check->bind_param('iis',$id,$projectId,$filename);$check->execute();$persisted=(int)($check->get_result()->fetch_assoc()['id_']??0);$check->close();if($persisted!==$id)throw new RuntimeException('code_edit_file_version_not_persisted');
            return['id'=>$id,'version'=>$version];
        }finally{$stmt=$this->db->prepare('SELECT RELEASE_LOCK(?)');if($stmt){$stmt->bind_param('s',$lock);$stmt->execute();$stmt->close();}}
    }

    private static function hasTraversal(string$path):bool{return(bool)preg_match('~(?:^|[\\/])\.\.(?:[\\/]|$)~',$path);}
    private static function validateContent(string$content,string$filename):void
    {
        if(trim($content)==='')throw new TaskValidationException('code_edit_content_invalid');
        if(strtolower(pathinfo($filename,PATHINFO_EXTENSION))==='php'){try{token_get_all($content,TOKEN_PARSE);}catch(ParseError$e){throw new TaskValidationException('code_edit_content_invalid: '.$e->getMessage());}}
    }
}
