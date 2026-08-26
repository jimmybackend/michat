<?php
declare(strict_types=1);

final class SchemaMigrationRepository
{
    public function __construct(private mysqli $db, private string $databaseName) {}

    public function historyTableExists(): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME='SchemaMigrations'");
        $stmt->bind_param('s', $this->databaseName);$stmt->execute();
        $exists = (int)$stmt->get_result()->fetch_assoc()['c'] === 1;$stmt->close();
        return $exists;
    }

    public function ensureHistoryTable(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS SchemaMigrations (
          migration_id varchar(128) NOT NULL,
          filename varchar(255) NOT NULL,
          checksum_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          applied_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
          execution_ms int UNSIGNED NOT NULL,
          application_mode enum('applied','adopted','clean_baseline') NOT NULL,
          runner_version varchar(64) DEFAULT NULL,
          PRIMARY KEY (migration_id),
          UNIQUE KEY uq_schema_migrations_filename (filename)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
    }

    /** @return array<string,array<string,mixed>> */
    public function fetchHistory(): array
    {
        if (!$this->historyTableExists()) return [];
        $result = $this->db->query('SELECT migration_id,filename,checksum_sha256,applied_at,execution_ms,application_mode,runner_version FROM SchemaMigrations ORDER BY applied_at,migration_id');
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[(string)$row['migration_id']] = $row;
        $result->free();
        return $rows;
    }

    /** @param array{migration_id:string,filename:string,checksum_sha256:string} $migration */
    public function insertHistory(array $migration, int $executionMs, string $mode, string $runnerVersion): void
    {
        $stmt = $this->db->prepare('INSERT INTO SchemaMigrations (migration_id,filename,checksum_sha256,execution_ms,application_mode,runner_version) VALUES (?,?,?,?,?,?)');
        $migrationId=$migration['migration_id'];$filename=$migration['filename'];$checksum=$migration['checksum_sha256'];
        $stmt->bind_param('sssiss', $migrationId, $filename, $checksum, $executionMs, $mode, $runnerVersion);
        $stmt->execute();$stmt->close();
    }

    public function acquireLock(int $timeout): void
    {
        $name = $this->lockName();
        $stmt = $this->db->prepare('SELECT GET_LOCK(?,?) acquired');
        $stmt->bind_param('si', $name, $timeout);$stmt->execute();
        $value = $stmt->get_result()->fetch_assoc()['acquired'] ?? null;$stmt->close();
        if ($value === null) throw new RuntimeException('LOCK ERROR: GET_LOCK returned NULL');
        if ((int)$value !== 1) throw new RuntimeException('LOCK CONTENTION: migration runner is already active');
    }

    public function releaseLock(): void
    {
        $name = $this->lockName();
        $stmt = $this->db->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->bind_param('s', $name);$stmt->execute();$stmt->close();
    }

    public function db(): mysqli { return $this->db; }
    private function lockName(): string { return 'michat:migrate:'.substr(hash('sha256', $this->databaseName), 0, 40); }
}
