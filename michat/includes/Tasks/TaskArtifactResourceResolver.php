<?php
declare(strict_types=1);

/** Batch resolver for public, ownership-scoped metadata behind weak TaskArtifact references. */
class TaskArtifactResourceResolver
{
    public function __construct(private mysqli $db) {}

    /** @param list<array<string,mixed>> $artifacts @return array<string,array<int,array<string,mixed>>> */
    public function resolve(array $artifacts, int $userId, ?int $projectId): array
    {
        $ids=[];
        foreach($artifacts as $artifact){$type=(string)($artifact['resource_type']??'');$id=(int)($artifact['resource_id']??0);if($id>0)$ids[$type][$id]=true;}
        $resolved=[];
        if($projectId!==null){
            $resolved['project_source']=$this->projectSources(array_keys($ids['project_source']??[]),$projectId,$userId);
            $resolved['source_chunk']=$this->sourceChunks(array_keys($ids['source_chunk']??[]),$projectId,$userId);
            $resolved['file_version']=$this->fileVersions(array_keys($ids['file_version']??[]),$projectId,$userId);
        }
        $resolved['file_s3']=$this->filesS3(array_keys($ids['file_s3']??[]),$userId);
        return$resolved;
    }

    /** @param list<int> $ids @return array<int,array{filename:string}> */
    private function projectSources(array$ids,int$projectId,int$userId):array
    {
        if($ids===[])return[];$stmt=$this->prepareIn("SELECT ps.id_,ps.filename FROM ProjectSources ps JOIN Projects p ON p.id_=ps.project_id_ WHERE ps.project_id_=? AND p.user_id_=? AND p.status<>'deleted' AND ps.id_ IN (",$ids);$values=[$projectId,$userId,...$ids];$stmt->bind_param(str_repeat('i',count($values)),...$values);$rows=$this->rows($stmt);$out=[];foreach($rows as$row)$out[(int)$row['id_']]=['filename'=>(string)$row['filename']];return$out;
    }

    /** @param list<int> $ids @return array<int,array{filename:string,name:?string,start_line:int,end_line:int}> */
    private function sourceChunks(array$ids,int$projectId,int$userId):array
    {
        if($ids===[])return[];$stmt=$this->prepareIn("SELECT sc.id_,ps.filename,sc.name,sc.start_line,sc.end_line FROM SourceChunks sc JOIN ProjectSources ps ON ps.id_=sc.source_id_ AND ps.project_id_=sc.project_id_ JOIN Projects p ON p.id_=ps.project_id_ WHERE sc.project_id_=? AND p.user_id_=? AND p.status<>'deleted' AND sc.id_ IN (",$ids);$values=[$projectId,$userId,...$ids];$stmt->bind_param(str_repeat('i',count($values)),...$values);$rows=$this->rows($stmt);$out=[];foreach($rows as$row)$out[(int)$row['id_']]=['filename'=>(string)$row['filename'],'name'=>$row['name']===null?null:(string)$row['name'],'start_line'=>(int)$row['start_line'],'end_line'=>(int)$row['end_line']];return$out;
    }

    /** @param list<int> $ids @return array<int,array{filename:string,version:string}> */
    private function fileVersions(array$ids,int$projectId,int$userId):array
    {
        if($ids===[])return[];$stmt=$this->prepareIn("SELECT fv.id_,fv.original_filename,fv.version FROM FileVersions fv JOIN Projects p ON p.id_=fv.project_id_ WHERE fv.project_id_=? AND p.user_id_=? AND p.status<>'deleted' AND fv.id_ IN (",$ids);$values=[$projectId,$userId,...$ids];$stmt->bind_param(str_repeat('i',count($values)),...$values);$rows=$this->rows($stmt);$out=[];foreach($rows as$row)$out[(int)$row['id_']]=['filename'=>(string)$row['original_filename'],'version'=>(string)$row['version']];return$out;
    }

    /** @param list<int> $ids @return array<int,array{filename:string}> */
    private function filesS3(array$ids,int$userId):array
    {
        if($ids===[])return[];$stmt=$this->prepareIn('SELECT id_,Nombre FROM FileS3 WHERE user_id_=? AND id_ IN (',$ids);$values=[$userId,...$ids];$stmt->bind_param(str_repeat('i',count($values)),...$values);$rows=$this->rows($stmt);$out=[];foreach($rows as$row)$out[(int)$row['id_']]=['filename'=>(string)$row['Nombre']];return$out;
    }

    /** @param list<int> $ids */
    private function prepareIn(string$sql,array$ids):mysqli_stmt{$sql.=implode(',',array_fill(0,count($ids),'?')).')';$stmt=$this->db->prepare($sql);if(!$stmt)throw new RuntimeException('database_error');return$stmt;}
    /** @return list<array<string,mixed>> */
    private function rows(mysqli_stmt$stmt):array{if(!$stmt->execute())throw new RuntimeException('database_error');$out=[];$result=$stmt->get_result();while($row=$result->fetch_assoc())$out[]=$row;$stmt->close();return$out;}
}
