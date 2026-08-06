<?php
/**
 * bootstrap.php — micro-framework de aserciones.
 *
 * El repo no incluye composer.json ni vendor/ (las dependencias viven en el
 * servidor, fuera del webroot), así que no hay PHPUnit disponible. Estos tests
 * corren con PHP a secas:
 *
 *     php tests/run.php
 *
 * Solo se prueban funciones PURAS. Nada aquí toca MySQL, S3 ni Bedrock.
 */

declare(strict_types=1);

final class TestState {
    public static int $passed = 0;
    public static array $failures = [];
    public static string $currentFile = '';
}

function t_pass(string $msg): void {
    TestState::$passed++;
    echo "  \033[32m✓\033[0m {$msg}\n";
}

function t_fail(string $msg, string $detail = ''): void {
    TestState::$failures[] = TestState::$currentFile . ' :: ' . $msg . ($detail !== '' ? " — {$detail}" : '');
    echo "  \033[31m✗\033[0m {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function t_ok(bool $condition, string $msg): void {
    $condition ? t_pass($msg) : t_fail($msg);
}

function t_eq($expected, $actual, string $msg): void {
    if ($expected === $actual) {
        t_pass($msg);
        return;
    }
    t_fail($msg, sprintf('esperado %s, obtenido %s', var_export($expected, true), var_export($actual, true)));
}

/**
 * Verifica que $fn lance una excepción. Si $expectedClass se indica, además
 * comprueba el tipo.
 */
function t_throws(callable $fn, string $msg, string $expectedClass = Throwable::class): void {
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $expectedClass) {
            t_pass($msg);
        } else {
            t_fail($msg, 'lanzó ' . get_class($e) . ' en vez de ' . $expectedClass);
        }
        return;
    }
    t_fail($msg, 'no lanzó ninguna excepción');
}

/** Verifica que $fn NO lance. */
function t_no_throw(callable $fn, string $msg): void {
    try {
        $fn();
        t_pass($msg);
    } catch (Throwable $e) {
        t_fail($msg, 'lanzó ' . get_class($e) . ': ' . $e->getMessage());
    }
}

function t_section(string $name): void {
    echo "\n\033[1m{$name}\033[0m\n";
}
