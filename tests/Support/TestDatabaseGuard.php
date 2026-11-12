<?php

namespace Tests\Support;

use RuntimeException;

/**
 * The single implementation of the test-database isolation assertion.
 *
 * PURE BY DESIGN: it takes two names, returns nothing, and throws. It boots nothing,
 * reads no config and opens no database connection. That is what makes it callable
 * from tests/bootstrap.php before the Laravel application exists, and what makes it
 * testable with no database present.
 *
 * Three callers, one implementation:
 *   - tests/bootstrap.php  (Layer 0, THE PRIMARY CONTROL) with values read from the environment
 *   - tests/TestCase.php   (Layer 3, defence in depth only) with values read from config()
 *   - tests/Unit/TestIsolationGuardTest.php with literals
 *
 * See docs/development/phase-1/01-SAFE-BASELINE.md §6.5.
 *
 * P2.5-SAFETY, 2026-11-06. `assertEveryEnvironmentSourceIsSafe()` exists because Layer 0 was
 * BLIND to the one source that mattered, and the development fixture was emptied through the gap.
 * Reproduced on a throwaway copy; these are the values measured INSIDE the destroying run:
 *
 *     $_ENV['DB_DATABASE']      ':memory:'           <- phpunit.xml force="true" writes here
 *     $_SERVER['DB_DATABASE']   '/.../decoy.sqlite'  <- an `export` survives HERE, and only here
 *     getenv('DB_DATABASE')     ':memory:'           <- phpunit.xml force="true" writes here
 *     ini variables_order       'GPCS'               <- no 'E', so $_ENV is not auto-populated
 *
 * PHPUnit's `<env force="true">` writes `$_ENV` and `putenv()` and does NOT write `$_SERVER`.
 * Laravel's `env()` goes through Dotenv, whose ServerConstAdapter reads `$_SERVER` FIRST. So the
 * framework resolved the exported path while every source Layer 0 consulted said `:memory:`:
 * `$_ENV['x'] ?? $_SERVER['x'] ?? getenv('x')` stops at the first source that EXISTS, and under
 * force="true" the first one always exists. The guard read its sources in the opposite precedence
 * to the framework it protects, and the decisive one was the one it could never reach.
 *
 * Layer 3 cannot substitute for it: tests/TestCase.php says in its own docblock that its assertion
 * runs after `parent::setUp()`, hence after RefreshDatabase has already run `migrate:fresh`. In the
 * incident it printed 27 correct failures over an already-empty database.
 */
final class TestDatabaseGuard
{
    public static function assertSqliteInMemory(?string $connection, ?string $database): void
    {
        if ($connection !== 'sqlite') {
            throw new RuntimeException(
                "ABORTING TEST RUN: the test database connection is '{$connection}', not 'sqlite'. "
                . 'RefreshDatabase would run migrate:fresh and DROP EVERY TABLE in that database. '
                . 'Fix phpunit.xml (DB_CONNECTION/DB_DATABASE overrides, with force="true") or '
                . 'provide .env.testing. Never point the suite at MySQL. '
                . 'See docs/development/phase-1/01-SAFE-BASELINE.md 6.3.'
            );
        }

        if ($database !== ':memory:') {
            throw new RuntimeException(
                "ABORTING TEST RUN: the sqlite database is '{$database}', not ':memory:'. "
                . 'Refusing to run migrate:fresh against a persistent database file.'
            );
        }
    }

    /**
     * ASSERT EVERY SOURCE, NOT THE FIRST ONE THAT EXISTS.
     *
     * Both arrays map a source label ('$_ENV', '$_SERVER', 'getenv()') to whatever that source
     * carries, or null when it carries nothing. Every non-null value is checked, so a dangerous
     * value cannot hide behind a safe one written by phpunit.xml.
     *
     * ALL SOURCES ABSENT IS NOT THIS METHOD'S BUSINESS. It returns normally, because that case is
     * tests/bootstrap.php's Source B branch, which requires phpunit.xml to carry both
     * force="true" overrides and refuses to run otherwise.
     *
     * A THIRD RULE WAS CONSIDERED AND DELIBERATELY NOT ADDED -- "abort when the sources disagree".
     * It cannot bite: each variable has exactly ONE safe value, so any disagreement necessarily
     * contains a value the two rules below already reject. An assertion that cannot fail is worse
     * than no assertion, because the next reader trusts it.
     *
     * @param array<string,?string> $connectionSources source label => DB_CONNECTION it carries
     * @param array<string,?string> $databaseSources    source label => DB_DATABASE it carries
     */
    public static function assertEveryEnvironmentSourceIsSafe(
        array $connectionSources,
        array $databaseSources
    ): void {
        foreach ($connectionSources as $source => $connection) {
            if ($connection === null || $connection === '') {
                continue;
            }

            if ($connection !== 'sqlite') {
                throw new RuntimeException(
                    "ABORTING TEST RUN: {$source} carries DB_CONNECTION='{$connection}', not 'sqlite'. "
                    . 'RefreshDatabase would run migrate:fresh and DROP EVERY TABLE in that database. '
                    . 'Laravel reads $_SERVER before $_ENV, so a value there wins over phpunit.xml and '
                    . 'its force="true" even when every other source looks safe. Unset it, or run the '
                    . 'suite in a shell that never exported it.'
                );
            }
        }

        foreach ($databaseSources as $source => $database) {
            if ($database === null || $database === '') {
                continue;
            }

            if ($database !== ':memory:') {
                throw new RuntimeException(
                    "ABORTING TEST RUN: {$source} carries DB_DATABASE='{$database}', not ':memory:'. "
                    . 'Refusing to run migrate:fresh against a persistent database file. '
                    . 'Laravel reads $_SERVER before $_ENV, so a value there wins over phpunit.xml and '
                    . 'its force="true" even when every other source looks safe -- that is exactly how '
                    . 'the development fixture was emptied on 2026-11-06. Unset it, or run the suite '
                    . 'in a shell that never exported it.'
                );
            }
        }
    }
}
