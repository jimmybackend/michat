<?php

declare(strict_types=1);

final class MemoryWriteRepository
{
    private mysqli $db;
    private ?bool $schemaReady = null;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function schemaReady(): bool
    {
        if ($this->schemaReady !== null) return $this->schemaReady;
        $res = $this->db->query("SHOW TABLES LIKE 'MemoryWriteEvents'");
        $this->schemaReady = $res instanceof mysqli_result && $res->num_rows > 0;
        if ($res instanceof mysqli_result) $res->free();
        return $this->schemaReady;
    }

    /** @return array{id:int,existing:bool,status:string} */
    public function beginEvent(int $userId, int $sessionId, int $projectId, int $questionId, int $answerId, string $intent, string $version): array
    {
        $sql = "SELECT id_, status FROM MemoryWriteEvents WHERE question_msg_id=? AND answer_msg_id=? AND writer_version=? LIMIT 1";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) throw new RuntimeException($this->db->error);
        $stmt->bind_param('iis', $questionId, $answerId, $version);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && in_array((string)$row['status'], ['completed','skipped'], true)) {
            return ['id' => (int)$row['id_'], 'existing' => true, 'status' => (string)$row['status']];
        }

        if ($row) {
            $id = (int)$row['id_'];
            $stmt = $this->db->prepare("UPDATE MemoryWriteEvents SET status='started', error_text=NULL, updated_at=NOW() WHERE id_=? AND user_id_=?");
            if (!$stmt) throw new RuntimeException($this->db->error);
            $stmt->bind_param('ii', $id, $userId);
            $stmt->execute();
            $stmt->close();
            return ['id' => $id, 'existing' => false, 'status' => 'started'];
        }

        $projectParam = $projectId > 0 ? $projectId : null;
        $stmt = $this->db->prepare(
            "INSERT INTO MemoryWriteEvents (user_id_, session_id_, project_id_, question_msg_id, answer_msg_id, writer_version, status, route_intent) VALUES (?,?,?,?,?,?,'started',?)"
        );
        if (!$stmt) throw new RuntimeException($this->db->error);
        $stmt->bind_param('iiiiiss', $userId, $sessionId, $projectParam, $questionId, $answerId, $version, $intent);
        $stmt->execute();
        $id = (int)$this->db->insert_id;
        $stmt->close();
        return ['id' => $id, 'existing' => false, 'status' => 'started'];
    }

    /** @param MemoryWriteCandidate[] $candidates @param array<int,array<string,mixed>> $writes @param array<string,int> $usage */
    public function finishEvent(int $id, string $status, string $reason, string $modelId, array $candidates, array $writes, array $usage, array $errors = []): void
    {
        $candidateJson = json_encode(array_map(static fn(MemoryWriteCandidate $c) => $c->toArray(), $candidates), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $writesJson = json_encode($writes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $usageJson = json_encode($usage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $errorText = $errors ? implode("\n", $errors) : null;
        $candidateCount = count($candidates);
        $writeCount = count(array_filter($writes, static fn(array $w): bool => in_array(($w['action'] ?? ''), ['inserted','updated','reinforced'], true)));
        $stmt = $this->db->prepare(
            "UPDATE MemoryWriteEvents SET status=?, reason=?, model_id=?, candidate_count=?, write_count=?, candidates_json=?, writes_json=?, usage_json=?, error_text=?, updated_at=NOW() WHERE id_=?"
        );
        if (!$stmt) throw new RuntimeException($this->db->error);
        $stmt->bind_param('sssiissssi', $status, $reason, $modelId, $candidateCount, $writeCount, $candidateJson, $writesJson, $usageJson, $errorText, $id);
        $stmt->execute();
        $stmt->close();
    }

    /** @param MemoryWriteCandidate[] $candidates @return array<int,array<string,mixed>> */
    public function persist(int $userId, int $sessionId, int $projectId, array $candidates): array
    {
        $writes = [];
        foreach ($candidates as $candidate) {
            if ($candidate->target === 'project_context' && $projectId > 0) {
                $writes[] = $this->persistProject($userId, $projectId, $candidate);
            } elseif ($candidate->target === 'procedural') {
                $writes[] = $this->persistProcedural($userId, $sessionId, $projectId, $candidate);
            }
        }
        return $writes;
    }

    /** @return array<string,mixed> */
    private function persistProject(int $userId, int $projectId, MemoryWriteCandidate $candidate): array
    {
        $owner = $this->db->prepare("SELECT id_ FROM Projects WHERE id_=? AND user_id_=? AND status<>'deleted' LIMIT 1");
        if (!$owner) throw new RuntimeException($this->db->error);
        $owner->bind_param('ii', $projectId, $userId);
        $owner->execute();
        $ok = (bool)$owner->get_result()->fetch_assoc();
        $owner->close();
        if (!$ok) return ['target'=>'project_context','action'=>'skipped','reason'=>'project_not_owned','type'=>$candidate->type];

        $existing = null;
        if ($candidate->title !== '') {
            $stmt = $this->db->prepare("SELECT id_, title, content FROM ProjectContext WHERE project_id_=? AND type=? AND LOWER(TRIM(title))=LOWER(TRIM(?)) ORDER BY id_ DESC LIMIT 1");
            if (!$stmt) throw new RuntimeException($this->db->error);
            $stmt->bind_param('iss', $projectId, $candidate->type, $candidate->title);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        if (!$existing) {
            $stmt = $this->db->prepare("SELECT id_, title, content FROM ProjectContext WHERE project_id_=? AND type=? AND LOWER(TRIM(content))=LOWER(TRIM(?)) ORDER BY id_ DESC LIMIT 1");
            if (!$stmt) throw new RuntimeException($this->db->error);
            $stmt->bind_param('iss', $projectId, $candidate->type, $candidate->content);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        if ($existing) {
            $id = (int)$existing['id_'];
            if ($this->normalize((string)$existing['content']) === $this->normalize($candidate->content)) {
                return ['target'=>'project_context','action'=>'unchanged','id'=>$id,'type'=>$candidate->type,'title'=>$candidate->title];
            }
            $stmt = $this->db->prepare("UPDATE ProjectContext SET title=?, content=?, updated_at=NOW() WHERE id_=? AND project_id_=?");
            if (!$stmt) throw new RuntimeException($this->db->error);
            $stmt->bind_param('ssii', $candidate->title, $candidate->content, $id, $projectId);
            $stmt->execute();
            $stmt->close();
            return ['target'=>'project_context','action'=>'updated','id'=>$id,'type'=>$candidate->type,'title'=>$candidate->title];
        }

        $stmt = $this->db->prepare("INSERT INTO ProjectContext (project_id_, type, title, content, source_chunk_id) VALUES (?,?,?,?,NULL)");
        if (!$stmt) throw new RuntimeException($this->db->error);
        $stmt->bind_param('isss', $projectId, $candidate->type, $candidate->title, $candidate->content);
        $stmt->execute();
        $id = (int)$this->db->insert_id;
        $stmt->close();
        return ['target'=>'project_context','action'=>'inserted','id'=>$id,'type'=>$candidate->type,'title'=>$candidate->title];
    }

    /** @return array<string,mixed> */
    private function persistProcedural(int $userId, int $sessionId, int $projectId, MemoryWriteCandidate $candidate): array
    {
        // Fase 4.1: consolidar únicamente dentro del mismo contenedor real.
        // En proyecto: memorias originadas en sesiones de ESE proyecto.
        // En chat libre: sólo memorias originadas en ESTA sesión.
        if ($projectId > 0) {
            $sql = "SELECT upm.id_, upm.content, upm.confidence
                    FROM UserProceduralMemory upm
                    INNER JOIN ChatSessions src ON src.id_=upm.source_session_id
                    WHERE upm.user_id_=? AND upm.memory_type=?
                      AND src.user_id_=? AND src.project_id_=?
                    ORDER BY upm.is_active DESC, upm.confidence DESC, upm.updated_at DESC LIMIT 50";
            $stmt = $this->db->prepare($sql);
            if (!$stmt) throw new RuntimeException($this->db->error);
            $stmt->bind_param('isii', $userId, $candidate->type, $userId, $projectId);
        } else {
            $stmt = $this->db->prepare("SELECT id_, content, confidence FROM UserProceduralMemory WHERE user_id_=? AND memory_type=? AND source_session_id=? ORDER BY is_active DESC, confidence DESC, updated_at DESC LIMIT 50");
            if (!$stmt) throw new RuntimeException($this->db->error);
            $stmt->bind_param('isi', $userId, $candidate->type, $sessionId);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $best = null;
        $bestSimilarity = 0.0;
        while ($row = $res->fetch_assoc()) {
            $similarity = $this->jaccard((string)$row['content'], $candidate->content);
            if ($similarity > $bestSimilarity) { $bestSimilarity = $similarity; $best = $row; }
        }
        $stmt->close();

        if ($best && ($bestSimilarity >= 0.84 || $this->normalize((string)$best['content']) === $this->normalize($candidate->content))) {
            $id = (int)$best['id_'];
            $confidence = min(10, max(1, (int)$best['confidence']) + 1);
            $stmt = $this->db->prepare("UPDATE UserProceduralMemory SET content=?, source_session_id=?, confidence=?, is_active=1, updated_at=NOW() WHERE id_=? AND user_id_=?");
            if (!$stmt) throw new RuntimeException($this->db->error);
            $stmt->bind_param('siiii', $candidate->content, $sessionId, $confidence, $id, $userId);
            $stmt->execute(); $stmt->close();
            return ['target'=>'procedural','action'=>'reinforced','id'=>$id,'type'=>$candidate->type,'similarity'=>round($bestSimilarity,4),'confidence'=>$confidence,'scope'=>$projectId>0?'project':'session'];
        }

        $confidence = 1;
        $stmt = $this->db->prepare("INSERT INTO UserProceduralMemory (user_id_, memory_type, content, source_session_id, confidence, is_active) VALUES (?,?,?,?,?,1)");
        if (!$stmt) throw new RuntimeException($this->db->error);
        $stmt->bind_param('issii', $userId, $candidate->type, $candidate->content, $sessionId, $confidence);
        $stmt->execute(); $id=(int)$this->db->insert_id; $stmt->close();
        return ['target'=>'procedural','action'=>'inserted','id'=>$id,'type'=>$candidate->type,'confidence'=>$confidence,'scope'=>$projectId>0?'project':'session'];
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = strtr($text, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text) ?? $text;
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function jaccard(string $a, string $b): float
    {
        $ta = array_values(array_unique(array_filter(explode(' ', $this->normalize($a)), static fn(string $v): bool => mb_strlen($v) >= 3)));
        $tb = array_values(array_unique(array_filter(explode(' ', $this->normalize($b)), static fn(string $v): bool => mb_strlen($v) >= 3)));
        if (!$ta || !$tb) return 0.0;
        $ia = array_intersect($ta, $tb);
        $union = array_unique(array_merge($ta, $tb));
        return count($union) ? count($ia) / count($union) : 0.0;
    }
}
