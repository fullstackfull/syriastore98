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
}
