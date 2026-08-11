<?php
/**
 * session_attachment_mode.php
 *
 * Lee y guarda el modo de uso de archivos adjuntos por sesión.
 *
 * Modos:
 *  - rag    => solo inyectar adjuntos relevantes según búsqueda vectorial
 *  - always => inyectar adjuntos sin filtro de relevancia
 *
 * Persistencia:
 *  - ChatSessions.meta (JSON)
 *
 * Ejemplos:
 *  GET  session_attachment_mode.php?session_id=123
 *  POST session_attachment_mode.php
 *       session_id=123
 *       mode=rag|always
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/app_bootstrap.php';

function jexit(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function getSessionUserId(): int {
    $keys = [
        'user_id_',
        'user_id',
        'id_usuario',
        'id_user',
        'id'
    ];

    foreach ($keys as $key) {
        if (isset($_SESSION[$key]) && ctype_digit((string)$_SESSION[$key])) {
            return (int)$_SESSION[$key];
        }
    }

    return 0;
}

function normalizeAttachmentMode(?string $mode): string {
    $mode = strtolower(trim((string)$mode));

    return $mode === 'always' ? 'always' : 'rag';
}

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    jexit([
        'ok' => false,
        'error' => 'Base de datos no disponible'
    ], 500);
}

$userId = getSessionUserId();

if ($userId <= 0) {
    jexit([
        'ok' => false,
        'error' => 'Sesión inválida'
    ], 401);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method !== 'GET' && $method !== 'POST') {
    jexit([
        'ok' => false,
        'error' => 'Método no permitido'
    ], 405);
}

$sessionId = isset($_REQUEST['session_id']) ? (int)$_REQUEST['session_id'] : 0;

if ($sessionId <= 0) {
    jexit([
        'ok' => false,
        'error' => 'session_id inválido'
    ], 400);
}

// =========================================================
// Seguridad: la sesión debe existir y pertenecer al usuario
// =========================================================
$stmt = $db_connection->prepare("
    SELECT id_, user_id_, meta
    FROM ChatSessions
    WHERE id_ = ?
      AND user_id_ = ?
    LIMIT 1
");

if (!$stmt) {
    jexit([
        'ok' => false,
        'error' => 'Error preparando consulta de sesión: ' . $db_connection->error
    ], 500);
}

$stmt->bind_param('ii', $sessionId, $userId);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();

    jexit([
        'ok' => false,
        'error' => 'Error consultando sesión: ' . $error
    ], 500);
}

$result = $stmt->get_result();
$session = $result->fetch_assoc();
$stmt->close();

if (!$session) {
    jexit([
        'ok' => false,
        'error' => 'La sesión no existe o no pertenece al usuario'
    ], 403);
}

// =========================================================
// Leer meta actual
// =========================================================
$metaRaw = (string)($session['meta'] ?? '');
$meta = json_decode($metaRaw, true);

if (!is_array($meta)) {
    $meta = [];
}

$currentMode = normalizeAttachmentMode($meta['attachment_rag_mode'] ?? 'rag');

// =========================================================
// GET: devolver modo actual
// =========================================================
if ($method === 'GET') {
    jexit([
        'ok' => true,
        'session_id' => $sessionId,
        'mode' => $currentMode
    ]);
}

// =========================================================
// POST: guardar nuevo modo
// =========================================================
$newMode = normalizeAttachmentMode($_POST['mode'] ?? '');

// Actualizar meta preservando otras claves existentes.
$meta['attachment_rag_mode'] = $newMode;
$meta['attachment_rag_updated_at'] = date('Y-m-d H:i:s');

$jsonMeta = json_encode(
    $meta,
    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
);

if ($jsonMeta === false) {
    jexit([
        'ok' => false,
        'error' => 'No se pudo codificar meta como JSON'
    ], 500);
}

$update = $db_connection->prepare("
    UPDATE ChatSessions
    SET meta = ?
    WHERE id_ = ?
      AND user_id_ = ?
");

if (!$update) {
    jexit([
        'ok' => false,
        'error' => 'Error preparando UPDATE de sesión: ' . $db_connection->error
    ], 500);
}

$update->bind_param('sii', $jsonMeta, $sessionId, $userId);

if (!$update->execute()) {
    $error = $update->error;
    $update->close();

    jexit([
        'ok' => false,
        'error' => 'Error guardando modo de adjuntos: ' . $error
    ], 500);
}

$update->close();

jexit([
    'ok' => true,
    'session_id' => $sessionId,
    'mode' => $newMode
]);