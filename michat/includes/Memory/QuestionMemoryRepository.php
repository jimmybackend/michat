<?php

declare(strict_types=1);

final class QuestionMemoryRepository
{
    private mysqli $db;
    private $bedrock;
    public function __construct(mysqli $db, $bedrock) { $this->db=$db; $this->bedrock=$bedrock; }

    /** @param float[]|null $queryVector @return array{legacy:array<string,mixed>,items:array<int,ContextItem>} */
    public function retrieve(
        int $userId, int $sessionId, int $projectId, string $queryText, string $scope,
        int $maxCandidates, int $windowLines, ?array $queryVector, ?int $logMsgId,
        ?ConversationScope $memoryScope = null
    ): array {
        if (!$this->ownsSession($userId,$sessionId) || !function_exists('buildSelectiveQuestionMemory')) {
            return ['legacy'=>$this->emptyLegacy('session'),'items'=>[]];
        }

        $memoryScope ??= (new ConversationScopeResolver($this->db))->resolve($userId,$sessionId);
        $effectiveScope = $memoryScope->semanticScope();

        // Para una rama, incluso dentro de proyecto, la memoria conversacional heredada
        // debe respetar el punto exacto de bifurcación. El ProjectContext estructurado sí
        // sigue siendo compartido por todo el proyecto.
        if ($memoryScope->isProject()) {
            // El proyecto es una frontera compartida por diseño, también si la sesión
            // nació como rama. El historial inmediato del padre se añade por SessionMemoryRepository.
            $legacy = buildSelectiveQuestionMemory($this->db,$this->bedrock,$sessionId,$userId,$projectId,$queryText,'project',$maxCandidates,$windowLines,$queryVector,$logMsgId);
        } elseif ($memoryScope->hasLineage()) {
            // En chats libres, una rama sólo hereda su linaje exacto.
            $legacy = $this->retrieveLineageLegacy($userId,$memoryScope,$queryText,$maxCandidates,$windowLines,$queryVector,$logMsgId);
        } else {
            $legacy = buildSelectiveQuestionMemory($this->db,$this->bedrock,$sessionId,$userId,0,$queryText,'session',$maxCandidates,$windowLines,$queryVector,$logMsgId);
        }
        $legacy['scope'] = $effectiveScope;
        $legacy['scope_kind'] = $memoryScope->kind();

        $items=[];
        foreach((array)($legacy['matches']??[]) as $match){
            $question=trim((string)($match['question']??'')); $fragment=trim((string)($match['fragment']??''));
            if($question===''&&$fragment==='') continue;
            $content=($question!==''?"Pregunta: {$question}\n":'').($fragment!==''?"Fragmento: {$fragment}":'');
            $itemScope = $memoryScope->isProject() ? 'project' : ($memoryScope->hasLineage() ? 'branch' : 'session');
            $items[]=new ContextItem('SessionContextBlocks',isset($match['block_id'])?(int)$match['block_id']:null,'qa_memory',$itemScope,$content,
                isset($match['score'])?(float)$match['score']:null,null,[
                    'session_id'=>isset($match['session_id'])?(int)$match['session_id']:null,
                    'question_msg_id'=>isset($match['question_msg_id'])?(int)$match['question_msg_id']:null,
                    'answer_msg_id'=>isset($match['answer_msg_id'])?(int)$match['answer_msg_id']:null,
                    'previous_memory_hits'=>isset($match['previous_memory_hits'])?(int)$match['previous_memory_hits']:0,
                    'last_memory_used_at'=>$match['last_memory_used_at']??null,
                    'scope_kind'=>$memoryScope->kind(),
                    'lineage'=>$memoryScope->hasLineage(),
                ]);
        }
        return ['legacy'=>$legacy,'items'=>$items];
    }

    /** @param float[]|null $queryVector @return array<string,mixed> */
    private function retrieveLineageLegacy(int $userId, ConversationScope $scope, string $queryText, int $maxCandidates, int $windowLines, ?array $queryVector, ?int $logMsgId): array
    {
        $combined=$this->emptyLegacy($scope->semanticScope());
        $combined['scope_kind']=$scope->kind();
        $combined['lineage']=true;
        $matches=[]; $scores=[];

        foreach($scope->lineage() as $entry){
            $sid=(int)($entry['session_id']??0);
            if($sid<=0) continue;
            $cutoff=isset($entry['max_message_id']) && $entry['max_message_id']!==null ? (int)$entry['max_message_id'] : null;

            // Se fuerza scope=session para que cada búsqueda quede confinada a una sola
            // sesión del linaje. Después aplicamos el cutoff del punto de rama.
            $part=buildSelectiveQuestionMemory($this->db,$this->bedrock,$sid,$userId,0,$queryText,'session',$maxCandidates,$windowLines,$queryVector,$logMsgId);
            $combined['candidates']+=(int)($part['candidates']??0);
            $combined['reindex_queued']+=(int)($part['reindex_queued']??0);
            $scores=array_merge($scores,(array)($part['candidate_scores']??[]));

            foreach((array)($part['matches']??[]) as $m){
                $questionId=(int)($m['question_msg_id']??0);
                $answerId=(int)($m['answer_msg_id']??0);
                if($cutoff!==null && (($questionId>0 && $questionId>$cutoff) || ($answerId>0 && $answerId>$cutoff))) {
                    continue;
                }
                $m['lineage_session_id']=$sid;
                $m['lineage_cutoff']=$cutoff;
                $matches[]=$m;
            }
        }

        usort($matches,static fn(array $a,array $b):int=>(float)($b['score']??0)<=> (float)($a['score']??0));
        $matches=array_slice($matches,0,max(1,min(12,$maxCandidates)));

        foreach($matches as $m){
            if(isset($m['question_msg_id'])) $combined['question_ids'][]=(int)$m['question_msg_id'];
            if(isset($m['block_id'])) $combined['block_ids'][]=(int)$m['block_id'];
        }
        $combined['matches']=$matches;
        $combined['candidate_scores']=$scores;
        $combined['question_ids']=array_values(array_unique(array_filter(array_map('intval',$combined['question_ids']))));
        $combined['block_ids']=array_values(array_unique(array_filter(array_map('intval',$combined['block_ids']))));
        $combined['fragments']=count($matches);
        $combined['used']=!empty($matches);
        return $combined;
    }

    /** @return array<string,mixed> */
    private function emptyLegacy(string $scope): array
    {
        return ['context'=>'','used'=>false,'question_ids'=>[],'block_ids'=>[],'fragments'=>0,'candidates'=>0,'reindex_queued'=>0,'scope'=>$scope,'matches'=>[],'candidate_scores'=>[]];
    }

    private function ownsSession(int $userId,int $sessionId):bool
    {
        $stmt=$this->db->prepare("SELECT id_ FROM ChatSessions WHERE id_=? AND user_id_=? LIMIT 1"); if(!$stmt)return false;
        $stmt->bind_param('ii',$sessionId,$userId); $stmt->execute(); $ok=(bool)$stmt->get_result()->fetch_assoc(); $stmt->close(); return $ok;
    }
}
