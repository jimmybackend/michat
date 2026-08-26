<?php
declare(strict_types=1);

final class MigrationCatalog
{
    private const FILES = [
        'fase8_1_task_orchestrator.sql',
        'fase8_6d_3d_toolcalls_code_edit.sql',
        'fase8_7b_task_artifacts.sql',
        'fase10d_task_recurrence.sql',
        'fase11b_project_autonomy.sql',
        'fase11c_next_work_proposals.sql',
        'fase11d_post_task_continuations.sql',
        'fase11e0_replan_checkpoint.sql',
        'fase11e1_versioned_replanning.sql',
        'fase11f2_hitl_controls.sql',
        'fase12b_2c_global_ai_configuration_scope.sql',
    ];

    private string $sqlDirectory;

    public function __construct(?string $sqlDirectory = null)
    {
        $directory = realpath($sqlDirectory ?? dirname(__DIR__, 2).'/sql');
        if ($directory === false || !is_dir($directory)) {
            throw new RuntimeException('INVALID CATALOG: SQL directory is unavailable');
        }
        $this->sqlDirectory = rtrim($directory, DIRECTORY_SEPARATOR);
    }

    /** @return list<array{migration_id:string,filename:string,path:string,checksum_sha256:string}> */
    public function all(): array
    {
        $definitions = [];
        $ids = [];
        $filenames = [];
        foreach (self::FILES as $filename) {
            $id = substr($filename, 0, -4);
            if (isset($ids[$id]) || isset($filenames[$filename])) {
                throw new RuntimeException('INVALID CATALOG: duplicate migration identity');
            }
            $candidate = $this->sqlDirectory.DIRECTORY_SEPARATOR.$filename;
            $path = realpath($candidate);
            if ($path === false || !is_file($path) || is_link($candidate)) {
                throw new RuntimeException('INVALID CATALOG: migration file is unavailable or linked: '.$filename);
            }
            if (!str_starts_with($path, $this->sqlDirectory.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('INVALID CATALOG: migration escapes SQL directory: '.$filename);
            }
            $bytes = file_get_contents($path);
            if ($bytes === false) throw new RuntimeException('INVALID CATALOG: migration cannot be read: '.$filename);
            $definitions[] = [
                'migration_id' => $id,
                'filename' => $filename,
                'path' => $path,
                'checksum_sha256' => hash('sha256', $bytes),
            ];
            $ids[$id] = true;
            $filenames[$filename] = true;
        }

        $disk = glob($this->sqlDirectory.DIRECTORY_SEPARATOR.'*.sql') ?: [];
        $diskNames = array_map('basename', $disk);
        sort($diskNames, SORT_STRING);
        $catalogNames = self::FILES;
        sort($catalogNames, SORT_STRING);
        if ($diskNames !== $catalogNames) {
            throw new RuntimeException('INVALID CATALOG: SQL files and closed catalog differ');
        }
        return $definitions;
    }
}
