<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function truncateExit(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    truncateExit(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

try {
    require_once __DIR__ . '/app_bootstrap.php';
    require_once __DIR__ . '/includes/Chat/ChatIdentity.php';
    require_once __DIR__ . '/includes/Auth/AuthorizationService.php';

    $csrf = (string)($_POST['csrf_token'] ?? '');
    $expectedCsrf = (string)($_SESSION['csrf_token'] ?? '');
    if ($csrf === '' || $expectedCsrf === '' || !hash_equals($expectedCsrf, $csrf)) {
        throw new RuntimeException('csrf_invalid');
    }

    $userId = ChatIdentity::resolveUserId($db_connection);
    if ($userId < 1) throw new RuntimeException('authentication_required');

    $authorization = new AuthorizationService($db_connection);
    $authorization->assertAllowed($userId, 'system.reset');

    $mode = trim((string)($_POST['truncate_mode'] ?? ''));
    if (!in_array($mode, ['dry_run', 'confirm'], true)) {
        throw new InvalidArgumentException('truncate_mode_invalid');
    }

    if ($mode === 'confirm' && !hash_equals('RESET_RUNTIME_DATA', (string)($_POST['confirm_text'] ?? ''))) {
        throw new RuntimeException('reset_confirmation_invalid');
    }

    /*
     * Conserva el comportamiento histórico de truncate.php, sustituyendo el
     * privilegio mágico user_id=1 por system_role=superadmin/system.reset.
     * Se añaden únicamente tablas durables incorporadas después: migraciones,
     * auditoría y política de autonomía del proyecto.
     */
    $preserveRequested = [
        'Users',
        'TokenUsage',
        'FileS3',
        'S3Folders',
        'Projects',
        'UserAIAgentConfigs',
        'UserPreferences',
        'UserProceduralMemory',
        'UserPipelineFeatures',
        'SchemaMigrations',
        'AccessControl',
        'ProjectAutonomyPolicies',
    ];

    $schemaRow = $db_connection->query('SELECT DATABASE() AS db')->fetch_assoc();
    $schema = trim((string)($schemaRow['db'] ?? ''));
    if ($schema === '') throw new RuntimeException('database_not_selected');

    $stmt = $db_connection->prepare(
        "SELECT TABLE_NAME
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE'
         ORDER BY TABLE_NAME"
    );
    if (!$stmt) throw new RuntimeException('table_catalog_unavailable');
    $stmt->bind_param('s', $schema);
    if (!$stmt->execute()) throw new RuntimeException('table_catalog_query_failed');
    $tables = array_map(
        static fn(array $row): string => (string)$row['TABLE_NAME'],
        $stmt->get_result()->fetch_all(MYSQLI_ASSOC)
    );
    $stmt->close();

    $preserve = array_values(array_intersect($preserveRequested, $tables));
    $reset = array_values(array_diff($tables, $preserve));
    sort($preserve, SORT_STRING);
    sort($reset, SORT_STRING);

    if ($mode === 'dry_run') {
        truncateExit([
            'ok' => true,
            'mode' => 'dry_run',
            'message' => 'Simulación completada. No se modificó la base de datos.',
            'database' => $schema,
            'tablas' => $reset,
            'preservadas' => $preserve,
        ]);
    }

    $lockName = 'michat:web_runtime_reset';
    $lockStmt = $db_connection->prepare('SELECT GET_LOCK(?,10) AS acquired');
    if (!$lockStmt) throw new RuntimeException('runtime_reset_lock_unavailable');
    $lockStmt->bind_param('s', $lockName);
    $lockStmt->execute();
    $locked = (int)($lockStmt->get_result()->fetch_assoc()['acquired'] ?? 0) === 1;
    $lockStmt->close();
    if (!$locked) throw new RuntimeException('runtime_reset_locked');

    try {
        $db_connection->query('SET FOREIGN_KEY_CHECKS=0');
        $db_connection->query('SET UNIQUE_CHECKS=0');

        foreach ($reset as $table) {
            if (preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
                throw new RuntimeException('unsafe_table_identifier');
            }
            $db_connection->query('TRUNCATE TABLE `' . $table . '`');
        }
    } finally {
        try { $db_connection->query('SET UNIQUE_CHECKS=1'); } catch (Throwable) {}
        try { $db_connection->query('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable) {}

        $unlock = $db_connection->prepare('SELECT RELEASE_LOCK(?)');
        if ($unlock) {
            $unlock->bind_param('s', $lockName);
            $unlock->execute();
            $unlock->close();
        }
    }

    $action = 'Otro';
    $ip = isset($_SERVER['REMOTE_ADDR']) ? mb_substr((string)$_SERVER['REMOTE_ADDR'], 0, 45) : null;
    $details = json_encode([
        'event' => 'runtime_data_web_reset',
        'actor_user_id' => $userId,
        'system_role' => $authorization->roleForActiveUser($userId),
        'deleted_tables' => $reset,
        'preserved_tables' => $preserve,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $audit = $db_connection->prepare(
        'INSERT INTO AccessControl(user_id,date_time,action,ip_address,action_details) VALUES(?,NOW(),?,?,?)'
    );
    if ($audit) {
        $audit->bind_param('isss', $userId, $action, $ip, $details);
        $audit->execute();
        $audit->close();
    }

    truncateExit([
        'ok' => true,
        'mode' => 'confirm',
        'message' => 'Datos runtime limpiados correctamente.',
        'database' => $schema,
        'tablas' => $reset,
        'preservadas' => $preserve,
    ]);
} catch (InvalidArgumentException $e) {
    truncateExit(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    $status = match ($e->getMessage()) {
        'authentication_required' => 401,
        'csrf_invalid', 'permission_denied' => 403,
        default => 500,
    };
    error_log('truncate.php: ' . $e->getMessage());
    truncateExit(['ok' => false, 'error' => $e->getMessage()], $status);
}
