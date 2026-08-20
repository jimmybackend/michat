<?php
declare(strict_types=1);

/** Persistence boundary for typed, metadata-free Task resource provenance. */
class TaskArtifactRepository
{
    private const RELATIONS = ['read', 'used', 'created', 'modified', 'generated'];
    private const RESOURCE_TYPES = ['project_source', 'source_chunk', 'file_version', 'file_s3'];

    public function __construct(private mysqli $db) {}

    /** @return array<string,mixed> */
    public function record(int $executionId, ?int $toolCallId, string $relation, string $resourceType, int $resourceId): array
    {
        $this->validateInput($executionId, $toolCallId, $relation, $resourceType, $resourceId);
        $scope = $this->executionScope($executionId);
        $this->assertResourceOwned($scope, $resourceType, $resourceId);
        if ($toolCallId !== null) $this->assertToolCallCoherent($scope, $toolCallId);

        $stmt = $this->prepare(
            'INSERT INTO TaskArtifacts(execution_id_,tool_call_id_,relation,resource_type,resource_id) '
            .'VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE id_=LAST_INSERT_ID(id_)'
        );
        $stmt->bind_param('iissi', $executionId, $toolCallId, $relation, $resourceType, $resourceId);
        $this->execute($stmt);
        $id = (int)$this->db->insert_id;
        $stmt->close();
        if ($id < 1) throw new RuntimeException('task_artifact_persist_failed');
        return $this->findById($id) ?? throw new RuntimeException('task_artifact_persist_failed');
    }

    /** @return list<array<string,mixed>> */
    public function listByExecution(int $executionId): array
    {
        if ($executionId < 1) throw new TaskValidationException('execution_id_invalid');
        $stmt = $this->prepare(
            'SELECT id_,execution_id_,tool_call_id_,relation,resource_type,resource_id,created_at '
            .'FROM TaskArtifacts WHERE execution_id_=? ORDER BY id_'
        );
        $stmt->bind_param('i', $executionId);
        return $this->rows($stmt);
    }

    /** @return list<array<string,mixed>> */
    public function listByTask(int $taskId): array
    {
        if ($taskId < 1) throw new TaskValidationException('task_id_invalid');
        $stmt = $this->prepare(
            'SELECT a.id_,a.execution_id_,a.tool_call_id_,a.relation,a.resource_type,a.resource_id,a.created_at '
            .'FROM TaskArtifacts a JOIN TaskExecutions e ON e.id_=a.execution_id_ '
            .'WHERE e.task_id_=? ORDER BY a.execution_id_,a.id_'
        );
        $stmt->bind_param('i', $taskId);
        return $this->rows($stmt);
    }

    private function validateInput(int $executionId, ?int $toolCallId, string $relation, string $resourceType, int $resourceId): void
    {
        if ($executionId < 1) throw new TaskValidationException('execution_id_invalid');
        if ($toolCallId !== null && $toolCallId < 1) throw new TaskValidationException('tool_call_id_invalid');
        if (!in_array($relation, self::RELATIONS, true)) throw new TaskValidationException('artifact_relation_invalid');
        if (!in_array($resourceType, self::RESOURCE_TYPES, true)) throw new TaskValidationException('artifact_resource_type_invalid');
        if ($resourceId < 1) throw new TaskValidationException('artifact_resource_id_invalid');
    }

    /** @return array{task_id:int,step_id:int,user_id:int,project_id:?int,session_id:int} */
    private function executionScope(int $executionId): array
    {
        $stmt = $this->prepare(
            'SELECT e.task_id_,e.step_id_,t.user_id_,t.project_id_,t.session_id_ '
            .'FROM TaskExecutions e JOIN Tasks t ON t.id_=e.task_id_ '
            .'JOIN TaskSteps s ON s.id_=e.step_id_ AND s.task_id_=e.task_id_ WHERE e.id_=? LIMIT 1'
        );
        $stmt->bind_param('i', $executionId);
        $this->execute($stmt);
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) throw new TaskValidationException('task_execution_not_found');
        return [
            'task_id' => (int)$row['task_id_'],
            'step_id' => (int)$row['step_id_'],
            'user_id' => (int)$row['user_id_'],
            'project_id' => $row['project_id_'] === null ? null : (int)$row['project_id_'],
            'session_id' => (int)$row['session_id_'],
        ];
    }

    /** @param array{user_id:int,project_id:?int} $scope */
    private function assertResourceOwned(array $scope, string $resourceType, int $resourceId): void
    {
        if ($resourceType === 'file_s3') {
            $stmt = $this->prepare('SELECT 1 FROM FileS3 WHERE id_=? AND user_id_=? LIMIT 1');
            $stmt->bind_param('ii', $resourceId, $scope['user_id']);
        } else {
            if ($scope['project_id'] === null) throw new TaskValidationException('artifact_resource_scope_mismatch');
            $projectId = $scope['project_id'];
            if ($resourceType === 'project_source') {
                $stmt = $this->prepare('SELECT 1 FROM ProjectSources ps JOIN Projects p ON p.id_=ps.project_id_ WHERE ps.id_=? AND ps.project_id_=? AND p.user_id_=? LIMIT 1');
            } elseif ($resourceType === 'source_chunk') {
                $stmt = $this->prepare('SELECT 1 FROM SourceChunks sc JOIN ProjectSources ps ON ps.id_=sc.source_id_ AND ps.project_id_=sc.project_id_ JOIN Projects p ON p.id_=ps.project_id_ WHERE sc.id_=? AND sc.project_id_=? AND p.user_id_=? LIMIT 1');
            } else {
                $stmt = $this->prepare('SELECT 1 FROM FileVersions fv JOIN Projects p ON p.id_=fv.project_id_ WHERE fv.id_=? AND fv.project_id_=? AND p.user_id_=? LIMIT 1');
            }
            $stmt->bind_param('iii', $resourceId, $projectId, $scope['user_id']);
        }
        $this->execute($stmt);
        $owned = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        if (!$owned) throw new TaskValidationException('artifact_resource_scope_mismatch');
    }

    /** @param array{project_id:?int,session_id:int} $scope */
    private function assertToolCallCoherent(array $scope, int $toolCallId): void
    {
        $stmt = $this->prepare('SELECT session_id_,project_id_ FROM ToolCalls WHERE id_=? LIMIT 1');
        $stmt->bind_param('i', $toolCallId);
        $this->execute($stmt);
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) throw new TaskValidationException('tool_call_not_found');
        $projectId = $row['project_id_'] === null ? null : (int)$row['project_id_'];
        if ((int)$row['session_id_'] !== $scope['session_id'] || $projectId !== $scope['project_id']) {
            throw new TaskValidationException('tool_call_scope_mismatch');
        }
    }

    /** @return array<string,mixed>|null */
    private function findById(int $id): ?array
    {
        $stmt = $this->prepare('SELECT id_,execution_id_,tool_call_id_,relation,resource_type,resource_id,created_at FROM TaskArtifacts WHERE id_=? LIMIT 1');
        $stmt->bind_param('i', $id);
        $this->execute($stmt);
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? $this->dto($row) : null;
    }

    /** @return list<array<string,mixed>> */
    private function rows(mysqli_stmt $stmt): array
    {
        $this->execute($stmt);
        $rows = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $rows[] = $this->dto($row);
        $stmt->close();
        return $rows;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function dto(array $row): array
    {
        return [
            'id_' => (int)$row['id_'],
            'execution_id_' => (int)$row['execution_id_'],
            'tool_call_id_' => $row['tool_call_id_'] === null ? null : (int)$row['tool_call_id_'],
            'relation' => (string)$row['relation'],
            'resource_type' => (string)$row['resource_type'],
            'resource_id' => (int)$row['resource_id'],
            'created_at' => (string)$row['created_at'],
        ];
    }

    private function prepare(string $sql): mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) throw new RuntimeException('database_error');
        return $stmt;
    }

    private function execute(mysqli_stmt $stmt): void
    {
        if (!$stmt->execute()) throw new RuntimeException('database_error');
    }
}
