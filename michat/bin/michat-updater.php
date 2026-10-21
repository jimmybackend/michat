#!/usr/bin/env php
<?php
declare(strict_types=1);
// Root-owned immutable copy installed by install_michat_updater.sh.
// The web process may invoke ONLY the fixed commands in sudoers.
const CONFIG = '/etc/michat/updater.json';
function failUpdate(string $message, int $code=1): never { fwrite(STDERR,$message."\n"); exit($code); }
function gitRun(array $cfg, array $args, array $allowed=[0]): array {
    // No permitir que git status/fetch deje bloqueado un worker web indefinidamente.
    // timeout termina el árbol de la operación; --kill-after evita procesos resistentes a SIGTERM.
    $seconds=($args[0]??'')==='fetch'?'60s':'30s';
    $cmd=array_merge(['/usr/bin/timeout','--signal=TERM','--kill-after=5s',$seconds,
        '/usr/sbin/runuser','-u',$cfg['repo_user'],'--','/usr/bin/git','-C',$cfg['repo_root']],$args);
    $p=proc_open($cmd,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
    if(!is_resource($p)) failUpdate('No se pudo ejecutar Git.');
    $out=stream_get_contents($pipes[1],131073); $err=stream_get_contents($pipes[2],32769);
    fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
    if(strlen((string)$out)>131072||strlen((string)$err)>32768) failUpdate('Salida de Git demasiado grande.');
    if(in_array($code,[124,137],true)) failUpdate('Tiempo máximo agotado al ejecutar Git ('.$args[0].').',75);
    if(!in_array($code,$allowed,true)) failUpdate('Git rechazó la operación: '.substr(trim((string)$err),0,500),70);
    return ['code'=>$code,'out'=>trim((string)$out)];
}
function state(array $cfg,bool $fetch): array {
    $remote=gitRun($cfg,['remote','get-url','origin'])['out'];
    if(!in_array($remote,['https://github.com/jimmybackend/michat.git','https://github.com/jimmybackend/michat','git@github.com:jimmybackend/michat.git','ssh://git@github.com/jimmybackend/michat.git'],true)) failUpdate('Origen Git no autorizado.',77);
    if($fetch)gitRun($cfg,['fetch','--quiet','origin','main']);
    $branch=gitRun($cfg,['rev-parse','--abbrev-ref','HEAD'])['out'];
    $local=gitRun($cfg,['rev-parse','HEAD'])['out'];
    $upstream=gitRun($cfg,['rev-parse','origin/main'])['out'];
    // Los paquetes instalados no son cambios de código. No mover ni borrar untracked.
    // Las modificaciones versionadas (incluido composer.lock) sí bloquean el despliegue.
    $tracked=gitRun($cfg,['status','--porcelain=v1','--untracked-files=no'])['out'];
    $untracked=gitRun($cfg,['ls-files','--others','--exclude-standard','--directory'])['out'];
    $dirty=$tracked;
    $dependencyChanges=gitRun($cfg,['diff','--name-only','HEAD','origin/main','--','composer.json','composer.lock','vendor/autoload.php'])['out'];
    $dependenciesReady=is_file($cfg['repo_root'].'/vendor/composer/autoload_real.php');
    $behind=(int)gitRun($cfg,['rev-list','--count','HEAD..origin/main'])['out'];
    $ahead=(int)gitRun($cfg,['rev-list','--count','origin/main..HEAD'])['out'];
    $ff=gitRun($cfg,['merge-base','--is-ancestor','HEAD','origin/main'],[0,1])['code']===0;
    $safe=$branch==='main'&&$behind>0&&$ahead===0&&$tracked===''&&$ff
        &&$dependencyChanges===''&&$dependenciesReady;
    return ['ok'=>true,'branch'=>$branch,'local_commit'=>$local,'remote_commit'=>$upstream,'behind'=>$behind,'ahead'=>$ahead,
        'dirty'=>$tracked!=='','dirty_files'=>$tracked===''?[]:array_slice(explode("\\n",$tracked),0,25),
        'untracked_files'=>$untracked===''?[]:array_slice(explode("\\n",$untracked),0,25),
        'dependency_changes'=>$dependencyChanges!=='' ,'dependencies_ready'=>$dependenciesReady,
        'update_available'=>$behind>0,'can_apply'=>$safe,
        'can_apply_with_stash'=>false,
        'checked_at'=>gmdate(DATE_ATOM)];
}
if(!function_exists('posix_geteuid')||posix_geteuid()!==0)failUpdate('El helper requiere root.',77);
$cfg=json_decode((string)@file_get_contents(CONFIG),true);
if(!is_array($cfg))failUpdate('Configuración del updater ausente.',78);
foreach(['repo_root','repo_user','php_user'] as $key)if(!is_string($cfg[$key]??null)||$cfg[$key]==='')failUpdate('Configuración inválida.',78);
if($cfg['repo_root']!=='/var/www/michat'||!is_dir($cfg['repo_root'].'/.git'))failUpdate('Directorio Git no autorizado.',77);
if(!preg_match('/^[a-z_][a-z0-9_-]*[$]?$/i',$cfg['repo_user']))failUpdate('Usuario Git inválido.',77);
$action=(string)($argv[1]??'');
if($action==='probe'){echo json_encode(['ok'=>true,'action'=>'probe'])."\n";exit;}
if(!in_array($action,['check','apply'],true))failUpdate('Acción no permitida.',64);
$lock=fopen('/run/lock/michat-updater.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))failUpdate('Otra actualización está en curso.',75);
$before=state($cfg,true);
if($action==='check'){echo json_encode($before,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";exit;}
if(!$before['update_available']||$before['branch']!=='main'||$before['ahead']>0)failUpdate('No existe actualización fast-forward segura.',77);
if(!$before['can_apply']) failUpdate('Actualización manual requerida: cambios versionados, dependencias o historial no seguro.',77);
// No se usa git stash: vendor/ y node_modules/ deben permanecer instalados.
gitRun($cfg,['merge','--ff-only','origin/main']);
$after=state($cfg,false);
if($after['behind']!==0||$after['dirty']||!$after['dependencies_ready'])failUpdate('Git terminó en un estado inesperado.',70);
$after['updated']=true;$after['previous_commit']=$before['local_commit'];$after['stash_created']=false;
$after['needs_attention']=true;
$after['message']='Código Git actualizado sin mover dependencias locales. Verifica la aplicación y el worker. Si hay cambios de dependencias, el actualizador exige despliegue manual. No se reiniciaron servicios ni modificó MySQL.';
echo json_encode($after,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
