<?php
declare(strict_types=1);
interface TaskManualTaskCreator{public function createManualTask(int$userId,array$data):array;}
interface TaskAutonomyTaskCreator{public function createAutonomyTask(int$userId,array$data):array;public function findAutonomyTask(int$userId,string$idempotencyKey,int$projectId,int$parentTaskId,string$objective):?array;}
