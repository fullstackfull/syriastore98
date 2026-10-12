<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * DEFENCE IN DEPTH ONLY -- this is NOT the primary isolation control.
     *
     * The primary control is tests/bootstrap.php (Layer 0), which runs before the
     * framework and before any trait hook. This assertion runs AFTER parent::setUp(),
     * and parent::setUp() is where setUpTraits() invokes RefreshDatabase -> migrate:fresh.
     * So by the time this line executes, a destructive refresh has ALREADY happened.
     * It is kept because it costs nothing and because it also covers test classes that
     * do not use RefreshDatabase at all.
     *
     * Do NOT move the assertion above parent::setUp() (config() does not exist yet),
     * do NOT relocate it into afterApplicationCreated() (dispatched from inside
     * parent::setUp(), so late for the same reason), and do NOT declare a
     * refreshDatabase() override here: a method brought in by `use RefreshDatabase`
     * shadows one inherited from this parent, so such an override would never run in
     * any of the 8 classes that actually use the trait. Verified directly with PHP.
     *
     * See docs/development/phase-1/05-REGRESSION-TEST-PLAN.md §1.3.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.default');

        TestDatabaseGuard::assertSqliteInMemory(
            $connection,
            config("database.connections.{$connection}.database")
        );
    }
}
