<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/Migrations/SqlMigrationExecutor.php';
$passed=0;$failed=0;$check=function(bool $ok,string $label)use(&$passed,&$failed){echo($ok?'PASS ':'FAIL ').$label."\n";$ok?$passed++:$failed++;};
$fixture=<<<'SQL'
-- DELIMITER $$ in a comment
DELIMITER $$
CREATE PROCEDURE `semi;colon`()
BEGIN
  SELECT 'a;b', "c;d", `odd;name`;
  /* $$ inside a comment */
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'escaped \' quote; still string';
END$$
DELIMITER ;
# ; comment
SELECT 'normal;statement';
SQL;
$statements=SqlMigrationExecutor::splitStatements($fixture);
$check(count($statements)===2,'delimiter-aware parser returns procedure and normal statement');
$check(str_starts_with($statements[0],'-- DELIMITER $$ in a comment')&&str_contains($statements[0],"SELECT 'a;b'")&&str_ends_with($statements[0],'END'),'procedure body is not split at internal semicolons');
$check($statements[1]==="# ; comment\nSELECT 'normal;statement'",'normal delimiter ignores comments and quoted semicolon');
$normal=SqlMigrationExecutor::splitStatements("SELECT 'it''s;ok'; SELECT `a;b` FROM t; -- ;\nSELECT 3;");
$check(count($normal)===3,'quotes backticks and line comments preserve normal SQL boundaries');
try{SqlMigrationExecutor::splitStatements("SELECT 'unterminated;");$check(false,'unterminated quote rejected');}catch(RuntimeException){$check(true,'unterminated quote rejected');}
echo"Result: {$passed} passed, {$failed} failed\n";exit($failed?1:0);
