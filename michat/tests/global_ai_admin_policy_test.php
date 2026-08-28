<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$identity=(string)file_get_contents($root.'/michat/includes/Chat/ChatIdentity.php');
$authorization=(string)file_get_contents($root.'/michat/includes/Auth/AuthorizationService.php');
$passed=0;$failed=0;
$check=static function(bool$ok,string$label)use(&$passed,&$failed):void{echo($ok?'PASS ':'FAIL ').$label."\n";$ok?$passed++:$failed++;};

$check(str_contains($identity,"allows(\$userId,'ai.global.manage')"),'ChatIdentity delegates GLOBAL AI administration to AuthorizationService');
$check(!str_contains($identity,"['administración', 'admin', 'administrator']")&&!str_contains($identity,"['administración','soporte'"),'legacy business-role allowlists no longer authorize GLOBAL AI');
$check(str_contains($authorization,"'user' => []"),'ordinary user has no administrative permissions');
$check(str_contains($authorization,"'admin' => ['users.manage']"),'admin permission set is intentionally limited');
foreach(['system.reset','ai.global.manage','users.manage','data.cross_user.read','system.roles.manage']as$permission){
    $check(str_contains($authorization,"'{$permission}'"),'superadmin contract contains '.$permission);
}
$check(str_contains($authorization,"userstatus']!=='Activo'")||str_contains($authorization,"userstatus'] !== 'Activo'"),'inactive users fail authorization');
$check(!preg_match('/userId\s*(?:===|==)\s*1|user_id\s*(?:===|==)\s*1/',$identity.$authorization),'user id 1 grants no runtime privilege');

echo"Result: {$passed} passed, {$failed} failed\n";exit($failed?1:0);
