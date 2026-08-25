<?php
declare(strict_types=1);
final class TaskReplanCheckpointService{
 public function __construct(private TaskReplanCheckpointStoreInterface$store){}
 public function inspect(int$u,string$public):TaskReplanCheckpoint{return TaskReplanCheckpoint::fromRow($this->store->safeCheckpoint($u,$public));}
 public function reject(int$u,string$public,int$lock):TaskReplanCheckpoint{return TaskReplanCheckpoint::fromRow($this->store->transition($u,$public,$lock,'rejected'));}
 public function fail(int$u,string$public,int$lock):TaskReplanCheckpoint{return TaskReplanCheckpoint::fromRow($this->store->transition($u,$public,$lock,'failed'));}
}
