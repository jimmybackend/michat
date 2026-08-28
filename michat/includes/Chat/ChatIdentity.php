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

    public static function isAdminLike(?mysqli $db=null,?int $userId=null): bool
    {
        $db=$db??($GLOBALS['db_connection']??null);
        if(!$db instanceof mysqli)return false;
        require_once dirname(__DIR__).'/Auth/AuthorizationService.php';
        $userId=$userId??self::resolveUserId($db);
        if($userId<1)return false;
        try{return (new AuthorizationService($db))->allows($userId,'users.manage');}
        catch(Throwable){return false;}
    }

    /** GLOBAL AI administration is granted only by system_role permissions. */
    public static function canManageGlobalAiConfiguration(?mysqli $db=null,?int $userId=null): bool
    {
        $db=$db??($GLOBALS['db_connection']??null);
        if(!$db instanceof mysqli)return false;
        require_once dirname(__DIR__).'/Auth/AuthorizationService.php';
        $userId=$userId??self::resolveUserId($db);
        if($userId<1)return false;
        try{return (new AuthorizationService($db))->allows($userId,'ai.global.manage');}
        catch(Throwable){return false;}
    }
}
