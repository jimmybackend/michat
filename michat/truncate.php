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

    // Lista cerrada: tablas nuevas, Federación, Office, S3 y configuración nunca se limpian automáticamente.
    // No incluir reglas de recurrencia, proyectos ni propuestas de trabajo persistentes.
    $allowedGroups = [
        'chat' => ['ChatActivityEvents', 'ChatMessages', 'ChatSessions'],
        'rag' => ['ChunkEmbeddings', 'EmbeddingJobs', 'SourceChunks'],
        'tasks' => [
            'PostTaskContinuations', 'TaskArtifacts', 'TaskDependencies', 'TaskEvents',
            'TaskExecutions', 'TaskPlanRevisions', 'TaskPlanRevisionSteps',
            'TaskReplanRequests', 'Tasks', 'TaskSteps', 'ToolCalls',
        ],
    ];
    $requested = $_POST['cleanup_groups'] ?? '';
    $groups = array_values(array_unique(array_filter(explode(',', (string)$requested))));
    sort($groups, SORT_STRING);
    if (!$groups || array_diff($groups, array_keys($allowedGroups))) {
        throw new InvalidArgumentException('cleanup_groups_invalid');
    }

    $schemaRow = $db_connection->query('SELECT DATABASE() AS db')->fetch_assoc();
    $schema = trim((string)($schemaRow['db'] ?? ''));
    if ($schema === '') throw new RuntimeException('database_not_selected');

    $stmt = $db_connection->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME"
    );
    if (!$stmt) throw new RuntimeException('table_catalog_unavailable');
    $stmt->bind_param('s', $schema);
    if (!$stmt->execute()) throw new RuntimeException('table_catalog_query_failed');
    $tables = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'TABLE_NAME');
    $stmt->close();

    $allow = [];
    foreach ($groups as $group) {
        foreach ($allowedGroups[$group] as $table) $allow[$table] = true;
    }
    $reset = array_values(array_intersect($tables, array_keys($allow)));
    $preserve = array_values(array_diff($tables, $reset));
    sort($reset, SORT_STRING);
    sort($preserve, SORT_STRING);
    if (!$reset) throw new RuntimeException('cleanup_no_matching_tables');

    // Nunca vaciar una tabla referenciada por otra que se conservará.
    $fkStmt = $db_connection->prepare(
        "SELECT TABLE_NAME, REFERENCED_TABLE_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA=? AND REFERENCED_TABLE_SCHEMA=?
           AND REFERENCED_TABLE_NAME IS NOT NULL"
    );
    if (!$fkStmt) throw new RuntimeException('cleanup_fk_catalog_unavailable');
    $fkStmt->bind_param('ss', $schema, $schema);
    if (!$fkStmt->execute()) throw new RuntimeException('cleanup_fk_catalog_query_failed');
    $fkRows = $fkStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $fkStmt->close();
    $protectedReferences = [];
    foreach ($fkRows as $fk) {
        $child = (string)$fk['TABLE_NAME'];
        $parent = (string)$fk['REFERENCED_TABLE_NAME'];
        if (in_array($parent, $reset, true) && !in_array($child, $reset, true)) {
            $protectedReferences[] = "$child -> $parent";
        }
    }
    if ($protectedReferences) {
        throw new RuntimeException('cleanup_fk_protected: '.implode(', ',array_slice(array_unique($protectedReferences),0,8)));
    }

    // Congelar la selección entre simulación y confirmación en la sesión superadmin.
    $fingerprint = hash('sha256', $schema."\\n".implode(',', $groups)."\\n".implode(',', $reset));
    if ($mode === 'dry_run') {
        $_SESSION['michat_cleanup_preview'] = ['fingerprint'=>$fingerprint,'time'=>time()];
    } else {
        $preview = $_SESSION['michat_cleanup_preview'] ?? [];
        unset($_SESSION['michat_cleanup_preview']);
        if (!is_array($preview) || !hash_equals((string)($preview['fingerprint']??''), $fingerprint)
            || time() - (int)($preview['time']??0) > 300) {
            throw new RuntimeException('cleanup_preview_required');
        }
    }

    if ($mode === 'dry_run') {
        truncateExit([
            'ok' => true,
            'mode' => 'dry_run',
            'message' => 'Simulación completada. No se modificó la base de datos.',
            'database' => $schema,
            'grupos' => $groups,
            'tablas' => $reset,
            'preservadas' => $preserve,
        ]);
    }

    // No interrumpir ejecuciones ni tareas todavía pendientes.
    if (in_array('tasks', $groups, true)) {
        $busy = $db_connection->query('SELECT COUNT(*) AS n FROM Tasks');
        if (!$busy || (int)($busy->fetch_assoc()['n'] ?? 0) > 0) {
            throw new RuntimeException('cleanup_tasks_not_empty_manual_review_required');
        }
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
        'cleanup_groups' => $groups,
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
        'grupos' => $groups,
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
