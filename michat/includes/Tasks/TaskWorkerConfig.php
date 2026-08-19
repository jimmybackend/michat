<?php
declare(strict_types=1);
final class TaskWorkerConfig{
 public function __construct(public readonly string$workerId,public readonly int$leaseSeconds,public readonly int$sleepSeconds,public readonly int$recoveryBatch){}
 public static function fromEnvironment():self{$o=trim((string)getenv('TASK_WORKER_ID'));$w=$o!==''?$o:sprintf('%s:%d:%s',gethostname()?:'host',getmypid(),bin2hex(random_bytes(6)));if(strlen($w)>120)throw new InvalidArgumentException('TASK_WORKER_ID is too long');return new self($w,self::bounded('TASK_WORKER_LEASE_SECONDS',300,60,3600),self::bounded('TASK_WORKER_SLEEP_SECONDS',2,1,60),self::bounded('TASK_WORKER_RECOVERY_BATCH',50,1,200));}
 public static function leaseToken():string{$h=bin2hex(random_bytes(16));return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-'.dechex((hexdec($h[16])&3)|8).substr($h,17,3).'-'.substr($h,20,12);}
 private static function bounded(string$k,int$d,int$min,int$max):int{$r=getenv($k);$v=$r===false||$r===''?$d:filter_var($r,FILTER_VALIDATE_INT);if($v===false||$v<$min||$v>$max)throw new InvalidArgumentException($k.' is outside the safe range');return$v;}
}
