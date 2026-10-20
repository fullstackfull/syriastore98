<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ComposerClassLoaderGuard;

/**
 * THE GUARD AGAINST A CACHED "NO" THAT HAS BECOME FALSE, and the wiring that attaches it.
 *
 * Composer's `ClassLoader` keeps a private `$missingClasses` map
 * (`vendor/composer/ClassLoader.php:93`), consults it first in `findFile()` (`:448`) and writes
 * into it on a miss (`:471`). It belongs to the loader SINGLETON, so it outlives every Laravel
 * application teardown. `Workdo\` is absent from composer.json's psr-4 block, so a module's
 * namespace exists only after `PackageServiceProvider::loadAddons()` runs `addPsr4()` -- which
 * means a cached miss recorded BEFORE that call can never be undone by it.
 *
 * WHAT THAT COST, MEASURED. `php artisan test tests/Feature/Tenancy tests/Feature/Security` --
 * the `/tenant-check` selection -- reported **5 failures** that neither the individual files nor
 * `--testsuite=Feature` reported. All five were POSITIVE controls ("a merchant can <verb> their
 * OWN row"), and all five failed on **HTTP 500, not 403**, so no guard was involved:
 * `Class "Workdo\AuctionProduct\app\Models\AuctionProductSetting" not found`. The failure
 * direction matters: an order-dependent POSITIVE control reads as "the fix locks the owner out",
 * which is a false alarm rather than a false pass -- but the same mechanism could silently make a
 * module route 500 under a NEGATIVE control and have it pass for the wrong reason, which is the
 * one this project keeps getting bitten by.
 *
 * This file asserts the helper's behaviour; the behaviour END TO END is asserted by the module
 * guard suites themselves, which go green in the combined selection only with the wiring in place.
 */
class ComposerClassLoaderGuardTest extends TestCase
{
    private function missing(): \ReflectionProperty
    {
        $property = new \ReflectionProperty(
            ComposerClassLoaderGuard::loader(),
            'missingClasses'
        );
        $property->setAccessible(true);

        return $property;
    }

    /** The premise. Without a registered ClassLoader there is nothing to clear and no bug. */
    public function test_premise_a_composer_class_loader_is_registered(): void
    {
        $this->assertInstanceOf(
            \Composer\Autoload\ClassLoader::class,
            ComposerClassLoaderGuard::loader(),
            'No Composer ClassLoader is registered, so the negative cache this guard clears does '
            . 'not exist and the guard is dead code. Re-derive before deleting it.'
        );
    }

    /** The premise that makes the bug possible: `Workdo\` is NOT a compile-time prefix. */
    public function test_premise_the_workdo_namespace_is_not_in_composer_json(): void
    {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);

        $this->assertArrayNotHasKey('Workdo\\', $composer['autoload']['psr-4'] ?? [],
            'Workdo\\ is now a static psr-4 prefix, so module classes resolve from boot and a '
            . 'miss can no longer be cached before the prefix exists. If that is deliberate, this '
            . 'guard can go -- but re-run the combined Tenancy+Security selection first.');
    }

    public function test_it_drops_workdo_misses_and_keeps_every_other_entry(): void
    {
        $property = $this->missing();
        $loader = ComposerClassLoaderGuard::loader();
        $original = $property->getValue($loader);

        try {
            $property->setValue($loader, [
                'Workdo\\Fabricated\\app\\Models\\Thing' => true,
                'Workdo\\Other\\app\\Models\\Thing' => true,
                'App\\Fabricated\\NotAModule' => true,
                'Vendor\\Something\\Else' => true,
            ]);

            $dropped = ComposerClassLoaderGuard::forgetWorkdoMisses();

            $this->assertSame(2, $dropped, 'Exactly the two Workdo entries must be dropped.');
            $this->assertSame(
                ['App\\Fabricated\\NotAModule' => true, 'Vendor\\Something\\Else' => true],
                $property->getValue($loader),
                'A non-Workdo cached miss was cleared too. Those are genuinely absent classes and '
                . 'clearing them only costs filesystem lookups -- the scope is deliberate.'
            );
        } finally {
            $property->setValue($loader, $original);
        }
    }

    public function test_it_reports_zero_and_touches_nothing_when_there_is_nothing_to_drop(): void
    {
        $property = $this->missing();
        $loader = ComposerClassLoaderGuard::loader();
        $original = $property->getValue($loader);

        try {
            $property->setValue($loader, ['App\\Fabricated\\NotAModule' => true]);

            $this->assertSame(0, ComposerClassLoaderGuard::forgetWorkdoMisses());
            $this->assertSame(
                ['App\\Fabricated\\NotAModule' => true],
                $property->getValue($loader)
            );
        } finally {
            $property->setValue($loader, $original);
        }
    }

    /**
     * THE WIRING. A helper nothing calls is not a control, and this is the single most likely way
     * the protection disappears -- the call is one line in a file whose purpose is something else.
     *
     * THE POSITION WITHIN setUp() IS NOT ASSERTED, AND THAT IS A CORRECTION. The first version of
     * this test required the call to FOLLOW `parent::setUp()`, on the reasoning that the
     * application boot is what primes the cache. **Measured both ways, and the reasoning was
     * wrong:** with the call moved ABOVE `parent::setUp()` the combined selection is equally green
     * (49 passed). It clears what the PREVIOUS test left behind, and the current test's own boot
     * re-primes nothing, because `RefreshDatabase` has rolled the `add_on_managers` rows back. An
     * assertion whose stated reason does not hold is worse than no assertion, so it is gone rather
     * than re-justified.
     *
     * THE RESIDUAL, stated because no position fixes it: if a test primes the cache for a module
     * class INSIDE its own body before calling `loadAddons()`, neither position helps. Such a test
     * must call `forgetWorkdoMisses()` itself after `addPsr4()`.
     */
    public function test_the_base_test_case_clears_the_cache_in_set_up(): void
    {
        $source = file_get_contents(__DIR__ . '/../TestCase.php');

        $this->assertStringContainsString('ComposerClassLoaderGuard::forgetWorkdoMisses();', $source,
            'tests/TestCase.php no longer clears the Workdo negative cache. Run '
            . '`php artisan test tests/Feature/Tenancy tests/Feature/Security` -- it reported 5 '
            . 'failures without this line, none of which the full Feature suite reports.');

        $setUpAt = strpos($source, 'protected function setUp(): void');
        $callAt = strpos($source, 'ComposerClassLoaderGuard::forgetWorkdoMisses();');

        $this->assertIsInt($setUpAt);
        $this->assertIsInt($callAt);
        $this->assertLessThan($callAt, $setUpAt,
            'The call must be inside setUp(). Anywhere else and it never runs.');
    }
}
