<?php
declare(strict_types=1);

final class UserProvisioningService
{
    private const BUSINESS_ROLES=['Alumno','Docente','Administración','Finanzas','Recursos Humanos','Ventas','Marketing','Soporte','Servicio Social','Otros'];
    private const GENDERS=['Masculino','Femenino','Otro'];
    private const SYSTEM_ROLES=['user','admin','superadmin'];

    public function __construct(
        private mysqli $db,
        private AuthorizationService $authorization,
        private InitialUserProfile $initialProfile
    ) {}

    /** @param array<string,mixed> $data */
    public function createFirstUser(array$data): array
    {
        $lock='michat:first_user_bootstrap';
        $this->acquireLock($lock);
        try{
            $this->db->begin_transaction();
            try{
                $count=(int)$this->db->query('SELECT COUNT(*) c FROM Users')->fetch_assoc()['c'];
                if($count!==0)throw new RuntimeException('first_user_already_exists');
                $normalized=$this->validate($data,'superadmin');
                $userId=$this->insertUser($normalized);
                $this->initialProfile->apply($userId);
                $this->audit($userId,'initial_superadmin_created',['target_user_id'=>$userId,'system_role'=>'superadmin']);
                $this->db->commit();
                return['id'=>$userId,'email'=>$normalized['email'],'system_role'=>'superadmin'];
            }catch(Throwable$e){$this->db->rollback();throw$e;}
        }finally{$this->releaseLock($lock);}
    }

    /**
     * One-time upgrade bootstrap for an existing installation that predates
     * Users.system_role. It never selects a user by numeric ID: the target must
     * authenticate as an active account and promotion is allowed only while the
     * database contains zero superadmins.
     *
     * @return array{id:int,email:string,system_role:string}
     */
    public function bootstrapExistingSuperadmin(string $email,string $password): array
    {
        $lock='michat:superadmin_bootstrap';
        $this->acquireLock($lock);
        try{
            $this->db->begin_transaction();
            try{
                $count=(int)$this->db->query("SELECT COUNT(*) c FROM Users WHERE system_role='superadmin'")->fetch_assoc()['c'];
                if($count!==0)throw new RuntimeException('superadmin_already_exists');

                $actor=$this->authorization->authenticateActiveUser($email,$password);
                $userId=(int)$actor['id'];

                $stmt=$this->db->prepare("UPDATE Users SET system_role='superadmin' WHERE id=? AND userstatus='Activo' AND system_role IN ('user','admin')");
                if(!$stmt)throw new RuntimeException('users_system_role_schema_required');
                $stmt->bind_param('i',$userId);
                if(!$stmt->execute())throw new RuntimeException('superadmin_bootstrap_failed');
                $affected=$stmt->affected_rows;$stmt->close();
                if($affected!==1)throw new RuntimeException('superadmin_bootstrap_failed');

                $this->audit($userId,'existing_superadmin_bootstrapped',[
                    'target_user_id'=>$userId,
                    'target_email'=>(string)$actor['email'],
                    'system_role'=>'superadmin',
                ]);
                $this->db->commit();
                return['id'=>$userId,'email'=>(string)$actor['email'],'system_role'=>'superadmin'];
            }catch(Throwable$e){$this->db->rollback();throw$e;}
        }finally{$this->releaseLock($lock);}
    }

    /** @param array<string,mixed> $data */
    public function createUser(int$actorUserId,array$data): array
    {
        $this->authorization->assertAllowed($actorUserId,'system.roles.manage');
        $lock='michat:user_provision';
        $this->acquireLock($lock);
        try{
            $this->db->begin_transaction();
            try{
                $this->authorization->assertAllowed($actorUserId,'system.roles.manage');
                $normalized=$this->validate($data,null);
                $userId=$this->insertUser($normalized);
                $this->initialProfile->apply($userId);
                $this->audit($actorUserId,'user_created',[
                    'target_user_id'=>$userId,
                    'target_email'=>$normalized['email'],
                    'system_role'=>$normalized['system_role'],
                ]);
                $this->db->commit();
                return['id'=>$userId,'email'=>$normalized['email'],'system_role'=>$normalized['system_role']];
            }catch(Throwable$e){$this->db->rollback();throw$e;}
        }finally{$this->releaseLock($lock);}
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function validate(array$data,?string$forcedSystemRole): array
    {
        $firstname=trim((string)($data['firstname']??''));
        $lastname=trim((string)($data['lastname']??''));
        $curp=strtoupper(trim((string)($data['curp']??'')));
        $gender=trim((string)($data['gender']??''));
        $birthdate=trim((string)($data['birthdate']??''));
        $email=strtolower(trim((string)($data['email']??'')));
        $password=(string)($data['password']??'');
        $role=trim((string)($data['role']??''));
        $systemRole=$forcedSystemRole??trim((string)($data['system_role']??''));

        if($firstname===''||mb_strlen($firstname)>255||$lastname===''||mb_strlen($lastname)>255)throw new InvalidArgumentException('name_invalid');
        if(strlen($curp)!==18||preg_match('/^[A-Z0-9]{18}$/D',$curp)!==1)throw new InvalidArgumentException('curp_invalid');
        if(!in_array($gender,self::GENDERS,true))throw new InvalidArgumentException('gender_invalid');
        if($birthdate!==''){
            $date=DateTimeImmutable::createFromFormat('!Y-m-d',$birthdate);
            if(!$date||$date->format('Y-m-d')!==$birthdate)throw new InvalidArgumentException('birthdate_invalid');
        }else{$birthdate=null;}
        if(filter_var($email,FILTER_VALIDATE_EMAIL)===false||strlen($email)>255)throw new InvalidArgumentException('email_invalid');
        if(strlen($password)<12)throw new InvalidArgumentException('password_too_short');
        if(!in_array($role,self::BUSINESS_ROLES,true))throw new InvalidArgumentException('business_role_invalid');
        if(!in_array($systemRole,self::SYSTEM_ROLES,true))throw new InvalidArgumentException('system_role_invalid');

        $stmt=$this->db->prepare('SELECT COUNT(*) c FROM Users WHERE email=?');
        if(!$stmt)throw new RuntimeException('database_error');
        $stmt->bind_param('s',$email);if(!$stmt->execute())throw new RuntimeException('database_error');
        $duplicate=(int)$stmt->get_result()->fetch_assoc()['c']!==0;$stmt->close();
        if($duplicate)throw new RuntimeException('email_already_exists');

        return compact('firstname','lastname','curp','gender','birthdate','email','password','role','systemRole')+['system_role'=>$systemRole];
    }

    /** @param array<string,mixed> $data */
    private function insertUser(array$data): int
    {
        $hash=password_hash((string)$data['password'],PASSWORD_DEFAULT);
        if(!is_string($hash)||$hash==='')throw new RuntimeException('password_hash_failed');
        $chat=1;$status='Activo';
        $stmt=$this->db->prepare(
            'INSERT INTO Users(firstname,lastname,curp,gender,birthdate,email,password,role,system_role,chat,userstatus) VALUES(?,?,?,?,?,?,?,?,?,?,?)'
        );
        if(!$stmt)throw new RuntimeException('users_system_role_schema_required');
        $stmt->bind_param(
            'sssssssssis',
            $data['firstname'],$data['lastname'],$data['curp'],$data['gender'],$data['birthdate'],$data['email'],$hash,$data['role'],$data['system_role'],$chat,$status
        );
        if(!$stmt->execute())throw new RuntimeException('user_create_failed');
        $id=(int)$this->db->insert_id;$stmt->close();
        if($id<1)throw new RuntimeException('user_create_failed');
        return$id;
    }

    /** @param array<string,mixed> $details */
    private function audit(?int$userId,string$event,array$details): void
    {
        $action='Otro';$ip=null;
        $payload=json_encode(['event'=>$event]+$details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!is_string($payload))$payload=$event;
        $stmt=$this->db->prepare('INSERT INTO AccessControl(user_id,date_time,action,ip_address,action_details) VALUES(?,NOW(),?,?,?)');
        if(!$stmt)throw new RuntimeException('audit_unavailable');
        $stmt->bind_param('isss',$userId,$action,$ip,$payload);
        if(!$stmt->execute())throw new RuntimeException('audit_failed');
        $stmt->close();
    }

    private function acquireLock(string$name): void
    {
        $stmt=$this->db->prepare('SELECT GET_LOCK(?,10) acquired');
        if(!$stmt)throw new RuntimeException('provision_lock_unavailable');
        $stmt->bind_param('s',$name);if(!$stmt->execute())throw new RuntimeException('provision_lock_failed');
        $ok=(int)($stmt->get_result()->fetch_assoc()['acquired']??0)===1;$stmt->close();
        if(!$ok)throw new RuntimeException('provision_locked');
    }

    private function releaseLock(string$name): void
    {
        $stmt=$this->db->prepare('SELECT RELEASE_LOCK(?)');
        if(!$stmt)return;$stmt->bind_param('s',$name);$stmt->execute();$stmt->close();
    }
}
