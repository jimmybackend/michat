<?php

declare(strict_types=1);

final class ChatIdentity
{
    public static function resolveUserId(mysqli $db): int
    {
        foreach (['user_id_', 'user_id', 'id_usuario', 'id_user'] as $key) {
            if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key]) && (int)$_SESSION[$key] > 0) {
                return (int)$_SESSION[$key];
            }
        }

        $email = isset($_SESSION['usuario']) ? trim((string)$_SESSION['usuario']) : '';
        if ($email === '') return 0;

        $stmt = $db->prepare("SELECT id FROM Users WHERE email=? LIMIT 1");
        if (!$stmt) return 0;
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['id'] : 0;
    }

    public static function isAdminLike(): bool
    {
        $role = (string)($_SESSION['role'] ?? $_SESSION['rol'] ?? '');
        $role = mb_strtolower(trim($role), 'UTF-8');
        return in_array($role, ['administración','soporte','admin','administrator','support'], true);
    }
}
