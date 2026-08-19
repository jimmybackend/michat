<?php

declare(strict_types=1);

require_once __DIR__ . '/TraceMetricsCalculator.php';

/**
 * Fase 7.7 · Métricas agregadas, READ-ONLY y siempre acotadas al usuario.
 */
final class TraceMetricsRepository
{
    public const VERSION = '7.7';

    private mysqli $db;
    private int $viewerUserId;
    private bool $adminLike;
    private int $targetUserId;
    /** @var array<string,bool> */
    private array $tableCache = [];
    /** @var array<string,bool> */
    private array $columnCache = [];

    public function __construct(mysqli $db, int $viewerUserId, bool $adminLike, ?int $targetUserId = null)
    {
        $this->db = $db;
        $this->viewerUserId = $viewerUserId;
        $this->adminLike = $adminLike;
        $requested = (int)($targetUserId ?? $viewerUserId);
        if ($requested <= 0) throw new InvalidArgumentException('user_id inválido.');
        if ($requested !== $viewerUserId && !$adminLike) {
            throw new RuntimeException('No tienes permisos para consultar métricas de otro usuario.');
        }
        $this->targetUserId = $requested;
    }

    /** @return array<string,mixed> */
    public function summary(int $sessionId, ?int $projectId = null, ?string $month = null): array
    {
        $session = $this->sessionForAccess($sessionId);
        $resolvedProjectId = (int)($projectId ?? 0);
        $sessionProjectId = (int)($session['project_id'] ?? 0);
        if ($resolvedProjectId > 0 && $resolvedProjectId !== $sessionProjectId) {
            throw new RuntimeException('El project_id solicitado no corresponde a esta sesión.');
        }
        if ($resolvedProjectId <= 0 && $sessionProjectId > 0) {
            $resolvedProjectId = $sessionProjectId;
        }
        if ($resolvedProjectId > 0) $this->projectForAccess($resolvedProjectId);

        $month = $this->normalizeMonth($month ?: date('Y-m'));
        [$monthStart, $monthEnd] = $this->monthBounds($month);

        $sessionMonth = $this->aggregateScope('session', $sessionId, $monthStart, $monthEnd);
        $sessionAll = $this->aggregateScope('session', $sessionId, null, null);

        $projectMonth = null;
        $projectAll = null;
        $projectBudget = null;
        if ($resolvedProjectId > 0) {
            $projectMonth = $this->aggregateScope('project', $resolvedProjectId, $monthStart, $monthEnd);
            $projectAll = $this->aggregateScope('project', $resolvedProjectId, null, null);
            $projectBudget = $this->projectBudget($resolvedProjectId, (float)($projectMonth['tokens']['recalculated_cost_usd'] ?? 0));
        }

        $userMonth = $this->aggregateScope('user', $this->targetUserId, $monthStart, $monthEnd);

        return [
            'version' => self::VERSION,
            'read_only' => true,
            'scope' => [
                'viewer_user_id' => $this->viewerUserId,
                'target_user_id' => $this->targetUserId,
                'session_id' => $sessionId,
                'project_id' => $resolvedProjectId > 0 ? $resolvedProjectId : null,
                'month' => $month,
                'month_start' => $monthStart,
                'month_end_exclusive' => $monthEnd,
            ],
            'session' => [
                'month' => $sessionMonth,
                'all_time' => $sessionAll,
            ],
            'project' => $resolvedProjectId > 0 ? [
                'month' => $projectMonth,
                'all_time' => $projectAll,
                'budget' => $projectBudget,
            ] : null,
            'user' => [
                'month' => $userMonth,
            ],
            'cost_note' => 'Los costos son estimaciones. Se muestran tanto los valores históricos guardados en TokenUsage como el recálculo con la tabla de precios usada por el dashboard actual.',
        ];
    }

    /** @return array<string,mixed> */
    private function aggregateScope(string $scope, int $scopeId, ?string $start, ?string $end): array
    {
        $tokens = $this->aggregateTokens($scope, $scopeId, $start, $end);
        $responses = $this->aggregateResponses($scope, $scopeId, $start, $end);
        $tools = $this->aggregateTools($scope, $scopeId, $start, $end);
        $memory = $this->aggregateMemoryWrites($scope, $scopeId, $start, $end);
        $traces = $this->aggregateTraces($scope, $scopeId, $start, $end);

        $responseCount = (int)($responses['assistant_responses'] ?? 0);
        $totalTokens = (int)($tokens['total_tokens'] ?? 0);
        $cost = (float)($tokens['recalculated_cost_usd'] ?? 0);

        return [
            'tokens' => $tokens,
            'responses' => $responses,
            'tools' => $tools,
            'memory' => $memory,
            'traces' => $traces,
            'efficiency' => [
                'tokens_per_response' => $responseCount > 0 ? round($totalTokens / $responseCount, 2) : null,
                'cost_per_response_usd' => $responseCount > 0 ? round($cost / $responseCount, 6) : null,
                'output_input_ratio' => (int)($tokens['input_tokens'] ?? 0) > 0
                    ? round((int)($tokens['output_tokens'] ?? 0) / (int)$tokens['input_tokens'], 4)
                    : null,
                'avg_response_latency_ms' => $responses['avg_latency_ms'] ?? null,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function aggregateTokens(string $scope, int $scopeId, ?string $start, ?string $end): array
    {
        if (!$this->tableExists('TokenUsage') || !$this->tableExists('ChatSessions')) {
            return TraceMetricsCalculator::aggregateTokenRows([]);
        }

        [$where, $types, $params] = $this->scopeWhere('cs', $scope, $scopeId);
        if ($start !== null && $end !== null) {
            $where .= ' AND tu.created_at >= ? AND tu.created_at < ?';
            $types .= 'ss';
            $params[] = $start;
            $params[] = $end;
        }

        $sql = "SELECT tu.model_id, tu.phase, COUNT(*) AS usage_count,
                       COALESCE(SUM(tu.input_tokens),0) AS total_input,
                       COALESCE(SUM(tu.output_tokens),0) AS total_output,
                       COALESCE(SUM(tu.estimated_cost_usd),0) AS total_stored_cost,
                       COALESCE(SUM(tu.duration_ms),0) AS total_duration_ms
                FROM TokenUsage tu
                JOIN ChatSessions cs ON cs.id_ = tu.session_id_
                WHERE {$where}
                GROUP BY tu.model_id, tu.phase
                ORDER BY total_input + total_output DESC";
        $rows = $this->queryRows($sql, $types, $params);
        return TraceMetricsCalculator::aggregateTokenRows($rows);
    }

    /** @return array<string,mixed> */
    private function aggregateResponses(string $scope, int $scopeId, ?string $start, ?string $end): array
    {
        if (!$this->tableExists('ChatMessages') || !$this->tableExists('ChatSessions')) {
            return ['assistant_responses'=>0,'avg_latency_ms'=>null,'total_latency_ms'=>0,'prompt_tokens'=>0,'completion_tokens'=>0];
        }
        [$where, $types, $params] = $this->scopeWhere('cs', $scope, $scopeId);
        $where .= " AND cm.role='assistant'";
        if ($start !== null && $end !== null) {
            $where .= ' AND cm.created_at >= ? AND cm.created_at < ?';
            $types .= 'ss'; $params[] = $start; $params[] = $end;
        }
        $sql = "SELECT COUNT(*) AS assistant_responses,
                       AVG(NULLIF(cm.latency_ms,0)) AS avg_latency_ms,
                       COALESCE(SUM(cm.latency_ms),0) AS total_latency_ms,
                       COALESCE(SUM(cm.prompt_tokens),0) AS prompt_tokens,
                       COALESCE(SUM(cm.completion_tokens),0) AS completion_tokens
                FROM ChatMessages cm
                JOIN ChatSessions cs ON cs.id_ = cm.session_id_
                WHERE {$where}";
        $row = $this->queryOne($sql, $types, $params);
        return [
            'assistant_responses' => (int)($row['assistant_responses'] ?? 0),
            'avg_latency_ms' => $row && $row['avg_latency_ms'] !== null ? round((float)$row['avg_latency_ms'], 2) : null,
            'total_latency_ms' => (int)($row['total_latency_ms'] ?? 0),
            'prompt_tokens' => (int)($row['prompt_tokens'] ?? 0),
            'completion_tokens' => (int)($row['completion_tokens'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private function aggregateTools(string $scope, int $scopeId, ?string $start, ?string $end): array
    {
        if (!$this->tableExists('ToolCalls') || !$this->tableExists('ChatSessions')) {
            return ['calls'=>0,'ok'=>0,'error'=>0,'timeout'=>0,'duration_ms_sum'=>0];
        }
        [$where, $types, $params] = $this->scopeWhere('cs', $scope, $scopeId);
        if ($start !== null && $end !== null) {
            $where .= ' AND tc.created_at >= ? AND tc.created_at < ?';
            $types .= 'ss'; $params[] = $start; $params[] = $end;
        }
        $sql = "SELECT COUNT(*) AS calls,
                       SUM(tc.status='ok') AS ok_count,
                       SUM(tc.status='error') AS error_count,
                       SUM(tc.status='timeout') AS timeout_count,
                       COALESCE(SUM(tc.duration_ms),0) AS duration_ms_sum
                FROM ToolCalls tc
                JOIN ChatSessions cs ON cs.id_ = tc.session_id_
                WHERE {$where}";
        $row = $this->queryOne($sql, $types, $params);
        return [
            'calls'=>(int)($row['calls'] ?? 0),
            'ok'=>(int)($row['ok_count'] ?? 0),
            'error'=>(int)($row['error_count'] ?? 0),
            'timeout'=>(int)($row['timeout_count'] ?? 0),
            'duration_ms_sum'=>(int)($row['duration_ms_sum'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private function aggregateMemoryWrites(string $scope, int $scopeId, ?string $start, ?string $end): array
    {
        if (!$this->tableExists('MemoryWriteEvents')) {
            return ['events'=>0,'writes'=>0,'candidates'=>0,'completed'=>0,'errors'=>0];
        }

        $where = 'mwe.user_id_ = ?';
        $types = 'i';
        $params = [$this->targetUserId];
        if ($scope === 'session') {
            $where .= ' AND mwe.session_id_ = ?'; $types .= 'i'; $params[] = $scopeId;
        } elseif ($scope === 'project') {
            $where .= ' AND mwe.project_id_ = ?'; $types .= 'i'; $params[] = $scopeId;
        }
        if ($start !== null && $end !== null) {
            $where .= ' AND mwe.created_at >= ? AND mwe.created_at < ?';
            $types .= 'ss'; $params[] = $start; $params[] = $end;
        }
        $sql = "SELECT COUNT(*) AS events,
                       COALESCE(SUM(mwe.write_count),0) AS writes,
                       COALESCE(SUM(mwe.candidate_count),0) AS candidates,
                       SUM(mwe.status='completed') AS completed_count,
                       SUM(mwe.status='error') AS error_count
                FROM MemoryWriteEvents mwe WHERE {$where}";
        $row = $this->queryOne($sql, $types, $params);
        return [
            'events'=>(int)($row['events'] ?? 0),
            'writes'=>(int)($row['writes'] ?? 0),
            'candidates'=>(int)($row['candidates'] ?? 0),
            'completed'=>(int)($row['completed_count'] ?? 0),
            'errors'=>(int)($row['error_count'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private function aggregateTraces(string $scope, int $scopeId, ?string $start, ?string $end): array
    {
        if (!$this->tableExists('ChatActivityEvents') || !$this->tableExists('ChatSessions')) {
            return ['traces'=>0,'events'=>0,'error_events'=>0];
        }
        [$where, $types, $params] = $this->scopeWhere('cs', $scope, $scopeId);
        if ($start !== null && $end !== null) {
            $where .= ' AND cae.created_at >= ? AND cae.created_at < ?';
            $types .= 'ss'; $params[] = $start; $params[] = $end;
        }
        $sql = "SELECT COUNT(DISTINCT cae.trace_id) AS traces,
                       COUNT(*) AS events,
                       SUM(cae.status='error') AS error_events
                FROM ChatActivityEvents cae
                JOIN ChatSessions cs ON cs.id_ = cae.session_id_
                WHERE {$where}";
        $row = $this->queryOne($sql, $types, $params);
        return [
            'traces'=>(int)($row['traces'] ?? 0),
            'events'=>(int)($row['events'] ?? 0),
            'error_events'=>(int)($row['error_events'] ?? 0),
        ];
    }

    /** @return array{0:string,1:string,2:array<int,mixed>} */
    private function scopeWhere(string $sessionAlias, string $scope, int $scopeId): array
    {
        $where = "{$sessionAlias}.user_id_ = ?";
        $types = 'i';
        $params = [$this->targetUserId];
        if ($scope === 'session') {
            $where .= " AND {$sessionAlias}.id_ = ?"; $types .= 'i'; $params[] = $scopeId;
        } elseif ($scope === 'project') {
            $where .= " AND {$sessionAlias}.project_id_ = ?"; $types .= 'i'; $params[] = $scopeId;
        }
        return [$where, $types, $params];
    }

    /** @return array<string,mixed> */
    private function sessionForAccess(int $sessionId): array
    {
        if ($sessionId <= 0) throw new InvalidArgumentException('session_id inválido.');
        $row = $this->queryOne(
            'SELECT id_, user_id_, project_id_, title, created_at, updated_at FROM ChatSessions WHERE id_=? LIMIT 1',
            'i', [$sessionId]
        );
        if (!$row) throw new RuntimeException('Sesión no encontrada.');
        if ((int)$row['user_id_'] !== $this->targetUserId) throw new RuntimeException('La sesión no pertenece al usuario seleccionado.');
        return [
            'id'=>(int)$row['id_'],
            'user_id'=>(int)$row['user_id_'],
            'project_id'=>$row['project_id_'] !== null ? (int)$row['project_id_'] : null,
            'title'=>(string)$row['title'],
        ];
    }

    /** @return array<string,mixed> */
    private function projectForAccess(int $projectId): array
    {
        $row = $this->queryOne('SELECT id_, user_id_, name FROM Projects WHERE id_=? LIMIT 1', 'i', [$projectId]);
        if (!$row) throw new RuntimeException('Proyecto no encontrado.');
        if ((int)$row['user_id_'] !== $this->targetUserId) throw new RuntimeException('El proyecto no pertenece al usuario seleccionado.');
        return ['id'=>(int)$row['id_'],'user_id'=>(int)$row['user_id_'],'name'=>(string)$row['name']];
    }

    /** @return array<string,mixed>|null */
    private function projectBudget(int $projectId, float $usedCost): ?array
    {
        if (!$this->columnExists('Projects', 'budget_usd_monthly')) return null;
        $row = $this->queryOne('SELECT budget_usd_monthly FROM Projects WHERE id_=? AND user_id_=? LIMIT 1', 'ii', [$projectId, $this->targetUserId]);
        if (!$row) return null;
        $budget = (float)($row['budget_usd_monthly'] ?? 0);
        if ($budget <= 0) return ['budget_usd_monthly'=>$budget,'used_usd'=>round($usedCost,6),'remaining_usd'=>null,'used_percent'=>null];
        return [
            'budget_usd_monthly'=>round($budget,4),
            'used_usd'=>round($usedCost,6),
            'remaining_usd'=>round(max(0, $budget - $usedCost),6),
            'used_percent'=>round(($usedCost / $budget) * 100,2),
        ];
    }

    private function normalizeMonth(string $month): string
    {
        $month = trim($month);
        if (preg_match('/^(\d{4})-(\d{2})$/', $month, $m)) {
            $monthNumber = (int)$m[2];
            if ($monthNumber >= 1 && $monthNumber <= 12) return $month;
        }
        return date('Y-m');
    }

    /** @return array{0:string,1:string} */
    private function monthBounds(string $month): array
    {
        $start = $month . '-01 00:00:00';
        $next = date('Y-m-d H:i:s', strtotime($start . ' +1 month'));
        return [$start, $next];
    }

    /** @param array<int,mixed> $params @return array<int,array<string,mixed>> */
    private function queryRows(string $sql, string $types, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) throw new RuntimeException($this->db->error);
        if ($types !== '') {
            $args = [$types];
            foreach ($params as $idx => $value) {
                $params[$idx] = $value;
                $args[] = &$params[$idx];
            }
            if (!call_user_func_array([$stmt, 'bind_param'], $args)) {
                $stmt->close();
                throw new RuntimeException('No se pudieron enlazar parámetros de métricas.');
            }
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        $stmt->close();
        return $rows;
    }

    /** @param array<int,mixed> $params @return array<string,mixed>|null */
    private function queryOne(string $sql, string $types, array $params): ?array
    {
        $rows = $this->queryRows($sql, $types, $params);
        return $rows[0] ?? null;
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableCache)) return $this->tableCache[$table];
        $safe = $this->db->real_escape_string($table);
        $res = $this->db->query("SHOW TABLES LIKE '{$safe}'");
        $exists = $res && $res->num_rows > 0;
        if ($res) $res->free();
        return $this->tableCache[$table] = $exists;
    }

    private function columnExists(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (array_key_exists($key, $this->columnCache)) return $this->columnCache[$key];
        if (!$this->tableExists($table)) return $this->columnCache[$key] = false;
        $safeTable = str_replace('`', '``', $table);
        $safeColumn = $this->db->real_escape_string($column);
        $res = $this->db->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
        $exists = $res && $res->num_rows > 0;
        if ($res) $res->free();
        return $this->columnCache[$key] = $exists;
    }
}
