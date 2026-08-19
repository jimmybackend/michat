<?php

declare(strict_types=1);

/**
 * chat_trace_api.php · Fase 7.1
 *
 * API unificada READ-ONLY para observabilidad/trazabilidad.
 *
 * GET ?action=capabilities
 * GET ?action=selectors[&user_id=N]
 * GET ?action=turns&session_id=N[&limit=300][&user_id=N]
 * GET ?action=trace&session_id=N&trace_id=UUID[&user_id=N]
 * GET ?action=trace&session_id=N&answer_message_id=N[&user_id=N]
 * GET ?action=trace&session_id=N&question_message_id=N[&user_id=N]
 *
 * Si el usuario autenticado no es administrador/soporte, user_id sólo puede ser
 * su propio ID. Esta API no modifica datos; la edición seguirá usando los CRUD
 * existentes en fases posteriores.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function traceApiExit(array $payload, int $status = 200): void
{
    http_response_code($status);
    $pretty = isset($_GET['pretty']) && (string)$_GET['pretty'] === '1';
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if ($pretty) $flags |= JSON_PRETTY_PRINT;
    echo json_encode($payload, $flags);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    traceApiExit([
        'ok' => false,
        'api_version' => '7.1',
        'error' => 'Fase 7.1 es read-only. Usa GET.',
    ], 405);
}

try {
    $endpointDir = __DIR__;

    $bootstrapHelper = $endpointDir . '/includes/Chat/ChatEndpointBootstrap.php';
    $identityHelper = $endpointDir . '/includes/Chat/ChatIdentity.php';
    $traceRepository = $endpointDir . '/includes/Trace/UnifiedTraceRepository.php';

    if (!is_file($bootstrapHelper)) {
        throw new RuntimeException('Falta includes/Chat/ChatEndpointBootstrap.php');
    }
    if (!is_file($identityHelper)) {
        throw new RuntimeException('Falta includes/Chat/ChatIdentity.php');
    }
    if (!is_file($traceRepository)) {
        throw new RuntimeException('Falta includes/Trace/UnifiedTraceRepository.php');
    }

    require_once $bootstrapHelper;
    require_once $identityHelper;
    require_once $traceRepository;

    $db = ChatEndpointBootstrap::mysqli($endpointDir);
    $db->set_charset('utf8mb4');

    $viewerUserId = ChatIdentity::resolveUserId($db);
    if ($viewerUserId <= 0) {
        traceApiExit(['ok' => false, 'api_version' => '7.1', 'error' => 'Sesión de usuario no válida'], 401);
    }

    $adminLike = ChatIdentity::isAdminLike();
    $requestedUserId = isset($_GET['user_id']) && is_numeric($_GET['user_id'])
        ? (int)$_GET['user_id']
        : $viewerUserId;

    if ($requestedUserId <= 0) {
        traceApiExit(['ok' => false, 'api_version' => '7.1', 'error' => 'user_id inválido'], 400);
    }
    if ($requestedUserId !== $viewerUserId && !$adminLike) {
        traceApiExit(['ok' => false, 'api_version' => '7.1', 'error' => 'No tienes permisos para consultar otro usuario'], 403);
    }

    $repo = new UnifiedTraceRepository($db, $viewerUserId, $adminLike, $requestedUserId);
    $action = strtolower(trim((string)($_GET['action'] ?? 'capabilities')));

    if ($action === 'capabilities') {
        traceApiExit([
            'ok' => true,
            'data' => $repo->capabilities(),
        ]);
    }

    if ($action === 'selectors') {
        traceApiExit([
            'ok' => true,
            'data' => $repo->selectors(),
        ]);
    }

    if ($action === 'turns') {
        $sessionId = (int)($_GET['session_id'] ?? 0);
        $limit = (int)($_GET['limit'] ?? 300);
        if ($sessionId <= 0) {
            traceApiExit(['ok' => false, 'api_version' => '7.1', 'error' => 'session_id es obligatorio'], 400);
        }
        traceApiExit([
            'ok' => true,
            'data' => $repo->turns($sessionId, $limit),
        ]);
    }

    if ($action === 'trace') {
        $sessionId = (int)($_GET['session_id'] ?? 0);
        $traceId = trim((string)($_GET['trace_id'] ?? ''));
        $answerId = isset($_GET['answer_message_id']) && is_numeric($_GET['answer_message_id'])
            ? (int)$_GET['answer_message_id']
            : null;
        $questionId = isset($_GET['question_message_id']) && is_numeric($_GET['question_message_id'])
            ? (int)$_GET['question_message_id']
            : null;

        if ($sessionId <= 0) {
            traceApiExit(['ok' => false, 'api_version' => '7.1', 'error' => 'session_id es obligatorio'], 400);
        }
        if ($traceId === '' && (!$answerId || $answerId <= 0) && (!$questionId || $questionId <= 0)) {
            traceApiExit([
                'ok' => false,
                'api_version' => '7.1',
                'error' => 'Indica trace_id, answer_message_id o question_message_id',
            ], 400);
        }

        traceApiExit([
            'ok' => true,
            'data' => $repo->trace($sessionId, $traceId !== '' ? $traceId : null, $answerId, $questionId),
        ]);
    }

    traceApiExit([
        'ok' => false,
        'api_version' => '7.1',
        'error' => 'Acción no válida. Usa capabilities, selectors, turns o trace.',
    ], 400);
} catch (InvalidArgumentException $e) {
    traceApiExit(['ok' => false, 'api_version' => '7.1', 'error' => $e->getMessage()], 400);
} catch (RuntimeException $e) {
    $message = $e->getMessage();
    $status = (str_contains($message, 'permis') || str_contains($message, 'pertenece')) ? 403 : 500;
    traceApiExit(['ok' => false, 'api_version' => '7.1', 'error' => $message], $status);
} catch (Throwable $e) {
    error_log('TRACE_API_7_1: ' . $e->getMessage());
    traceApiExit([
        'ok' => false,
        'api_version' => '7.1',
        'error' => 'Error interno construyendo la trazabilidad.',
    ], 500);
}
