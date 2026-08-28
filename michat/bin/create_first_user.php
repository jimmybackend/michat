<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}

require_once dirname(__DIR__).'/app_bootstrap.php';
require_once dirname(__DIR__).'/includes/Auth/AuthorizationService.php';
require_once dirname(__DIR__).'/includes/Admin/InitialUserProfile.php';
require_once dirname(__DIR__).'/includes/Admin/UserProvisioningService.php';

$options=getopt('',[
    'email:','firstname:','lastname:','curp:','gender:','birthdate::','role:','help'
]);
if(isset($options['help'])){
    fwrite(STDOUT,"Usage: MICHAT_NEW_USER_PASSWORD='...' php michat/bin/create_first_user.php --email=... --firstname=... --lastname=... --curp=18CHARS --gender=Masculino|Femenino|Otro --role=...
");
    exit(0);
}
$password=(string)(getenv('MICHAT_NEW_USER_PASSWORD')?:'');
$data=[
    'email'=>$options['email']??'',
    'firstname'=>$options['firstname']??'',
    'lastname'=>$options['lastname']??'',
    'curp'=>$options['curp']??'',
    'gender'=>$options['gender']??'',
    'birthdate'=>$options['birthdate']??'',
    'role'=>$options['role']??'',
    'password'=>$password,
];

try{
    $auth=new AuthorizationService($db_connection);
    $service=new UserProvisioningService($db_connection,$auth,new InitialUserProfile($db_connection));
    $created=$service->createFirstUser($data);
    fwrite(STDOUT,"First MiChat user created: id={$created['id']} email={$created['email']} system_role={$created['system_role']}
");
    exit(0);
}catch(Throwable$e){
    fwrite(STDERR,"First-user bootstrap failed: ".$e->getMessage()."
");
    exit(1);
}
