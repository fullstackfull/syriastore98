<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseGuard;

/**
 * P2.5-SAFETY — THE PRIMARY ISOLATION CONTROL READ ITS SOURCES IN THE WRONG ORDER.
 * ================================================================================================
 *
 * On 2026-11-06 a shell exported `DB_DATABASE=database/p2dev.sqlite` and then ran
 * `php artisan test` in the same shell. Every row of the development fixture was deleted. The
 * suite printed 27 failures carrying the guard's own correct message, and the data was already
 * gone when it printed them.
 *
 * REPRODUCED ON A THROWAWAY COPY, and this is what each source really held inside the run:
 *
 *     $_ENV['DB_DATABASE']      ':memory:'                  <- phpunit.xml force="true" writes here
 *     $_SERVER['DB_DATABASE']   '/…/decoy.sqlite'           <- THE EXPORT SURVIVES HERE
 *     getenv('DB_DATABASE')     ':memory:'                  <- phpunit.xml force="true" writes here
 *     ini variables_order       'GPCS'                       <- no 'E', so $_ENV is not auto-filled
 *
 * PHPUnit's `<env force="true">` writes `$_ENV` and `putenv()` and does NOT write `$_SERVER`.
 * Laravel's `env()` goes through Dotenv, whose `ServerConstAdapter` reads **`$_SERVER` first** —
 * so the framework resolved the exported path while every source the guard looked at said
 * `:memory:`. `tests/bootstrap.php` read `$_ENV ?? $_SERVER ?? getenv()`, a chain that stops at
 * the FIRST source that exists, and under `force="true"` the first one always exists.
 *
 * So the guard consulted its sources in the opposite precedence to the framework it protects, and
 * the one source that mattered was the one it could never reach. It was not a weak guard; it was
 * a guard looking the wrong way.
 *
 * `Tests\TestCase`'s own assertion is documented in that file as defence in depth only, because it
 * runs after `parent::setUp()` and therefore after `RefreshDatabase` has already run
 * `migrate:fresh`. Measured here too: a probe class WITHOUT the trait threw the same message and
 * left the copy intact at users=5, orders=62. So Layer 3 cannot be the control, and Layer 0 was
 * blind — which is the whole of the incident.
 *
 * THE FIX IS TO ASSERT EVERY SOURCE, not the first one found. Two rules, both strict equality.
 *
 * A THIRD RULE WAS CONSIDERED AND DELIBERATELY NOT ADDED: "abort when the sources disagree". It
 * cannot bite. Each variable has exactly ONE safe value, so any disagreement necessarily contains
 * a value that rules 1 or 2 already reject. An assertion that cannot fail is worse than no
 * assertion, because the next reader trusts it.
 */
class TestDatabaseEnvironmentSourcesTest extends TestCase
{
    /** The real incident, with the values measured inside the run that destroyed the fixture. */
    public function test_it_rejects_a_persistent_path_that_only_survives_in_server(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/\$_SERVER/');

        TestDatabaseGuard::assertEveryEnvironmentSourceIsSafe(
            ['$_ENV' => 'sqlite', '$_SERVER' => 'sqlite', 'getenv()' => 'sqlite'],
            ['$_ENV' => ':memory:', '$_SERVER' => '/home/user/syriastore98/database/p2dev.sqlite', 'getenv()' => ':memory:']
        );
    }

    /** The same blindness for the CONNECTION: MySQL hiding in $_SERVER behind a forced sqlite. */
    public function test_it_rejects_a_non_sqlite_connection_that_only_survives_in_server(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/\$_SERVER/');

        TestDatabaseGuard::assertEveryEnvironmentSourceIsSafe(
            ['$_ENV' => 'sqlite', '$_SERVER' => 'mysql', 'getenv()' => 'sqlite'],
            ['$_ENV' => ':memory:', '$_SERVER' => ':memory:', 'getenv()' => ':memory:']
        );
    }

    /** Whichever source carries it, it must be named in the message so the fix is obvious. */
    public function test_it_names_the_offending_source(): void
    {
        try {
            TestDatabaseGuard::assertEveryEnvironmentSourceIsSafe(
                ['getenv()' => 'sqlite'],
                ['getenv()' => '/tmp/x.sqlite']
            );
            $this->fail('the guard returned normally for a persistent path in getenv()');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('getenv()', $e->getMessage());
            $this->assertStringContainsString('/tmp/x.sqlite', $e->getMessage());
        }
    }

    /**
     * THE POSITIVE CASE, with the exact shape of a correct run: phpunit.xml's force="true" has
     * written $_ENV and putenv() and has NOT written $_SERVER, so that source is absent. Without
     * this test the guard could be unconditionally fatal and every negative case above would
     * still pass.
     */
    public function test_it_accepts_the_shape_of_a_correctly_forced_run(): void
    {
        TestDatabaseGuard::assertEveryEnvironmentSourceIsSafe(
            ['$_ENV' => 'sqlite', '$_SERVER' => null, 'getenv()' => 'sqlite'],
            ['$_ENV' => ':memory:', '$_SERVER' => null, 'getenv()' => ':memory:']
        );

        $this->assertTrue(true, 'the guard returned normally for the forced-run shape');
    }

    /**
     * ALL SOURCES ABSENT is not this method's business and must not be treated as safe BY it:
     * it is `tests/bootstrap.php`'s Source B branch, which requires phpunit.xml to carry both
     * force="true" overrides and refuses to run otherwise. This method has nothing to object to,
     * and the test exists so that contract is written down rather than assumed.
     */
    public function test_it_passes_when_no_source_carries_anything(): void
    {
        TestDatabaseGuard::assertEveryEnvironmentSourceIsSafe(
            ['$_ENV' => null, '$_SERVER' => null, 'getenv()' => null],
            ['$_ENV' => null, '$_SERVER' => null, 'getenv()' => null]
        );

        $this->assertTrue(true, 'absence is Source B\'s problem, not this method\'s');
    }
}
