<?php
declare(strict_types=1);

const MICHAT_EXTERNAL_TEST_DATABASE_PREFIX = 'michat_test_12b4_';

function requireExternalMysqlDestructiveAuthorization(): void
{
    if (getenv('TASK_TEST_DB_ALLOW_DESTRUCTIVE') !== '1') {
        throw new RuntimeException('REFUSE TO RUN: set TASK_TEST_DB_ALLOW_DESTRUCTIVE=1 only for an isolated TEST server');
    }
}

function externalMysqlTemporaryDatabaseName(string $purpose): string
{
    if (preg_match('/^[a-z0-9_]{1,24}$/D', $purpose) !== 1) {
        throw new InvalidArgumentException('invalid temporary database purpose');
    }
    return MICHAT_EXTERNAL_TEST_DATABASE_PREFIX.$purpose.'_'.bin2hex(random_bytes(8));
}

/** @param list<string> $created */
function assertExternalMysqlDatabaseOwned(string $database, array $created): void
{
    if (!str_starts_with($database, MICHAT_EXTERNAL_TEST_DATABASE_PREFIX) || !in_array($database, $created, true)) {
        throw new RuntimeException('REFUSE TO DROP: temporary database ownership is not proven');
    }
}
