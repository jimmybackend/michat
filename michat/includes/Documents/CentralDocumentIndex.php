<?php
declare(strict_types=1);
require_once __DIR__.'/DocumentKnowledgeRepository.php';
require_once dirname(__DIR__).'/Memory/DocumentAccessRepository.php';

/** A binding to a conversation never becomes a global bucket grant. */
final class CentralDocumentIndex
{
    private DocumentKnowledgeRepository $repo;
    public function __construct(mysqli $db) { $this->repo=new DocumentKnowledgeRepository($db); }

    public function sources(int $userId,int $sessionId,array $session,bool $indexedOnly=true): array
    {
        if (getenv('MICHAT_DOCUMENT_KNOWLEDGE_ENABLED')!=='1') return [];
        if (!$this->repo->available()) throw new RuntimeException('Falta la migración del índice documental central');
        $meta=json_decode((string)($session['meta']??''),true)?:[];
        $ids=array_slice(array_values(array_filter(array_map('intval',(array)($meta['knowledge_file_ids']??[])),static fn($id)=>$id>0)),0,100);
        $linked=$ids?' OR f.id_ IN ('.implode(',',$ids).')':'';
        $complete=$indexedOnly?' AND s.chunks_total>0 AND s.chunks_done=s.chunks_total':'';
        $rows=$this->repo->all("SELECT s.id_,s.files3_id,s.user_id_,s.revision_hash,s.s3_key,s.source_etag,s.source_sha256,s.format,s.extractor,s.state,s.coverage,s.extracted_chars,s.chunks_total,s.chunks_done,s.embedding_model,s.created_at,s.updated_at,f.Nombre,f.Ruta,f.Encriptado,f.Found,f.AccessType FROM DocumentKnowledgeSources s JOIN FileS3 f ON f.id_=s.files3_id AND f.user_id_=s.user_id_ WHERE s.user_id_=? AND f.Found=1 AND f.AccessType<>'secure' AND (f.Ruta LIKE ?{$linked}){$complete} ORDER BY s.id_ DESC",'is',$userId,'Data/Chat/Uploads/'.$userId.'/%/'.$sessionId.'/');
        $sources=[];$heads=[];
        foreach ($rows as $source) {
            $fileId=(int)$source['files3_id'];
            if (isset($sources[$fileId])) continue;
            if (!idx_file_belongs_to_chat_session($source,$userId,$sessionId) && !DocumentAccessRepository::linked($session,$fileId)) continue;
            if (idx_build_s3_key($source)!==$source['s3_key'] || strtolower(pathinfo($source['Nombre'],PATHINFO_EXTENSION))!==$source['format']) continue;
            $key=$source['s3_key'];
            if (!array_key_exists($key,$heads)) {
                try { $head=Config::getS3()->headObject(['Bucket'=>Config::getBucket(),'Key'=>$key]);$heads[$key]=(string)($head['ETag']??''); }
                catch (Throwable $e) { $heads[$key]=''; }
            }
            if (!hash_equals($source['source_etag'],$heads[$key])) continue;
            $sources[$fileId]=$source;
        }
        return $sources;
    }

    public function rows(array $source,int $after,int $limit=200): array
    {
        $rows=$this->repo->all('SELECT * FROM DocumentKnowledgeChunks WHERE source_id=? AND id_>? ORDER BY id_ LIMIT '.max(1,min(200,$limit)),'ii',(int)$source['id_'],$after);
        return array_map(fn($row)=>self::normalize($source,$row),$rows);
    }

    public function neighbor(array $source,int $ordinal): ?array
    {
        $row=$this->repo->one('SELECT * FROM DocumentKnowledgeChunks WHERE source_id=? AND ordinal=?','ii',(int)$source['id_'],$ordinal);
        return $row?self::normalize($source,$row):null;
    }

    public static function normalize(array $source,array $chunk): array
    {
        $meta=['files3_id'=>(int)$source['files3_id'],'filename'=>$source['Nombre'],'source_etag'=>$source['source_etag'],'source_sha256'=>$source['source_sha256'],'chunk'=>(int)$chunk['ordinal'],'total'=>(int)$source['chunks_total'],'locator'=>json_decode((string)$chunk['locator_json'],true)?:[],'truncated'=>$source['coverage']==='partial'];
        return ['id_'=>(int)$chunk['id_'],'source_table'=>'DocumentKnowledgeChunks','central_source'=>$source,'block_type'=>'file_chunk','content_preview'=>$chunk['content'],'s3_path'=>$source['s3_key'],'source_ids'=>json_encode($meta,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'embedding_json'=>$chunk['embedding_json'],'embedding_model'=>$chunk['embedding_model']];
    }

    /** Bounded inspection plus an exact citation lookup, both restricted to live authorized sources. */
    public function inspect(array $sources,int $after=0,int $chunkId=0): array
    {
        if (!$sources) return ['rows'=>[],'has_more'=>false,'next_after'=>$after];
        $byId=[];
        foreach ($sources as $source) $byId[(int)$source['id_']]=$source;
        $ids=implode(',',array_keys($byId));
        $where=$chunkId>0?'id_=?':'id_>?';
        $rows=$this->repo->all('SELECT * FROM DocumentKnowledgeChunks WHERE source_id IN ('.$ids.') AND '.$where.' ORDER BY id_ LIMIT 201','i',$chunkId>0?$chunkId:max(0,$after));
        $more=count($rows)>200;if($more)array_pop($rows);
        $out=[];
        foreach($rows as $row) $out[]=self::normalize($byId[(int)$row['source_id']],$row);
        return ['rows'=>$out,'has_more'=>$more,'next_after'=>$rows?(int)end($rows)['id_']:$after];
    }

    public function status(array $source,string $model): array
    {
        $job=$this->repo->one('SELECT id_,status,phase,failure_count,error_message FROM DocumentKnowledgeJobs WHERE source_id=?','i',(int)$source['id_']);
        $count=$this->repo->one('SELECT COUNT(*) AS chunks,SUM(embedding_json IS NOT NULL AND embedding_model=?) AS embedded FROM DocumentKnowledgeChunks WHERE source_id=?','si',$model,(int)$source['id_']);
        return ['source_id'=>(int)$source['id_'],'state'=>$source['state'],'coverage'=>$source['coverage'],'chunks_total'=>(int)$source['chunks_total'],'chunks_done'=>(int)$source['chunks_done'],'chunks_with_embedding'=>(int)$count['embedded'],'extracted_chars'=>(int)$source['extracted_chars'],'job'=>$job];
    }
}
