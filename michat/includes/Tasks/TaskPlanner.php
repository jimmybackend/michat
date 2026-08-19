<?php
declare(strict_types=1);
interface TaskPlanner { public function plan(string $objective, array $context = []): TaskPlan; }
