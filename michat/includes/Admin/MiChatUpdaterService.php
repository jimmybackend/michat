<?php
declare(strict_types=1);
final class MiChatUpdaterService {
    private const HELPER='/usr/local/sbin/michat-updater';
    public function execute(string $action): array {
        if(!in_array($action,['check','apply','apply-stash'],true))throw new InvalidArgumentException('Acción no permitida.');
        if(!is_executable(self::HELPER))throw new RuntimeException('Instala primero el helper con michat/bin/install_michat_updater.sh.');
        $p=proc_open(['/usr/bin/sudo','-n',self::HELPER,$action],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($p))throw new RuntimeException('No se pudo iniciar el updater.');
        $stdout=stream_get_contents($pipes[1],131073);$stderr=stream_get_contents($pipes[2],32769);
        fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
        if($code!==0)throw new RuntimeException(substr(trim((string)$stderr)?:'El updater rechazó la operación.',0,500));
        $result=json_decode((string)$stdout,true);
        if(!is_array($result)||($result['ok']??false)!==true)throw new RuntimeException('Respuesta del updater inválida.');
        return $result;
    }
}
