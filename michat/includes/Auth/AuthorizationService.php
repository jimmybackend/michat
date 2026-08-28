<?php
declare(strict_types=1);

/** Database-backed authorization for privileged MiChat operations. */
final class AuthorizationService
{
    /** @var array<string,list<string>> */
    private const ROLE_PERMISSIONS = [
        'user' => [],
        'admin' => ['users.manage'],
        'superadmin' => [
            'users.manage',
            'system.reset',
            'ai.global.manage',
            'data.cross_user.read',
            'system.roles.manage',
        ],
    ];

    public function __construct(private mysqli $db) {}

    public function roleForActiveUser(int $userId): string
    {
        if($userId<1)throw new InvalidArgumentException('user_id_invalid');
        $stmt=$this->db->prepare("SELECT system_role,userstatus FROM Users WHERE id=? LIMIT 1");
        if(!$stmt)throw new RuntimeException('authorization_schema_unavailable');
        $stmt->bind_param('i',$userId);
        if(!$stmt->execute())throw new RuntimeException('authorization_query_failed');
        $row=$stmt->get_result()->fetch_assoc();$stmt->close();
        if(!$row||$row['userstatus']!=='Activo')throw new RuntimeException('authorization_user_inactive');
        $role=(string)($row['system_role']??'');
        if(!array_key_exists($role,self::ROLE_PERMISSIONS))throw new RuntimeException('authorization_role_invalid');
        return$role;
    }

    public function allows(int $userId,string $permission): bool
    {
        $role=$this->roleForActiveUser($userId);
        return in_array($permission,self::ROLE_PERMISSIONS[$role],true);
    }

    public function assertAllowed(int $userId,string $permission): void
    {
        if(!$this->allows($userId,$permission))throw new RuntimeException('permission_denied');
    }

    /** @return array{id:int,email:string,system_role:string} */
    public function authenticateActiveUser(string $email,string $password): array
    {
        $email=trim($email);
        if($email===''||$password==='')throw new InvalidArgumentException('actor_credentials_required');
        $stmt=$this->db->prepare("SELECT id,email,password,system_role,userstatus FROM Users WHERE email=? LIMIT 2");
        if(!$stmt)throw new RuntimeException('authorization_schema_unavailable');
        $stmt->bind_param('s',$email);
        if(!$stmt->execute())throw new RuntimeException('authorization_query_failed');
        $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        if(count($rows)!==1)throw new RuntimeException('actor_credentials_invalid');
        $row=$rows[0];
        if((string)$row['userstatus']!=='Activo'||!password_verify($password,(string)$row['password']))throw new RuntimeException('actor_credentials_invalid');
        $role=(string)($row['system_role']??'');
        if(!array_key_exists($role,self::ROLE_PERMISSIONS))throw new RuntimeException('authorization_role_invalid');
        return['id'=>(int)$row['id'],'email'=>(string)$row['email'],'system_role'=>$role];
    }

    /** @return list<string> */
    public static function permissionsForRole(string $role): array
    {
        if(!array_key_exists($role,self::ROLE_PERMISSIONS))throw new InvalidArgumentException('system_role_invalid');
        return self::ROLE_PERMISSIONS[$role];
    }
}
