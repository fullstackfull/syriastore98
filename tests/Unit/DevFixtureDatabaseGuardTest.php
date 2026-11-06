<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\DevFixtureDatabaseGuard;

/**
 * SAFE NEGATIVE TESTS FOR THE WRITE-SIDE DATABASE GUARD. P2.5-SAFETY-W.
 *
 * The owner's directive asks for a fail-closed guard against dangerous database reuse AND for the
 * guard to be validated with SAFE negative tests. "Safe" is the load-bearing word: the failure
 * this guard prevents is writing to the wrong database, so a test that proves it by pointing a
 * tool at a real database is the accident, not the test.
 *
 * So every case here is driven with LITERALS against a pure function, and `file_exists` is
 * injected. This class extends PHPUnit's `TestCase`, not `Tests\TestCase`: it boots no
 * application, opens no connection and needs no migration. Nothing in this file can touch a
 * database even if it is wrong.
 *
 * `assertCurrentEnvironmentIsADevFixture()` -- the one method that reads the real environment -- is
 * deliberately NOT driven here. Exercising it would mean mutating `$_SERVER`/`putenv()` inside a
 * test process whose own isolation depends on those exact variables, which is the mechanism of the
 * incident `TestDatabaseGuard` records. Its only job is to read three sources and hand them to the
 * pure method that every case below covers.
 */
class DevFixtureDatabaseGuardTest extends TestCase
{
    private function assertRefused(
        array $connection,
        array $database,
        string $expectedFragment,
        ?callable $fileExists = null
    ): void {
        try {
            DevFixtureDatabaseGuard::assertEveryEnvironmentSourceIsSafe(
                $connection,
                $database,
                $fileExists ?? static fn (string $p): bool => true
            );
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($expectedFragment, $e->getMessage());

            return;
        }

        $this->fail(
            'The guard ALLOWED this environment: connection=' . json_encode($connection)
            . ' database=' . json_encode($database)
        );
    }

    // ---------------------------------------------------------------------------------------
    // It allows the one thing it is for
    // ---------------------------------------------------------------------------------------

    public function test_it_allows_the_dev_fixture_named_by_every_source(): void
    {
        DevFixtureDatabaseGuard::assertEveryEnvironmentSourceIsSafe(
            ['$_ENV' => 'sqlite', '$_SERVER' => 'sqlite', 'getenv()' => 'sqlite'],
            [
                '$_ENV' => '/repo/database/p2dev.sqlite',
                '$_SERVER' => '/repo/database/p2dev.sqlite',
                'getenv()' => '/repo/database/p2dev.sqlite',
            ],
            static fn (string $p): bool => true
        );

        $this->expectNotToPerformAssertions();
    }

    public function test_it_allows_the_fixture_when_only_one_source_carries_it(): void
    {
        // The realistic shape: a prefixed command writes getenv() and $_SERVER but not $_ENV.
        DevFixtureDatabaseGuard::assertEveryEnvironmentSourceIsSafe(
            ['$_ENV' => null, '$_SERVER' => 'sqlite', 'getenv()' => 'sqlite'],
            ['$_ENV' => null, '$_SERVER' => '/repo/database/p2dev.sqlite', 'getenv()' => '/repo/database/p2dev.sqlite'],
            static fn (string $p): bool => true
        );

        $this->expectNotToPerformAssertions();
    }

    // ---------------------------------------------------------------------------------------
    // RULE 3a -- an empty environment is REFUSED, not allowed to fall through to .env
    // ---------------------------------------------------------------------------------------

    public function test_it_refuses_when_no_source_names_a_database(): void
    {
        $this->assertRefused(
            ['$_ENV' => null, '$_SERVER' => null, 'getenv()' => null],
            ['$_ENV' => null, '$_SERVER' => null, 'getenv()' => null],
            'no environment source names a database'
        );
    }

    public function test_it_refuses_an_empty_string_as_a_named_database(): void
    {
        $this->assertRefused(
            ['$_ENV' => null, '$_SERVER' => null, 'getenv()' => null],
            ['$_ENV' => '', '$_SERVER' => '', 'getenv()' => ''],
            'no environment source names a database'
        );
    }

    // ---------------------------------------------------------------------------------------
    // RULE 1 -- EVERY source is checked, not the first one present.
    //
    // These are the cases that matter most, and they encode the measured incident: an `export`
    // survives in $_SERVER and ONLY there, while phpunit's force="true" guarantees $_ENV and
    // getenv() always carry the safe value. A guard that short-circuits on the first present
    // source reads the safe one and never sees the dangerous one.
    // ---------------------------------------------------------------------------------------

    public function test_it_refuses_mysql_hiding_in_server_behind_two_safe_sources(): void
    {
        $this->assertRefused(
            ['$_ENV' => 'sqlite', '$_SERVER' => 'mysql', 'getenv()' => 'sqlite'],
            [
                '$_ENV' => '/repo/database/p2dev.sqlite',
                '$_SERVER' => '/repo/database/p2dev.sqlite',
                'getenv()' => '/repo/database/p2dev.sqlite',
            ],
            'source $_SERVER reports DB_CONNECTION='
        );
    }

    public function test_it_refuses_a_foreign_database_path_hiding_in_server(): void
    {
        $this->assertRefused(
            ['$_ENV' => 'sqlite', '$_SERVER' => 'sqlite', 'getenv()' => 'sqlite'],
            [
                '$_ENV' => '/repo/database/p2dev.sqlite',
                '$_SERVER' => '/var/www/live/database/production.sqlite',
                'getenv()' => '/repo/database/p2dev.sqlite',
            ],
            "whose filename 'production.sqlite' is not a known development fixture"
        );
    }

    public function test_it_refuses_mysql_hiding_in_env_when_the_others_are_absent(): void
    {
        $this->assertRefused(
            ['$_ENV' => 'mysql', '$_SERVER' => null, 'getenv()' => null],
            ['$_ENV' => '/repo/database/p2dev.sqlite', '$_SERVER' => null, 'getenv()' => null],
            'source $_ENV reports DB_CONNECTION='
        );
    }

    public function test_it_refuses_mysql_hiding_in_getenv_when_the_others_are_absent(): void
    {
        $this->assertRefused(
            ['$_ENV' => null, '$_SERVER' => null, 'getenv()' => 'mysql'],
            ['$_ENV' => null, '$_SERVER' => null, 'getenv()' => '/repo/database/p2dev.sqlite'],
            'source getenv() reports DB_CONNECTION='
        );
    }

    // ---------------------------------------------------------------------------------------
    // RULE 2 -- it must be the fixture, not merely a sqlite file
    // ---------------------------------------------------------------------------------------

    public function test_it_refuses_the_repositorys_own_database_sqlite(): void
    {
        // The specific trap: `database/database.sqlite` is what .env names, is a real path in this
        // repository, and is the name a developer reaches for by habit. serve-dev.sh records that
        // it is NOT the fixture.
        $this->assertRefused(
            ['getenv()' => 'sqlite'],
            ['getenv()' => '/repo/database/database.sqlite'],
            "filename 'database.sqlite' is not a known development fixture"
        );
    }

    public function test_it_refuses_in_memory(): void
    {
        $this->assertRefused(
            ['getenv()' => 'sqlite'],
            ['getenv()' => ':memory:'],
            'is not a known development fixture'
        );
    }

    public function test_it_refuses_a_mysql_database_name(): void
    {
        $this->assertRefused(
            ['getenv()' => 'mysql'],
            ['getenv()' => 'storesyria_production'],
            'reports DB_CONNECTION='
        );
    }

    public function test_it_refuses_a_fixture_lookalike_in_another_directory_only_if_renamed(): void
    {
        // Honest about the guard's limit: it checks the BASENAME, so a copy of the fixture
        // elsewhere is allowed. That is intended -- a developer may keep the fixture outside the
        // repository -- and it is recorded here so nobody mistakes the check for a path lock.
        DevFixtureDatabaseGuard::assertEveryEnvironmentSourceIsSafe(
            ['getenv()' => 'sqlite'],
            ['getenv()' => '/somewhere/else/p2dev.sqlite'],
            static fn (string $p): bool => true
        );

        $this->expectNotToPerformAssertions();
    }

    // ---------------------------------------------------------------------------------------
    // RULE 3b -- it must already exist
    // ---------------------------------------------------------------------------------------

    public function test_it_refuses_a_fixture_path_that_does_not_exist(): void
    {
        $this->assertRefused(
            ['getenv()' => 'sqlite'],
            ['getenv()' => '/repo/database/p2dev.sqlite'],
            'which does not exist',
            static fn (string $p): bool => false
        );
    }

    /**
     * The guard's own allowlist must not grow silently. A new entry is a decision about which
     * files a writing tool may touch, and it should be made in a diff somebody reads.
     */
    public function test_the_allowed_fixture_list_is_exactly_what_is_recorded(): void
    {
        $this->assertSame(['p2dev.sqlite'], DevFixtureDatabaseGuard::ALLOWED_FIXTURES);
    }
}
