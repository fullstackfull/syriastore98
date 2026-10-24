<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\ComposerClassLoaderGuard;
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

        // PROCESS-GLOBAL POLLUTION, CLEARED PER TEST. Composer's ClassLoader caches NEGATIVE
        // lookups, and `Workdo\` is not in composer.json -- a module's prefix is registered at
        // runtime by PackageServiceProvider::loadAddons(). So one test that asks class_exists()
        // for a module model before that call makes the class PERMANENTLY unresolvable for every
        // later test in the process, whatever addPsr4() they then run.
        //
        // MEASURED: `php artisan test tests/Feature/Tenancy tests/Feature/Security` reported
        // 5 failures that each file alone and the full Feature suite did not -- all five POSITIVE
        // controls, all five on HTTP 500 rather than 403, all five
        // `Class "Workdo\AuctionProduct\app\Models\AuctionProductSetting" not found`. 23 of the
        // 184 cached misses were `Workdo\`; clearing them took the request from 500 to 302.
        //
        // See Tests\Support\ComposerClassLoaderGuard for the full derivation. It changes no
        // production behaviour -- production registers every prefix at boot, before any
        // application code can ask -- and it weakens nothing: class_exists() still answers false
        // for a prefix nobody registered.
        //
        // The position relative to parent::setUp() does NOT matter, measured both ways: what this
        // clears is what the PREVIOUS test left behind, and this test's own boot re-primes nothing
        // because RefreshDatabase has rolled the add_on_managers rows back. It sits here only to
        // keep the isolation assertion above it first.
        ComposerClassLoaderGuard::forgetWorkdoMisses();
    }
}
