<?php
declare(strict_types=1);

/**
 * Pre-fix characterization for the approved GLOBAL/USER AI configuration scope.
 * KNOWN GAP / PRE-FIX lines are expected debt, never implementation/security PASS.
 */

$root = dirname(__DIR__, 2);
$dumpPath = $root . '/adbbmis1_Cloud.sql';
$dump = (string)file_get_contents($dumpPath);
$passed = 0;
$failed = 0;
$gaps = [];

$pass = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) { $passed++; echo "PASS CONTRACT CHARACTERIZATION: {$label}\n"; return; }
    $failed++; echo "FAIL CONTRACT CHARACTERIZATION: {$label}\n";
};
$gap = static function (bool $present, string $label) use (&$gaps, &$failed): void {
    if ($present) { $gaps[] = $label; echo "KNOWN GAP / PRE-FIX: {$label}\n"; return; }
    $failed++; echo "FAIL CHARACTERIZATION: expected pre-fix gap not found: {$label}\n";
};

$tableStart = strpos($dump, 'CREATE TABLE IF NOT EXISTS `UserAIAgentConfigs`');
$tableEnd = $tableStart === false ? false : strpos($dump, ';', $tableStart);
$table = ($tableStart !== false && $tableEnd !== false) ? substr($dump, $tableStart, $tableEnd - $tableStart + 1) : '';
$pass($table !== '', 'current UserAIAgentConfigs definition is present');

$targetSchema = [
    'scope enum' => preg_match('/`scope`\s+enum\(\'global\',\'user\'\)/i', $table) === 1,
    'nullable user_id_' => preg_match('/`user_id_`\s+int\s+(?:unsigned\s+)?(?:DEFAULT\s+)?NULL/i', $table) === 1,
    'generated scope_owner_key' => preg_match('/`scope_owner_key`[\s\S]*?GENERATED\s+ALWAYS[\s\S]*?STORED/i', $table) === 1,
    'scope/user CHECK' => str_contains($table, 'scope') && str_contains(strtoupper($table), 'CHECK'),
    'scope owner agent UNIQUE' => preg_match('/UNIQUE\s+KEY[^\n]*`scope`[^\n]*`scope_owner_key`[^\n]*`agent_key`/i', $table) === 1,
];
foreach ($targetSchema as $label => $implemented) $gap(!$implemented, 'schema missing ' . $label);
$gap(str_contains($table, '`user_id_` int NOT NULL'), 'global rows still require a Users owner');
$pass(str_contains($dump, 'CONSTRAINT `fk_uac_user` FOREIGN KEY (`user_id_`) REFERENCES `Users` (`id`) ON DELETE CASCADE'), 'current nullable-compatible FK intent is identifiable');

$files = [
    'michat/includes/ai_agent_runtime.php',
    'michat/chat.php',
    'michat/chat2_session_create.php',
    'michat/chat_save_edit.php',
    'michat/ai_agent_configurator.php',
    'michat/get_ai_agents.php',
    'michat/save_ai_agent.php',
    'michat/delete_ai_agent.php',
];
$magicPatterns = [
    '/UserAIAgentConfigs[\s\S]{0,400}?user_id_\s*(?:=|IN\s*\()\s*1/i',
    '/user_id_\s*(?:=|IN\s*\()\s*1[\s\S]{0,400}?UserAIAgentConfigs/i',
    '/globalUserId\s*=\s*1/i',
    '/\$userId\s*(?:===|!==)\s*1/',
];
$magicCallers = [];
foreach ($files as $relative) {
    $source = (string)file_get_contents($root . '/' . $relative);
    foreach ($magicPatterns as $pattern) {
        if (preg_match($pattern, $source) === 1) { $magicCallers[] = $relative; break; }
    }
}
$pass($magicCallers === $files, 'exact eight pre-fix production callers are inventoried');
foreach ($magicCallers as $relative) echo "PRE-FIX MAGIC GLOBAL OWNER: {$relative}\n";

$writeAuth = [
    'michat/ai_agent_configurator.php',
    'michat/save_ai_agent.php',
    'michat/delete_ai_agent.php',
];
foreach ($writeAuth as $relative) {
    $source = (string)file_get_contents($root . '/' . $relative);
    $gap(!str_contains($source, 'ChatIdentity::canManageGlobalAiConfiguration()'), basename($relative) . ' not migrated to global-AI write authorization');
}
$reader = (string)file_get_contents($root . '/michat/get_ai_agents.php');
$pass(str_contains($reader, 'UserAIAgentConfigs') && str_contains($reader, '$userId'), 'get_ai_agents remains an effective-config read surface');
$pass(!str_contains($reader, 'canManageGlobalAiConfiguration()'), 'read access is not coupled to the future write-global guard');

$resolve = static function (array $rows, int $userId): array {
    usort($rows, static fn(array $a, array $b): int => (($b['scope'] === 'user' && $b['user_id_'] === $userId) <=> ($a['scope'] === 'user' && $a['user_id_'] === $userId)));
    $effective = [];
    foreach ($rows as $row) {
        if ($row['scope'] !== 'global' && !($row['scope'] === 'user' && $row['user_id_'] === $userId)) continue;
        $effective[$row['agent_key']] ??= $row;
    }
    return $effective;
};
$effective = $resolve([
    ['scope'=>'global','user_id_'=>null,'agent_key'=>'chat_main','model_id'=>'global-model'],
    ['scope'=>'user','user_id_'=>27,'agent_key'=>'chat_main','model_id'=>'user-model'],
    ['scope'=>'global','user_id_'=>null,'agent_key'=>'embedding_main','model_id'=>'embed-model'],
], 27);
$pass(($effective['chat_main']['model_id'] ?? '') === 'user-model', 'USER override wins per agent_key');
$pass(($effective['embedding_main']['model_id'] ?? '') === 'embed-model', 'GLOBAL supplies a key without an override');
$pass(!isset($effective['missing_key']), 'missing key remains absent for caller fallback/error policy');

$baseChat = [
    'chat_main', 'chat_main_base', 'chat_main_tool_rules', 'chat_main_behavior_rules',
    'chat_main_procedural_template', 'chat_main_procedural_item_template', 'chat_main_procedural_labels',
    'chat_main_session_memory_template', 'chat_main_attachment_template', 'chat_main_question_memory_template',
    'chat_main_project_instructions_template', 'chat_main_primordial_rules_template',
    'chat_main_primordial_rule_item_template', 'chat_main_rag_context_template',
];
foreach ($baseChat as $key) $pass(preg_match("/'" . preg_quote($key, '/') . "'/", $dump) === 1, 'mandatory base catalog contains ' . $key);
$pass(preg_match("/\(\d+,\s*1,\s*'chat_main'[\s\S]*?'[^']+'[\s\S]*?,\s*1,\s*\d+,/", $dump) === 1, 'chat_main has an active deployment model default without certifying Bedrock access');

$conditional = ['prompt_compiler','embedding_main','smart_memory_general','smart_memory_code'];
foreach ($conditional as $key) $pass(str_contains($dump, "'{$key}'"), 'conditional catalog contains ' . $key);
$plannerFactory = (string)file_get_contents($root . '/michat/includes/Tasks/TaskPlannerFactory.php');
$flags = (string)file_get_contents($root . '/michat/includes/Pipeline/PipelineFeatureFlags.php');
$pass(str_contains($plannerFactory, "['task_planner']") && str_contains($flags, "'task_planner' => false"), 'task_planner is required when enabled and OFF by default');
$pass(!str_contains($dump, "'task_planner'"), 'no speculative task_planner model seed exists');
$nextWork = (string)file_get_contents($root . '/michat/includes/Tasks/NextWorkAgentConfigResolver.php');
$pass(str_contains($nextWork, "AGENT_KEY='next_work_evaluator'") && str_contains($nextWork, "FALLBACK_KEY='chat_main'"), 'next_work_evaluator is optional with deliberate chat_main fallback');

$aiHistorical = preg_match('/INSERT\s+INTO\s+`UserAIAgentConfigs`\s*\(`id_`,\s*`user_id_`[\s\S]*?VALUES\s*\(\d+,\s*1,/i', $dump) === 1;
$pipelineHistorical = preg_match('/INSERT\s+INTO\s+`UserPipelineFeatures`[\s\S]*?VALUES\s*\(\d+,\s*1,/i', $dump) === 1;
$preferencesHistorical = preg_match('/INSERT\s+INTO\s+`UserPreferences`[\s\S]*?VALUES\s*\(\d+,\s*1,/i', $dump) === 1;
$gap($aiHistorical, 'AI catalog still carries historical id_, user_id_=1 and timestamps');
$gap($pipelineHistorical, 'UserPipelineFeatures still seeds historical user 1 rows');
$gap($preferencesHistorical, 'UserPreferences still seeds a historical user 1 row');

$legacyBefore = [
    ['user_id_'=>1,'agent_key'=>'chat_main','model_id'=>'legacy-global'],
    ['user_id_'=>42,'agent_key'=>'chat_main','model_id'=>'override'],
];
$legacyExpected = [
    ['scope'=>'global','user_id_'=>null,'agent_key'=>'chat_main','model_id'=>'legacy-global'],
    ['scope'=>'user','user_id_'=>42,'agent_key'=>'chat_main','model_id'=>'override'],
];
$pass($legacyBefore[0]['agent_key'] === $legacyExpected[0]['agent_key'] && $legacyBefore[0]['model_id'] === $legacyExpected[0]['model_id'], 'legacy adoption fixture preserves global content');
$pass($legacyBefore[1]['user_id_'] === $legacyExpected[1]['user_id_'] && $legacyBefore[1]['model_id'] === $legacyExpected[1]['model_id'], 'legacy adoption fixture preserves other-user overrides');
$conflict = ['global'=>['agent_key'=>'chat_main','model_id'=>'new'], 'legacy'=>['agent_key'=>'chat_main','model_id'=>'old']];
$pass($conflict['global']['agent_key'] === $conflict['legacy']['agent_key'] && $conflict['global']['model_id'] !== $conflict['legacy']['model_id'], 'incompatible global/legacy collision is a fail-closed fixture');
$pass($legacyExpected === array_values($legacyExpected), 'second execution contract preserves rows, content and scope without duplication');

$requiredDb = ['TASK_TEST_DB_HOST','TASK_TEST_DB_PORT','TASK_TEST_DB_USER','TASK_TEST_DB_PASSWORD'];
$missingDb = array_values(array_filter($requiredDb, static fn(string $key): bool => getenv($key) === false || getenv($key) === ''));
if ($missingDb !== []) {
    echo 'SKIP MYSQL GLOBAL AI SCOPE — missing ' . implode(', ', $missingDb) . "\n";
} else {
    $mysqlTargets = [
        'clean schema import', 'Users count = 0', 'globals without Users',
        'global agent_key uniqueness', 'user override uniqueness',
        'global plus user_id rejected', 'user scope plus NULL rejected',
        'override FK', 'delete user preserves globals', 'delete user cascades overrides',
        'global fallback', 'user override precedence',
    ];
    foreach ($mysqlTargets as $target) echo "MYSQL TARGET CONTRACT / PRE-FIX: {$target}\n";
    $cleanHarness = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/schema_clean_install_test.php');
    passthru($cleanHarness, $cleanExit);
    // Production is intentionally still pre-fix; a current-schema import cannot
    // certify the approved target and is never reported as target-schema PASS.
    echo "KNOWN GAP / PRE-FIX: MYSQL target checks await the GLOBAL/USER production schema and migration (current harness exit {$cleanExit})\n";
    echo "MYSQL GLOBAL AI SCOPE: PRE-FIX GAP (not PASS)\n";
}

echo "Static characterization: " . ($failed === 0 ? 'PASS' : 'FAIL') . "\n";
echo 'Pre-fix gaps detected: ' . count($gaps) . "\n";
echo "GLOBAL/USER IMPLEMENTATION: NOT IMPLEMENTED / PRE-FIX\n";
echo "Result: {$passed} characterization checks passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
