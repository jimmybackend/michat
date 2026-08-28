<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}

require_once dirname(__DIR__).'/app_bootstrap.php';
require_once dirname(__DIR__).'/includes/Auth/AuthorizationService.php';
require_once dirname(__DIR__).'/includes/Admin/InitialUserProfile.php';
require_once dirname(__DIR__).'/includes/Admin/UserProvisioningService.php';

$options=getopt('',[
    'actor-email:','email:','firstname:','lastname:','curp:','gender:','birthdate::','role:','system-role:','help'
]);
if(isset($options['help'])){
    fwrite(STDOUT,"Usage: MICHAT_ACTOR_PASSWORD='...' MICHAT_NEW_USER_PASSWORD='...' php michat/bin/create_user.php --actor-email=... --email=... --firstname=... --lastname=... --curp=18CHARS --gender=Masculino|Femenino|Otro --role=... --system-role=user|admin|superadmin
");
    exit(0);
}
$actorEmail=trim((string)($options['actor-email']??''));
$actorPassword=(string)(getenv('MICHAT_ACTOR_PASSWORD')?:'');
$newPassword=(string)(getenv('MICHAT_NEW_USER_PASSWORD')?:'');
$data=[
    'email'=>$options['email']??'',
    'firstname'=>$options['firstname']??'',
    'lastname'=>$options['lastname']??'',
    'curp'=>$options['curp']??'',
    'gender'=>$options['gender']??'',
    'birthdate'=>$options['birthdate']??'',
    'role'=>$options['role']??'',
    'system_role'=>$options['system-role']??'',
    'password'=>$newPassword,
];

try{
    $auth=new AuthorizationService($db_connection);
    $actor=$auth->authenticateActiveUser($actorEmail,$actorPassword);
    $auth->assertAllowed((int)$actor['id'],'system.roles.manage');
    $service=new UserProvisioningService($db_connection,$auth,new InitialUserProfile($db_connection));
    $created=$service->createUser((int)$actor['id'],$data);
    fwrite(STDOUT,"MiChat user created: id={$created['id']} email={$created['email']} system_role={$created['system_role']}
");
    exit(0);
}catch(Throwable$e){
    fwrite(STDERR,"User provisioning failed: ".$e->getMessage()."
");
    exit(1);
}
