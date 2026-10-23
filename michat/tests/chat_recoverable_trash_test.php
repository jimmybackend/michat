<?php
declare(strict_types=1);

// Contratos estáticos mínimos. Validación funcional adicional: navegador y MySQL.
$root = dirname(__DIR__);
$service = file_get_contents($root . '/includes/Chat/SessionLifecycleService.php');
$list = file_get_contents($root . '/chat2_sessions.php');
$delete = file_get_contents($root . '/chat2_session_delete.php');
$chat = file_get_contents($root . '/chat.php');
$js = file_get_contents($root . '/js/chat.js');
$send = file_get_contents($root . '/bedrock_chat2.php');

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$check(!str_contains($service, 'DELETE FROM ChatSessions'), 'No se deben borrar físicamente las sesiones desde su ciclo de vida');
$check(str_contains($service, "SET status='closed'"), 'La limpieza de borradores conserva las sesiones en Eliminadas');
$check(str_contains($service, "public function trash("), 'Existe operación de eliminación recuperable');
$check(str_contains($service, "['open','archived','closed']"), 'El servicio acepta tres estados');
$check(str_contains($list, "['open','archived','closed']"), 'La API filtra los tres estados');
$check(str_contains($list, "'cs.status=?'"), 'La API no incluye eliminadas en la vista activa');
$check(str_contains($delete, 'CsrfGuard::assertSessionToken'), 'Eliminar requiere CSRF');
$check(str_contains($delete, '->trash($userId,$sessionId)'), 'Eliminar únicamente cambia el estado del usuario dueño');
$check(str_contains($chat, 'id="sbChatView"') && str_contains($chat, '<option value="closed">Eliminadas</option>'), 'Existe selector de Eliminadas');
$check(str_contains($chat, 'id="chat2Delete"'), 'Existe acción Eliminar');
$check(str_contains($js, 'API.deleteSession') && str_contains($js, 'async function doRestore('), 'Hay acciones AJAX para eliminar y recuperar');
$check(str_contains($js, 'updateCurrentSessionState') && str_contains($js, 'Restaura esta conversación'), 'El visor bloquea escritura en archivo y papelera');
$check(str_contains($send, "($sessionData['status'] ?? '') !== 'open'"), 'Bedrock rechaza mensajes a sesiones no activas');

if ($failures) {
    fwrite(STDERR, "Chat recoverable trash contract failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "Chat recoverable trash contract: OK\n";
