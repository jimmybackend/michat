<?php
declare(strict_types=1);
require_once __DIR__ . '/DocumentAccessRepository.php';
require_once __DIR__ . '/AttachmentEvidence.php';
require_once dirname(__DIR__).'/Documents/CentralDocumentIndex.php';

final class AttachmentContextRepository
{
    private DocumentAccessRepository $access;
    public function __construct(private mysqli $db, private $bedrock)
    {
        $this->access = new DocumentAccessRepository($db);
    }

    public function hasContext(int $userId, int $sessionId): bool
    {
        $session=$this->access->session($userId,$sessionId);
        if (!$session) return false;
        if ((new CentralDocumentIndex($this->db))->sources($userId,$sessionId,$session)) return true;
        $s = $this->db->prepare("SELECT id_ FROM SessionContextBlocks WHERE session_id_=? AND block_type IN ('file','file_chunk') LIMIT 1");
        if (!$s) return false;
        $s->bind_param('i', $sessionId); $s->execute();
        $found = $s->get_result()->num_rows > 0; $s->close();
        return $found;
    }

    /** Keyset scan: bounded memory, includes lexical-only chunks after failed embeddings. */
    public function retrieve(int $userId, int $sessionId, string $queryText, string $mode, ?array $queryVector, ?int $logMsgId): array
    {
        $t = ['mode'=>$mode, 'candidates'=>0, 'selected'=>[], 'owner_verified'=>false, 'scan_complete'=>false];
        $session = $this->access->session($userId, $sessionId);
        if (!$session) return ['context'=>'','items'=>[],'telemetry'=>$t];
        $t['owner_verified'] = true;
        $model = (string)aiAgentModel('embedding_main', '');
        $threshold = (float)aiAgentExtra('embedding_main', 'attachment_rag_threshold', 0.3);
        $top = max(1, min(24, (int)aiAgentExtra('embedding_main', 'attachment_rag_top', 8)));
        $limit = max(1000, min(48000, (int)aiAgentExtra('embedding_main', 'attachment_rag_max_chars', 16000)));
        preg_match_all('/["“]([^"”]+)["”]/u',$queryText,$quoted);
        $requestedNames = array_values(array_filter(array_map('mb_strtolower',$quoted[1]), static fn($name)=>in_array(strtolower(pathinfo($name,PATHINFO_EXTENSION)),array_merge(idx_text_extensions(),idx_extractable_extensions(),['jpg','jpeg','png','webp','gif']),true)));
        if(!$requestedNames) {
            $extensions=array_unique(array_merge(idx_text_extensions(),idx_extractable_extensions(),['jpg','jpeg','png','webp','gif']));
            $pattern='/([\\pL\\pN_.-]+\\.(?:'.implode('|',array_map(static fn($ext)=>preg_quote($ext,'/'),$extensions)).'))\\b/iu';
            preg_match_all($pattern,$queryText,$unquotedNames);
            $requestedNames=array_values(array_unique(array_map('mb_strtolower',$unquotedNames[1])));
        }
        if (preg_match('/busca|referenci/iu',$queryText)) $requestedNames=[];
        $central=new CentralDocumentIndex($this->db);
        $centralSources=$central->sources($userId,$sessionId,$session);
        $passes=array_merge([null],array_values($centralSources));
        $completeScan=true;
        $last = 0; $best = []; $files = []; $revisions = [];
        foreach ($centralSources as $source) $revisions[$source['s3_key'].':'.$source['source_etag']]=true;
        $started = microtime(true);
        foreach ($passes as $centralSource) {
        $last=0;
        do {
          if ($centralSource===null) {
            $s = $this->db->prepare("SELECT id_,block_type,content_preview,s3_path,source_ids,embedding_json,embedding_model FROM SessionContextBlocks WHERE session_id_=? AND id_>? AND block_type IN ('file','file_chunk') ORDER BY id_ LIMIT 200");
            if (!$s) throw new RuntimeException('No se pudo recuperar conocimiento');
            $s->bind_param('ii', $sessionId, $last); $s->execute(); $r = $s->get_result();
            $batch=$r->fetch_all(MYSQLI_ASSOC);$s->close();
          } else { $batch=$central->rows($centralSource,$last); }
            $count=count($batch);
            foreach ($batch as $row) {
                $last = (int)$row['id_'];
                $meta = json_decode((string)$row['source_ids'], true) ?: [];
                $fileId = (int)($meta['files3_id'] ?? 0);
                if ($centralSource===null && $row['block_type']==='file_chunk' && isset($centralSources[$fileId])) continue;
                if (!array_key_exists($fileId, $files)) $files[$fileId] = $this->access->file($userId, $fileId);
                $file = $files[$fileId];
                if (!$file || idx_build_s3_key($file) !== $row['s3_path']) continue;
                if (!idx_file_belongs_to_chat_session($file, $userId, $sessionId) && !DocumentAccessRepository::linked($session, $fileId)) continue;
                $revision = $row['s3_path'] . ':' . (string)($meta['source_etag'] ?? '');
                if (!array_key_exists($revision,$revisions)) $revisions[$revision] = DocumentAccessRepository::currentObject($meta,$row['s3_path']);
                if (!$revisions[$revision]) { $t['stale_or_unavailable_blocks'] = ($t['stale_or_unavailable_blocks'] ?? 0) + 1; continue; }
                if ($requestedNames && !in_array(mb_strtolower((string)$file['Nombre']),$requestedNames,true)) continue;
                $t['candidates']++;
                $lex = AttachmentEvidence::lexical($queryText, (string)$file['Nombre'] . "\n" . $row['content_preview']);
                $vec = $row['embedding_model'] === $model ? json_decode((string)$row['embedding_json'], true) : null;
                $cos = AttachmentEvidence::cosine($queryVector, is_array($vec) ? $vec : null);
                if ($mode !== 'always' && $lex <= 0 && ($cos === null || $cos < $threshold)) continue;
                $row['score'] = min(1.0,max($lex, $cos ?? 0) + 0.1 * $lex);
                $row['lexical_score'] = $lex; $row['semantic_score'] = $cos;
                $row['filename'] = $file['Nombre'];
                $best[] = $row;
            }
            usort($best, static fn($a,$b) => ($b['score'] <=> $a['score']) ?: ($a['id_'] <=> $b['id_']));
            // Keep candidates per source for multi-document comparisons.
            $perFile = []; $best = array_values(array_filter($best, static function($row) use (&$perFile, $top) {
                $key = (string)$row['s3_path']; $perFile[$key] = ($perFile[$key] ?? 0) + 1;
                return $perFile[$key] <= $top;
            }));
            if (count($best) > 500) $best = array_slice(AttachmentEvidence::diversify($best, 500), 0, 500);
            if (microtime(true) - $started > 10) {
                $t['limitation'] = 'Búsqueda parcial por presupuesto de tiempo; refine la consulta.';
                $completeScan=false;break 2;
            }
        } while ($count === 200);
        }
        $t['scan_complete'] = $completeScan;
        $selected = AttachmentEvidence::diversify($best, $top);
        // Adjacent source chunks complete split clauses without expanding to the whole file.
        $seen = array_fill_keys(array_map(static fn($r)=>($r['source_table']??'SessionContextBlocks').':'.$r['id_'],$selected),true);
        foreach ($selected as $anchor) {
            if (count($seen) >= 24) break;
            $meta = json_decode((string)$anchor['source_ids'],true) ?: [];
            if ($anchor['block_type'] !== 'file_chunk' || empty($meta['chunk'])) continue;
            $next = (int)$meta['chunk'] + 1;
            if (($anchor['source_table']??'')==='DocumentKnowledgeChunks') {
                $neighbor=$central->neighbor($anchor['central_source'],$next);
            } else {
                $q = $this->db->prepare("SELECT id_,block_type,content_preview,s3_path,source_ids FROM SessionContextBlocks WHERE session_id_=? AND s3_path=? AND block_type='file_chunk' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(source_ids,'$.chunk')) AS UNSIGNED)=? ORDER BY id_ LIMIT 1");
                if (!$q) continue;
                $q->bind_param('isi',$sessionId,$anchor['s3_path'],$next);$q->execute();$neighbor=$q->get_result()->fetch_assoc();$q->close();
            }
            if (!$neighbor) continue;
            $identity=($neighbor['source_table']??'SessionContextBlocks').':'.$neighbor['id_'];
            if (isset($seen[$identity])) continue;
            $other=json_decode((string)$neighbor['source_ids'],true) ?: [];
            if (($other['files3_id']??null)!==($meta['files3_id']??null) || ($other['source_etag']??null)!==($meta['source_etag']??null)) continue;
            $seen[$identity]=true;
            $selected[]=array_merge($anchor,$neighbor,['score'=>$anchor['score']*0.9,'lexical_score'=>0,'semantic_score'=>null]);
        }
        $items = []; $parts = []; $chars = 0;
        foreach ($selected as $row) {
            $citation = AttachmentEvidence::citation($row);
            $available = $limit - $chars - mb_strlen($citation) - 4;
            if ($available < 100) break;
            $content = mb_substr((string)$row['content_preview'], 0, min(4500, $available));
            $meta = json_decode((string)$row['source_ids'], true) ?: [];
            $parts[] = $citation . "\n" . $content; $chars += mb_strlen(end($parts)) + 2;
            $metadata = ['session_id'=>$sessionId,'files3_id'=>(int)($meta['files3_id'] ?? 0),'source_etag'=>(string)($meta['source_etag'] ?? ''),'filename'=>$row['filename'],'mode'=>$mode,'citation'=>$citation,'locator'=>$meta['locator'] ?? [],'evidence_kind'=>$meta['evidence_kind']??'document_text','chunk_ordinal'=>(int)($meta['chunk']??0),'chunk_total'=>(int)($meta['total']??0),'content_partial'=>mb_strlen($content)<mb_strlen((string)$row['content_preview']),'truncated'=>!empty($meta['truncated']),'scan_complete'=>$t['scan_complete']];
            if (($row['source_table']??'')==='DocumentKnowledgeChunks') $metadata['evidence_url']='session_attachment_viewer.php?session_id='.$sessionId.'&central_chunk_id='.(int)$row['id_'];
            $items[] = new ContextItem($row['source_table']??'SessionContextBlocks',(int)$row['id_'],$row['block_type'],'session_attachment',$content,(float)$row['score'],null,$metadata);
            $t['selected'][] = ['block_id'=>(int)$row['id_'],'source_table'=>$row['source_table']??'SessionContextBlocks','block_type'=>$row['block_type'],'filename'=>$row['filename'],'content'=>$content,'score'=>$row['score'],'lexical_score'=>$row['lexical_score'],'semantic_score'=>$row['semantic_score'],'citation'=>$citation];
        }
        $context = implode("\n\n", $parts);
        return ['context'=>$context,'items'=>$items,'telemetry'=>$t];
    }
}
