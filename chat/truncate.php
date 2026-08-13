<?php
// truncate.php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// 1. Validaciones de Seguridad
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No autorizado. Solo admin (ID 1).']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

if (
    empty($_POST['csrf_token'])
    || empty($_SESSION['csrf_token'])
    || !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])
) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido.']);
    exit;
}

$truncateMode = (string)($_POST['truncate_mode'] ?? '');
if (!in_array($truncateMode, ['dry_run', 'confirm'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Modo inválido. Usa dry_run o confirm.']);
    exit;
}

// 2. Cargar Bootstrap y BD
try {
    $bootstrap = __DIR__ . '/app_bootstrap.php';
    if (!is_file($bootstrap)) {
        throw new RuntimeException('app_bootstrap.php no encontrado.');
    }
    require_once $bootstrap;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Bootstrap: ' . $e->getMessage()]);
    exit;
}

$db = $GLOBALS['db_connection'] ?? $GLOBALS['db'] ?? $GLOBALS['conn'] ?? null;
if (!$db instanceof mysqli) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No hay conexión mysqli disponible.']);
    exit;
}

// 3. Lógica de Truncado
$excluir = ['Users', 'TokenUsage', 'FileS3', 'S3Folders', 'Projects'];

$tablas = [
    'AccessControl', 'ChatMessages', 'ChatSessions', 'ChunkEmbeddings',
    'EmbeddingJobs', 'FileVersions', 'LintAttempts', 'PhaseCache',
    'ProjectContext', 'ProjectSources', 'ProjectTestCommands',
    'PromptCompilations', 'SessionContextBlocks', 'SourceChunks',
    'ToolCalls', 'UserProceduralMemory',
];

$tablas = array_values(array_filter($tablas, fn($t) => !in_array($t, $excluir, true)));

$resultado = [
    'ok' => true,
    'mode' => $truncateMode,
    'message' => '',
    'tablas' => [],
    'omitidas' => [],
];

try {
    if ($truncateMode === 'confirm') {
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->query('SET UNIQUE_CHECKS = 0');
    }

    foreach ($tablas as $tabla) {
        $tablaEscapada = $db->real_escape_string($tabla);
        $rs = $db->query("SHOW TABLES LIKE '{$tablaEscapada}'");

        if (!$rs || $rs->num_rows === 0) {
            $resultado['omitidas'][] = $tabla;
            continue;
        }

        if ($truncateMode === 'confirm') {
            $tablaSegura = str_replace('`', '``', $tabla);
            $db->query("TRUNCATE TABLE `{$tablaSegura}`");
        }

        $resultado['tablas'][] = $tabla;
    }

    if ($truncateMode === 'confirm') {
        $db->query('SET UNIQUE_CHECKS = 1');
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $resultado['message'] = 'Tablas truncadas correctamente.';
    } else {
        $resultado['message'] = 'Simulación completada. No se modificó la base de datos.';
    }

    echo json_encode($resultado);
    exit;
} catch (Throwable $e) {
    if ($truncateMode === 'confirm') {
        try {
            $db->query('SET UNIQUE_CHECKS = 1');
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
        } catch (Throwable $ignored) {}
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error DB: ' . $e->getMessage()]);
    exit;
}