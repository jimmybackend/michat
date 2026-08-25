<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require_once dirname(__DIR__).'/app_bootstrap.php';
require_once dirname(__DIR__).'/includes/Tasks/bootstrap.php';
$options=getopt('', ['once','loop','max-jobs:','sleep:']);
if(isset($options['once'])===isset($options['loop'])){fwrite(STDERR,"Usage: php michat/bin/task_worker.php --once|--loop [--max-jobs=N] [--sleep=N]\n");exit(2);}
$config=TaskWorkerConfig::fromEnvironment();
if(isset($options['sleep'])){$sleep=filter_var($options['sleep'],FILTER_VALIDATE_INT);if($sleep===false||$sleep<1||$sleep>60){fwrite(STDERR,"Invalid --sleep\n");exit(2);}$config=new TaskWorkerConfig($config->workerId,$config->leaseSeconds,$sleep,$config->recoveryBatch,$config->recurrenceBatch,$config->recurrenceCatchUpLimit,$config->recurrenceRetryBatch,$config->recurrenceOrphanSeconds,$config->continuationBatch,$config->replanBatch);}
$queue=new TaskQueueRepository($db_connection);
$steps=(new TaskStepExecutionServiceFactory($db_connection))->create();
$tasks=new TaskRepository($db_connection);$rules=new TaskRecurrenceRuleRepository($db_connection);$occurrences=new TaskRecurrenceOccurrenceRepository($db_connection);$recurrence=new TaskRecurrenceEvaluator($db_connection,$rules,$occurrences,$tasks,(new TaskApplicationServiceFactory($db_connection))->create(false),new TaskRecurrenceMisfirePlanner(new TaskRecurrenceCalculator()),$config->recurrenceBatch,$config->recurrenceCatchUpLimit,$config->recurrenceRetryBatch,$config->recurrenceOrphanSeconds);
$continuations=(new PostTaskContinuationServiceFactory($db_connection))->create($config->continuationBatch,false);
$replans=(new TaskReplanServiceFactory($db_connection))->create($config->replanBatch);
$worker=new TaskWorker(new TaskClaimService($queue,$config),new TaskExecutionRunner(new TaskStepProgressionService($queue),new TaskLeaseService($queue,$config->leaseSeconds),$steps),new TaskRecoveryService($queue),$config,new TaskWaitService($queue),$recurrence,$continuations,$replans);
if(isset($options['once'])){$worker->once();exit(0);}
$max=isset($options['max-jobs'])?max(1,(int)$options['max-jobs']):null;$worker->loop($max);
