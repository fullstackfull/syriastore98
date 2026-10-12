<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseGuard;

/**
 * Exercises the isolation guard's LOGIC in isolation.
 *
 * It extends PHPUnit's base class directly and calls the assertion with LITERALS:
 * no config(), no application container, no refreshApplication(), and no PDO handle.
 * That is deliberate. A test for a data-destruction guard must not itself be capable
 * of destroying data, and an earlier draft that drove setUp() with a mutated config
 * would both have been unable to pass (refreshApplication() discards the mutation)
 * and have opened a live connection to whatever database.default named.
 *
 * See docs/development/phase-1/05-REGRESSION-TEST-PLAN.md §7.2.
 */
class TestIsolationGuardTest extends TestCase
{
    public function test_guard_rejects_non_sqlite_connection(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ABORTING TEST RUN.*DROP EVERY TABLE/s');

        TestDatabaseGuard::assertSqliteInMemory('mysql', 'anything');
    }

    public function test_guard_rejects_persistent_sqlite_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/not ':memory:'/");

        TestDatabaseGuard::assertSqliteInMemory('sqlite', '/tmp/x.sqlite');
    }

    /** An unset variable must FAIL CLOSED, not fall through. */
    public function test_guard_rejects_absent_configuration(): void
    {
        $this->expectException(RuntimeException::class);

        TestDatabaseGuard::assertSqliteInMemory(null, null);
    }

    /**
     * The positive case. Without it the guard could be unconditionally fatal and the
     * three negative cases would still pass.
     */
    public function test_guard_accepts_sqlite_in_memory(): void
    {
        TestDatabaseGuard::assertSqliteInMemory('sqlite', ':memory:');

        $this->assertTrue(true, 'the guard returned normally for sqlite/:memory:');
    }
}
