<?php

namespace Tests\Feature\Tenancy;

use App\Models\Customer;
use App\Scopes\StoreScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * NEW-SCOPE-BYPASS-01 / NEW-SCOPE-BYPASS-02 -- THE TWO ELOQUENT BUILDER METHODS THAT ESCAPE
 * EVERY GLOBAL SCOPE.
 *
 * ============================================================================================
 *  HOW THIS WAS FOUND, because it matters that it was not found by reading.
 * ============================================================================================
 *
 * It was found by a FAILING TEST. A cross-tenant mutation test in
 * StorefrontContextModelIsolationTest fired five attack shapes at another store's `customers`
 * row and read the result back through raw `DB::table()`. Four were denied. The fifth --
 * `Customer::where('id', $victimId)->forceDelete()` -- DELETED THE ROW, and the raw read-back
 * caught it:
 *
 *     RAW read-back: the customer row was DELETED across the tenant boundary.
 *     Failed asserting that null is not null.
 *
 * That is exactly why every negative in these suites is read back through raw SQL rather than
 * through Eloquent: a scoped re-read would have shown the row absent and the test would have
 * passed while the data was gone.
 *
 * ============================================================================================
 *  THE MECHANISM, read from the installed framework source.
 * ============================================================================================
 *
 * Global scopes are applied by `Builder::applyScopes()`, which is reached only through
 * `Builder::toBase()` (vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:
 * 1907-1910, `return $this->applyScopes()->getQuery()`). So any Eloquent Builder method that
 * touches `$this->query` DIRECTLY instead of `toBase()` is a scope bypass. There are exactly two
 * ways to do that, and both were enumerated mechanically over the whole class rather than
 * guessed at:
 *
 *   1. `Builder::forceDelete()` -- Builder.php:1397-1400
 *          public function forceDelete()
 *          {
 *              return $this->query->delete();
 *          }
 *      Measured: it is the ONLY method in the entire Eloquent Builder whose body is
 *      `return $this->query->...`. Contrast `Builder::delete()` at :1381-1388, which does go
 *      through `toBase()` and so IS scoped -- which is why the soft `delete()` in the same test
 *      was correctly denied.
 *
 *   2. The `__call` FALLBACK -- Builder.php:2123, `$this->forwardCallTo($this->query, $method,
 *      $parameters)`. A method that is not a macro, not a named scope and not in the `$passthru`
 *      allowlist (:105-137) is forwarded straight to the base query builder. `$passthru` entries
 *      go through `toBase()` at :2119-2120 and are therefore safe; anything else is not.
 *      `truncate` is NOT in `$passthru` (checked entry by entry) and IS a base-builder method
 *      (Query/Builder.php:4036), so `Model::query()->truncate()` empties the WHOLE TABLE for
 *      EVERY tenant with no predicate at all. That is strictly worse than the first.
 *
 * ============================================================================================
 *  WHY NEITHER IS FIXED HERE, AND WHY THAT IS THE RIGHT CALL RATHER THAN A SHRUG.
 * ============================================================================================
 *
 * The obvious fix is a custom Eloquent builder attached by the trait --
 * `BelongsToStore::newEloquentBuilder()` returning a subclass that overrides `forceDelete()`.
 * IT MUST NOT BE DONE THAT WAY, and the reason is a fatal, not a preference:
 *
 *     vendor/genealabs/laravel-model-caching/src/Traits/ModelCaching.php:109 ALREADY declares
 *     `newEloquentBuilder()`, and `Cachable` composes `ModelCaching`. All ten allowlisted models
 *     use `Cachable`. Declaring the same method in `BelongsToStore` is a TRAIT METHOD COLLISION:
 *     PHP raises a fatal error at class-composition time for every model carrying both traits.
 *
 * That is the same failure class as the `Illuminate\Database\Eloquent\Scopes\Scope` FQCN that was
 * shipped here once and fatalled all eleven trait-carrying models -- a composition error that
 * `php -l` cannot see. Overriding `newEloquentBuilder()` on each model class individually would
 * avoid the collision but would mean reimplementing the caching package's builder selection by
 * hand on ten models, so a mistake silently disables the model cache or serves another tenant's
 * warmed rows. Neither trade is acceptable for a bypass with NO first-party call site.
 *
 * MEASURED REACHABILITY, which is what makes "document and pin" the proportionate response:
 *
 *   $ grep -rn "forceDelete" app/ packages/ routes/ resources/ themes/ database/ \
 *       --include=*.php --include=*.blade.php | wc -l
 *   0
 *   $ grep -rn "::truncate()\|->truncate()" app/ packages/ routes/ database/ --include=*.php
 *   app/Models/Utility.php:3174:        City::truncate();
 *   app/Models/Utility.php:3199:        State::truncate();
 *   app/Models/Utility.php:3215:        Country::truncate();
 *
 * Zero `forceDelete` sites anywhere. Three `truncate` sites, all three on PLATFORM-GLOBAL
 * geographic reference models -- City, State and Country carry no `store_id`, do not carry the
 * BelongsToStore trait and are not allowlisted, so none of the three crosses a tenant boundary.
 *
 * So both findings are REAL and both are currently UNREACHED. They are reported OPEN with this
 * evidence, pinned by the tests below so nobody can mistake them for protected, and ratcheted so
 * that the first first-party call site turns this suite red.
 */
class EloquentScopeBypassInventoryTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        StoreScope::resetExplicitContext();
        StoreScope::resetSuppression();
        StoreScope::forgetSlugStoreIds();
        StoreScope::forgetOwnedStoreIds();

        parent::tearDown();
    }

    private function seedCustomer($store, string $email): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Victim', 'last_name' => 'Row',
            'email' => $email,
            'password' => bcrypt('not-a-real-credential'),
            'store_id' => $store->id, 'status' => 1,
            'billable_id' => $store->created_by,
            'billable_type' => \App\Models\User::class,
        ]);
    }

    /**
     * NEW-SCOPE-BYPASS-01. The behaviour, asserted as it IS rather than as it should be.
     *
     * This test passes BECAUSE the bypass exists. If the framework or a future custom builder
     * closes it, this test goes red -- and that is the correct signal: the finding has been
     * fixed and this suite must be rewritten to assert the denial instead.
     */
    public function test_force_delete_escapes_the_tenant_scope_and_that_is_an_open_finding(): void
    {
        [, $storeA] = $this->makeTenant();
        [$merchantB] = $this->makeTenant();

        $email = 'bypass-' . uniqid() . '@example.test';
        $victim = $this->seedCustomer($storeA, $email);

        $this->actingAs($merchantB->fresh());

        // Precondition: the scope really is live on this model for this principal, so the result
        // below is attributable to forceDelete() and not to the scope being off.
        $this->assertTrue(StoreScope::isAllowlisted(Customer::class));
        $this->assertSame(StoreScope::MODE_TENANT, StoreScope::resolveTenant()['mode']);
        $this->assertNull(Customer::find($victim->id),
            'the scoped read must be denied, or this test proves nothing about forceDelete');

        // The scoped soft delete is correctly denied -- Builder::delete() goes through toBase().
        Customer::where('id', $victim->id)->delete();
        $this->assertNotNull(DB::table('customers')->where('id', $victim->id)->first(),
            'Builder::delete() must stay scoped (Builder.php:1381-1388).');

        // And forceDelete() is not -- Builder.php:1397-1400 calls $this->query->delete().
        Customer::where('id', $victim->id)->forceDelete();

        $this->assertNull(DB::table('customers')->where('id', $victim->id)->first(),
            'NEW-SCOPE-BYPASS-01 has been CLOSED. That is good news, not a failure -- but this '
            . 'suite now documents behaviour that no longer exists. Rewrite it to assert the '
            . 'denial, and move the register row to FIXED AND VERIFIED.');
    }

    /** NEW-SCOPE-BYPASS-02. Same shape, via the `__call` fallback, and worse: no predicate at all. */
    public function test_truncate_escapes_the_tenant_scope_entirely_and_that_is_an_open_finding(): void
    {
        [, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $this->seedCustomer($storeA, 'trunc-a-' . uniqid() . '@example.test');
        $this->seedCustomer($storeB, 'trunc-b-' . uniqid() . '@example.test');

        $this->actingAs($merchantB->fresh());

        $this->assertSame(1, Customer::count(),
            'precondition: the scope limits merchant B to their own one row');
        $this->assertSame(2, (int) DB::table('customers')->count(),
            'precondition: there really are two rows in the table');

        Customer::query()->truncate();

        $this->assertSame(0, (int) DB::table('customers')->count(),
            'NEW-SCOPE-BYPASS-02 has been CLOSED. Rewrite this test to assert the denial and '
            . 'move the register row to FIXED AND VERIFIED.');
    }

    /**
     * THE RATCHET. Both bypasses are unreached TODAY; this is what makes that a measurement
     * instead of a memory. The first first-party `forceDelete()` turns this red.
     */
    public function test_no_first_party_code_calls_force_delete(): void
    {
        $hits = $this->grepFirstParty('/->\s*forceDelete\s*\(|::\s*forceDelete\s*\(/');

        $this->assertSame([], $hits,
            'A first-party `forceDelete()` call has appeared. It BYPASSES every global scope '
            . '(Builder.php:1397-1400), so tenancy at that site is whatever predicate is written '
            . 'at the call site and nothing else. Either write an explicit `store_id` predicate '
            . 'there, or replace it with `delete()`, which is scoped.');
    }

    /**
     * `truncate()` is allowed to exist -- it legitimately reloads the geographic reference
     * tables -- but NEVER on a model the tenant scope is supposed to be protecting.
     */
    public function test_no_first_party_truncate_targets_a_scoped_model(): void
    {
        $hits = $this->grepFirstParty('/->\s*truncate\s*\(\s*\)|::\s*truncate\s*\(\s*\)/');

        // The three known-good sites: platform-global geographic reference data, no `store_id`,
        // no BelongsToStore trait, not allowlisted.
        $this->assertSame([
            // LINE NUMBERS SHIFTED BY 56 on 2026-10-23 (P1-ORD-01): a docblock was added to
            // Utility::paymentWebhook() above these sites. The SITES ARE UNCHANGED -- each
            // entry below was verified to point at the same construct it named before -- and
            // nothing was added to or removed from this inventory. A census keyed on line
            // numbers moves whenever a comment does, which is why the D13 store-less
            // declaration in OwnedPathRawQueryInventoryTest keys on a statement fragment
            // instead.
            'app/Models/Utility.php:3230',   // City::truncate()
            'app/Models/Utility.php:3255',   // State::truncate()
            'app/Models/Utility.php:3271',   // Country::truncate()
        ], $hits, 'The truncate inventory changed. `truncate()` is forwarded straight to the base '
            . 'query builder (Builder.php:2123) and so empties the WHOLE TABLE for EVERY tenant. '
            . 'A new site is only acceptable on a model with no tenant discriminator -- verify '
            . 'that, then add it here with the reason.');

        foreach ([\App\Models\City::class, \App\Models\State::class, \App\Models\Country::class] as $class) {
            $this->assertFalse(StoreScope::isAllowlisted($class),
                $class . ' is truncated at one of the sites above AND is now allowlisted. A '
                . 'truncate on a scoped model empties every tenant\'s rows with no predicate.');
        }
    }

    /**
     * Grep app/, packages/, routes/, database/ and themes/ for a pattern, skipping comment and
     * docblock lines so that this very file's documentation cannot satisfy its own ratchet.
     *
     * @return array<int, string>
     */
    private function grepFirstParty(string $pattern): array
    {
        $hits = [];

        foreach (['app', 'packages', 'routes', 'database', 'themes'] as $dir) {
            $base = base_path($dir);

            if (! is_dir($base)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['php'], true)) {
                    continue;
                }

                $relative = ltrim(str_replace(base_path(), '', $file->getPathname()), '/');
                $lines = file($file->getPathname()) ?: [];

                foreach ($lines as $index => $line) {
                    $trimmed = ltrim($line);

                    if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')
                        || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')) {
                        continue;
                    }

                    if (preg_match($pattern, $line)) {
                        $hits[] = $relative . ':' . ($index + 1);
                    }
                }
            }
        }

        sort($hits);

        return $hits;
    }
}
