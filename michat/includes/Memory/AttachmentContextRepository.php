<?php
declare(strict_types=1);
require_once __DIR__ . '/DocumentAccessRepository.php';
require_once __DIR__ . '/AttachmentEvidence.php';

final class AttachmentContextRepository
{
    private DocumentAccessRepository $access;
    public function __construct(private mysqli $db, private $bedrock)
    {
        $this->access = new DocumentAccessRepository($db);
    }

    public function hasContext(int $userId, int $sessionId): bool
    {
        if (!$this->access->session($userId, $sessionId)) return false;
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
        $last = 0; $best = []; $files = []; $revisions = []; $started = microtime(true);
        do {
            $s = $this->db->prepare("SELECT id_,block_type,content_preview,s3_path,source_ids,embedding_json,embedding_model FROM SessionContextBlocks WHERE session_id_=? AND id_>? AND block_type IN ('file','file_chunk') ORDER BY id_ LIMIT 200");
            if (!$s) throw new RuntimeException('No se pudo recuperar conocimiento');
            $s->bind_param('ii', $sessionId, $last); $s->execute(); $r = $s->get_result();
            $count = $r->num_rows;
            while ($row = $r->fetch_assoc()) {
                $last = (int)$row['id_'];
                $meta = json_decode((string)$row['source_ids'], true) ?: [];
                $fileId = (int)($meta['files3_id'] ?? 0);
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
                $row['score'] = max($lex, $cos ?? 0) + 0.1 * $lex;
                $row['lexical_score'] = $lex; $row['semantic_score'] = $cos;
                $row['filename'] = $file['Nombre'];
                $best[] = $row;
            }
            $s->close();
            usort($best, static fn($a,$b) => ($b['score'] <=> $a['score']) ?: ($a['id_'] <=> $b['id_']));
            // Keep candidates per source for multi-document comparisons.
            $perFile = []; $best = array_values(array_filter($best, static function($row) use (&$perFile, $top) {
                $key = (string)$row['s3_path']; $perFile[$key] = ($perFile[$key] ?? 0) + 1;
                return $perFile[$key] <= $top;
            }));
            if (count($best) > 500) $best = array_slice(AttachmentEvidence::diversify($best, 500), 0, 500);
            if (microtime(true) - $started > 10 && $count === 200) {
                $t['limitation'] = 'Búsqueda parcial por presupuesto de tiempo; refine la consulta.';
                break;
            }
        } while ($count === 200);
        $t['scan_complete'] = $count < 200;
        $selected = AttachmentEvidence::diversify($best, $top);
        // Adjacent source chunks complete split clauses without expanding to the whole file.
        $seen = array_fill_keys(array_map(static fn($r)=>(int)$r['id_'],$selected),true);
        foreach ($selected as $anchor) {
            if (count($seen) >= 24) break;
            $meta = json_decode((string)$anchor['source_ids'],true) ?: [];
            if ($anchor['block_type'] !== 'file_chunk' || empty($meta['chunk'])) continue;
            $next = (int)$meta['chunk'] + 1;
            $q = $this->db->prepare("SELECT id_,block_type,content_preview,s3_path,source_ids FROM SessionContextBlocks WHERE session_id_=? AND s3_path=? AND block_type='file_chunk' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(source_ids,'$.chunk')) AS UNSIGNED)=? ORDER BY id_ LIMIT 1");
            if (!$q) continue;
            $q->bind_param('isi',$sessionId,$anchor['s3_path'],$next);$q->execute();$neighbor=$q->get_result()->fetch_assoc();$q->close();
            if (!$neighbor || isset($seen[(int)$neighbor['id_']])) continue;
            $other=json_decode((string)$neighbor['source_ids'],true) ?: [];
            if (($other['files3_id']??null)!==($meta['files3_id']??null) || ($other['source_etag']??null)!==($meta['source_etag']??null)) continue;
            $seen[(int)$neighbor['id_']]=true;
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
            $metadata = ['session_id'=>$sessionId,'files3_id'=>(int)($meta['files3_id'] ?? 0),'filename'=>$row['filename'],'mode'=>$mode,'citation'=>$citation,'locator'=>$meta['locator'] ?? [],'truncated'=>!empty($meta['truncated']),'scan_complete'=>$t['scan_complete']];
            $items[] = new ContextItem('SessionContextBlocks',(int)$row['id_'],$row['block_type'],'session_attachment',$content,(float)$row['score'],null,$metadata);
            $t['selected'][] = ['block_id'=>(int)$row['id_'],'block_type'=>$row['block_type'],'filename'=>$row['filename'],'content'=>$content,'score'=>$row['score'],'lexical_score'=>$row['lexical_score'],'semantic_score'=>$row['semantic_score'],'citation'=>$citation];
        }
        $context = implode("\n\n", $parts);
        return ['context'=>$context,'items'=>$items,'telemetry'=>$t];
    }
}
