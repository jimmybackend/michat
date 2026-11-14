<?php
declare(strict_types=1);

/** Stable public statuses calculated solely from persisted index/job facts. */
final class LegalBridgeKnowledgeStatus
{
    public static function map(array $state,string $activeModel,string $sourceModel): string
    {
        $job=(array)($state['job']??[]);
        $status=(string)($job['status']??'pending');
        $phase=(string)($job['phase']??'');
        $coverage=(string)($state['coverage']??'none');
        $total=(int)($state['chunks_total']??0);
        $done=(int)($state['chunks_done']??0);
        $embedded=(int)($state['chunks_with_embedding']??0);
        if($status==='failed'||(string)($state['state']??'')==='failed')return 'error';
        if($status==='completed'&&$total>0&&$done===$total&&$coverage==='partial')return 'partial';
        if($status==='completed'&&$total>0&&$done===$total
          &&$embedded===$total&&$activeModel!==''&&hash_equals($activeModel,$sourceModel)
          &&(string)($state['state']??'')==='ready'&&$coverage!=='partial')return 'ready';
        if($status==='blocked'&&$done>0)return 'partial';
        if($status==='running') {
            if($phase==='extract')return 'extracting';
            if(in_array($phase,['chunks','embeddings'],true))return 'indexing';
        }
        if(in_array($status,['pending','retry'],true))return 'pending';
        if($total>0&&$done>0)return 'partial';
        return 'pending';
    }
}
