<?php declare(strict_types=1);
foreach(['TaskStatus','TaskStepStatus','TaskExecutionStatus','TaskExceptions','TaskPublicId','TaskStateMachine','TaskRepository','TaskStepRepository','TaskDependencyRepository','TaskExecutionRepository','TaskEventRepository','TaskOrchestrator','TaskInputValidator','TaskApplicationService','TaskApiResponse','TaskApiController']as$f)require_once __DIR__.'/'.$f.'.php';
