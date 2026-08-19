<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function prefs_response(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$bootstrap = __DIR__ . '/app_bootstrap.php';

if (!is_file($bootstrap)) {
    prefs_response([
        'ok' => false,
        'error' => 'No se encontró app_bootstrap.php'
    ], 500);
}

require_once $bootstrap;

$pipelineFeaturePath = __DIR__ . '/includes/Pipeline/PipelineFeatureFlags.php';
if (is_file($pipelineFeaturePath)) {
    require_once $pipelineFeaturePath;
}

if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    prefs_response([
        'ok' => false,
        'error' => 'No autenticado'
    ], 401);
}

$user_id = (int)$_SESSION['user_id'];

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    prefs_response([
        'ok' => false,
        'error' => 'DB no disponible'
    ], 500);
}

function prefs_defaults(): array
{
    return [
        'model_id' => 'amazon.nova-micro-v1:0',
        'seed' => 42,

        'compile_temperature' => 0.0,
        'compile_max_tokens' => 200,
        'response_max_tokens' => 1000,
        'compile_top_p' => 0.1,

        'question_memory_enabled' => 1,
        'question_memory_scope' => 'project',
        'question_memory_max_candidates' => 20,
        'question_memory_window_lines' => 5,

        'theme_mode' => 'theme-light'
    ];
}

function prefs_clamp_int($value, int $min, int $max, int $default): int
{
    if (!is_numeric($value)) {
        return $default;
    }

    $value = (int)$value;

    if ($value < $min) {
        return $min;
    }

    if ($value > $max) {
        return $max;
    }

    return $value;
}

function prefs_clamp_float($value, float $min, float $max, float $default): float
{
    if (!is_numeric($value)) {
        return $default;
    }

    $value = (float)$value;

    if ($value < $min) {
        return $min;
    }

    if ($value > $max) {
        return $max;
    }

    return $value;
}

function prefs_normalize_model($value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return prefs_defaults()['model_id'];
    }

    // Model IDs típicos de Bedrock:
    // amazon.nova-micro-v1:0
    // anthropic.claude-3-haiku-20240307-v1:0
    if (preg_match('/^[A-Za-z0-9._:\-]+$/u', $value)) {
        return $value;
    }

    return prefs_defaults()['model_id'];
}

function prefs_normalize_scope($value): string
{
    $value = strtolower(trim((string)$value));

    return $value === 'session' ? 'session' : 'project';
}

function prefs_normalize_theme($value): string
{
    $value = strtolower(trim((string)$value));

    return $value === 'theme-dark' || $value === 'dark'
        ? 'theme-dark'
        : 'theme-light';
}

function prefs_bind_execute(mysqli $db, string $sql, array $params): bool
{
    $stmt = $db->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $types = '';

    foreach ($params as $value) {
        if (is_int($value)) {
            $types .= 'i';
        } elseif (is_float($value)) {
            $types .= 'd';
        } else {
            $types .= 's';
        }
    }

    $args = [$types];

    foreach ($params as $key => $value) {
        $args[$key + 1] = &$params[$key];
    }

    if (!call_user_func_array([$stmt, 'bind_param'], $args)) {
        $stmt->close();
        return false;
    }

    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function get_user_pipeline_features(mysqli $db, int $user_id): array
{
    if (!class_exists('PipelineFeatureFlags')) {
        return [
            'features' => [],
            'storage_available' => false,
            'storage_error' => 'Falta includes/Pipeline/PipelineFeatureFlags.php'
        ];
    }

    $flags = new PipelineFeatureFlags($db, $user_id);
    $diag = $flags->diagnostic();

    return [
        'features' => $flags->all(),
        'storage_available' => (bool)($diag['storage_available'] ?? false),
        'storage_error' => $diag['storage_error'] ?? null,
    ];
}

function save_user_pipeline_features(mysqli $db, int $user_id, array $features): array
{
    if (!class_exists('PipelineFeatureFlags')) {
        throw new RuntimeException('Falta includes/Pipeline/PipelineFeatureFlags.php');
    }

    $allowed = array_flip(PipelineFeatureFlags::keys());
    $normalized = [];

    foreach ($features as $key => $value) {
        $key = trim((string)$key);
        if (!isset($allowed[$key])) continue;
        $normalized[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }

    if (!$normalized) return [];

    $sql = "INSERT INTO UserPipelineFeatures (user_id_, feature_key, is_enabled, config_json)
            VALUES (?, ?, ?, NULL)
            ON DUPLICATE KEY UPDATE
                is_enabled = VALUES(is_enabled),
                updated_at = CURRENT_TIMESTAMP";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException(
            'No se pudo guardar UserPipelineFeatures. Ejecuta primero fase5_pipeline_features.sql. ' . $db->error
        );
    }

    foreach ($normalized as $featureKey => $enabled) {
        $stmt->bind_param('isi', $user_id, $featureKey, $enabled);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Error guardando switch ' . $featureKey . ': ' . $error);
        }
    }
    $stmt->close();

    return $normalized;
}

function get_user_preferences(mysqli $db, int $user_id): array
{
    $defaults = prefs_defaults();

    $stmt = $db->prepare(
        "SELECT
            model_id,
            seed,
            compile_temperature,
            compile_max_tokens,
            response_max_tokens,
            compile_top_p,
            question_memory_enabled,
            question_memory_scope,
            question_memory_max_candidates,
            question_memory_window_lines,
            theme_mode
         FROM UserPreferences
         WHERE user_id_ = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return $defaults;
    }

    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return $defaults;
    }

    return [
        'model_id' => prefs_normalize_model($row['model_id'] ?? ''),
        'seed' => prefs_clamp_int($row['seed'] ?? 42, 0, 999999999, 42),

        'compile_temperature' => prefs_clamp_float(
            $row['compile_temperature'] ?? 0,
            0,
            2,
            0
        ),

        'compile_max_tokens' => prefs_clamp_int(
            $row['compile_max_tokens'] ?? 200,
            100,
            4096,
            200
        ),

        'response_max_tokens' => prefs_clamp_int(
            $row['response_max_tokens'] ?? 1000,
            100,
            4096,
            1000
        ),

        'compile_top_p' => prefs_clamp_float(
            $row['compile_top_p'] ?? 0.1,
            0.05,
            1,
            0.1
        ),

        'question_memory_enabled' => (int)($row['question_memory_enabled'] ?? 1) === 1 ? 1 : 0,
        'question_memory_scope' => prefs_normalize_scope($row['question_memory_scope'] ?? 'project'),

        'question_memory_max_candidates' => prefs_clamp_int(
            $row['question_memory_max_candidates'] ?? 20,
            5,
            50,
            20
        ),

        'question_memory_window_lines' => prefs_clamp_int(
            $row['question_memory_window_lines'] ?? 5,
            2,
            15,
            5
        ),

        'theme_mode' => prefs_normalize_theme($row['theme_mode'] ?? 'theme-light')
    ];
}

$action = strtolower(trim((string)($_REQUEST['action'] ?? '')));

if ($action === '') {
    $action = $_SERVER['REQUEST_METHOD'] === 'POST' ? 'save' : 'get';
}

if ($action === 'get') {
    $pipeline = get_user_pipeline_features($db_connection, $user_id);
    prefs_response([
        'ok' => true,
        'preferences' => get_user_preferences($db_connection, $user_id),
        'pipeline_features' => $pipeline['features'],
        'pipeline_storage_available' => $pipeline['storage_available'],
        'pipeline_storage_error' => $pipeline['storage_error'],
    ]);
}

if ($action === 'save') {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    $sent_csrf = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $sent_csrf)) {
        prefs_response([
            'ok' => false,
            'error' => 'Token CSRF inválido'
        ], 403);
    }

    $defaults = prefs_defaults();

    $model_id = prefs_normalize_model($_POST['model_id'] ?? $defaults['model_id']);

    $seed = prefs_clamp_int(
        $_POST['seed'] ?? $defaults['seed'],
        0,
        999999999,
        $defaults['seed']
    );

    $compile_temperature = prefs_clamp_float(
        $_POST['compile_temperature'] ?? $defaults['compile_temperature'],
        0,
        2,
        $defaults['compile_temperature']
    );

    $compile_max_tokens = prefs_clamp_int(
        $_POST['compile_max_tokens'] ?? $defaults['compile_max_tokens'],
        100,
        4096,
        $defaults['compile_max_tokens']
    );

    $response_max_tokens = prefs_clamp_int(
        $_POST['response_max_tokens'] ?? $defaults['response_max_tokens'],
        100,
        4096,
        $defaults['response_max_tokens']
    );

    $compile_top_p = prefs_clamp_float(
        $_POST['compile_top_p'] ?? $defaults['compile_top_p'],
        0.05,
        1,
        $defaults['compile_top_p']
    );

    $question_memory_enabled = filter_var(
        $_POST['question_memory_enabled'] ?? 1,
        FILTER_VALIDATE_BOOLEAN
    ) ? 1 : 0;

    $pipeline_features = [];
    $pipeline_features_raw = trim((string)($_POST['pipeline_features_json'] ?? ''));
    if ($pipeline_features_raw !== '') {
        $decoded_pipeline_features = json_decode($pipeline_features_raw, true);
        if (!is_array($decoded_pipeline_features)) {
            prefs_response([
                'ok' => false,
                'error' => 'pipeline_features_json no es JSON válido'
            ], 400);
        }
        $pipeline_features = $decoded_pipeline_features;

        // Compatibilidad: el switch antiguo y el nuevo representan la misma
        // función. El nuevo tablero manda cuando viene en la petición.
        if (array_key_exists('question_memory_read', $pipeline_features)) {
            $question_memory_enabled = filter_var(
                $pipeline_features['question_memory_read'],
                FILTER_VALIDATE_BOOLEAN
            ) ? 1 : 0;
        }
    }

    $question_memory_scope = prefs_normalize_scope(
        $_POST['question_memory_scope'] ?? $defaults['question_memory_scope']
    );

    $question_memory_max_candidates = prefs_clamp_int(
        $_POST['question_memory_max_candidates'] ?? $defaults['question_memory_max_candidates'],
        5,
        50,
        $defaults['question_memory_max_candidates']
    );

    $question_memory_window_lines = prefs_clamp_int(
        $_POST['question_memory_window_lines'] ?? $defaults['question_memory_window_lines'],
        2,
        15,
        $defaults['question_memory_window_lines']
    );

    $theme_mode = prefs_normalize_theme(
        $_POST['theme_mode'] ?? $defaults['theme_mode']
    );

    $params = [
        $user_id,
        $model_id,
        $seed,
        $compile_temperature,
        $compile_max_tokens,
        $response_max_tokens,
        $compile_top_p,
        $question_memory_enabled,
        $question_memory_scope,
        $question_memory_max_candidates,
        $question_memory_window_lines,
        $theme_mode
    ];

    $sql = "
        INSERT INTO UserPreferences (
            user_id_,
            model_id,
            seed,
            compile_temperature,
            compile_max_tokens,
            response_max_tokens,
            compile_top_p,
            question_memory_enabled,
            question_memory_scope,
            question_memory_max_candidates,
            question_memory_window_lines,
            theme_mode
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )
        ON DUPLICATE KEY UPDATE
            model_id = VALUES(model_id),
            seed = VALUES(seed),
            compile_temperature = VALUES(compile_temperature),
            compile_max_tokens = VALUES(compile_max_tokens),
            response_max_tokens = VALUES(response_max_tokens),
            compile_top_p = VALUES(compile_top_p),
            question_memory_enabled = VALUES(question_memory_enabled),
            question_memory_scope = VALUES(question_memory_scope),
            question_memory_max_candidates = VALUES(question_memory_max_candidates),
            question_memory_window_lines = VALUES(question_memory_window_lines),
            theme_mode = VALUES(theme_mode)
    ";

    $ok = prefs_bind_execute($db_connection, $sql, $params);

    if (!$ok) {
        prefs_response([
            'ok' => false,
            'error' => 'No se pudieron guardar las preferencias',
            'db_error' => $db_connection->error
        ], 500);
    }

    if ($pipeline_features) {
        try {
            save_user_pipeline_features($db_connection, $user_id, $pipeline_features);
        } catch (Throwable $e) {
            prefs_response([
                'ok' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    $pipeline = get_user_pipeline_features($db_connection, $user_id);
    prefs_response([
        'ok' => true,
        'message' => 'Preferencias guardadas correctamente',
        'preferences' => get_user_preferences($db_connection, $user_id),
        'pipeline_features' => $pipeline['features'],
        'pipeline_storage_available' => $pipeline['storage_available'],
        'pipeline_storage_error' => $pipeline['storage_error'],
    ]);
}

prefs_response([
    'ok' => false,
    'error' => 'Acción no válida'
], 400);