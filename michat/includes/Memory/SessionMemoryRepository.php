<?php

declare(strict_types=1);

final class SessionMemoryRepository
{
    private mysqli $db;
    public function __construct(mysqli $db) { $this->db = $db; }

    /** @return array{context:string,items:array<int,ContextItem>,telemetry:array<string,mixed>} */
    public function retrieve(int $userId, int $sessionId, string $queryText, int $limit = 30, ?ConversationScope $scope = null): array
    {
        $scope ??= (new ConversationScopeResolver($this->db))->resolve($userId, $sessionId);
        $metaQuery = $this->isMetaCognitiveQuery($queryText);
        $telemetry = [
            'source' => 'none', 'meta_query_detected' => $metaQuery, 'block_counts' => [],
            'blocks_total' => 0, 'context_chars' => 0, 'scope' => $scope->toArray(),
        ];
        $items = [];
        if ($userId <= 0 || $sessionId <= 0) return ['context'=>'','items'=>[],'telemetry'=>$telemetry];

        // Una rama hereda sólo el linaje explícito. No consulta otros chats libres.
        if ($scope->hasLineage()) {
            $branchItems = $this->branchHistory($userId, $scope, 28);
            foreach ($branchItems as $item) $items[] = $item;
            $telemetry['source'] = 'branch_lineage';
            $telemetry['block_counts']['branch_history'] = count($branchItems);
        }

        // Resumen consolidado de la sesión ACTUAL.
        $stmt = $this->db->prepare("SELECT context_summary FROM ChatSessions WHERE id_=? AND user_id_=? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ii', $sessionId, $userId);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                $summary = trim((string)($row['context_summary'] ?? ''));
                if ($summary !== '') {
                    $items[] = new ContextItem('ChatSessions', $sessionId, 'context_summary', 'session', $summary, null, null, ['session_id'=>$sessionId]);
                    $telemetry['block_counts']['context_summary'] = 1;
                    if ($telemetry['source'] === 'none') $telemetry['source'] = 'chat_sessions.context_summary';
                }
            }
            $stmt->close();
        }

        $limit = max(1, min(60, $limit));
        // Consultas explícitas sobre decisiones/reglas/preferencias o historial necesitan
        // level_0 reciente aunque el worker de embeddings/resúmenes aún no haya corrido.
        $includeLevel0 = $metaQuery || $scope->hasLineage();
        $typesSql = $includeLevel0
            ? "scb.block_type IN ('level_0','level_1','level_2','level_3')"
            : "scb.block_type IN ('level_1','level_2','level_3')";

        $sql = "SELECT scb.id_, scb.block_type, scb.content_preview, scb.created_at, scb.question_msg_id, scb.answer_msg_id
                FROM SessionContextBlocks scb
                INNER JOIN ChatSessions cs ON cs.id_=scb.session_id_
                WHERE scb.session_id_=? AND cs.user_id_=? AND {$typesSql}
                ORDER BY scb.created_at DESC LIMIT {$limit}";
        $stmt = $this->db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ii', $sessionId, $userId);
            $stmt->execute();
            $rows = [];
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $rows[] = $row;
            $stmt->close();
            $rows = array_reverse($rows);
            foreach ($rows as $row) {
                $content = trim((string)($row['content_preview'] ?? ''));
                if ($content === '') continue;
                $type = (string)($row['block_type'] ?? 'level_1');
                $items[] = new ContextItem(
                    'SessionContextBlocks', (int)$row['id_'], $type, 'session', $content, null, null,
                    ['session_id'=>$sessionId,'question_msg_id'=>$row['question_msg_id']!==null?(int)$row['question_msg_id']:null,
                     'answer_msg_id'=>$row['answer_msg_id']!==null?(int)$row['answer_msg_id']:null,'created_at'=>$row['created_at']??null]
                );
                $telemetry['block_counts'][$type] = (int)($telemetry['block_counts'][$type] ?? 0) + 1;
            }
            if ($rows && $telemetry['source'] === 'none') $telemetry['source'] = $includeLevel0 ? 'session_level0_plus_consolidated' : 'consolidated_levels_only';
        }

        // Follow-ups y consultas de memoria deben funcionar incluso antes de crear level_0.
        if (($metaQuery || !$items || $scope->hasLineage())) {
            foreach ($this->recentMessages($userId, $sessionId, 8) as $recent) $items[] = $recent;
            if ($telemetry['source'] === 'none') $telemetry['source'] = 'recent_messages_fallback';
        }

        $telemetry['blocks_total'] = count($items);
        if (!$items) return ['context'=>'','items'=>[],'telemetry'=>$telemetry];

        $out = "=== CONTEXTO PERMITIDO DE ESTA CONVERSACIÓN ===\n";
        foreach ($items as $idx => $item) $out .= ($idx + 1) . '. ' . mb_substr($item->content, 0, 500) . "\n";
        $telemetry['context_chars'] = mb_strlen($out);
        return ['context'=>trim($out),'items'=>$items,'telemetry'=>$telemetry];
    }

    /** @return ContextItem[] */
    public function recentMessages(int $userId, int $sessionId, int $limit = 6): array
    {
        if ($userId <= 0 || $sessionId <= 0) return [];
        $limit = max(1, min(20, $limit));
        $stmt = $this->db->prepare(
            "SELECT cm.id_, cm.role, cm.content, cm.created_at FROM ChatMessages cm
             INNER JOIN ChatSessions cs ON cs.id_=cm.session_id_
             WHERE cm.session_id_=? AND cs.user_id_=? AND cm.user_id_=?
               AND cm.role IN ('user','assistant') AND cm.content IS NOT NULL AND cm.content<>''
             ORDER BY cm.id_ DESC LIMIT {$limit}"
        );
        if (!$stmt) return [];
        $stmt->bind_param('iii', $sessionId, $userId, $userId);
        $stmt->execute();
        $rows=[]; $res=$stmt->get_result(); while($row=$res->fetch_assoc()) $rows[]=$row; $stmt->close();
        $rows=array_reverse($rows); $items=[];
        foreach($rows as $row){
            $items[] = new ContextItem('ChatMessages',(int)$row['id_'],(string)$row['role'],'session_recent',(string)$row['content'],null,null,['created_at'=>$row['created_at']??null,'session_id'=>$sessionId]);
        }
        return $items;
    }

    /** @return ContextItem[] */
    private function branchHistory(int $userId, ConversationScope $scope, int $limit): array
    {
        $items=[]; $remaining=max(4,min(40,$limit));
        foreach ($scope->lineage() as $entry) {
            $sid=(int)$entry['session_id'];
            if ($sid === $scope->sessionId()) continue;
            if ($remaining <= 0) break;
            $cutoff=$entry['max_message_id'];
            $take=min(12,$remaining);
            $sql="SELECT cm.id_, cm.role, cm.content, cm.created_at FROM ChatMessages cm
                  INNER JOIN ChatSessions cs ON cs.id_=cm.session_id_
                  WHERE cm.session_id_=? AND cm.user_id_=? AND cs.user_id_=?
                    AND cm.role IN ('user','assistant') AND cm.content<>''";
            if ($cutoff !== null) $sql .= " AND cm.id_ <= " . (int)$cutoff;
            $sql .= " ORDER BY cm.id_ DESC LIMIT {$take}";
            $stmt=$this->db->prepare($sql); if(!$stmt) continue;
            $stmt->bind_param('iii',$sid,$userId,$userId); $stmt->execute();
            $rows=[]; $res=$stmt->get_result(); while($row=$res->fetch_assoc()) $rows[]=$row; $stmt->close();
            $rows=array_reverse($rows);
            foreach($rows as $row){
                $items[]=new ContextItem('ChatMessages',(int)$row['id_'],'branch_history','branch',(string)$row['content'],null,null,[
                    'role'=>(string)$row['role'],'session_id'=>$sid,'created_at'=>$row['created_at']??null,'lineage'=>true
                ]);
                $remaining--; if($remaining<=0) break;
            }
        }
        return $items;
    }

    private function isMetaCognitiveQuery(string $query): bool
    {
        $q=mb_strtolower(trim($query),'UTF-8'); if($q==='') return false;
        $q=strtr($q,['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
        $q=preg_replace('/\s+/u',' ',$q)?:$q;
        $patterns=[
            '/\bque (?:te )?he preguntado\b/u','/\bque (?:te )?pregunte\b/u','/\bhistorial de (?:mis )?preguntas\b/u',
            '/\bde que hemos hablado\b/u','/\bde que hablamos\b/u','/\bque hemos (?:hablado|tratado|conversado)\b/u',
            '/\bque temas (?:hemos )?(?:tratado|hablado|visto|conversado)\b/u','/\b(?:resume|resumeme|hazme un resumen de) (?:esta |la )?(?:conversacion|sesion|charla|chat)\b/u',
            '/\bque recuerdas de (?:esta |la )?(?:conversacion|sesion|charla|chat)\b/u','/\bque (?:decidimos|acordamos|definimos)\b/u',
            '/\bque decisiones\b/u','/\bque reglas (?:tengo|tenemos|hay)\b/u','/\bque preferencias? (?:tengo|tenemos|hay)\b/u',
            '/\bque sabemos (?:de|sobre)\b/u','/\bdonde nos quedamos\b/u','/\ben que quedamos\b/u',
        ];
        foreach($patterns as $pattern) if(preg_match($pattern,$q)) return true;
        return false;
    }
}
