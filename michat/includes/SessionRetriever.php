<?php
/**
 * SessionRetriever
 *
 * Repositorio de lectura para memoria/contexto del chat.
 * No decide qué memoria usar: esa responsabilidad pertenece a
 * MemoryContextRouter. Esta clase solo recupera datos solicitados.
 */

declare(strict_types=1);

final class SessionRetriever
{
    private mysqli $db;

    /** @var array<string,mixed> */
    private array $options;

    /**
     * @param array<string,mixed>|null $options
     */
    public function __construct(mysqli $db_connection, ?array $options = null)
    {
        $this->db = $db_connection;
        $this->options = $options ?? [];
    }

    /**
     * Recupera contexto auxiliar para el compilador.
     *
     * @param array<string,bool> $flags
     * @return array<string,mixed>
     */
    public function retrieve(
        int $session_id,
        string $text,
        ?int $projectId,
        array $flags = []
    ): array {
        $includeRecent = $flags['include_recent'] ?? true;
        $includeProject = $flags['include_project'] ?? true;
        $includeSummary = $flags['include_summary'] ?? true;

        $result = [
            'session_info' => null,
            'recent_context' => [],
            'project_context' => null,
            'summary' => null,
        ];

        $stmt = $this->db->prepare(
            "SELECT id_, project_id_, meta, context_summary, created_at
             FROM ChatSessions
             WHERE id_ = ?"
        );
        if ($stmt) {
            $stmt->bind_param('i', $session_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $result['session_info'] = $row;

                if (!empty($row['meta'])) {
                    $meta = json_decode((string)$row['meta'], true);
                    if (is_array($meta)) {
                        $result['session_meta'] = $meta;
                        if ($includeSummary && isset($meta['summary']) && trim((string)$meta['summary']) !== '') {
                            $result['summary'] = (string)$meta['summary'];
                        }
                    }
                }

                if (
                    $includeSummary
                    && empty($result['summary'])
                    && !empty($row['context_summary'])
                ) {
                    $result['summary'] = (string)$row['context_summary'];
                }
            }
            $stmt->close();
        }

        if ($includeRecent) {
            $stmt = $this->db->prepare(
                "SELECT role, content, created_at
                 FROM ChatMessages
                 WHERE session_id_ = ?
                   AND role IN ('user', 'assistant')
                 ORDER BY id_ DESC
                 LIMIT 10"
            );
            if ($stmt) {
                $stmt->bind_param('i', $session_id);
                $stmt->execute();
                $res = $stmt->get_result();
                $messages = [];
                while ($row = $res->fetch_assoc()) {
                    $messages[] = $row;
                }
                $result['recent_context'] = array_reverse($messages);
                $stmt->close();
            }
        }

        if ($includeProject && $projectId !== null && $projectId > 0) {
            $stmt = $this->db->prepare("SELECT meta, root_prefix FROM Projects WHERE id_ = ?");
            if ($stmt) {
                $stmt->bind_param('i', $projectId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $result['project_context'] = $row;
                    if (!empty($row['meta'])) {
                        $projectMeta = json_decode((string)$row['meta'], true);
                        if (is_array($projectMeta)) {
                            $result['project_meta'] = $projectMeta;
                        }
                    }
                }
                $stmt->close();
            }
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $sessionRetrieval
     */
    public function formatContextForCompiler(array $sessionRetrieval): string
    {
        $contextParts = [];

        if (!empty($sessionRetrieval['summary'])) {
            $contextParts[] = 'RESUMEN DE SESIÓN: ' . trim((string)$sessionRetrieval['summary']);
        }

        if (!empty($sessionRetrieval['recent_context'])) {
            $recentText = '';
            foreach ($sessionRetrieval['recent_context'] as $msg) {
                $roleLabel = (($msg['role'] ?? '') === 'user') ? 'USUARIO' : 'ASISTENTE';
                $content = (string)($msg['content'] ?? '');
                $contentPreview = mb_substr($content, 0, 150);
                if (mb_strlen($content) > 150) $contentPreview .= '...';
                $recentText .= '[' . $roleLabel . ']: ' . $contentPreview . "\n";
            }
            if (trim($recentText) !== '') {
                $contextParts[] = "CONTEXTO RECIENTE:\n" . trim($recentText);
            }
        }

        if (!empty($sessionRetrieval['project_context'])) {
            $projCtx = $sessionRetrieval['project_context'];
            $projectInfo = 'PROYECTO ACTIVO';
            if (!empty($projCtx['root_prefix'])) {
                $projectInfo .= ' (Ruta: ' . $projCtx['root_prefix'] . ')';
            }
            if (!empty($sessionRetrieval['project_meta'])) {
                $pm = $sessionRetrieval['project_meta'];
                if (isset($pm['description'])) $projectInfo .= "\nDescripción: " . $pm['description'];
                if (isset($pm['instructions'])) $projectInfo .= "\nInstrucciones: " . $pm['instructions'];
            }
            $contextParts[] = $projectInfo;
        }

        return empty($contextParts) ? '' : implode("\n\n---\n\n", $contextParts);
    }

    /**
     * Recupera memoria procedural activa. Por diseño esta fuente también puede
     * actuar como política global de comportamiento.
     *
     * @param string[] $types
     * @return array<int,array<string,mixed>>
     */
    public function retrieveProceduralMemory(int $userId, array $types = [], int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $allowed = ['preference','rule','pattern','correction','workflow'];
        $types = array_values(array_intersect($allowed, array_map('strval', $types)));

        $sql = "SELECT memory_type, content, confidence, source_session_id, updated_at
                FROM UserProceduralMemory
                WHERE user_id_ = ? AND is_active = 1";

        if (!empty($types)) {
            $escaped = array_map(fn(string $t): string => "'" . $this->db->real_escape_string($t) . "'", $types);
            $sql .= ' AND memory_type IN (' . implode(',', $escaped) . ')';
        }

        $sql .= " ORDER BY confidence DESC, updated_at DESC LIMIT {$limit}";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) return [];

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        $stmt->close();
        return $rows;
    }

    /**
     * Recupera conocimiento estructurado del proyecto solo de los tipos pedidos
     * por MemoryContextRouter.
     *
     * @param string[] $types
     * @return array<int,array<string,mixed>>
     */
    public function retrieveProjectContext(int $projectId, array $types, int $limit = 20): array
    {
        if ($projectId <= 0 || empty($types)) return [];

        $allowed = ['rule','decision','fact','style','todo','note'];
        $types = array_values(array_unique(array_intersect($allowed, array_map('strval', $types))));
        if (empty($types)) return [];

        $limit = max(1, min(100, $limit));
        $escaped = array_map(fn(string $t): string => "'" . $this->db->real_escape_string($t) . "'", $types);
        $typeList = implode(',', $escaped);

        $sql = "SELECT id_, type, title, content, source_chunk_id, created_at, updated_at
                FROM ProjectContext
                WHERE project_id_ = ?
                  AND type IN ({$typeList})
                ORDER BY updated_at DESC, id_ DESC
                LIMIT {$limit}";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('i', $projectId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        $stmt->close();
        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    public function formatProjectContext(array $items): string
    {
        if (empty($items)) return '';

        $lines = [
            '[MEMORIA DIRIGIDA DEL PROYECTO]',
            'Información estructurada seleccionada por el Memory Context Router:',
        ];
        foreach ($items as $item) {
            $type = strtoupper((string)($item['type'] ?? 'NOTE'));
            $title = trim((string)($item['title'] ?? ''));
            $content = trim((string)($item['content'] ?? ''));
            if ($content === '') continue;
            $prefix = '[' . $type . ']';
            if ($title !== '') $prefix .= ' ' . $title . ':';
            $lines[] = $prefix . ' ' . $content;
        }

        return count($lines) > 2 ? implode("\n", $lines) : '';
    }

    public function hasSessionAttachmentContext(int $sessionId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT id_
             FROM SessionContextBlocks
             WHERE session_id_ = ?
               AND block_type IN ('file','file_chunk')
             LIMIT 1"
        );
        if (!$stmt) return false;
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $res = $stmt->get_result();
        $found = $res && $res->num_rows > 0;
        $stmt->close();
        return $found;
    }
}
