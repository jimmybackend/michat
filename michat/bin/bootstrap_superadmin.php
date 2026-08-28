<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}

require_once dirname(__DIR__).'/app_bootstrap.php';
require_once dirname(__DIR__).'/includes/Auth/AuthorizationService.php';
require_once dirname(__DIR__).'/includes/Admin/InitialUserProfile.php';
require_once dirname(__DIR__).'/includes/Admin/UserProvisioningService.php';

const BOOTSTRAP_CONFIRM_TOKEN='BOOTSTRAP_SUPERADMIN';

$options=getopt('',['email:','help']);
if(isset($options['help'])){
    fwrite(STDOUT,"Usage: MICHAT_ACTOR_PASSWORD='...' MICHAT_BOOTSTRAP_CONFIRM=".BOOTSTRAP_CONFIRM_TOKEN." php michat/bin/bootstrap_superadmin.php --email=existing@example.com\n");
    exit(0);
}

$email=trim((string)($options['email']??''));
$password=(string)(getenv('MICHAT_ACTOR_PASSWORD')?:'');
$confirm=(string)(getenv('MICHAT_BOOTSTRAP_CONFIRM')?:'');

if($confirm!==BOOTSTRAP_CONFIRM_TOKEN){
    fwrite(STDERR,"Superadmin bootstrap failed: bootstrap_confirmation_invalid\n");
    exit(1);
}

try{
    $auth=new AuthorizationService($db_connection);
    $service=new UserProvisioningService($db_connection,$auth,new InitialUserProfile($db_connection));
    $promoted=$service->bootstrapExistingSuperadmin($email,$password);
    fwrite(STDOUT,"Existing MiChat user promoted: id={$promoted['id']} email={$promoted['email']} system_role={$promoted['system_role']}\n");
    exit(0);
}catch(Throwable$e){
    fwrite(STDERR,"Superadmin bootstrap failed: ".$e->getMessage()."\n");
    exit(1);
}
