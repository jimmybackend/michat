<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require_once dirname(__DIR__).'/app_bootstrap.php';
require_once dirname(__DIR__).'/includes/Tasks/bootstrap.php';
$options=getopt('', ['once','loop','max-jobs:','sleep:']);
if(isset($options['once'])===isset($options['loop'])){fwrite(STDERR,"Usage: php michat/bin/task_worker.php --once|--loop [--max-jobs=N] [--sleep=N]\n");exit(2);}
$config=TaskWorkerConfig::fromEnvironment();
if(isset($options['sleep'])){$sleep=filter_var($options['sleep'],FILTER_VALIDATE_INT);if($sleep===false||$sleep<1||$sleep>60){fwrite(STDERR,"Invalid --sleep\n");exit(2);}$config=new TaskWorkerConfig($config->workerId,$config->leaseSeconds,$sleep,$config->recoveryBatch);}
$queue=new TaskQueueRepository($db_connection);
$executor=$GLOBALS['task_chat_execution_service']??null;
if(!is_callable($executor)){$executor=static function():array{throw new RuntimeException('Server-side chat execution service is not configured');};}
$worker=new TaskWorker(new TaskClaimService($queue,$config),new TaskExecutionRunner($queue,new TaskLeaseService($queue,$config->leaseSeconds),$executor),new TaskRecoveryService($queue),$config);
if(isset($options['once'])){$worker->once();exit(0);}
$max=isset($options['max-jobs'])?max(1,(int)$options['max-jobs']):null;$worker->loop($max);
