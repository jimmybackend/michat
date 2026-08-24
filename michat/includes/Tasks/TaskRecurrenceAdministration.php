<?php
declare(strict_types=1);
interface TaskRecurrenceAdministration{
 public function recurrenceList(int$userId,array$query):array;
 public function recurrenceDetail(int$userId,string$publicId):array;
 public function recurrenceCreate(int$userId,array$data):array;
 public function recurrencePause(int$userId,array$data):array;
 public function recurrenceResume(int$userId,array$data):array;
 public function recurrenceCancel(int$userId,array$data):array;
}
