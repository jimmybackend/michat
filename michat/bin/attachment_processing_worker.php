#!/usr/bin/env php
<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
try{
    if(count($argv)!==2||!preg_match('/^--max-batches=([1-9][0-9]?)$/',$argv[1],$m))throw new RuntimeException('Uso: php attachment_processing_worker.php --max-batches=1..99');
    require_once dirname(__DIR__).'/app_bootstrap.php';
    require_once dirname(__DIR__).'/includes/Drive/AttachmentProcessingService.php';
    $service=new AttachmentProcessingService($db_connection);if(!$service->available())throw new RuntimeException('Aplica fase14_attachment_processing');
    for($i=0;$i<(int)$m[1];$i++){
        $row=$db_connection->query("SELECT id_,user_id_ FROM AttachmentProcessingJobs WHERE status IN ('pending','retry','waiting','running') AND available_at<=UTC_TIMESTAMP() ORDER BY updated_at,id_ LIMIT 1")->fetch_assoc();
        if(!$row)break;
        echo json_encode(AttachmentProcessingService::publicJob($service->tick((int)$row['user_id_'],(int)$row['id_'])),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";
    }
}catch(Throwable $e){fwrite(STDERR,'ATTACHMENT_WORKER: '.$e->getMessage()."\n");exit(1);}
