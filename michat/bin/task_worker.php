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

// ======================================================================
// Ciclo de vida "bajo demanda": señalización cooperativa + actividad.
// ======================================================================
$lifecycleConfig=TaskWorkerRuntimeConfig::fromEnvironment();
$activity=new TaskWorkerActivity($lifecycleConfig->activityFile);
$keepRunning=true;
$shutdownSignal=static function(int$signal)use(&$keepRunning):void{
 // SIGTERM/SIGINT: se procesa AL FINAL del ciclo actual (apagado cooperativo).
 $keepRunning=false;
 fwrite(STDERR,sprintf("task_worker: señal %d recibida; terminando el ciclo en curso.\n",$signal));
};
if(function_exists('pcntl_async_signals'))pcntl_async_signals(true);
if(function_exists('pcntl_signal')){pcntl_signal(SIGTERM,$shutdownSignal);pcntl_signal(SIGINT,$shutdownSignal);}

if(isset($options['once'])){
 // Red de seguridad del timer: si el worker largo ya está vivo y activo,
 // no duplicar trabajo; salir limpio para que systemd libere memoria.
 $status=$activity->getStatus();
 if($status['active']&&$status['timestamp']!==null&&(time()-(int)$status['timestamp'])<90){exit(0);}
 $worked=$worker->once();
 $activity->record(['worked'=>$worked,'mode'=>'once','worker_id'=>$config->workerId]);
 exit(0);
}

$max=isset($options['max-jobs'])?max(1,(int)$options['max-jobs']):null;
if($max!==null){$worker->loop($max);exit(0);}

// Modo --loop puro: bucle con autoapagado por inactividad configurable.
// Sale con código 0 => Restart=on-failure NO lo relanza => 0 MB consumidos.
$lastWorkedAt=time();
$cycleSleep=max(5,$config->sleepSeconds); // sondeo de cola/BD cada >=5 s
do{
 $activity->record(['worked'=>true,'mode'=>'loop','worker_id'=>$config->workerId]);
 try{
  $worked=$worker->once();
 }catch(Throwable$e){
  error_log('Task worker cycle failed: '.ChatTaskBridge::sanitizeError($e));
  $worked=false;
 }
 if(!$keepRunning)break; // señal llegada durante once(): apagado cooperativo
 if($worked){
  $lastWorkedAt=time();
  continue; // hay trabajo: siguiente ciclo inmediato
 }
 $activity->record(['worked'=>false,'mode'=>'loop','worker_id'=>$config->workerId]);
 $idle=min(time()-$lastWorkedAt,$activity->getIdleSeconds());
 if($idle>=$lifecycleConfig->idleTimeoutSeconds&&!TaskWorkerWorkload::hasPendingWork($db_connection)){
  fwrite(STDERR,sprintf("task_worker: %d s sin trabajo; autoapagado limpio.\n",$idle));
  break;
 }
 usleep(200000); // reaccionar a SIGTERM en <=200 ms aunque el sleep sea mayor
 if(!$keepRunning)break;
 $remaining=$cycleSleep-($cycleSleep%1);
 for($i=0;$i<$remaining&&$keepRunning;$i++){sleep(1);}
}while($keepRunning);

// El proceso termina SOLO cuando systemd se lo pida (stop manual o idle-timeout).
// No invoca al helper de parada: eso requeriría sudo desde dentro del servicio
// y systemd no permite matar su propia unidad desde su cgroup.
exit(0);
